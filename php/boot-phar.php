<?php

// This file needs to parse without error in PHP 5.x.

if ( 'cli' !== PHP_SAPI ) {
	echo "WP-CLI only works correctly from the command line, using the 'cli' PHP SAPI.\n",
		"You're currently executing the WP-CLI binary via the '" . PHP_SAPI . "' PHP SAPI.\n",
		"In case you were trying to run this file with a web browser, know that this cannot technically work.\n",
		"When running the WP-CLI binary on the command line, you can ensure you're using the right PHP SAPI",
		"by checking that `php -v` has the word 'cli' in the first line of output.\n";
	die( -1 );
}

if ( version_compare( PHP_VERSION, '7.2.24', '<' ) ) {
	fwrite(
		STDERR,
		sprintf( "Error: WP-CLI requires PHP %s or newer. You are running version %s.\n", '7.2.24', PHP_VERSION )
	);
	die( -1 );
}

// Store the path to the Phar early on for `Utils\phar-safe-path()` function.
define( 'WP_CLI_PHAR_PATH', Phar::running( true ) );

// The bundled third-party dependencies are namespace-prefixed and ship with
// their own autoloader. See utils/prefix-dependencies.php.
$wp_cli_third_party_autoload = dirname( __DIR__ ) . '/third_party/vendor/autoload.php';
if ( file_exists( $wp_cli_third_party_autoload ) ) {
	require $wp_cli_third_party_autoload;
}
unset( $wp_cli_third_party_autoload );

if ( file_exists( 'phar://wp-cli.phar/php/wp-cli.php' ) ) {
	define( 'WP_CLI_ROOT', 'phar://wp-cli.phar' );
	include WP_CLI_ROOT . '/php/wp-cli.php';
} elseif ( file_exists( 'phar://wp-cli.phar/vendor/wp-cli/wp-cli/php/wp-cli.php' ) ) {
	define( 'WP_CLI_ROOT', 'phar://wp-cli.phar/vendor/wp-cli/wp-cli' );
	include WP_CLI_ROOT . '/php/wp-cli.php';
} else {
	echo "Couldn't find 'php/wp-cli.php'. Was this Phar built correctly?";
	exit( 1 );
}
