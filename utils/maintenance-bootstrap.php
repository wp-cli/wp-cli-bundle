<?php

$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $autoloader;

WP_CLI::add_command( 'contrib-list', 'WP_CLI\Maintenance\Contrib_list_Command' );
WP_CLI::add_command( 'milestones-after', 'WP_CLI\Maintenance\Milestones_After_Command' );
WP_CLI::add_command( 'milestones-since', 'WP_CLI\Maintenance\Milestones_Since_Command' );
WP_CLI::add_command( 'release-date', 'WP_CLI\Maintenance\Release_Date_Command' );
WP_CLI::add_command( 'release-notes', 'WP_CLI\Maintenance\Release_Notes_Command' );
