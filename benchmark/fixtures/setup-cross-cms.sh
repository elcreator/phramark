#!/usr/bin/env sh
set -eu

mysql_root() {
  MYSQL_PWD="$DB_ROOT_PASSWORD" mysql -h "$DB_HOST" -u root "$@"
}

create_database() {
  mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql_root -e "GRANT ALL PRIVILEGES ON \`$1\`.* TO '$DB_USER'@'%'"
}

install_drupal() {
  site=/sites/drupal-11
  [ -f "$site/.phramark-installed" ] && return
  create_database benchmark_drupal_11
  composer create-project drupal/recommended-project:^11 "$site" --no-interaction --no-progress
  cd "$site"
  composer require drush/drush --no-interaction --no-progress
  vendor/bin/drush site:install minimal --db-url="mysql://$DB_USER:$DB_PASSWORD@$DB_HOST/benchmark_drupal_11" --account-name=benchmark --account-pass="$DRUPAL_ADMIN_PASSWORD" --site-name=Phramark --yes
  mkdir -p web/modules/custom
  cp -R /opt/phramark/benchmark/implementations/drupal-11/modules/custom/phramark_benchmark web/modules/custom/
  vendor/bin/drush en phramark_benchmark --yes
  vendor/bin/drush role:perm:add anonymous 'access content'
  vendor/bin/drush php:script /opt/phramark/benchmark/fixtures/cms/drupal-seed.php
  vendor/bin/drush php:script /opt/phramark/benchmark/fixtures/cms/drupal-admin-seed.php
  chown -R www-data:www-data "$site"
  touch "$site/.phramark-installed"
}

# Adapter code is re-synced on every run so a rebuilt image updates an
# existing site volume; the FPM containers restart afterwards because
# OPcache does not validate timestamps.
sync_drupal() {
  site=/sites/drupal-11
  [ -f "$site/.phramark-installed" ] || return 0
  cd "$site"
  rm -rf web/modules/custom/phramark_benchmark
  cp -R /opt/phramark/benchmark/implementations/drupal-11/modules/custom/phramark_benchmark web/modules/custom/
  vendor/bin/drush php:script /opt/phramark/benchmark/fixtures/cms/drupal-admin-seed.php
  vendor/bin/drush cache:rebuild
  chown -R www-data:www-data "$site"
}


# The load generator reaches the stack as nginx-typo3:80 on the compose
# network while verification uses 127.0.0.1, so every host must be trusted;
# otherwise TYPO3 answers the load with HTTP 500 and the gate never sees it.
write_typo3_additional() {
  printf '%s\n' "<?php" "\$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';" > config/system/additional.php
}

install_typo3() {
  site=/sites/typo3
  [ -f "$site/.phramark-installed" ] && return
  mysql_root -e 'DROP DATABASE IF EXISTS `benchmark_typo3`'
  create_database benchmark_typo3
  if [ ! -f "$site/composer.json" ]; then
    composer create-project typo3/cms-base-distribution:^14 "$site" --no-interaction --no-progress
  fi
  cd "$site"
  composer config repositories.phramark path /opt/phramark/benchmark/implementations/typo3
  composer require phramark/typo3-benchmark:@dev --no-interaction --no-progress
  vendor/bin/typo3 setup --no-interaction --force --server-type=other --driver=pdoMysql --host="$DB_HOST" --port=3306 --dbname=benchmark_typo3 --username="$DB_USER" --password="$DB_PASSWORD" --admin-username=benchmark --admin-user-password="$TYPO3_ADMIN_PASSWORD" --admin-email=benchmark@example.test --project-name=Phramark --create-site=no
  write_typo3_additional
  mysql_root benchmark_typo3 < /opt/phramark/benchmark/fixtures/cms/typo3-seed.sql
  php /opt/phramark/benchmark/fixtures/cms/seed-articles.php "$DB_HOST" benchmark_typo3 "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/typo3-admin-seed.php "$DB_HOST" benchmark_typo3 "$DB_USER" "$DB_PASSWORD"
  vendor/bin/typo3 cache:flush
  chown -R www-data:www-data "$site"
  touch "$site/.phramark-installed"
}

