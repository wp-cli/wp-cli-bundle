Name:       wp-cli
Version:    0.0.0
Release:    3%{?dist}
Summary:    The command line interface for WordPress
License:    MIT
URL:        http://wp-cli.org/
Source0:    wp-cli.phar
Source1:    wp.1
Source2:    wp
BuildArch:  noarch

%post
echo "PHP 7.2 or above must be installed."

%description
WP-CLI is the command-line interface for WordPress.
You can update plugins, configure multisite installations
and much more, without using a web browser.

%prep
chmod +x %{SOURCE0}
{
    echo '.TH "WP" "1"'
    php %{SOURCE0} --help
} \
    | sed -e 's/^\([A-Z ]\+\)$/.SH "\1"/' \
    | sed -e 's/^  wp$/wp \\- The command line interface for WordPress/' \
    > %{SOURCE1}

%build

%install
install -d -m 0755 %{buildroot}%{_datadir}/wp-cli
install -p -m 0755 %{SOURCE0} %{buildroot}%{_datadir}/wp-cli/wp-cli.phar
install -d -m 0755 %{buildroot}%{_bindir}
install -p -m 0755 %{SOURCE2} %{buildroot}%{_bindir}/wp
install -d -m 0755 %{buildroot}%{_mandir}/man1
install -p -m 0644 %{SOURCE1} %{buildroot}%{_mandir}/man1/

%files
%attr(0755, root, root) %{_bindir}/wp
%dir %attr(0755, root, root) %{_datadir}/wp-cli
%attr(0755, root, root) %{_datadir}/wp-cli/wp-cli.phar
%attr(0644, root, root) %{_mandir}/man1/wp.1*

%changelog
* Tue Jul 21 2026 Alain Schlesser <alain.schlesser@gmail.com> - 0.0.0-3
- Install the Phar to %{_datadir}/wp-cli and ship a launcher at %{_bindir}/wp
  so WP_CLI_PHP and WP_CLI_PHP_ARGS are honored.

* Tue Dec 12 2017 Murtaza Sarıaltun <murtaza.sarialtun@ozguryazzilim.com.tr> - 0.0.0-2
- Remove php requirements.
- Update creating man page steps.
- Added output message.

* Fri Jul 7 2017 Murtaza Sarıaltun <murtaza.sarialtun@ozguryazilim.com.tr> - 0.0.0-1
- First release of the spec file
- Check the spec file with `rpmlint -i -v wp-cli-rpm.spec`
- Build the package with `rpmbuild -bb wp-cli-rpm.spec`
