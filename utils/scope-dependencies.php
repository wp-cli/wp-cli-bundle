<?php
/**
 * Prefix the namespaces of the Phar's third-party dependencies.
 *
 * Run after `composer install` and before `utils/make-phar.php`. Rewrites the
 * `composer/composer` dependency tree in `vendor/` in place, then regenerates
 * the Composer autoloader so it advertises the prefixed class names instead of
 * the original ones.
 *
 * That last step is not optional. php-scoper rewrites source files but leaves
 * Composer's generated autoload maps alone, so a scoped tree with a stale
 * autoloader still claims `Psr\Log\` and the conflict it was meant to fix
 * survives, silently.
 *
 * Usage: php utils/scope-dependencies.php [--vendor-dir=<path>] [--quiet]
 *
 * @see https://github.com/wp-cli/wp-cli/issues/5920
 */

declare( strict_types=1 );

define( 'WP_CLI_BUNDLE_ROOT', rtrim( dirname( __DIR__ ), '/' ) );

/**
 * Vendor directories handed to php-scoper. Keep in sync with the finders in
 * `utils/scoper/scoper.inc.php`.
 */
const SCOPED_VENDOR_DIRS = [
	'composer',
	'justinrainbow',
	'marc-mabe', // spellchecker:disable-line
	'psr',
	'react',
	'seld',
	'symfony',
];

$options    = getopt( '', [ 'vendor-dir::', 'quiet' ] );
$be_quiet   = isset( $options['quiet'] );
$vendor_dir = isset( $options['vendor-dir'] ) && is_string( $options['vendor-dir'] )
	? rtrim( $options['vendor-dir'], '/' )
	: WP_CLI_BUNDLE_ROOT . '/vendor';

$scoper_dir = WP_CLI_BUNDLE_ROOT . '/utils/scoper';

/**
 * Write a progress line unless running quietly.
 */
function report( string $message ): void {
	if ( ! $GLOBALS['be_quiet'] ) {
		fwrite( STDOUT, $message . PHP_EOL );
	}
}

/**
 * Run a command, returning its exit code.
 *
 * @param array<string> $command
 */
function run( array $command, ?string $cwd = null ): int {
	$cwd_prefix = null !== $cwd ? sprintf( 'cd %s && ', escapeshellarg( $cwd ) ) : '';
	$escaped    = implode( ' ', array_map( 'escapeshellarg', $command ) );

	passthru( $cwd_prefix . $escaped, $exit_code );

	return $exit_code;
}

/**
 * Fail with a message.
 */
function fail( string $message ): void {
	fwrite( STDERR, 'Error: ' . $message . PHP_EOL );
	exit( 1 );
}

if ( ! is_dir( $vendor_dir ) ) {
	fail( sprintf( "Vendor directory '%s' does not exist. Run `composer install` first.", $vendor_dir ) );
}

// php-scoper needs PHP 8.2+, which is why it lives in its own composer.json
// rather than in the bundle's (that one still has to resolve against PHP 7.2.24).
if ( PHP_VERSION_ID < 80200 ) {
	fail( sprintf( 'php-scoper requires PHP 8.2 or newer, but this is PHP %s.', PHP_VERSION ) );
}

// --- 1. Make sure the isolated toolchain is installed. ----------------------

if ( ! file_exists( $scoper_dir . '/vendor/bin/php-scoper' ) ) {
	report( 'Installing the php-scoper toolchain...' );
	if ( 0 !== run( [ 'composer', 'install', '--no-interaction', '--prefer-dist', '--quiet' ], $scoper_dir ) ) {
		fail( 'Failed to install the php-scoper toolchain.' );
	}
}

// --- 2. Prefix the dependency tree. -----------------------------------------

$output_dir = $vendor_dir . '/../build/scoped-vendor';

if ( is_dir( $output_dir ) ) {
	run( [ 'rm', '-rf', $output_dir ] );
}

report( 'Prefixing third-party dependencies...' );

putenv( 'WP_CLI_SCOPER_VENDOR_DIR=' . $vendor_dir );

$scoper_exit = run(
	[
		$scoper_dir . '/vendor/bin/php-scoper',
		'add-prefix',
		'--config=' . $scoper_dir . '/scoper.inc.php',
		'--output-dir=' . $output_dir,
		'--force',
		'--no-interaction',
		$be_quiet ? '--quiet' : '--no-ansi',
	]
);

if ( 0 !== $scoper_exit ) {
	fail( 'php-scoper failed.' );
}

// --- 3. Swap the prefixed tree into vendor/. --------------------------------

foreach ( SCOPED_VENDOR_DIRS as $dir ) {
	$scoped = $output_dir . '/' . $dir;
	$target = $vendor_dir . '/' . $dir;

	if ( ! is_dir( $scoped ) ) {
		continue;
	}

	report( sprintf( '  Replacing vendor/%s', $dir ) );

	// Composer's autoloader machinery lives alongside the composer/* packages
	// in vendor/composer and is regenerated below, so only the package
	// subdirectories are replaced wholesale.
	if ( 0 !== run( [ 'cp', '-a', $scoped . '/.', $target . '/' ] ) ) {
		fail( sprintf( "Failed to copy the prefixed '%s' into place.", $dir ) );
	}
}

// --- 4. Teach Composer about the new class names. ---------------------------

/*
 * The prefixed files no longer satisfy their packages' PSR-4 rules: the classes
 * in vendor/psr/log now declare WP_CLI\Vendor\Psr\Log\*, while psr/log's
 * composer.json still maps Psr\Log\ to that directory. Left alone, a dump would
 * both re-advertise the unprefixed prefix and skip the prefixed classes as
 * "not compliant with PSR-4".
 *
 * Rewriting the affected packages' autoload rules to a classmap sidesteps both
 * problems: Composer scans the directories and records whatever class names the
 * files actually declare.
 */
