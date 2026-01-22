Feature: `wp cli` tasks

  Scenario: Ability to set a custom version when building
    Given an empty directory
    And save the {FRAMEWORK_ROOT}/VERSION file as {TRUE_VERSION}
    And a new Phar with version "1.2.3"

    When I run `{PHAR_PATH} cli version`
    Then STDOUT should be:
      """
      WP-CLI 1.2.3
      """
    And the {FRAMEWORK_ROOT}/VERSION file should be:
      """
      {TRUE_VERSION}
      """

  @github-api
  Scenario: Check for updates
    Given an empty directory
    And a new Phar with version "0.0.0"

    When I run `{PHAR_PATH} cli check-update`
    Then STDOUT should contain:
      """
      package_url
      """
    And STDERR should be empty

  @github-api
  Scenario: Do WP-CLI Update
    Given an empty directory
    And a new Phar with version "0.0.0"

    When I run `{PHAR_PATH} --info`
    Then STDOUT should contain:
      """
      WP-CLI version
      """
    And STDOUT should contain:
      """
      0.0.0
      """

    When I run `{PHAR_PATH} cli update --yes`
    Then STDOUT should contain:
      """
      sha512 hash verified:
      """
    And STDOUT should contain:
      """
      Success:
      """
    And STDERR should be empty
    And the return code should be 0

    When I run `{PHAR_PATH} --info`
    Then STDOUT should contain:
      """
      WP-CLI version
      """
    And STDOUT should not contain:
      """
      0.0.0
      """

    When I run `{PHAR_PATH} cli update`
    Then STDOUT should be:
      """
      Success: WP-CLI is at the latest version.
      """

  @github-api
  Scenario: Patch update from 2.8.0 to 2.8.1
    Given an empty directory
    And a new Phar with version "2.8.0"

    When I run `{PHAR_PATH} --version`
    Then STDOUT should be:
      """
      WP-CLI 2.8.0
      """

    When I run `{PHAR_PATH} cli update --patch --yes`
    Then STDOUT should contain:
      """
      sha512 hash verified: c1d40ee90b330ca1f8ddbed14b938b41ec5d9ff723c7c1cf3f41a2d9a1b271079a51a37ea3d1c9aa9c628fdd43449dba3995a8de150a68abbd505b06b91d9d2b
      """
    And STDOUT should contain:
      """
      Success: Updated WP-CLI to 2.8.1
      """
    And STDERR should be empty
    And the return code should be 0

    When I run `{PHAR_PATH} --version`
    Then STDOUT should be:
      """
      WP-CLI 2.8.1
      """

  @github-api
  Scenario: Not a patch update from 2.8.0
    Given an empty directory
    And a new Phar with version "2.8.0"

    When I run `{PHAR_PATH} cli update --no-patch --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And STDOUT should not contain:
      """
      2.8.1
      """
    And STDERR should be empty
    And the return code should be 0

  Scenario: Install WP-CLI nightly
    Given an empty directory
    And a new Phar with version "2.8.0"

    When I run `{PHAR_PATH} cli update --nightly --yes`
    Then STDOUT should contain:
      """
      sha512 hash verified:
      """
    And STDOUT should contain:
      """
      Success: Updated WP-CLI to the latest nightly release.
      """

    And STDERR should be empty
    And the return code should be 0

  @github-api
  Scenario: Install WP-CLI stable
    Given an empty directory
    And a new Phar with version "2.8.0"
    And a session file:
      """
      y
      """

    When I run `{PHAR_PATH} cli check-update --field=version | head -1`
    Then STDOUT should not be empty
    And save STDOUT as {UPDATE_VERSION}

    When I run `{PHAR_PATH} cli update --stable < session`
    Then STDOUT should contain:
      """
      You are currently using WP-CLI version 2.8.0. Would you like to update to the latest stable release? [y/n]
      """
    And STDOUT should contain:
      """
      sha512 hash verified:
      """
    And STDOUT should contain:
      """
      Success: Updated WP-CLI to the latest stable release.
      """
    And STDERR should be empty
    And the return code should be 0

    When I run `{PHAR_PATH} cli check-update`
    Then STDOUT should be:
      """
      Success: WP-CLI is at the latest version.
      """

    When I run `{PHAR_PATH} cli version`
    Then STDOUT should be:
      """
      WP-CLI {UPDATE_VERSION}
      """

  @github-api
  Scenario: Update command works with PHP binary path containing spaces
    Given an empty directory
    And a new Phar with version "0.0.0"

    # Create a directory with spaces and a PHP wrapper
    When I run `mkdir -p "php with spaces/bin"`
    And I run `printf '#!/bin/bash\nexec php "$@"' > "php with spaces/bin/php"`
    And I run `chmod +x "php with spaces/bin/php"`
    Then the return code should be 0

    # Test that the update command works when PHP_BINARY has spaces
    When I run `PHP_BINARY="$PWD/php with spaces/bin/php" "$PWD/php with spaces/bin/php" {PHAR_PATH} cli update --yes`
    Then STDOUT should contain:
      """
      sha512 hash verified:
      """
    And STDOUT should contain:
      """
      Success:
      """
    And STDERR should be empty
    And the return code should be 0
