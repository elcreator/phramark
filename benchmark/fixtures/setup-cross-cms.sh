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

install_drupal
install_typo3
sync_drupal
sync_typo3