# The TYPO3 extension is a Composer path repository symlinked into
# /opt/phramark, so a rebuilt image already carries new code; only the
# container and file caches have to be flushed.
sync_typo3() {
  site=/sites/typo3
  [ -f "$site/.phramark-installed" ] || return 0
  cd "$site"
  write_typo3_additional
  php /opt/phramark/benchmark/fixtures/cms/typo3-admin-seed.php "$DB_HOST" benchmark_typo3 "$DB_USER" "$DB_PASSWORD"
  vendor/bin/typo3 cache:flush
  chown -R www-data:www-data "$site"
}

# Winter CMS (the licence-free October fork). The plugin and theme are copied
# from benchmark/implementations/winter; the default "admin" account is
# renamed to the shared benchmark user.
write_winter_env() {
  cat > .env <<ENV
APP_NAME="Phramark"
APP_KEY=${APP_KEY:-}
APP_DEBUG=false
APP_URL=http://127.0.0.1:8086
APP_LOCALE=en
DB_CONNECTION=mysql
DB_HOST=$DB_HOST
DB_PORT=3306
DB_DATABASE=benchmark_winter
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
ROUTES_CACHE=true
ASSET_CACHE=true
LINK_POLICY=detect
ENABLE_CSRF=true
DATABASE_TEMPLATES=false
ENV
}

copy_winter_adapter() {
  rm -rf plugins/phramark themes/phramark
  mkdir -p plugins themes
  cp -R /opt/phramark/benchmark/implementations/winter/plugins/phramark plugins/
  cp -R /opt/phramark/benchmark/implementations/winter/themes/phramark themes/
}

install_winter() {
  site=/sites/winter
  [ -f "$site/.phramark-installed" ] && return
  mysql_root -e 'DROP DATABASE IF EXISTS `benchmark_winter`'
  create_database benchmark_winter
  if [ ! -f "$site/artisan" ]; then
    composer create-project wintercms/winter "$site" --no-interaction --no-progress
  fi
  cd "$site"
  write_winter_env
  php artisan key:generate --force --no-interaction
  rm -rf plugins/winter/demo themes/demo
  copy_winter_adapter
  php artisan winter:up --no-interaction
  php artisan theme:use phramark --force --no-interaction
  mysql_root benchmark_winter -e "UPDATE backend_users SET login='benchmark', email='benchmark@example.test'"
  php artisan winter:passwd benchmark "$WINTER_ADMIN_PASSWORD" --no-interaction
  php /opt/phramark/benchmark/fixtures/cms/seed-articles.php "$DB_HOST" benchmark_winter "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/winter-admin-seed.php "$DB_HOST" benchmark_winter "$DB_USER" "$DB_PASSWORD"
  php artisan cache:clear --no-interaction
  chown -R www-data:www-data "$site"
  touch "$site/.phramark-installed"
}

sync_winter() {
  site=/sites/winter
  [ -f "$site/.phramark-installed" ] || return 0
  cd "$site"
  copy_winter_adapter
  php artisan winter:up --no-interaction
  php /opt/phramark/benchmark/fixtures/cms/winter-admin-seed.php "$DB_HOST" benchmark_winter "$DB_USER" "$DB_PASSWORD"
  php artisan cache:clear --no-interaction
  chown -R www-data:www-data "$site"
}

# MODX Revolution (https://github.com/modxcms/revolution), installed from the
# release archive its GitHub tags point at (the git tree needs a transport
# build first; the archive ships core/packages/core unpacked, hence
# inplace=1 unpacked=1). MODX writes the absolute core path into its four
# config files; the FPM container mounts the volume at /var/www/html, so
# those are rewritten from the setup path after the install.
MODX_VERSION=${MODX_VERSION:-3.2.4}

