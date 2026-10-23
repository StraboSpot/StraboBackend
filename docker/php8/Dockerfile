# strabo-php on PHP 8.4 (Debian 13 trixie). Replaces docker/phpDockerfile (PHP 7.4 on
# bullseye; prod still runs an older PHP 7.3 buster image). Debian security keeps php8.4
# patched (8.4.27 = the PHP2Shell fix, CVE-2026-109618 / CVE-2026-109619).
#
# dbase (ShapeFile.inc.php reads .dbf files): pecl.php.net has no release that installs on
# PHP 8.4, so it is built from the PECL source on GitHub, pinned to a tested commit.
#
# Build context is the parent folder (DC/Strabo), so the extras/ COPY lines still work:
#   docker build -f www/docker/php8/Dockerfile -t strabo-php8 .
FROM debian:trixie

LABEL maintainer="Jason Ash jasonash@ku.edu"

RUN rm -f /var/run/apache2/apache2.pid \
	&& apt-get update -y \
	&& apt-get upgrade -y \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y \
	apache2 \
	php8.4 \
	php8.4-pgsql \
	php8.4-dev \
	libapache2-mod-php8.4 \
	libapache2-mod-authnz-external \
	php8.4-gd \
	php8.4-curl \
	php8.4-bcmath \
	php8.4-mbstring \
	php-geos \
	php-mail \
	php8.4-zip \
	libaprutil1-dbd-pgsql \
	gdal-bin \
	gdal-data \
	python3 \
	python3-gdal \
	cgi-mapserver \
	zip \
	php-fpdf \
	git \
	ca-certificates \
	&& rm -rf /var/lib/apt/lists/* \
	&& rm -rf /var/www \
	&& ln -s /srv/app/www /var/www \
	&& git clone -q https://github.com/php/pecl-database-dbase.git /tmp/dbase \
	&& cd /tmp/dbase && git checkout -q 09fe623 \
	&& phpize && ./configure && make && make install \
	&& cd / && rm -rf /tmp/dbase \
	&& mkdir -p /srv/app \
	&& mkdir /mapserv-work \
	&& echo 'memory_limit=1G' > /etc/php/8.4/apache2/conf.d/20-memory-limit.ini \
	&& echo 'upload_max_filesize=10000M' > /etc/php/8.4/apache2/conf.d/20-upload-max-filesize.ini \
	&& echo 'post_max_size=10000M' > /etc/php/8.4/apache2/conf.d/20-post-max-size.ini \
	&& echo 'error_reporting = E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE' > /etc/php/8.4/apache2/conf.d/20-error_reporting.ini \
	&& echo 'display_errors = On' > /etc/php/8.4/apache2/conf.d/20-display_errors.ini \
	&& echo 'extension=dbase.so' > /etc/php/8.4/apache2/conf.d/20-dbase.ini \
	&& echo 'short_open_tag = On' > /etc/php/8.4/apache2/conf.d/20-short-tags.ini \
	&& echo 'session.upload_progress.enabled = 0' > /etc/php/8.4/apache2/conf.d/20-no-upload-progress.ini \
	&& echo 'memory_limit=1G' > /etc/php/8.4/cli/conf.d/20-memory-limit.ini \
	&& echo 'error_reporting = E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE' > /etc/php/8.4/cli/conf.d/20-error_reporting.ini \
	&& echo 'display_errors = On' > /etc/php/8.4/cli/conf.d/20-display_errors.ini \
	&& echo 'extension=dbase.so' > /etc/php/8.4/cli/conf.d/20-dbase.ini \
	&& echo 'short_open_tag = On' > /etc/php/8.4/cli/conf.d/20-short-tags.ini \
	&& echo 'include_path=".:/usr/share/php:/usr/share"' > /etc/php/8.4/apache2/conf.d/20-include_path.ini \
	&& echo "ServerName strabospot" >> /etc/apache2/apache2.conf \
	&& chown -R www-data:www-data /srv/app \
	&& a2enmod rewrite \
	&& a2enmod headers \
	&& a2enmod proxy \
	&& a2enmod proxy_http \
	&& a2enmod proxy_wstunnel \
	&& a2enmod authn_dbd \
	&& a2enmod cgi \
	&& a2enmod macro

# MapServer 8 (trixie) refuses to run without a config file ("msLoadConfig(): Unable to
# access file") and only opens mapfiles whose path matches MS_MAP_PATTERN. Used by
# geotiff/tile.php and /cgi-bin/mapserv. /etc/mapserver.conf is the compiled-in default.
RUN printf 'CONFIG\n  ENV\n    MS_MAP_PATTERN "^/var/www/|^/srv/app/www/"\n  END\n  MAPS\n  END\nEND\n' > /etc/mapserver.conf

COPY extras/fonts/* /usr/share/fonts/truetype/
COPY extras/mapserv/* /mapserv-work/
COPY extras/apache.conf /etc/apache2/sites-available/000-default.conf
# No scripts and no .htaccess in the upload / data folders (see the file)
COPY www/docker/php8/datadirs.conf /etc/apache2/conf-enabled/strabo-datadirs.conf

CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
