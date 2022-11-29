<?php

if ( 'cli' !== PHP_SAPI ) {
	echo "Only PHP-CLI access.\n";
	echo "You're currently using the " . PHP_SAPI . " PHP SAPI.\n";
	echo "If you're trying to run this file with a web browser, don't.\n";
	echo "When running in command line, ensure that `php -v` has the\n";
	echo "word \"cli\" in the first line of output.\n";
	die( -1 );
}

// Store the path to the Phar early on for `Utils\phar-safe-path()` function.
define( 'WP_CLI_PHAR_PATH', getcwd() );

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
