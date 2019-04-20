<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

final class Contrib_List_Command {

	/**
	 * Lists all contributors to this release.
	 *
	 * Run within the main WP-CLI project repository.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a specific format.
	 * ---
	 * default: markdown
	 * options:
	 *   - markdown
	 *   - html
	 * ---
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $_, $assoc_args ) {

		$contributors       = array();
		$pull_request_count = 0;

		// Get the contributors to the current open large project milestones
		foreach ( array( 'wp-cli/wp-cli-bundle', 'wp-cli/wp-cli', 'wp-cli/handbook', 'wp-cli/wp-cli.github.com' ) as $repo ) {
			$milestones = GitHub::get_project_milestones( $repo );
			// Cheap way to get the latest milestone
			$milestone = array_shift( $milestones );
			if ( ! $milestone ) {
				continue;
			}
			WP_CLI::log( 'Current open ' . $repo . ' milestone: ' . $milestone->title );
			$pull_requests     = GitHub::get_project_milestone_pull_requests( $repo, $milestone->number );
			$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
			WP_CLI::log( ' - Contributors: ' . count( $repo_contributors ) );
			WP_CLI::log( ' - Pull requests: ' . count( $pull_requests ) );
			$pull_request_count += count( $pull_requests );
			$contributors        = array_merge( $contributors, $repo_contributors );
		}

		// Identify all command dependencies and their contributors

		// TODO: Bundle repo needs to be switched to `wp-cli/wp-cli-bundle` for next release.
		$bundle = 'wp-cli/wp-cli';

		$milestones = GitHub::get_project_milestones( 'wp-cli/wp-cli', array( 'state' => 'closed' ) );
		// Cheap way to get the latest closed milestone
		$milestone = array_shift( $milestones );
		$tag       = is_object( $milestone ) ? "v{$milestone->title}" : 'master';

		// TODO: Only needed for switch from v1 to v2.
		if ( 'wp-cli/wp-cli' === $bundle ) {
			$tag = 'v1.5.1';
		}

		$composer_lock_url = sprintf( 'https://raw.githubusercontent.com/%s/%s/composer.lock', $bundle, $tag );
		WP_CLI::log( 'Fetching ' . $composer_lock_url );
		$response = Utils\http_request( 'GET', $composer_lock_url );
		if ( 200 !== $response->status_code ) {
			WP_CLI::error( sprintf( 'Could not fetch composer.json (HTTP code %d)', $response->status_code ) );
		}
		$composer_json = json_decode( $response->body, true );

		// TODO: Only need for initial v2.
		$composer_json['packages'][] = array(
			'name'    => 'wp-cli/i18n-command',
			'version' => 'v2',
		);
		usort(
			$composer_json['packages'],
			function ( $a, $b ) {
				return $a['name'] < $b['name'] ? -1 : 1;
			}
		);

		foreach ( $composer_json['packages'] as $package ) {
			$package_name       = $package['name'];
			$version_constraint = str_replace( 'v', '', $package['version'] );
			if ( ! preg_match( '#^wp-cli/.+-command$#', $package_name )
				&& ! in_array(
					$package_name,
					array(
						'wp-cli/wp-cli-tests',
						'wp-cli/regenerate-readme',
						'wp-cli/autoload-splitter',
						'wp-cli/wp-config-transformer',
						'wp-cli/php-cli-tools',
						'wp-cli/spyc',
					),
					true
				) ) {
				continue;
			}
			// Closed milestones denote a tagged release
			$milestones       = GitHub::get_project_milestones( $package_name, array( 'state' => 'closed' ) );
			$milestone_ids    = array();
			$milestone_titles = array();
			foreach ( $milestones as $milestone ) {
				if ( ! version_compare( $milestone->title, $version_constraint, '>' ) ) {
					continue;
				}
				$milestone_ids[]    = $milestone->number;
				$milestone_titles[] = $milestone->title;
			}
			// No shipped releases for this milestone.
			if ( empty( $milestone_ids ) ) {
				continue;
			}
			WP_CLI::log( 'Closed ' . $package_name . ' milestone(s): ' . implode( ', ', $milestone_titles ) );
			foreach ( $milestone_ids as $milestone_id ) {
				$pull_requests     = GitHub::get_project_milestone_pull_requests( $package_name, $milestone_id );
				$repo_contributors = GitHub::parse_contributors_from_pull_requests( $pull_requests );
				WP_CLI::log( ' - Contributors: ' . count( $repo_contributors ) );
				WP_CLI::log( ' - Pull requests: ' . count( $pull_requests ) );
				$pull_request_count += count( $pull_requests );
				$contributors        = array_merge( $contributors, $repo_contributors );
			}
		}

		WP_CLI::log( 'Total contributors: ' . count( $contributors ) );
		WP_CLI::log( 'Total pull requests: ' . $pull_request_count );

		// Sort and render the contributor list
		asort( $contributors, SORT_NATURAL | SORT_FLAG_CASE );
		if ( in_array( $assoc_args['format'], array( 'markdown', 'html' ) ) ) {
			$contrib_list = '';
			foreach ( $contributors as $url => $login ) {
				if ( 'markdown' === $assoc_args['format'] ) {
					$contrib_list .= '[@' . $login . '](' . $url . '), ';
				} elseif ( 'html' === $assoc_args['format'] ) {
					$contrib_list .= '<a href="' . $url . '">@' . $login . '</a>, ';
				}
			}
			$contrib_list = rtrim( $contrib_list, ', ' );
			WP_CLI::log( $contrib_list );
		}
	}
}
