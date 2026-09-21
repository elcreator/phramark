#!/usr/bin/env sh
# Installs (or re-syncs) the cross-CMS stacks. Every site is installed by
# its own process (`sh $0 install_x`, see the end), so a version that cannot
# be built fails that site alone; PHRAMARK_STACKS (set per round by
# benchmark/scripts/matrix) names the stacks the run needs, and only a
# failed one of those fails the run. Without the variable every site is
# required.
set -eu

mysql_root() {
  MYSQL_PWD="$DB_ROOT_PASSWORD" mysql -h "$DB_HOST" -u root "$@"
}

create_database() {
  mysql_root -e "DROP DATABASE IF EXISTS \`$1\`"
  mysql_root -e "CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql_root -e "GRANT ALL PRIVILEGES ON \`$1\`.* TO '$DB_USER'@'%'"
}

# Versions. Every part a stack can be pinned to (Phramark\VersionSpec: the
# CMS, the extensions the harness adds) is resolved once per run by
# resolve-version.php from its $<PRODUCT>_VERSION variable ("latest" when
# unset): a published tag of that name, otherwise the branch of that name.
# The resolved lines are the site's .phramark-versions marker, so a site is
# reinstalled exactly when they change (a new ref, a moved branch, a new
# "latest"); otherwise only the adapter code and the admin fixture are
# re-synced.
resolve_version() {
  php /opt/phramark/benchmark/fixtures/resolve-version.php "$1"
}

# "product=kind value [commit]" lines for the products of a site.
wanted_versions() {
  for product in "$@"; do
    resolved=$(resolve_version "$product")
    printf '%s=%s\n' "$product" "$resolved"
  done
}

# The kind (tag, branch, composer, image) and value of one product in a
# wanted_versions text.
version_kind() { printf '%s\n' "$1" | sed -n "s/^$2=\([a-z]*\) .*/\1/p"; }
version_value() { printf '%s\n' "$1" | sed -n "s/^$2=[a-z]* \([^ ]*\).*/\1/p"; }

# 0 when the site was installed from exactly these versions.
site_current() {
  [ -f "$1/.phramark-versions" ] && [ "$(cat "$1/.phramark-versions")" = "$2" ]
}

# Empties a site built from other versions (or a failed attempt) so it is
# installed again from scratch; the directory itself is a volume mount and
# stays.
reset_site() {
  mkdir -p "$1"
  if [ -n "$(ls -A "$1")" ]; then
    echo "$1: versions changed, reinstalling"
    find "$1" -mindepth 1 -delete
  fi
}

# Composer needs the dev stability flag to create a project from a branch.
composer_stability() {
  case "$1" in *dev*) echo "--stability=dev" ;; esac
}

# A project from a directory on the host (a "path" version): copied as it
# is, its dependencies installed when the copy has none.
copy_project() {
  cp -R "$1/." "$2/"
  cd "$2"
  [ -d vendor ] || composer install --no-interaction --no-progress
}

install_drupal() {
  site=/sites/drupal
  wanted=$(wanted_versions drupal)
  site_current "$site" "$wanted" && return
  reset_site "$site"
  create_database benchmark_drupal
  version=$(version_value "$wanted" drupal)
  if [ "$(version_kind "$wanted" drupal)" = path ]; then
    echo "drupal: project directory $version"
    copy_project "$version" "$site"
  else
    echo "drupal: drupal/recommended-project $version"
    composer create-project "drupal/recommended-project:$version" "$site" --no-interaction --no-progress $(composer_stability "$version")
  fi
  cd "$site"
  composer require drush/drush --no-interaction --no-progress
  vendor/bin/drush site:install minimal --db-url="mysql://$DB_USER:$DB_PASSWORD@$DB_HOST/benchmark_drupal" --account-name=benchmark --account-pass="$DRUPAL_ADMIN_PASSWORD" --site-name=Phramark --yes
  mkdir -p web/modules/custom
  cp -R /opt/phramark/benchmark/implementations/drupal/modules/custom/phramark_benchmark web/modules/custom/
  vendor/bin/drush en phramark_benchmark --yes
  vendor/bin/drush role:perm:add anonymous 'access content'
  vendor/bin/drush php:script /opt/phramark/benchmark/fixtures/cms/drupal-seed.php
  vendor/bin/drush php:script /opt/phramark/benchmark/fixtures/cms/drupal-admin-seed.php
  chown -R www-data:www-data "$site"
  printf '%s\n' "$wanted" > "$site/.phramark-versions"
}

# Adapter code is re-synced on every run so a rebuilt image updates an
# existing site volume; the FPM containers restart afterwards because
# OPcache does not validate timestamps.
sync_drupal() {
  site=/sites/drupal
  [ -f "$site/.phramark-versions" ] || return 0
  cd "$site"
  rm -rf web/modules/custom/phramark_benchmark
  cp -R /opt/phramark/benchmark/implementations/drupal/modules/custom/phramark_benchmark web/modules/custom/
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
  wanted=$(wanted_versions typo3)
  site_current "$site" "$wanted" && return
  reset_site "$site"
  create_database benchmark_typo3
  version=$(version_value "$wanted" typo3)
  if [ "$(version_kind "$wanted" typo3)" = path ]; then
    echo "typo3: project directory $version"
    copy_project "$version" "$site"
  else
    echo "typo3: typo3/cms-base-distribution $version"
    composer create-project "typo3/cms-base-distribution:$version" "$site" --no-interaction --no-progress $(composer_stability "$version")
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
  printf '%s\n' "$wanted" > "$site/.phramark-versions"
}

# The TYPO3 extension is a Composer path repository symlinked into
# /opt/phramark, so a rebuilt image already carries new code; only the
# container and file caches have to be flushed.
sync_typo3() {
  site=/sites/typo3
  [ -f "$site/.phramark-versions" ] || return 0
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
  wanted=$(wanted_versions winter)
  site_current "$site" "$wanted" && return
  reset_site "$site"
  create_database benchmark_winter
  version=$(version_value "$wanted" winter)
  if [ "$(version_kind "$wanted" winter)" = path ]; then
    echo "winter: project directory $version"
    copy_project "$version" "$site"
  else
    echo "winter: wintercms/winter $version"
    composer create-project "wintercms/winter:$version" "$site" --no-interaction --no-progress $(composer_stability "$version")
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
  printf '%s\n' "$wanted" > "$site/.phramark-versions"
}

sync_winter() {
  site=/sites/winter
  [ -f "$site/.phramark-versions" ] || return 0
  cd "$site"
  copy_winter_adapter
  php artisan winter:up --no-interaction
  php /opt/phramark/benchmark/fixtures/cms/winter-admin-seed.php "$DB_HOST" benchmark_winter "$DB_USER" "$DB_PASSWORD"
  php artisan cache:clear --no-interaction
  chown -R www-data:www-data "$site"
}

# MODX Revolution (https://github.com/modxcms/revolution): a tag is installed
# from the release archive it points at (the archive ships core/packages/core
# unpacked, hence inplace=1 unpacked=1); a branch is cloned and built with
# the project's own transport build (_build/transport.core.php), which packs
# core/packages/core.transport.zip for the installer to unpack. MODX writes
# the absolute core path into its four config files; the FPM container
# mounts the volume at /var/www/html, so those are rewritten from the setup
# path after the install.

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
    <unpacked>$unpacked</unpacked>
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
  wanted=$(wanted_versions modx)
  site_current "$site" "$wanted" && return
  reset_site "$site"
  create_database benchmark_modx
  if [ "$(version_kind "$wanted" modx)" = tag ]; then
    # Tags are v3.2.4-pl; the archive is named by the bare number.
    release=$(version_value "$wanted" modx | sed 's/^v//; s/-pl$//')
    echo "modx: release archive $release"
    curl -fsSL -o /tmp/modx.zip "https://modx.s3.amazonaws.com/releases/$release/modx-$release-pl.zip"
    rm -rf /tmp/modx-unpack
    unzip -q /tmp/modx.zip -d /tmp/modx-unpack
    cp -R "/tmp/modx-unpack/modx-$release-pl/." "$site/"
    rm -rf /tmp/modx.zip /tmp/modx-unpack
    unpacked=1
  else
    branch=$(version_value "$wanted" modx)
    if [ "$(version_kind "$wanted" modx)" = path ]; then
      echo "modx: source directory $branch (transport build)"
      cp -R "$branch/." "$site/"
    else
      echo "modx: branch $branch (transport build)"
      git clone --depth=1 --branch "$branch" https://github.com/modxcms/revolution.git "$site"
    fi
    cd "$site"
    composer install --no-dev --no-interaction --no-progress
    cp -n _build/build.properties.sample.php _build/build.properties.php
    cp -n _build/build.config.sample.php _build/build.config.php
    php _build/transport.core.php
    unpacked=0
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
  printf '%s\n' "$wanted" > "$site/.phramark-versions"
}

sync_modx() {
  site=/sites/modx
  [ -f "$site/.phramark-versions" ] || return 0
  cd "$site"
  copy_modx_adapter
  php /opt/phramark/benchmark/fixtures/cms/modx-seed.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/modx-admin-seed.php "$DB_HOST" benchmark_modx "$DB_USER" "$DB_PASSWORD"
  rm -rf core/cache/*
  chown -R www-data:www-data "$site"
}

# WordPress with the Gantry 5 framework plugin and its Hydrogen theme (one
# stack: the framework is only ever measured through a Gantry theme). Core
# comes from wordpress.org through WP-CLI for a tag and from the
# WordPress/WordPress build mirror for a branch; the plugin and theme from
# the Gantry release archives on GitHub (a Gantry branch is not installable:
# the archives are assembled by the project's build); the Classic Editor
# plugin from wordpress.org for a tag and from its git branch otherwise, so
# the admin workload edits a page body in a plain textarea like the Drupal,
# Evolution and Winter fixtures. The site URL follows the
# request's Host header, because the load generator and the browser reach
# the stack as nginx-wordpress-gantry:80 while verification uses
# 127.0.0.1:8088; loopback and external HTTP (update checks, feeds) and the
# cron spawn are off so no timed step waits on the internet.
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
  wanted=$(wanted_versions wordpress gantry classic-editor)
  site_current "$site" "$wanted" && return
  if [ "$(version_kind "$wanted" gantry)" != tag ]; then
    echo "gantry: only release tags can be installed (the WordPress packages are assembled by Gantry's build), not branch $(version_value "$wanted" gantry)" >&2
    exit 1
  fi
  reset_site "$site"
  create_database benchmark_wordpress
  cd "$site"
  if [ "$(version_kind "$wanted" wordpress)" = tag ]; then
    echo "wordpress: release $(version_value "$wanted" wordpress)"
    wp core download --version="$(version_value "$wanted" wordpress)" --skip-content
  elif [ "$(version_kind "$wanted" wordpress)" = path ]; then
    echo "wordpress: source directory $(version_value "$wanted" wordpress)"
    cp -R "$(version_value "$wanted" wordpress)/." "$site/"
  else
    echo "wordpress: branch $(version_value "$wanted" wordpress)"
    git clone --depth=1 --branch "$(version_value "$wanted" wordpress)" https://github.com/WordPress/WordPress.git "$site"
    rm -rf "$site/.git"
  fi
  write_wordpress_config
  wp core install --url=http://127.0.0.1:8088 --title=Phramark --admin_user=benchmark --admin_password="$WORDPRESS_ADMIN_PASSWORD" --admin_email=benchmark@example.test --skip-email
  gantry=$(version_value "$wanted" gantry | sed 's/^v//')
  wp plugin install "https://github.com/gantry/gantry5/releases/download/$gantry/wordpress-pkg_gantry5_v$gantry.zip" --force --activate
  wp theme install "https://github.com/gantry/gantry5/releases/download/$gantry/wordpress-tpl_g5_hydrogen_v$gantry.zip" --force --activate
  if [ "$(version_value "$wanted" classic-editor)" = latest ]; then
    wp plugin install classic-editor --force --activate
  elif [ "$(version_kind "$wanted" classic-editor)" = tag ]; then
    wp plugin install classic-editor --version="$(version_value "$wanted" classic-editor)" --force --activate
  elif [ "$(version_kind "$wanted" classic-editor)" = path ]; then
    rm -rf wp-content/plugins/classic-editor
    mkdir -p wp-content/plugins/classic-editor
    cp -R "$(version_value "$wanted" classic-editor)/." wp-content/plugins/classic-editor/
    wp plugin activate classic-editor
  else
    rm -rf wp-content/plugins/classic-editor
    git clone --depth=1 --branch "$(version_value "$wanted" classic-editor)" https://github.com/WordPress/classic-editor.git wp-content/plugins/classic-editor
    wp plugin activate classic-editor
  fi
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
  printf '%s\n' "$wanted" > "$site/.phramark-versions"
}

sync_wordpress_gantry() {
  site=/sites/wordpress-gantry
  [ -f "$site/.phramark-versions" ] || return 0
  cd "$site"
  write_wordpress_config
  copy_wordpress_adapter
  php /opt/phramark/benchmark/fixtures/cms/wordpress-seed.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  php /opt/phramark/benchmark/fixtures/cms/wordpress-admin-seed.php "$DB_HOST" benchmark_wordpress "$DB_USER" "$DB_PASSWORD"
  chown -R www-data:www-data "$site"
}

# One site, in a process of its own (so set -e still aborts inside it).
if [ $# -gt 0 ]; then
  "$1"
  exit
fi

required=${PHRAMARK_STACKS:-drupal typo3 winter modx wordpress-gantry}
failed=
for stack in drupal typo3 winter modx wordpress-gantry; do
  if ! sh "$0" "install_$(echo "$stack" | tr '-' '_')"; then
    echo "$stack: not installed" >&2
    failed="$failed $stack"
  fi
done
sync_drupal
sync_typo3
sync_winter
sync_modx
sync_wordpress_gantry
for stack in $failed; do
  case " $required " in
    *" $stack "*) echo "$stack is required by this run and could not be installed" >&2; exit 1 ;;
  esac
done