$installed_json = $vendor_dir . '/composer/installed.json';

if ( ! file_exists( $installed_json ) ) {
	fail( sprintf( "Could not find '%s'.", $installed_json ) );
}

$decoded = json_decode( (string) file_get_contents( $installed_json ), true );

if ( ! is_array( $decoded ) || ! isset( $decoded['packages'] ) || ! is_array( $decoded['packages'] ) ) {
	fail( sprintf( "Could not decode '%s'.", $installed_json ) );
}

/**
 * @var array<string, mixed> $decoded
 * @var array<int|string, mixed> $packages
 */
$packages = $decoded['packages'];
$patched  = 0;

foreach ( $packages as $index => $package ) {
	if ( ! is_array( $package ) || ! isset( $package['name'] ) || ! is_string( $package['name'] ) ) {
		continue;
	}

	$vendor_name = explode( '/', $package['name'] )[0];

	if ( ! in_array( $vendor_name, SCOPED_VENDOR_DIRS, true ) ) {
		continue;
	}

	if ( ! isset( $package['autoload'] ) || ! is_array( $package['autoload'] ) ) {
		continue;
	}

	$autoload = $package['autoload'];
	$roots    = [];

	foreach ( [ 'psr-4', 'psr-0' ] as $standard ) {
		$rules = $autoload[ $standard ] ?? [];

		if ( ! is_array( $rules ) ) {
			continue;
		}

		foreach ( $rules as $paths ) {
			foreach ( (array) $paths as $path ) {
				if ( is_string( $path ) ) {
					$roots[] = '' === $path ? '.' : $path;
				}
			}
		}
	}

	$classmap_rules = $autoload['classmap'] ?? [];

	if ( is_array( $classmap_rules ) ) {
		foreach ( $classmap_rules as $path ) {
			if ( is_string( $path ) ) {
				$roots[] = $path;
			}
		}
	}

	if ( ! $roots ) {
		continue;
	}

	$package['autoload']  = [ 'classmap' => array_values( array_unique( $roots ) ) ];
	$packages[ $index ]   = $package;
	++$patched;
}

$decoded['packages'] = $packages;

report( sprintf( 'Rewrote autoload rules for %d prefixed package(s).', $patched ) );

$encoded = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

if ( false === $encoded || false === file_put_contents( $installed_json, $encoded ) ) {
	fail( sprintf( "Failed to write '%s'.", $installed_json ) );
}

// --- 5. Regenerate the autoloader. ------------------------------------------

report( 'Regenerating the Composer autoloader...' );

/*
 * --classmap-authoritative makes the ClassLoader consult only the classmap, so
 * no leftover PSR-4 rule can resurrect an unprefixed name. Everything the Phar
 * runs is inside the Phar, so there is nothing to discover at runtime.
 */
// Derived from the vendor directory rather than assumed, so the script can be
// pointed at a scratch tree for testing.
$composer_root = dirname( $vendor_dir );

if ( 0 !== run( [ 'composer', 'dump-autoload', '--classmap-authoritative', '--no-interaction' ], $composer_root ) ) {
	fail( 'Failed to regenerate the Composer autoloader.' );
}

run( [ 'rm', '-rf', dirname( $output_dir ) ] );

// --- 6. Verify the autoloader no longer claims the unprefixed names. --------

/*
 * The failure mode this guards against is silent: php-scoper rewrites source
 * files but not Composer's generated maps, so a tree that looks scoped can
 * still resolve `Psr\Log\LoggerInterface` to the bundled copy and reintroduce
 * the conflict with nothing in the build output to show for it.
 */
$autoload_files = array_filter(
	[
		$vendor_dir . '/composer/autoload_classmap.php',
		$vendor_dir . '/composer/autoload_psr4.php',
		$vendor_dir . '/composer/autoload_static.php',
	],
	'file_exists'
);

$must_not_appear = [
	'Psr\\Log\\',
	'Symfony\\Component\\Console\\',
	'React\\Promise\\',
	'Seld\\JsonLint\\',
];

$leaked = [];

foreach ( $autoload_files as $file ) {
	$contents = (string) file_get_contents( $file );

	foreach ( $must_not_appear as $symbol ) {
		// Written as it appears in the generated PHP source, where each
		// namespace separator is escaped.
		$needle    = str_replace( '\\', '\\\\', $symbol );
		$prefixed  = 'WP_CLI\\\\Vendor\\\\' . $needle;
		$occurring = substr_count( $contents, $needle ) - substr_count( $contents, $prefixed );

		if ( $occurring > 0 ) {
			$leaked[] = sprintf( '  %s advertises %s (%d time(s))', basename( $file ), $symbol, $occurring );
		}
	}
}

if ( $leaked ) {
	fail(
		"The regenerated autoloader still advertises unprefixed dependencies:\n"
		. implode( "\n", $leaked )
		. "\nThe Phar would keep imposing these on the site. See https://github.com/wp-cli/wp-cli/issues/5920"
	);
}

$classmap = $vendor_dir . '/composer/autoload_classmap.php';

// strpos() rather than str_contains() so the file still parses under the 7.2
// baseline phpcs checks this repository against, even though the script itself
// refuses to run on anything below PHP 8.2.
if ( file_exists( $classmap ) && false === strpos( (string) file_get_contents( $classmap ), 'WP_CLI\\\\Vendor\\\\' ) ) {
	fail( 'The regenerated classmap contains no prefixed classes at all; the prefixing step did not take effect.' );
}

report( 'Verified: the autoloader advertises only prefixed dependencies.' );
report( 'Done.' );
