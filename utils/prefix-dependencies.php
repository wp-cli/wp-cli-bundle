<?php
/**
 * Prefix the namespaces of the Phar's third-party dependencies.
 *
 * Composer's dependency tree (Symfony, psr/log, React, ...) is rewritten by
 * php-scoper under the `WP_CLI\Vendor` namespace into `third_party/`, which
 * gets its own classmap autoloader. `utils/make-phar.php` bundles that tree
 * instead of the original one from `vendor/`, so the Phar no longer imposes
 * its own copies of those libraries on the site it runs against.
 *
 * Runs as Composer's `post-install-cmd`/`post-update-cmd` hook, so a plain
 * `composer install` keeps `third_party/` in sync with `vendor/`. `vendor/` is
 * never modified: a Composer-based installation of the bundle keeps resolving
 * its own dependency versions and has no conflict to avoid.
 *
 * php-scoper needs PHP 8.2+, while the bundle targets PHP 7.2, so the
 * toolchain lives in its own `utils/scoper/composer.json`. When the PHP running
 * Composer is too old, the step is skipped with a notice and the Phar built
 * from this checkout bundles the unprefixed tree. CI that must test on an older
 * PHP can install the toolchain with a newer interpreter first and point
 * `WP_CLI_SCOPER_PHP` at it.
 *
 * Usage: composer prefix-dependencies [-- --force]
 *
 * @see https://github.com/wp-cli/wp-cli/issues/5920
 */

require_once __DIR__ . '/scoper/prefixed-packages.php';

define( 'WP_CLI_BUNDLE_ROOT', dirname( __DIR__ ) );
define( 'WP_CLI_BUNDLE_VENDOR_DIR', WP_CLI_BUNDLE_ROOT . '/vendor' );
define( 'WP_CLI_THIRD_PARTY_DIR', WP_CLI_BUNDLE_ROOT . '/third_party' );
define( 'WP_CLI_SCOPER_DIR', WP_CLI_BUNDLE_ROOT . '/utils/scoper' );
define( 'WP_CLI_SCOPER_MIN_PHP', '8.2.0' );

/**
 * Recorded after a successful run to skip it while nothing changed.
 */
define( 'WP_CLI_THIRD_PARTY_STAMP', WP_CLI_THIRD_PARTY_DIR . '/.stamp' );

$force = in_array( '--force', array_slice( $GLOBALS['argv'], 1 ), true );

/**
 * @param string $message
 * @return void
 */
function report( $message ) {
	fwrite( STDOUT, $message . PHP_EOL );
}

/**
 * Bails out. Composer surfaces the non-zero exit code as a failed install.
 *
 * @param string $message
 * @return never
 */
