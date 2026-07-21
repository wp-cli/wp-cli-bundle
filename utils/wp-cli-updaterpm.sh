#!/bin/bash
#
# Package WP-CLI to be installed on RPM-based systems.
#
# VERSION       :0.1.0
# DATE          :2017-07-12
# AUTHOR        :Viktor Szépe <viktor@szepe.net>
# LICENSE       :The MIT License (MIT)
# URL           :https://github.com/wp-cli/wp-cli-bundle/tree/main/utils
# BASH-VERSION  :4.2+
# DEPENDS       :apt-get install rpm rpmlint php-cli

PHAR_URL="https://github.com/wp-cli/builds/raw/gh-pages/phar/wp-cli.phar"
# Source directory
SOURCE_DIR="rpm-src"

die() {
    local RET="$1"
    shift

    echo -e "$@" >&2
    exit "$RET"
}

set -e

# Check dependencies
if ! hash php rpm; then
    die 1 "Missing RPM build tools"
fi

# Download the binary if needed
if [ ! -f "wp-cli.phar" ]; then
	wget -nv -O wp-cli.phar "$PHAR_URL"
	chmod +x wp-cli.phar
fi

if ! [ -d "$SOURCE_DIR" ]; then
    mkdir "$SOURCE_DIR" || die 2 "Cannot create directory here: ${PWD}"
fi

pushd "$SOURCE_DIR" > /dev/null

# Move files
mv ../wp-cli.phar wp-cli.phar
cp ../wp-cli-rpm.spec wp-cli.spec

# Write the launcher script that selects the PHP interpreter (honoring
# WP_CLI_PHP and WP_CLI_PHP_ARGS) and runs the bundled Phar.
# Quoted heredoc delimiter keeps the variables literal.
cat > wp <<'LAUNCHER'
#!/bin/sh
#
# WP-CLI launcher for the RPM package.
# Selects the PHP interpreter, honoring the WP_CLI_PHP and WP_CLI_PHP_ARGS
# environment variables, then runs the bundled Phar.
# See https://github.com/wp-cli/wp-cli-bundle/issues/1078

if [ -n "$WP_CLI_PHP" ]; then
	php="$WP_CLI_PHP"
else
	php="$(command -v php)"
fi

export WP_CLI_PHP_USED="$php"

# WP_CLI_PHP_ARGS is intentionally unquoted so multiple arguments are split.
# shellcheck disable=SC2086
exec "$php" $WP_CLI_PHP_ARGS /usr/share/wp-cli/wp-cli.phar "$@"
LAUNCHER

# Replace version placeholder
WPCLI_VER="$(php wp-cli.phar cli version | cut -d " " -f 2)"
if [ -z "$WPCLI_VER" ]; then
    die 3 "Cannot get WP_CLI version"
fi
echo "Current version: ${WPCLI_VER}"
sed -i -e "s/^Version: .*\$/Version:    ${WPCLI_VER}/" wp-cli.spec || die 4 "Version update failed"
# Rewrite the placeholder version in every changelog entry (0.0.0-N -> ${WPCLI_VER}-N)
# so the top entry stays coherent with the package version for rpmlint.
sed -i -e "s/^\(\* .*\) 0\.0\.0-\([0-9]\+\)\$/\1 ${WPCLI_VER}-\2/" wp-cli.spec || die 5 "Changelog update failed"

# Create man page
{
    echo '.TH "WP" "1"'
    php wp-cli.phar --help
} \
    | sed -e 's/^\([A-Z ]\+\)$/.SH "\1"/' \
    | sed -e 's/^  wp$/wp \\- The command line interface for WordPress/' \
    > wp.1

# Build the package
rpmbuild --define "_sourcedir ${PWD}" --define "_rpmdir ${PWD}" -bb wp-cli.spec | tee wp-cli-updaterpm-rpmbuild.$$.log

rpm_path=`grep -o "/.*/noarch/wp-cli-.*noarch.rpm" wp-cli-updaterpm-rpmbuild.$$.log`

rm -f wp-cli-updaterpm-rpmbuild.$$.log

if [ ${#rpm_path} -lt 20 ] ; then
	echo "RPM path doesn't exist ($rpm_path)"
	exit
fi

if [[ $(type -P "rpmlint") ]] ; then
	echo "Using rpmlint to check for errors"
# Run linter
cat <<"EOF" > rpmlint.config
setOption("CompressExtension", "gz")
addFilter(": E: no-packager-tag")
addFilter(": E: no-signature")
addFilter(": E: no-dependency-on locales-cli")
EOF

	rpmlint -v -f rpmlint.config -i $rpm_path || true

elif ([ $(type -P "rpm2cpio") ] && [ $(type -P "cpio") ]); then
	echo "No RPM lint found $rpm_path .. using alternative method"
	mkdir rpm-test-$$
	cd rpm-test-$$
	if [ $? -ne 0 ] ; then
		echo "Failed to cd into rpm-test-$$"
		exit;
	fi
	rpm2cpio $rpm_path | cpio -idmv

	if [ -f "usr/bin/wp" ] ; then
		echo "RPM test succeeded"
	else
		echo "RPM test failed"
	fi
	rm -rfv ../rpm-test-$$
else
	echo "All test methods failed"
fi


popd > /dev/null

echo "OK."
