Feature: Requests integration with both v1 and v2

  @require-php-7.0
  Scenario: Composer stack with Requests v1
    Given an empty directory
    And a composer.json file:
      """
      {
          "name": "wp-cli/composer-test",
          "type": "project",
          "require": {
              "wp-cli/wp-cli": "2.7.0",
              "wp-cli/core-command": "^2",
              "wp-cli/eval-command": "^2"
          }
      }
      """
    # Note: Composer outputs messages to stderr.
    And I run `composer install --no-interaction 2>&1`

    When I run `vendor/bin/wp cli version`
    Then STDOUT should contain:
      """
      WP-CLI 2.7.0
      """

    Given a WP installation
    And I run `vendor/bin/wp core update --version=5.8 --force`

    When I run `vendor/bin/wp core version`
    Then STDOUT should contain:
      """
      5.8
      """

    When I run `vendor/bin/wp eval 'var_dump( \WP_CLI\Utils\http_request( "GET", "https://example.com/" ) );'`
    Then STDOUT should contain:
      """
      object(Requests_Response)
      """
    And STDOUT should contain:
      """
      HTTP/1.1 200 OK
      """
    And STDERR should be empty

  @require-php-7.0
  Scenario: Current version with WordPress-bundled Requests v1
    Given a WP installation
    And I run `wp core update --version=5.8 --force`

    When I run `wp core version`
    Then STDOUT should contain:
      """
      5.8
      """

    When I run `wp eval 'var_dump( \WP_CLI\Utils\http_request( "GET", "https://example.com/" ) );'`
    Then STDOUT should contain:
      """
      object(Requests_Response)
      """
    And STDOUT should contain:
      """
      HTTP/1.1 200 OK
      """
    And STDERR should be empty

    When I run `wp plugin install duplicate-post`
    Then STDOUT should contain:
      """
      Success: Installed 1 of 1 plugins.
      """

  @require-php-7.0
    Scenario: Current version with WordPress-bundled Requests v2
    Given a WP installation
    And I run `wp core update --version=6.2 --force`

    When I run `wp core version`
    Then STDOUT should contain:
      """
      6.2
      """

    When I run `wp eval 'var_dump( \WP_CLI\Utils\http_request( "GET", "https://example.com/" ) );'`
    Then STDOUT should contain:
      """
      object(WpOrg\Requests\Response)
      """
    And STDOUT should contain:
      """
      HTTP/1.1 200 OK
      """
    And STDERR should be empty

    When I run `wp plugin install duplicate-post`
    Then STDOUT should contain:
      """
      Success: Installed 1 of 1 plugins.
      """

  Scenario: Composer stack with Requests v1 pulling wp-cli/wp-cli-bundle
    Given an empty directory
    And a composer.json file:
      """
      {
        "name": "example/wordpress",
        "type": "project",
        "extra": {
          "wordpress-install-dir": "wp",
          "installer-paths": {
            "content/plugins/{$name}/": [
              "type:wordpress-plugin"
            ],
            "content/themes/{$name}/": [
              "type:wordpress-theme"
            ]
          }
        },
        "repositories": [
          {
            "type": "composer",
            "url": "https://wpackagist.org"
          }
        ],
        "require": {
          "johnpbloch/wordpress": "6.1"
        },
        "require-dev": {
          "wp-cli/wp-cli-bundle": "dev-main as 2.8.1"
        },
        "minimum-stability": "dev",
        "config": {
          "allow-plugins": {
            "johnpbloch/wordpress-core-installer": true
          }
        }
      }
      """
    # Note: Composer outputs messages to stderr.
    And I run `composer install --no-interaction 2>&1`
    And a wp-cli.yml file:
      """
      path: wp
      """
    And an extra-config.php file:
      """
      require __DIR__ . "/../vendor/autoload.php";
      """
    And the {RUN_DIR}/vendor/wp-cli/wp-cli/bundle/rmccue/requests directory should exist
    And the {RUN_DIR}/vendor/rmccue/requests directory should not exist

    When I run `vendor/bin/wp config create --dbname={DB_NAME} --dbuser={DB_USER} --dbpass={DB_PASSWORD} --dbhost={DB_HOST} --extra-php < extra-config.php`
    Then STDOUT should be:
      """
      Success: Generated 'wp-config.php' file.
      """

    When I run `vendor/bin/wp config set WP_DEBUG true --raw`
    Then STDOUT should be:
      """
      Success: Updated the constant 'WP_DEBUG' in the 'wp-config.php' file with the raw value 'true'.
      """

    When I run `vendor/bin/wp config set WP_DEBUG_DISPLAY true --raw`
    Then STDOUT should be:
      """
      Success: Added the constant 'WP_DEBUG_DISPLAY' to the 'wp-config.php' file with the raw value 'true'.
      """

    When I run `vendor/bin/wp db create`
    Then STDOUT should be:
      """
      Success: Database created.
      """

    # This can throw deprecated warnings on PHP 8.1+.
    When I try `vendor/bin/wp core install --url=localhost:8181 --title=Composer --admin_user=admin --admin_password=password --admin_email=admin@example.com`
    Then STDOUT should contain:
      """
      Success: WordPress installed successfully.
      """

    When I run `vendor/bin/wp core version`
    Then STDOUT should contain:
      """
      6.1
      """

    # This can throw deprecated warnings on PHP 8.1+.
    When I try `vendor/bin/wp eval 'var_dump( \WP_CLI\Utils\http_request( "GET", "https://example.com/" ) );'`
    Then STDOUT should contain:
      """
      object(Requests_Response)
      """
    And STDOUT should contain:
      """
      HTTP/1.1 200 OK
      """

    # This can throw deprecated warnings on PHP 8.1+.
    When I try `vendor/bin/wp plugin install duplicate-post --activate`
    Then STDOUT should contain:
      """
      Success: Installed 1 of 1 plugins.
      """

    And I launch in the background `wp server --host=localhost --port=8181`
    And I run `wp option set blogdescription 'Just another Composer-based WordPress site'`

    When I run `curl -sS localhost:8181`
    Then STDOUT should contain:
      """
      Just another Composer-based WordPress site
      """

    When I run `vendor/bin/wp eval 'echo COOKIEHASH;'`
    And save STDOUT as {COOKIEHASH}
    Then STDOUT should not be empty

    When I run `vendor/bin/wp eval 'echo wp_generate_auth_cookie( 1, 32503680000 );'`
    And save STDOUT as {AUTH_COOKIE}
    Then STDOUT should not be empty

    When I run `curl -b 'wordpress_{COOKIEHASH}={AUTH_COOKIE}' -sS localhost:8181/wp-admin/plugins.php`
    Then STDOUT should contain:
      """
      Plugins</h1>
      """
    And STDOUT should contain:
      """
      plugin=duplicate-post
      """
