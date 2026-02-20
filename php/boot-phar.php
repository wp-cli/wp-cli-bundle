<?php

if ( 'cli' !== PHP_SAPI ) {
	echo "WP-CLI only works correctly from the command line, using the 'cli' PHP SAPI.\n",
		"You're currently executing the WP-CLI binary via the '" . PHP_SAPI . "' PHP SAPI.\n",
		"In case you were trying to run this file with a web browser, know that this cannot technically work.\n",
		"When running the WP-CLI binary on the command line, you can ensure you're using the right PHP SAPI",
		"by checking that `php -v` has the word 'cli' in the first line of output.\n";
	die( -1 );
}

// Store the filesystem path for `Utils\phar_safe_path()` function.
// Using Phar::running(false) returns just the filesystem path without phar:// protocol.
define( 'WP_CLI_PHAR_PATH', Phar::running( false ) );

// Use the phar alias 'wp-cli.phar' which is set during phar creation and works
// regardless of the actual filename. This ensures template paths resolve correctly.
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
