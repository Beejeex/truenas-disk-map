#!/bin/bash
# entrypoint.sh
#
# Starts the auto-refresh daemon in the background, then launches
# Apache in the foreground. No host cron needed.
#
# The daemon calls scheduler.php every 60 seconds, which checks
# the UI-configured interval and triggers a refresh when due.

set -e

echo "[entrypoint] Starting scheduler daemon..."
php /var/www/html/daemon.php &

echo "[entrypoint] Starting Apache..."
exec apachectl -D FOREGROUND
