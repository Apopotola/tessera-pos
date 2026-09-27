#!/bin/sh
# Rebuild the user-guide sample shop ("Karen Wines & Spirits") in its own database.
# Local only. PHP 8.4 comes from TESSERA_PHP (default: php on the PATH).
set -e
cd "$(dirname "$0")/../../backend"
export APP_ENV=local DB_DATABASE="${GUIDE_DB:-tessera_pos_guide}" QUEUE_CONNECTION=sync MPESA_DRIVER=fake ETIMS_DRIVER=fake
PHP="${TESSERA_PHP:-php}"
"$PHP" artisan migrate:fresh --seed --force --no-ansi > /dev/null
"$PHP" artisan db:seed --class=DemoShowcaseSeeder --force --no-ansi
"$PHP" artisan db:seed --class=UserGuideSeeder --force --no-ansi
