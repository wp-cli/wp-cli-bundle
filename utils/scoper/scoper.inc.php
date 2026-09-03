<?php
/**
 * php-scoper configuration for the Phar build.
 *
 * WP-CLI executes inside the WordPress process and registers its autoloader
 * before WordPress boots, so for any class shipped both by the Phar and by the
 * site's own `vendor/`, the Phar's copy wins and is imposed on the site. That
 * is how a site running against psr/log v3 ends up loading the Phar's psr/log
 * v1 and fataling on the incompatible `LoggerInterface` signature.
 *
 * Composer's dependency tree (resolved in `prefixed-packages.php`) is
 * therefore rewritten under the `WP_CLI\Vendor` namespace into `third_party/`.
 * Anything reachable from WP-CLI's public API stays out of it and unprefixed:
 * the `wp-cli/*` packages, `wp-cli/php-cli-tools`, Mustache, Requests.
 *
 * Driven by `composer prefix-dependencies` (`utils/prefix-dependencies.php`);
 * do not run php-scoper by hand.
 *
 * @see https://github.com/wp-cli/wp-cli/issues/5920
 * @see https://github.com/humbug/php-scoper/blob/main/docs/configuration.md
 */

use Isolated\Symfony\Component\Finder\Finder;

require_once __DIR__ . '/prefixed-packages.php';

$vendor_dir = dirname( __DIR__, 2 ) . '/vendor';
$packages   = wp_cli_prefixed_packages( $vendor_dir );

if ( ! $packages ) {
	fwrite( STDERR, 'Error: ' . WP_CLI_PREFIXED_ROOT_PACKAGE . " is not installed in '{$vendor_dir}'; nothing to prefix." . PHP_EOL );
	exit( 1 );
}

/*
 * The polyfills declare global functions and classes behind `function_exists()`
 * and `class_exists()` guards. They only work under their original names and
 * cannot conflict with a site's copy, so they are copied verbatim.
 */
$verbatim_files = array_merge(
	(array) glob( $vendor_dir . '/symfony/polyfill-*/bootstrap*.php' ),
	(array) glob( $vendor_dir . '/symfony/polyfill-*/Resources/stubs/*.php' ),
	[ $vendor_dir . '/symfony/deprecation-contracts/function.php' ]
);

return [
	'prefix'                  => WP_CLI_VENDOR_PREFIX,

	'finders'                 => [
		Finder::create()
			->files()
			->ignoreVCS( true )
			->name( '*.php' )
			->exclude( [ 'test', 'tests', 'Test', 'Tests' ] )
			->in( array_column( $packages, 'path' ) ),

		// Non-PHP files Composer reads at runtime; copied unchanged.
		Finder::create()
			->append(
				[
					$vendor_dir . '/composer/composer/res/composer-schema.json',
					$vendor_dir . '/composer/composer/LICENSE',
				]
			),
	],

	// See WP_CLI_UNPREFIXED_NAMESPACES for why these keep their names.
	'exclude-namespaces'      => WP_CLI_UNPREFIXED_NAMESPACES,
	'exclude-files'           => array_filter( $verbatim_files, 'is_string' ),
	'exclude-functions'       => [ 'trigger_deprecation' ],
	'exclude-constants'       => [ '/^SYMFONY_[\p{L}_]+$/' ],

	// Nothing in the tree is meant to be reachable under a global name; the
	// polyfills above are the one exception and are handled explicitly.
	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,

	'patchers'                => [
		/*
		 * Excluding a namespace keeps php-scoper away from its declarations
		 * and references, but not reliably from string literals naming its
		 * classes: `ArrayLoader::load()` defaults its $class parameter to
		 * 'Composer\Package\CompletePackage' and that string does get
		 * prefixed, pointing at a class that does not exist because
		 * `Composer\` itself was left alone.
		 */
		static function ( $file_path, $prefix, $contents ) {
			foreach ( WP_CLI_UNPREFIXED_NAMESPACES as $namespace ) {
				$contents = str_replace(
					[
						$prefix . '\\' . $namespace . '\\',
						str_replace( '\\', '\\\\', $prefix . '\\' . $namespace . '\\' ),
					],
					[
						$namespace . '\\',
						str_replace( '\\', '\\\\', $namespace . '\\' ),
					],
					$contents
				);
			}

			return $contents;
		},
	],
];
