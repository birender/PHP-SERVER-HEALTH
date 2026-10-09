# 🩺 PHP Server Health System

> **Lightweight Linux server health monitoring and performance tuning tool written in pure PHP.**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Linux](https://img.shields.io/badge/Linux-Supported-E95420?style=flat-square&logo=linux&logoColor=white)](https://www.linux.org/)
[![CLI](https://img.shields.io/badge/Interface-CLI-blue?style=flat-square)](#usage)
[![License](https://img.shields.io/badge/License-GPL--3.0-blue?style=flat-square)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Active-success?style=flat-square)](#roadmap)

**PHP Server Health** is a command-line tool for checking Linux server health, diagnosing PHP application infrastructure, and generating performance-tuning recommendations.

It is designed for developers and system administrators running **PHP, PHP-FPM, Apache, MySQL, and Linux servers**.

---

## 🚀 Why PHP Server Health?

Running a PHP application in production often requires checking multiple components:

```text
Linux Server
    │
    ├── CPU
    ├── Memory
    ├── Swap
    ├── Disk
    ├── Load Average
    │
    ├── PHP
    │   └── OPcache
    │
    ├── PHP-FPM
    │   └── Worker utilization
    │
    ├── Apache
    │   └── MPM configuration
    │
    └── systemd services
```

Instead of manually running many Linux commands, PHP Server Health provides a single CLI interface.

---

# ✨ Features

### 🖥️ System Health

- CPU information
- CPU load average
- Memory usage
- Swap usage
- Disk usage
- Server uptime
- Operating system information
- Kernel information

### 🐘 PHP

- PHP version
- PHP CLI configuration
- Installed PHP extensions
- PHP configuration
- OPcache status
- OPcache configuration

### ⚡ PHP-FPM

- PHP-FPM service status
- PHP-FPM configuration
- Worker configuration
- `pm.max_children`
- `pm.start_servers`
- `pm.min_spare_servers`
- `pm.max_spare_servers`
- Worker memory usage
- PHP-FPM tuning recommendations

### 🌐 Apache

- Apache service status
- Apache version
- MPM configuration
- `MaxRequestWorkers`
- `ThreadsPerChild`
- `ServerLimit`
- Apache tuning recommendations

### 🔧 Performance Tuning

The `--tune` option analyzes the server and provides recommendations based on:

- Available RAM
- CPU cores
- PHP-FPM worker memory
- Current PHP-FPM configuration
- Apache configuration
- OPcache configuration

The tool **does not automatically modify your configuration files**.

---

# 📦 Requirements

### Operating System

Linux is currently supported.

Tested primarily with:

- Ubuntu
- Debian-based Linux distributions

### PHP

```text
PHP 8.0+
```

Check your PHP version:

```bash
php -v
```

### Required PHP Extensions

The tool is designed to keep external dependencies to a minimum.

---

# 📥 Installation

## Option 1 — Clone the Repository

```bash
git clone https://github.com/birender/PHP-SERVER-HEALTH.git
```

Enter the project directory:

```bash
cd PHP-SERVER-HEALTH
```

Make the CLI executable:

```bash
chmod +x bin/php-server-health
```

Run:

```bash
./bin/php-server-health
```

---

## Option 2 — Installation Script

If you want to install the command globally:

```bash
sudo ./install.sh
```

After installation:

```bash
php-server-health
```

Check:

```bash
php-server-health --help
```

---

# ▶️ Usage

Run the standard health check:

```bash
php-server-health
```

Example:

```text
PHP Server Health
────────────────────────────────────────

System
────────────────────────────────────────
OS              : Ubuntu 24.04
Kernel          : 6.x
CPU Cores       : 16
Load Average    : 2.14
Memory          : 8.2 GB / 62 GB
Swap            : 0.5 GB / 8 GB
Disk            : 42%

PHP
────────────────────────────────────────
PHP Version     : 8.3.x
OPcache         : Enabled

PHP-FPM
────────────────────────────────────────
Service         : Running
Workers         : 64
Average RSS     : 34 MB
Max RSS         : 42 MB

Apache
────────────────────────────────────────
Service         : Running
MPM             : event
Max Workers     : 64

Status
────────────────────────────────────────
System          : 🟢 HEALTHY
PHP             : 🟢 HEALTHY
PHP-FPM         : 🟡 REVIEW
Apache          : 🟢 HEALTHY
```

---

# 🔧 Performance Tuning

One of the main features of PHP Server Health is the tuning engine.

Run:

```bash
sudo php-server-health --tune
```

The tool analyzes your server resources and current configuration.

Example:

```text
PHP-FPM Tuning
────────────────────────────────────────

CPU Cores                 : 16
Available RAM             : 60 GB
Average Worker RSS        : 34 MB

Current Configuration
────────────────────────────────────────
pm.max_children          : 64
pm.start_servers         : 16
pm.min_spare_servers     : 12
pm.max_spare_servers     : 32

Recommended Configuration
────────────────────────────────────────
pm.max_children          : 120
pm.start_servers         : 24
pm.min_spare_servers     : 16
pm.max_spare_servers     : 48

Recommendation
────────────────────────────────────────
🟢 PHP-FPM has sufficient memory available.
🟡 Consider increasing pm.max_children for higher concurrency.
```

### Important

The tuning command provides **recommendations only**.

It does not automatically change:

```text
php-fpm configuration
Apache configuration
PHP configuration
system configuration
```

You remain in control of all production changes.

---

# 📊 Health Levels

The tool can classify server conditions using simple health indicators.

| Status | Meaning |
|---|---|
| 🟢 Healthy | Resource/configuration is within expected range |
| 🟡 Warning | Review recommended |
| 🔴 Critical | Immediate investigation recommended |

---

# 🧠 Tuning Philosophy

PHP Server Health does not blindly recommend very large worker counts.

For example:

```text
Available RAM
      ↓
Average PHP-FPM Worker RSS
      ↓
Expected Worker Capacity
      ↓
CPU Capacity
      ↓
Current Configuration
      ↓
Recommended Configuration
```

The goal is to find a practical balance between:

- CPU
- RAM
- PHP-FPM workers
- Apache workers
- Application concurrency
- System stability

More workers do **not** always mean better performance.

---

# 🛡️ Safety

PHP Server Health is designed as a **diagnostic and recommendation tool**.

The tuning engine does not automatically overwrite production configuration.

Before applying any recommendation, always:

1. Review the recommendation.
2. Backup your configuration.
3. Apply changes gradually.
4. Restart/reload the affected service.
5. Monitor the server afterward.

Example:

```bash
sudo cp /etc/php/8.3/fpm/pool.d/www.conf \
        /etc/php/8.3/fpm/pool.d/www.conf.backup
```

Then validate the configuration before restarting PHP-FPM.

---

# 📁 Project Structure

```text
PHP-SERVER-HEALTH/
│
├── bin/
│   └── php-server-health
│
├── config/
│
├── src/
│   ├── System/
│   ├── PHP/
│   ├── PHPFPM/
│   ├── Apache/
│   └── ...
│
├── systemd/
│
├── install.sh
├── composer.json
├── LICENSE
└── README.md
```

The project is structured to allow additional health checks and monitoring modules to be added independently.

---

# 🔌 Architecture

The project follows a modular architecture.

```text
                    ┌─────────────────────┐
                    │ php-server-health   │
                    │        CLI          │
                    └──────────┬──────────┘
                               │
          ┌────────────────────┼────────────────────┐
          │                    │                    │
          ▼                    ▼                    ▼
     System Check          PHP Check          Service Check
          │                    │                    │
          ▼                    ▼                    ▼
       CPU/RAM             PHP/OPcache       PHP-FPM/Apache
       Disk/Load           Extensions        systemd
          │                    │                    │
          └────────────────────┼────────────────────┘
                               │
                               ▼
                       Health Analyzer
                               │
                               ▼
                       Tuning Engine
                               │
                               ▼
                         Recommendations
```

---

# 🧪 Development

Clone the repository:

```bash
git clone https://github.com/birender/PHP-SERVER-HEALTH.git
cd PHP-SERVER-HEALTH
```

Install dependencies:

```bash
composer install
```

Run:

```bash
php bin/php-server-health
```

---

# 🖥️ Supported Environment

The project is primarily designed for Linux production servers running PHP applications.

Typical environments include:

```text
Ubuntu
Debian
Apache
PHP
PHP-FPM
MySQL
systemd
```

---

# 🗺️ Roadmap

The project is actively evolving.

### Current

- [x] CPU monitoring
- [x] Memory monitoring
- [x] Disk monitoring
- [x] Load monitoring
- [x] PHP detection
- [x] OPcache detection
- [x] PHP-FPM analysis
- [x] Apache analysis
- [x] systemd service checks
- [x] Performance tuning recommendations

### Planned

- [ ] MySQL health analysis
- [ ] MySQL connection monitoring
- [ ] MySQL slow-query analysis
- [ ] Network monitoring
- [ ] TCP connection analysis
- [ ] Port monitoring
- [ ] JSON output
- [ ] Machine-readable output
- [ ] Health score
- [ ] Historical metrics
- [ ] Alerting
- [ ] Email notifications
- [ ] Web dashboard
- [ ] Docker support
- [ ] CI/CD integration
- [ ] Automated benchmark mode

---

# 💡 Example Use Cases

### Before deploying a PHP application

```bash
php-server-health
```

Check whether the server has sufficient:

- CPU
- RAM
- disk space
- PHP configuration
- PHP-FPM capacity

### Troubleshooting high PHP-FPM usage

```bash
sudo php-server-health --tune
```

Review:

```text
Worker count
Average RSS
Maximum RSS
Available RAM
pm.max_children
pm.start_servers
pm.min_spare_servers
pm.max_spare_servers
```

### Production server audit

Run the tool periodically to identify:

```text
High CPU
High memory usage
Low disk space
Swap pressure
PHP-FPM saturation
Apache worker limitations
OPcache problems
Service failures
```

---

# 🤝 Contributing

Contributions are welcome.

You can contribute by:

- Reporting bugs
- Suggesting new health checks
- Improving Linux compatibility
- Adding tests
- Improving documentation
- Adding monitoring modules
- Improving tuning algorithms

Fork the repository, create a feature branch, and submit a pull request.

---

# 🐛 Issues

If you find a problem, please open an issue with:

```text
Operating System:
PHP Version:
PHP-FPM Version:
Apache Version:
Command Used:
Expected Result:
Actual Result:
Error Output:
```

This makes troubleshooting significantly easier.

---

# 📜 License

This project is licensed under the **GNU General Public License v3.0**.

See [LICENSE](LICENSE) for details.

---

# 👨‍💻 Author

**Birender Rana**

Senior Developer | PHP | SQL | Linux | Backend Systems | Performance Optimization

GitHub:

https://github.com/birender

Project:

https://github.com/birender/PHP-SERVER-HEALTH

---

## ⭐ Support the Project

If you find **PHP Server Health** useful:

⭐ Star the repository

🐛 Report issues

💡 Suggest improvements

🤝 Contribute code

---

> **PHP Server Health — Know your server before your server tells you there is a problem.**
