Feature: Bootstrap WP-CLI

  Scenario: Override command bundled with freshly built PHAR

    Given an empty directory
    And a new Phar with the same version
    And a cli-override-command/cli.php file:
      """
      <?php
      if ( ! class_exists( 'WP_CLI' ) ) {
        return;
      }
      $autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
      if ( file_exists( $autoload ) ) {
        require_once $autoload;
      }
      WP_CLI::add_command( 'cli', 'CLI_Command', array( 'when' => 'before_wp_load' ) );
      """
    And a cli-override-command/src/CLI_Command.php file:
      """
      <?php
      class CLI_Command extends WP_CLI_Command {
        public function version() {
          WP_CLI::success( "WP-Override-CLI" );
        }
      }
      """
    And a cli-override-command/composer.json file:
      """
      {
        "name": "wp-cli/cli-override",
        "description": "A command that overrides the bundled 'cli' command.",
        "autoload": {
          "psr-4": { "": "src/" },
          "files": [ "cli.php" ]
        },
        "extra": {
          "commands": [
            "cli"
          ]
        }
      }
      """
    And I run `composer install --working-dir={RUN_DIR}/cli-override-command --no-interaction 2>&1`

    When I run `{PHAR_PATH} cli version`
    Then STDOUT should contain:
      """
      WP-CLI
      """

    When I run `{PHAR_PATH} --require=cli-override-command/cli.php cli version`
    Then STDOUT should contain:
      """
      WP-Override-CLI
      """

  Scenario: Template paths should be resolved correctly when PHAR is renamed

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
      """
    And STDERR should be empty

    When I run `cp {PHAR_PATH} wp-renamed.phar`
    And I try `php wp-renamed.phar plugin status generic-example-plugin`
    Then STDOUT should contain:
      """
      Plugin generic-example-plugin details:
          Name: Example Plugin
          Status: Inactive
      """
    And STDERR should be empty
