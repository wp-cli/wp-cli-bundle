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

    When I run `php {PHAR_PATH} plugin get generic-example-plugin --fields=title,status,version,author,description --format=csv`
    Then STDOUT should contain:
      """
      title,"Example Plugin"
      status,inactive
      version,0.1.0
      author,"YOUR NAME HERE"
      description,"PLUGIN DESCRIPTION HERE"
      """
    And STDERR should be empty

    When I run `cp {PHAR_PATH} wp`
    And I try `php wp plugin get generic-example-plugin`
    Then STDERR should not contain:
      """
      Error: Couldn't find plugin-status.mustache
      """
    And the return code should be 0

  # Prefixing runs on `composer install` and needs PHP 8.2+, so the tree only
  # exists on such a checkout. See utils/prefix-dependencies.php.
  @require-php-8.2
  Scenario: Third-party dependencies are bundled under the WP_CLI\Vendor prefix
    Given an empty directory
    And a new Phar with the same version

    When I run `php {PHAR_PATH} eval --skip-wordpress 'echo json_encode( [ interface_exists( "Psr\\Log\\LoggerInterface" ), class_exists( "Symfony\\Component\\Console\\Application" ), interface_exists( "WP_CLI\\Vendor\\Psr\\Log\\LoggerInterface" ), class_exists( "WP_CLI\\Vendor\\Symfony\\Component\\Console\\Application" ), class_exists( "Composer\\Semver\\Comparator" ) ] );'`
    Then STDOUT should be:
      """
      [false,false,true,true,true]
      """
    And STDERR should be empty
