<?php
/**
 * php-scoper configuration for the WP-CLI Phar build.
 *
 * WP-CLI executes inside the WordPress process, so every unprefixed class the
 * Phar ships is imposed on the site: WP-CLI's autoloader is registered before
 * WordPress boots, so for any class present both in the Phar and in the site's
 * `vendor/`, the Phar's copy wins. That is how a site running `monolog/monolog`
 * against `psr/log` v3 ends up loading the Phar's `psr/log` 1.1.4 and fataling
 * on the incompatible `LoggerInterface` signature.
 *
 * Only the `composer/composer` dependency tree is prefixed here. Two rules
 * shape the configuration:
 *
 * 1. The `Composer\` namespace itself stays UNPREFIXED. Third-party Composer
 *    plugins (`johnpbloch/wordpress-core-installer` and friends) are compiled
 *    against the real `Composer\Plugin\PluginInterface`; prefixing it would
 *    break `wp package install` for any package shipping a Composer plugin.
 *    References from inside `Composer\` to the prefixed vendors are still
 *    rewritten, so Composer keeps using its own `psr/log` v1.
 *
 * 2. Anything reachable from WP-CLI's public API stays unprefixed, and is
 *    simply never handed to the finders below: `wp-cli/php-cli-tools`
 *    (`Utils\make_progress_bar()` returns `cli\progress\Bar`), Requests
 *    (`Utils\http_request()` returns `WpOrg\Requests\Response`, and
 *    `RequestsLibrary` deliberately shares the library with WordPress Core),
 *    and every `wp-cli/*` package.
 *
 * @see https://github.com/wp-cli/wp-cli/issues/5920
 */

declare( strict_types=1 );

$finder_class = class_exists( \Isolated\Symfony\Component\Finder\Finder::class )
	? \Isolated\Symfony\Component\Finder\Finder::class
	: \Symfony\Component\Finder\Finder::class;

$vendor_dir = getenv( 'WP_CLI_SCOPER_VENDOR_DIR' );

if ( ! $vendor_dir || ! is_dir( $vendor_dir ) ) {
	fwrite( STDERR, "Error: WP_CLI_SCOPER_VENDOR_DIR is not set to an existing directory.\n" );
	exit( 1 );
}

/**
 * Vendor directories making up the `composer/composer` dependency tree.
 *
 * `vendor/composer` holds both Composer's own autoloader machinery and the
 * `composer/*` packages; the former declares classes under `Composer\` and is
 * therefore left unprefixed by the exclusion below.
 */
$scoped_paths = array_values(
	array_filter(
		array_map(
			static function ( $relative ) use ( $vendor_dir ) {
				$path = $vendor_dir . '/' . $relative;
				return is_dir( $path ) ? $path : null;
			},
			[
				'composer',
				'justinrainbow',
				'marc-mabe', // spellchecker:disable-line
				'psr',
				'react',
				'seld',
				'symfony',
			]
		)
	)
);

return [
	'prefix'             => 'WP_CLI\\Vendor',
	'finders'            => [
		/*
		 * Deliberately without exclusions. The prefixed output is merged back
		 * over `vendor/` rather than replacing it, because php-scoper only
		 * emits the PHP files it processed and the directories also hold
		 * assets the Phar needs (certificate bundles, templates, stubs).
		 * Any PHP file skipped here would therefore survive the merge with its
		 * original namespace intact and be picked up by the regenerated
		 * classmap -- which is exactly the unprefixed name the Phar is not
		 * supposed to advertise any more. Test fixtures are the usual culprit:
		 * `Psr\Log\Test\TestLogger` implements the very interface at issue.
		 */
		$finder_class::create()
			->files()
			->ignoreVCS( true )
			->name( '*.php' )
			->in( $scoped_paths ),
	],

	/*
	 * Left unprefixed so third-party Composer plugins keep implementing the
	 * real interfaces. References from these files to the prefixed vendors are
	 * still rewritten by php-scoper.
	 */
	'exclude-namespaces' => [
		'Composer',
	],

	'exclude-classes'    => [],
	'exclude-functions'  => [],
	'exclude-constants'  => [],

	'patchers'           => [
		/*
		 * Excluding a namespace stops php-scoper prefixing its declarations,
		 * but not string literals that name classes inside it. Composer passes
		 * plenty of class names around as strings -- `ArrayLoader::load()`
		 * defaults `$class` to 'Composer\Package\CompletePackage' and compares
		 * against it -- and prefixing those strings points them at classes
		 * that do not exist, because `Composer\` itself was left alone.
		 *
		 * Left unpatched this is quiet rather than fatal: Composer emits a
		 * spurious "The $class arg is deprecated" notice and carries on, while
		 * the same mismatch in a `new $class` path would be a hard failure.
		 */
		static function ( string $file_path, string $prefix, string $contents ): string {
			return str_replace(
				[
					$prefix . '\\Composer\\',
					$prefix . '\\\\Composer\\\\',
				],
				[
					'Composer\\',
					'Composer\\\\',
				],
				$contents
			);
		},
	],
];
