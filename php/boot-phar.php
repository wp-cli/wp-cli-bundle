<?php

if ( 'cli' !== PHP_SAPI ) {
	echo "WP-CLI only works correctly from the command line, using the 'cli' PHP SAPI.\n",
		"You're currently executing the WP-CLI binary via the '" . PHP_SAPI . "' PHP SAPI.\n",
		"In case you were trying to run this file with a web browser, know that this cannot technically work.\n",
		"When running the WP-CLI binary on the command line, you can ensure you're using the right PHP SAPI",
		"by checking that `php -v` has the word 'cli' in the first line of output.\n";
	die( -1 );
}

// Store the path to the Phar early on for `Utils\phar-safe-path()` function.
define( 'WP_CLI_PHAR_PATH', Phar::running( true ) );

// Determine WP_CLI_ROOT dynamically based on the actual phar path
// instead of hardcoding 'phar://wp-cli.phar' to handle renamed phars.
// Use Phar::running(false) to get the phar stream path (e.g., phar:///path/to/file.phar)
// instead of the filesystem path, ensuring consistency when the phar is renamed.
if ( file_exists( Phar::running( false ) . '/php/wp-cli.php' ) ) {
	define( 'WP_CLI_ROOT', Phar::running( false ) );
	include WP_CLI_ROOT . '/php/wp-cli.php';
} elseif ( file_exists( Phar::running( false ) . '/vendor/wp-cli/wp-cli/php/wp-cli.php' ) ) {
	define( 'WP_CLI_ROOT', Phar::running( false ) . '/vendor/wp-cli/wp-cli' );
	include WP_CLI_ROOT . '/php/wp-cli.php';
} else {
	echo "Couldn't find 'php/wp-cli.php'. Was this Phar built correctly?";
	exit( 1 );
}
