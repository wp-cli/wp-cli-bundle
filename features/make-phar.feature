Feature: Check `utils/make-phar.php` output

  @broken
  # TODO: Composer v2 has added a new install-path which references dealerdirect:
  # 'install_path' => __DIR__ . '/../dealerdirect/phpcodesniffer-composer-installer',
  Scenario: Check autoload stripping of phpcs development classes
    Given an empty directory
    And a new Phar with the same version
    And a custom-cmd.php file:
      """
      <?php

      WP_CLI::add_command( 'command example', 'Dealerdirect\Composer\Plugin\Installers\PHPCodeSniffer\Plugin' );
      """

    When I try `php -derror_log='' {PHAR_PATH} --require=custom-cmd.php help`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Callable
      """
    And STDERR should not contain:
      """
      PHP Warning
      """
    And STDOUT should be empty

    When I try `grep -a '/dealerdirect\|[^/]/squizlabs(?!/PHP_CodeSniffer/wiki)\|/wimg' {PHAR_PATH}`
    Then the return code should be 1
    And STDOUT should be empty
    And STDERR should be empty

  Scenario: Phar renaming affects template path resolution
    Given an empty directory
    And a new Phar with the same version
    And a WP installation
    And I run `wp plugin install https://github.com/wp-cli-test/generic-example-plugin/releases/download/v0.1.1/generic-example-plugin.0.1.1.zip --activate`
    And I run `wp plugin deactivate generic-example-plugin`

    When I run `php {PHAR_PATH} plugin status generic-example-plugin`
    Then STDOUT should contain:
      """
      Plugin generic-example-plugin details:
          Name: Example Plugin
          Status: Inactive
          Version: 0.1.0
          Author: YOUR NAME HERE
          Description: PLUGIN DESCRIPTION HERE
      """
    And STDERR should be empty

    When I run `cp {PHAR_PATH} wp`
    And I try `php wp plugin status generic-example-plugin`
    Then STDERR should not contain:
      """
      Error: Couldn't find plugin-status.mustache
      """
    And the return code should be 0
