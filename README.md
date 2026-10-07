# php-server-health

Lightweight Linux server health checker in PHP 8.1+. No dependencies.

Checks: uptime, load (per core), memory/swap, disk usage, PHP version/extensions/OPcache, systemd services.

## Install
**Script:** `sudo ./install.sh [--with-timer]`
**Composer:** `composer require yourvendor/php-server-health` then `vendor/bin/php-server-health`

## Usage
    php-server-health                    # human-readable
    php-server-health -f json            # machine-readable
    php-server-health -c my.json         # custom config

Exit codes: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN/error (Nagios-style), so it works in cron, CI, or monitoring.

## Config
Copy `config/health.example.json` to `/etc/php-server-health.json`. Any key you omit keeps its default.

## Cron example
    */5 * * * * /usr/local/bin/php-server-health -f json >> /var/log/php-server-health.log
