wp-cli/wp-cli-bundle
=================

Combines the most common commands into the standard, installable version of WP-CLI.

Generally, bundled commands either relate directly to a WordPress API or offer some common developer convenience. New commands are included in the WP-CLI bundle when the [project governance](https://make.wordpress.org/cli/handbook/contributions/governance/) decides they should be. There isn't much of a formal process to it, so feel free to ask if you ever have a question.

The handbook documents the [various ways you can install the bundle](https://make.wordpress.org/cli/handbook/guides/installing/). The Phar is [built on every merge](https://github.com/wp-cli/wp-cli-bundle/blob/main/.github/workflows/deployment.yml) and pushed to [wp-cli/builds](https://github.com/wp-cli/builds) repository. A stable version is [tagged a few times each year](https://make.wordpress.org/cli/handbook/contributions/release-checklist/).
Both `wp-cli/wp-cli` and `wp-cli/wp-cli-bundle` use milestones to indicate the next stable release. For `wp-cli/wp-cli`, the milestone represents the version of the WP-CLI framework. For `wp-cli/wp-cli-bundle`, the milestone represents the WP-CLI Phar version. We keep these in sync for backwards compatibility reasons, and to avoid causing confusion with third party commands. Each of the command repositories are versioned independently according to semantic versioning principles as needed.
