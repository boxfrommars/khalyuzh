#!/usr/bin/env sh

set -eu

php bin/migrate.php
exec php -S 0.0.0.0:8000 -t public bin/router.php
