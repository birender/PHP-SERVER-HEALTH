# A zero-dependency PHP CLI tool for Linux server health monitoring and performance tuning

Lightweight Linux server health checker in PHP 8.0+. No dependencies.

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

## Tuning mode (`--tune`)
Reads this server's RAM, CPU cores and the memory of running PHP-FPM/Apache workers, compares your configs
against what the hardware can handle, and prints exact values to change. It never edits any file.

    sudo php-server-health --tune                 # FPM pools + php.ini + Apache
    sudo php-server-health --tune --only fpm      # just /etc/php/*/fpm/pool.d/*.conf
    sudo php-server-health --tune --only apache
    sudo php-server-health --tune -f json         # machine-readable

Run it as root (accurate PSS memory numbers, readable logs) and ideally while the site is busy.

What it checks
- **PHP-FPM pool**: pm.max_children (RAM budget / real worker size), spare servers, max_requests,
  request_terminate_timeout, slowlog, listen address, and "reached pm.max_children" in the FPM log.
- **php.ini (FPM)**: OPcache sizing, expose_php, display_errors. Suggestions go to a conf.d/99-tuning.ini drop-in.
- **Apache**: MPM choice (prefork -> event when PHP-FPM is used), MaxRequestWorkers/ServerLimit from RAM,
  KeepAlive, Timeout, ServerTokens, deflate/expires/headers/proxy_fcgi modules. Suggestions go to a zz-tuning.conf drop-in.

Tuning knobs in /etc/php-server-health.json:

    "tuning": { "reserve_percent": 30, "fpm_share": 0.8, "max_children_cap": 500 }

`reserve_percent` is RAM kept for the OS, database and everything else; raise it if MySQL runs on the same host.

Exit codes with --tune: 0 nothing to change, 1 changes suggested, 2 risky setting found, 3 usage error.