write_modx_config() {
  cat > setup/config.xml <<XML
<modx>
    <database_type>mysql</database_type>
    <database_server>$DB_HOST</database_server>
    <database>benchmark_modx</database>
    <database_user>$DB_USER</database_user>
    <database_password>$DB_PASSWORD</database_password>
    <database_connection_charset>utf8mb4</database_connection_charset>
    <database_charset>utf8mb4</database_charset>
    <database_collation>utf8mb4_unicode_ci</database_collation>
    <table_prefix>modx_</table_prefix>
    <https_port>443</https_port>
    <http_host>127.0.0.1:8087</http_host>
    <cache_disabled>0</cache_disabled>
    <inplace>1</inplace>
    <unpacked>1</unpacked>
    <language>en</language>
    <cmsadmin>benchmark</cmsadmin>
    <cmspassword>$MODX_ADMIN_PASSWORD</cmspassword>
    <cmsadminemail>benchmark@example.test</cmsadminemail>
    <core_path>$site/core/</core_path>
    <context_mgr_path>$site/manager/</context_mgr_path>
    <context_mgr_url>/manager/</context_mgr_url>
    <context_connectors_path>$site/connectors/</context_connectors_path>
    <context_connectors_url>/connectors/</context_connectors_url>
    <context_web_path>$site/</context_web_path>
    <context_web_url>/</context_web_url>
    <remove_setup_directory>1</remove_setup_directory>
</modx>
XML
}

copy_modx_adapter() {
  rm -rf core/components/phramark
  cp -R /opt/phramark/benchmark/implementations/modx/core/components/phramark core/components/
}

rewrite_modx_paths() {
  for file in config.core.php manager/config.core.php connectors/config.core.php core/config/config.inc.php; do
    sed -i "s#$site/#/var/www/html/#g" "$file"
  done
}