function fail( $message ) {
	fwrite( STDERR, 'Error: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Prints the reason prefixing is skipped and exits successfully.
 *
 * @param string $reason
 * @return never
 */
function skip( $reason ) {
	if ( is_dir( WP_CLI_THIRD_PARTY_DIR ) ) {
		remove_directory( WP_CLI_THIRD_PARTY_DIR );
		report( 'Removed the stale third_party/ directory.' );
	}

	fwrite( STDERR, 'Notice: ' . $reason . PHP_EOL );
	fwrite( STDERR, 'Notice: A Phar built from this checkout bundles unprefixed third-party dependencies.' . PHP_EOL );
	exit( 0 );
}

/**
 * Runs a command with inherited standard streams and returns its exit code.
 *
 * @param string[] $command
 * @return int
 */
function run( array $command ) {
	// Arrays bypass the shell and its quoting rules, but need PHP 7.4+.
	$command_line = PHP_VERSION_ID >= 70400 ? $command : implode( ' ', array_map( 'escapeshellarg', $command ) );

	// @phpstan-ignore argument.type (the stubs describe PHP 7.2, where proc_open() only takes a string)
	$process = proc_open( $command_line, [ STDIN, STDOUT, STDERR ], $pipes );

	return is_resource( $process ) ? proc_close( $process ) : 1;
}

/**
 * @param string $dir
 * @return void
 */
function remove_directory( $dir ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $entry ) {
		/**
		 * @var SplFileInfo $entry
		 */
		if ( $entry->isDir() && ! $entry->isLink() ) {
			rmdir( $entry->getPathname() );
		} else {
			unlink( $entry->getPathname() );
		}
	}

	rmdir( $dir );
}

/**
 * The Composer binary running this hook, if any.
 *
 * @return string[] Command prefix.
 */
function composer_command() {
	$binary = getenv( 'COMPOSER_BINARY' );

	return is_string( $binary ) && '' !== $binary
		? [ PHP_BINARY, $binary ]
		: [ 'composer' ];
}

// --- Which PHP runs php-scoper? ---------------------------------------------

$scoper_php = getenv( 'WP_CLI_SCOPER_PHP' );

if ( ! is_string( $scoper_php ) || '' === $scoper_php ) {
	$scoper_php         = PHP_BINARY;
	$scoper_php_version = PHP_VERSION;
} else {
	$scoper_php_version = trim( (string) shell_exec( escapeshellarg( $scoper_php ) . ' -r ' . escapeshellarg( 'echo PHP_VERSION;' ) ) );

	if ( ! preg_match( '/^\d+\.\d+\.\d+/', $scoper_php_version ) ) {
		fail( "WP_CLI_SCOPER_PHP does not point to a working PHP interpreter: '{$scoper_php}'." );
	}
}

if ( version_compare( $scoper_php_version, WP_CLI_SCOPER_MIN_PHP, '<' ) ) {
	skip(
		sprintf(
			'Skipping the prefixing of third-party dependencies: php-scoper needs PHP %s or newer, but %s is PHP %s.',
			WP_CLI_SCOPER_MIN_PHP,
			$scoper_php,
			$scoper_php_version
		)
	);
}

// --- What gets prefixed? ----------------------------------------------------

try {
	$installed = wp_cli_installed_packages( WP_CLI_BUNDLE_VENDOR_DIR );
	$packages  = wp_cli_prefixed_packages( WP_CLI_BUNDLE_VENDOR_DIR );
} catch ( RuntimeException $exception ) {
	fail( $exception->getMessage() );
}

if ( ! $packages ) {
	skip( WP_CLI_PREFIXED_ROOT_PACKAGE . ' is not installed (a `--no-dev` install?), so there is nothing to prefix.' );
}

/*
 * Bundled WP-CLI code is not prefixed, so anything it requires from the
 * prefixed tree has to be reachable under its original name. Today that is
 * only `Composer\Semver`, which stays unprefixed anyway; catch a command that
 * starts depending on, say, symfony/process before it breaks in the Phar.
 */
$consumers = [];

foreach ( $installed as $name => $package ) {
	if ( 0 === strpos( $name, 'wp-cli/' ) && 'wp-cli/wp-cli-tests' !== $name ) {
		$consumers[ $name ] = $package['require'];
	}
}

$bundle_composer_json = json_decode( (string) file_get_contents( WP_CLI_BUNDLE_ROOT . '/composer.json' ), true );

if ( is_array( $bundle_composer_json ) && isset( $bundle_composer_json['require'] ) && is_array( $bundle_composer_json['require'] ) ) {
	$consumers['wp-cli/wp-cli-bundle'] = array_map( 'strval', array_keys( $bundle_composer_json['require'] ) );
}

$unreachable = [];

foreach ( $consumers as $consumer => $requirements ) {
	$queue   = $requirements;
	$visited = [];

	while ( $queue ) {
		$name = array_shift( $queue );

		if ( isset( $visited[ $name ] ) || ! isset( $installed[ $name ] ) ) {
			continue;
		}

		$visited[ $name ] = true;

		if ( ! isset( $packages[ $name ] ) ) {
			$queue = array_merge( $queue, $installed[ $name ]['require'] );
			continue;
		}

		$namespaces = [];

		foreach ( [ 'psr-4', 'psr-0' ] as $standard ) {
			if ( isset( $packages[ $name ]['autoload'][ $standard ] ) && is_array( $packages[ $name ]['autoload'][ $standard ] ) ) {
				$namespaces = array_merge( $namespaces, array_map( 'strval', array_keys( $packages[ $name ]['autoload'][ $standard ] ) ) );
			}
		}

		foreach ( $namespaces as $namespace ) {
			if ( ! wp_cli_is_unprefixed_namespace( rtrim( $namespace, '\\' ) ) ) {
				$unreachable[] = "{$consumer} requires {$name}, whose namespace {$namespace} gets prefixed";
			}
		}
	}
}

if ( $unreachable ) {
	fail(
		"Bundled WP-CLI code depends on packages that are prefixed for the Phar and would not find them:\n  "
		. implode( "\n  ", array_unique( $unreachable ) )
		. "\nEither drop the dependency or add its namespace to WP_CLI_UNPREFIXED_NAMESPACES in utils/scoper/prefixed-packages.php."
	);
}

// --- Anything to do? --------------------------------------------------------

$stamp = sha1(
	implode(
		"\n",
		array_map(
			static function ( $file ) {
				return sha1( (string) file_get_contents( $file ) );
			},
			[
				WP_CLI_BUNDLE_VENDOR_DIR . '/composer/installed.json',
				WP_CLI_SCOPER_DIR . '/composer.lock',
				WP_CLI_SCOPER_DIR . '/scoper.inc.php',
				WP_CLI_SCOPER_DIR . '/prefixed-packages.php',
				__FILE__,
			]
		)
	)
);

if ( ! $force && file_exists( WP_CLI_THIRD_PARTY_STAMP ) && trim( (string) file_get_contents( WP_CLI_THIRD_PARTY_STAMP ) ) === $stamp ) {
	report( 'Prefixed third-party dependencies in third_party/ are up to date.' );
	exit( 0 );
}

// --- Toolchain --------------------------------------------------------------

report( 'Installing the php-scoper toolchain into utils/scoper/vendor/...' );

$composer = composer_command();
// Composer checks the platform of the interpreter running it, so the
// toolchain must be installed with the PHP that will run php-scoper.
$composer[0] = PHP_BINARY === $scoper_php ? $composer[0] : $scoper_php;

if ( 0 !== run( array_merge( $composer, [ 'install', '--working-dir=' . WP_CLI_SCOPER_DIR, '--no-interaction', '--no-progress', '--no-plugins' ] ) ) ) {
	fail( 'Failed to install the php-scoper toolchain.' );
}

// --- Prefix -----------------------------------------------------------------

if ( is_dir( WP_CLI_THIRD_PARTY_DIR ) ) {
	remove_directory( WP_CLI_THIRD_PARTY_DIR );
}

report( sprintf( 'Prefixing %d packages into third_party/...', count( $packages ) ) );

$scoper_exit = run(
	[
		$scoper_php,
		WP_CLI_SCOPER_DIR . '/vendor/bin/php-scoper',
		'add-prefix',
		'--config=' . WP_CLI_SCOPER_DIR . '/scoper.inc.php',
		'--output-dir=' . WP_CLI_THIRD_PARTY_DIR,
		'--force',
		'--stop-on-failure',
		'--no-interaction',
		'--no-ansi',
		'--quiet',
	]
);

if ( 0 !== $scoper_exit ) {
	fail( 'php-scoper failed.' );
}

// --- Autoloader -------------------------------------------------------------

/*
 * The prefixed files no longer satisfy their packages' PSR-4 rules (the files
 * in third_party/psr/log now declare `WP_CLI\Vendor\Psr\Log\*`), so those
 * rules become classmap rules over the same directories: Composer then records
 * whatever names the files actually declare. `files` rules are kept as they
 * are; they load the polyfills and the namespaced function files.
 */
$classmap = [];
$files    = [];

foreach ( $packages as $name => $package ) {
	$prefixed_path = WP_CLI_THIRD_PARTY_DIR . '/' . $package['relative'];

	if ( ! is_dir( $prefixed_path ) ) {
		fail( "php-scoper did not produce '{$prefixed_path}'." );
	}

	foreach ( [ 'psr-4', 'psr-0', 'classmap', 'files' ] as $rule ) {
		if ( ! isset( $package['autoload'][ $rule ] ) || ! is_array( $package['autoload'][ $rule ] ) ) {
			continue;
		}

		foreach ( $package['autoload'][ $rule ] as $paths ) {
			foreach ( (array) $paths as $path ) {
				if ( ! is_string( $path ) ) {
					continue;
				}

				$relative = trim( $package['relative'] . '/' . wp_cli_normalize_path( $path ), '/' );

				// Skipped by the finders, like a `Tests/` directory.
				if ( ! file_exists( WP_CLI_THIRD_PARTY_DIR . '/' . $relative ) ) {
					continue;
				}

				if ( 'files' === $rule ) {
					$files[] = $relative;
				} else {
					$classmap[] = $relative;
				}
			}
		}
	}
}

$third_party_composer_json = [
	'name'        => 'wp-cli/wp-cli-bundle-third-party',
	'description' => 'Autoloader for the prefixed dependencies bundled into the Phar. Generated by utils/prefix-dependencies.php.',
	'autoload'    => [
		'classmap' => array_values( array_unique( $classmap ) ),
		'files'    => array_values( array_unique( $files ) ),
	],
	'config'      => [
		'autoloader-suffix'      => 'WpCliBundleThirdParty',
		'classmap-authoritative' => true,
		'optimize-autoloader'    => true,
		'platform-check'         => false,
	],
];

if ( false === file_put_contents( WP_CLI_THIRD_PARTY_DIR . '/composer.json', json_encode( $third_party_composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
	fail( 'Failed to write third_party/composer.json.' );
}

report( 'Generating the third_party/ autoloader...' );

/*
 * `install` rather than `dump-autoload`: only a real install writes the
 * `InstalledVersions.php` that the generated classmap points at, and this
 * autoloader has to stand on its own, as it is registered before the
 * bundle's main one.
 */
if ( 0 !== run( array_merge( composer_command(), [ 'install', '--working-dir=' . WP_CLI_THIRD_PARTY_DIR, '--no-interaction', '--no-plugins', '--no-progress' ] ) ) ) {
	fail( 'Failed to generate the third_party/ autoloader.' );
}

// --- Verify -----------------------------------------------------------------

/*
 * The failure mode this guards against is silent: a class php-scoper left
 * alone still ends up in the classmap under its original name, and the Phar
 * would keep imposing it on the site with nothing in the build output to
 * show for it.
 */
$class_map = require WP_CLI_THIRD_PARTY_DIR . '/vendor/composer/autoload_classmap.php';

if ( ! is_array( $class_map ) || ! $class_map ) {
	fail( 'The third_party/ classmap is empty.' );
}

$leaked   = [];
$prefixed = 0;

foreach ( $class_map as $class => $file ) {
	$class = (string) $class;

	if ( 0 === strpos( $class, WP_CLI_VENDOR_PREFIX . '\\' ) ) {
		++$prefixed;
		continue;
	}

	if ( wp_cli_is_unprefixed_namespace( $class ) ) {
		continue;
	}

	// Global classes are only expected from the polyfills, which guard them.
	if ( false === strpos( $class, '\\' ) && is_string( $file ) && preg_match( '#/symfony/polyfill-[^/]+/#', str_replace( '\\', '/', $file ) ) ) {
		continue;
	}

	$leaked[] = $class;
}

if ( $leaked ) {
	fail(
		"The third_party/ autoloader advertises classes under their original names:\n  "
		. implode( "\n  ", $leaked )
		. "\nThe Phar would keep imposing these on the site, see https://github.com/wp-cli/wp-cli/issues/5920"
	);
}

if ( 0 === $prefixed ) {
	fail( 'The third_party/ classmap contains no prefixed classes at all.' );
}

file_put_contents( WP_CLI_THIRD_PARTY_STAMP, $stamp . "\n" );

report( sprintf( 'Prefixed %d packages (%d classes) into third_party/.', count( $packages ), $prefixed ) );
