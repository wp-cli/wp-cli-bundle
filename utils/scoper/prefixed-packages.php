<?php
/**
 * Resolves which Composer packages get namespace-prefixed for the Phar.
 *
 * The set is `composer/composer` plus everything it pulls in, read from
 * Composer's `installed.json` so that it follows dependency bumps on its own.
 * Shared by `utils/prefix-dependencies.php`, which drives php-scoper and
 * builds the `third_party/` autoloader, and `utils/scoper/scoper.inc.php`,
 * which hands the same directories to php-scoper.
 *
 * Must stay compatible with PHP 7.2, the bundle's minimum version.
 *
 * @see https://github.com/wp-cli/wp-cli/issues/5920
 */

/**
 * Namespace under which the prefixed packages end up.
 */
const WP_CLI_VENDOR_PREFIX = 'WP_CLI\\Vendor';

/**
 * Package whose dependency tree is prefixed.
 */
const WP_CLI_PREFIXED_ROOT_PACKAGE = 'composer/composer';

/**
 * Namespaces that keep their names even though their packages move into
 * `third_party/`.
 *
 * - `Composer`: third-party Composer plugins that `wp package install` runs
 *   are written against the real `Composer\Plugin\PluginInterface`, and
 *   WP-CLI's own commands use `Composer\Semver` directly. References from
 *   this namespace to the prefixed ones are still rewritten.
 * - `Symfony\Polyfill`: the polyfills declare global PHP functions and
 *   classes behind `function_exists()`/`class_exists()` guards, so they
 *   cannot conflict and only work under their original names.
 */
const WP_CLI_UNPREFIXED_NAMESPACES = [ 'Composer', 'Symfony\\Polyfill' ];

/**
 * Reads the packages Composer installed into the given vendor directory.
 *
 * @param string $vendor_dir Absolute path to the bundle's vendor directory.
 * @return array<string, array{path: string, relative: string, require: string[], autoload: array<string, mixed>}> Keyed by package name.
 * @throws RuntimeException If `installed.json` cannot be read.
 */
function wp_cli_installed_packages( $vendor_dir ) {
	$installed_json = $vendor_dir . '/composer/installed.json';
	$installed      = file_exists( $installed_json )
		? json_decode( (string) file_get_contents( $installed_json ), true )
		: null;

	if ( ! is_array( $installed ) || ! isset( $installed['packages'] ) || ! is_array( $installed['packages'] ) ) {
		throw new RuntimeException( "Could not read '{$installed_json}'. Run `composer install` first." );
	}

	$packages = [];

	foreach ( $installed['packages'] as $package ) {
		if ( ! is_array( $package ) || ! isset( $package['name'] ) || ! is_string( $package['name'] ) ) {
			continue;
		}

		// Metapackages have no install path and nothing to prefix.
		if ( ! isset( $package['install-path'] ) || ! is_string( $package['install-path'] ) ) {
			continue;
		}

		// `install-path` is relative to `vendor/composer/`.
		$relative = wp_cli_normalize_path( 'composer/' . $package['install-path'] );
		$require  = isset( $package['require'] ) && is_array( $package['require'] )
			? array_map( 'strval', array_keys( $package['require'] ) )
			: [];
		$autoload = isset( $package['autoload'] ) && is_array( $package['autoload'] )
			? $package['autoload']
			: [];

		$packages[ $package['name'] ] = [
			'path'     => $vendor_dir . '/' . $relative,
			'relative' => $relative,
			'require'  => $require,
			'autoload' => $autoload,
		];
	}

	return $packages;
}

/**
 * Resolves `composer/composer` and its transitive dependencies.
 *
 * Platform packages (`php`, `ext-*`, `composer-plugin-api`) never appear in
 * `installed.json` and drop out on their own.
 *
 * @param string $vendor_dir Absolute path to the bundle's vendor directory.
 * @return array<string, array{path: string, relative: string, require: string[], autoload: array<string, mixed>}> Keyed and sorted by package name; empty if `composer/composer` is not installed.
 */
function wp_cli_prefixed_packages( $vendor_dir ) {
	$installed = wp_cli_installed_packages( $vendor_dir );
	$prefixed  = [];
	$queue     = [ WP_CLI_PREFIXED_ROOT_PACKAGE ];

	while ( $queue ) {
		$name = array_shift( $queue );

		if ( isset( $prefixed[ $name ] ) || ! isset( $installed[ $name ] ) ) {
			continue;
		}

		$prefixed[ $name ] = $installed[ $name ];
		$queue             = array_merge( $queue, $installed[ $name ]['require'] );
	}

	ksort( $prefixed );

	return $prefixed;
}

/**
 * Whether a class or namespace name is one the Phar deliberately keeps unprefixed.
 *
 * @param string $name Fully qualified name without a leading backslash.
 * @return bool
 */
function wp_cli_is_unprefixed_namespace( $name ) {
	foreach ( WP_CLI_UNPREFIXED_NAMESPACES as $namespace ) {
		if ( $name === $namespace || 0 === strpos( $name, $namespace . '\\' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Collapses `.` and `..` segments in a relative, forward-slash path.
 *
 * @param string $path
 * @return string
 */
function wp_cli_normalize_path( $path ) {
	$normalized = [];

	foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}

		if ( '..' === $segment ) {
			array_pop( $normalized );
			continue;
		}

		$normalized[] = $segment;
	}

	return implode( '/', $normalized );
}