install_modx() {
  site=/sites/modx
  [ -f "$site/.phramark-installed" ] && return
  mysql_root -e 'DROP DATABASE IF EXISTS `benchmark_modx`'
  create_database benchmark_modx
  # The installer removes setup/ when it succeeds; a tree without it and
  # without the marker is a failed attempt and is extracted again.
  if [ ! -d "$site/setup" ]; then
    curl -fsSL -o /tmp/modx.zip "https://modx.s3.amazonaws.com/releases/$MODX_VERSION/modx-$MODX_VERSION-pl.zip"
    rm -rf /tmp/modx-unpack
    unzip -q /tmp/modx.zip -d /tmp/modx-unpack
    cp -R "/tmp/modx-unpack/modx-$MODX_VERSION-pl/." "$site/"
    rm -rf /tmp/modx.zip /tmp/modx-unpack
  fi
  cd "$site"
  write_modx_config
  php setup/index.php --installmode=new --config="$site/setup/config.xml"
  rewrite_modx_paths
  copy_modx_adapter
  php /opt/phramark/benchmark/fixtures/cms/modx-seed.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/seed-articles.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/modx-admin-seed.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  rm -rf core/cache/*
  chown -R www-data:www-data "$site"
  touch "$site/.phramark-installed"
}

sync_modx() {
  site=/sites/modx
  [ -f "$site/.phramark-installed" ] || return 0
  cd "$site"
  copy_modx_adapter
  php /opt/phramark/benchmark/fixtures/cms/modx-seed.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/modx-admin-seed.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  rm -rf core/cache/*
  chown -R www-data:www-data "$site"
}

# WordPress with the Gantry 5 framework plugin and its Hydrogen theme (one
# stack: the framework is only ever measured through a Gantry theme). Core
# comes from wordpress.org through WP-CLI, the plugin and theme from the
# Gantry release archives on GitHub, the Classic Editor plugin from
# wordpress.org so the admin workload edits a page body in a plain textarea
# like the Drupal, Evolution and Winter fixtures. The site URL follows the
# request's Host header, because the load generator and the browser reach
# the stack as nginx-wordpress-gantry:80 while verification uses
# 127.0.0.1:8088; loopback and external HTTP (update checks, feeds) and the
# cron spawn are off so no timed step waits on the internet.
WORDPRESS_VERSION=${WORDPRESS_VERSION:-7.1.1}
GANTRY_VERSION=${GANTRY_VERSION:-5.6.4}
CLASSIC_EDITOR_VERSION=${CLASSIC_EDITOR_VERSION:-1.7.0}

wp() {
  /usr/local/bin/wp --allow-root --path="$site" "$@"
}

write_wordpress_config() {
  rm -f wp-config.php
  wp config create --dbname=benchmark_wordpress --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --dbcharset=utf8mb4 --skip-check --extra-php <<'PHP'
define('WP_HOME', 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8088'));
define('WP_SITEURL', WP_HOME);
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('WP_DEBUG', false);
define('FS_METHOD', 'direct');
if (PHP_SAPI !== 'cli') {
    define('WP_HTTP_BLOCK_EXTERNAL', true);
}
PHP
}

copy_wordpress_adapter() {
  rm -rf wp-content/mu-plugins/phramark-benchmark.php wp-content/mu-plugins/phramark-benchmark wp-content/themes/g5_hydrogen/custom/views/phramark-category.html.twig
  mkdir -p wp-content/mu-plugins wp-content/themes/g5_hydrogen/custom/views
  cp -R /opt/phramark/benchmark/implementations/wordpress-gantry/mu-plugins/. wp-content/mu-plugins/
  cp /opt/phramark/benchmark/implementations/wordpress-gantry/theme-custom/views/phramark-category.html.twig wp-content/themes/g5_hydrogen/custom/views/
  # Compiled Twig views and SCSS live under wp-content/cache/gantry5; the
  # warm-up rebuilds them.
  rm -rf wp-content/cache/gantry5
}

install_wordpress_gantry() {
  site=/sites/wordpress-gantry
  [ -f "$site/.phramark-installed" ] && return
  mysql_root -e 'DROP DATABASE IF EXISTS `benchmark_wordpress`'
  create_database benchmark_wordpress
  mkdir -p "$site"
  cd "$site"
  if [ ! -f "$site/wp-settings.php" ]; then
    wp core download --version="$WORDPRESS_VERSION" --skip-content
  fi
  write_wordpress_config
  wp core install --url=http://127.0.0.1:8088 --title=Phramark --admin_user=benchmark --admin_password="$WORDPRESS_ADMIN_PASSWORD" --admin_email=benchmark@example.test --skip-email
  wp plugin install "https://github.com/gantry/gantry5/releases/download/$GANTRY_VERSION/wordpress-pkg_gantry5_v$GANTRY_VERSION.zip" --force --activate
  wp theme install "https://github.com/gantry/gantry5/releases/download/$GANTRY_VERSION/wordpress-tpl_g5_hydrogen_v$GANTRY_VERSION.zip" --force --activate
  wp plugin install classic-editor --version="$CLASSIC_EDITOR_VERSION" --force --activate
  # No trailing slash: the canonical URL form every stack serves without a
  # redirect is /articles/category-042.
  wp option update permalink_structure '/%postname%'
  wp option update blog_public 0
  wp rewrite flush
  copy_wordpress_adapter
  php /opt/phramark/benchmark/fixtures/cms/wordpress-seed.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/seed-articles.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/wordpress-admin-seed.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  chown -R www-data:www-data "$site"
  touch "$site/.phramark-installed"
}

sync_wordpress_gantry() {
  site=/sites/wordpress-gantry
  [ -f "$site/.phramark-installed" ] || return 0
  cd "$site"
  write_wordpress_config
  copy_wordpress_adapter
  php /opt/phramark/benchmark/fixtures/cms/wordpress-seed.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/wordpress-admin-seed.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  chown -R www-data:www-data "$site"
}

install_drupal
install_typo3
install_winter
install_modx
install_wordpress_gantry
sync_drupal
sync_typo3
sync_winter
sync_modx
sync_wordpress_gantry
