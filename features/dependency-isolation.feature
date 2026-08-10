Feature: Bundled dependencies do not conflict with the site's own

  # WP-CLI's autoloader is registered before WordPress boots, so for any class
  # shipped both by the Phar and by the site, the Phar's copy wins and is
  # imposed on the site. Prefixing the `composer/composer` dependency tree stops
  # the Phar from claiming those names at all.
  #
  # These scenarios run against the built Phar, which is the only artifact the
  # prefixing applies to; a Composer-based installation resolves its own
  # dependency versions and has no conflict to avoid.
  #
  # See https://github.com/wp-cli/wp-cli/issues/5920

  Scenario: A site providing its own psr/log is not broken by the bundled one
    Given a WP installation
    # Stands in for a site that ships psr/log v3 through its own vendor
    # directory, as anything depending on monolog/monolog does. The typed
    # signatures are incompatible with the psr/log v1 that composer/composer
    # resolves to under the Phar's PHP 7.2 platform requirement, so whichever
    # copy of the interface loads first decides whether this fatals.
    And a wp-content/mu-plugins/site-logger.php file:
      """
      <?php

      spl_autoload_register(
      	function ( $class ) {
      		if ( 'Psr\\Log\\LoggerInterface' !== $class ) {
      			return;
      		}

      		eval(
      			'namespace Psr\Log;
      			interface LoggerInterface {
      				public function emergency( \Stringable|string $message, array $context = [] ): void;
      			}'
      		);
      	}
      );

      final class Site_Logger implements \Psr\Log\LoggerInterface {
      	public function emergency( \Stringable|string $message, array $context = [] ): void {
      	}
      }
      """

    When I try `wp option get siteurl`
    Then STDERR should not contain:
      """
      must be compatible with
      """
    And STDERR should not contain:
      """
      critical error
      """
    And the return code should be 0

  Scenario: A site providing its own Symfony Console is not broken by the bundled one
    Given a WP installation
    And a wp-content/mu-plugins/site-console.php file:
      """
      <?php

      spl_autoload_register(
      	function ( $class ) {
      		if ( 'Symfony\\Component\\Console\\Output\\OutputInterface' !== $class ) {
      			return;
      		}

      		eval(
      			'namespace Symfony\Component\Console\Output;
      			interface OutputInterface {
      				public function writeln( \Stringable|string $messages, int $options = 0 ): void;
      			}'
      		);
      	}
      );

      final class Site_Output implements \Symfony\Component\Console\Output\OutputInterface {
      	public function writeln( \Stringable|string $messages, int $options = 0 ): void {
      	}
      }
      """

    When I try `wp option get siteurl`
    Then STDERR should not contain:
      """
      must be compatible with
      """
    And the return code should be 0

  Scenario: Package management still works against the prefixed tree
    # Exercises the paths where Composer resolves classes dynamically from
    # strings, which prefixing of static `use` statements does not cover.
    Given an empty directory

    When I run `wp package list --format=count`
    Then STDERR should be empty
    And the return code should be 0
