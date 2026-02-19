<?php
/**
 * Override for Utils\phar_safe_path() to fix template path resolution
 * when phar is renamed.
 *
 * This fix will be removed once wp-cli/wp-cli#6242 is merged and integrated.
 */

namespace WP_CLI\Utils;

/**
 * Get a Phar-safe version of a path.
 *
 * For paths inside a Phar, this strips the outer filesystem's location to
 * reduce the path to what it needs to be within the Phar archive.
 *
 * Use the __FILE__ or __DIR__ constants as a starting point.
 *
 * @param string $path An absolute path that might be within a Phar.
 * @return string A Phar-safe version of the path.
 */
function phar_safe_path( $path ) {

	if ( ! inside_phar() ) {
		return $path;
	}

	return str_replace(
		PHAR_STREAM_PREFIX . rtrim( WP_CLI_PHAR_PATH, '/' ) . '/',
		'phar://wp-cli.phar/',
		$path
	);
}
