<?php namespace WP_CLI\Maintenance;

use WP_CLI;
use WP_CLI\Utils;

class GitHub {

	const API_ROOT = 'https://api.github.com/';

	/**
	 * Gets the milestones for a given project.
	 *
	 * @param string $project
	 *
	 * @return array
	 */
	public static function get_project_milestones(
		$project,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/milestones',
			$project
		);

		$args['per_page'] = 100;

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Gets a release for a given project by its tag name.
	 *
	 * @param string $project
	 * @param string $tag
	 * @param array $args
	 *
	 * @return array|false
	 */
	public static function get_release_by_tag(
		$project,
		$tag,
		$args = array()
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/releases/tags/%s',
			$project,
			$tag
		);

		$args['per_page'] = 100;

		list( $body, $headers ) = self::request( $request_url, $args );

		return $body;
	}

	/**
	 * Gets the pull requests assigned to a milestone of a given project.
	 *
	 * @param string  $project
	 * @param integer $milestone_id
	 *
	 * @return array
	 */
	public static function get_project_milestone_pull_requests(
		$project,
		$milestone_id
	) {
		$request_url = sprintf(
			self::API_ROOT . 'repos/%s/issues',
			$project
		);

		$args = array(
			'per_page'  => 100,
			'milestone' => $milestone_id,
			'state'     => 'all',
		);

		$pull_requests = array();
		do {
			list( $body, $headers ) = self::request( $request_url, $args );
			foreach ( $body as $issue ) {
				if ( ! empty( $issue->pull_request ) ) {
					$pull_requests[] = $issue;
				}
			}
			$args        = array();
			$request_url = false;
			// Set $request_url to 'rel="next" if present'
			if ( ! empty( $headers['Link'] ) ) {
				$bits = explode( ',', $headers['Link'] );
				foreach ( $bits as $bit ) {
					if ( false !== stripos( $bit, 'rel="next"' ) ) {
						$hrefandrel  = explode( '; ', $bit );
						$request_url = trim( trim( $hrefandrel[0] ), '<>' );
						break;
					}
				}
			}
		} while ( $request_url );

		return $pull_requests;
	}

	/**
	 * Parses the contributors from pull request objects.
	 *
	 * @param array $pull_requests
	 *
	 * @return array
	 */
	public static function parse_contributors_from_pull_requests(
		$pull_requests
	) {
		$contributors = array();
		foreach ( $pull_requests as $pull_request ) {
			if ( ! empty( $pull_request->user ) ) {
				$contributors[ $pull_request->user->html_url ] = $pull_request->user->login;
			}
		}

		return $contributors;
	}

	/**
	 * Makes a request to the GitHub API.
	 *
	 * @param string $url
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public static function request( $url, $args = array() ) {
		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WP-CLI',
		);
		if ( $token = getenv( 'GITHUB_TOKEN' ) ) {
			$headers['Authorization'] = 'token ' . $token;
		}
		$response = Utils\http_request( 'GET', $url, $args, $headers );
		if ( 200 !== $response->status_code ) {
			if ( isset( $args['throw_errors'] ) && false === $args['throw_errors'] ) {
				return false;
			}

			WP_CLI::error(
				sprintf(
					"Failed request to $url\nGitHub API returned: %s (HTTP code %d)",
					$response->body,
					$response->status_code
				)
			);
		}

		return array( json_decode( $response->body ), $response->headers );
	}
}
