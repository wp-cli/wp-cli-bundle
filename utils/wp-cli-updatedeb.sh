#!/bin/bash
#
# Package wp-cli to be installed in Debian-compatible systems.
# Only the phar file is included.
#
# VERSION       :0.2.5
# DATE          :2023-07-22
# AUTHOR        :Viktor Szépe <viktor@szepe.net>
# LICENSE       :The MIT License (MIT)
# URL           :https://github.com/wp-cli/wp-cli/tree/main/utils
# BASH-VERSION  :4.2+

# packages source path
DIR="php-wpcli"
# phar URL
PHAR="https://github.com/wp-cli/builds/raw/gh-pages/phar/wp-cli.phar"

die() {
    local RET="$1"
    shift

    echo -e "$@" >&2
    exit "$RET"
}

dump_control() {
    cat > DEBIAN/control <<EOF
Package: php-wpcli
Version: 0.0.0
Architecture: all
Maintainer: Alain Schlesser <alain.schlesser@gmail.com>
Section: php
Priority: optional
Depends: php-cli, php7.2-mysql | php7.3-mysql | php7.4-mysql | php8.0-mysql | php8.1-mysql | php8.2-mysql | php8.3-mysql | php8.4-mysql | php8.5-mysql | php-mysql, mysql-client | mariadb-client
Homepage: http://wp-cli.org/
Description: wp-cli is a set of command-line tools for managing
 WordPress installations. You can update plugins, set up multisite
 installations and much more, without using a web browser.

EOF
}

dump_launcher() {
    # Write the launcher script that selects the PHP interpreter (honoring
    # WP_CLI_PHP and WP_CLI_PHP_ARGS) and runs the bundled Phar.
    # Quoted heredoc delimiter keeps the variables literal.
    cat > "$1" <<'LAUNCHER'
#!/bin/sh
#
# WP-CLI launcher for the Debian package.
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
}

set -e

# Download the binary if needed
if [ ! -f "wp-cli.phar" ]; then
	wget -nv -O wp-cli.phar "$PHAR"
	chmod +x wp-cli.phar
fi

# deb's dir
if ! [ -d "$DIR" ]; then
    mkdir "$DIR" || die 1 "Cannot create directory here: ${PWD}"
fi

pushd "$DIR"

# control file
if ! [ -r DEBIAN/control ]; then
    mkdir DEBIAN
    dump_control
fi

# copyright
if ! [ -r usr/share/doc/php-wpcli/copyright ]; then
    mkdir -p usr/share/doc/php-wpcli &> /dev/null
    wget -nv -O usr/share/doc/php-wpcli/copyright https://raw.githubusercontent.com/wp-cli/wp-cli/main/LICENSE
fi

# changelog
if ! [ -r usr/share/doc/php-wpcli/changelog.gz ]; then
    mkdir -p usr/share/doc/php-wpcli &> /dev/null
    echo "Changelog can be found in the blog: https://make.wordpress.org/cli/" \
        | gzip -n -9 > usr/share/doc/php-wpcli/changelog.gz
fi

# content dirs
[ -d usr/bin ] || mkdir -p usr/bin
[ -d usr/share/wp-cli ] || mkdir -p usr/share/wp-cli

# install the Phar to a shared location and a launcher to the bin dir
mv ../wp-cli.phar usr/share/wp-cli/wp-cli.phar
chmod 0755 usr/share/wp-cli/wp-cli.phar
dump_launcher usr/bin/wp
chmod 0755 usr/bin/wp

# get version
# The launcher hard-codes the installed /usr/share path, which does not exist
# inside the staging dir yet, so invoke PHP against the staged Phar directly.
WPCLI_VER="$(php usr/share/wp-cli/wp-cli.phar cli version | cut -d " " -f 2)"
[ -z "$WPCLI_VER" ] && die 5 "Cannot get wp-cli version"
echo "Current version: ${WPCLI_VER}"

# update version
sed -i -e "s/^Version: .*$/Version: ${WPCLI_VER}/" DEBIAN/control || die 6 "Version update failure"

# minimal man page
if ! [ -r usr/share/man/man1/wp.1.gz ]; then
    mkdir -p usr/share/man/man1 &> /dev/null
    {
        echo '.TH "WP" "1"'
        php usr/share/wp-cli/wp-cli.phar --help
    } \
        | sed 's/^\([A-Z ]\+\)$/.SH "\1"/' \
        | sed 's/^  wp$/wp \\- A command line interface for WordPress/' \
        | gzip -n -9 > usr/share/man/man1/wp.1.gz
fi

# update MD5-s
find usr -type f -exec md5sum "{}" ";" > DEBIAN/md5sums || die 7 "md5sum creation failure"

popd

# build package in the current directory
WPCLI_PKG="${PWD}/php-wpcli_${WPCLI_VER}_all.deb"
fakeroot dpkg-deb -Zxz --build "$DIR" "$WPCLI_PKG" || die 8 "Packaging failed"

# check package - not critical
lintian --display-info --display-experimental --pedantic --show-overrides php-wpcli_*_all.deb || true

# optional steps
echo "sign it:               dpkg-sig -k SIGNING-KEY -s builder \"${WPCLI_PKG}\""
echo "include in your repo:  pushd /var/www/REPO-DIR"
echo "                       reprepro includedeb jessie \"${WPCLI_PKG}\" && popd"
