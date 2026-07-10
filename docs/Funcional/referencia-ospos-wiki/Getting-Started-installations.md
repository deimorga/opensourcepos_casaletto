
[← Back to Home](Home) | [Minimum Requirements](Minimum-Server-Requirements) | [Usage Guide](Getting-Started-usage)

---

## Table of Contents

- [Quick Start](#quick-start)
- [Server Requirements](#server-requirements)
- [Installation Methods](#installation-methods)
  - [Local Install (Unix/Linux)](#local-deploy-install-for-unixlinux-environments)
  - [Docker Install](#local-docker-install)
  - [Cloud Install (DigitalOcean)](#cloud-deploy-installation)
- [Other Installation Guides](#other-installation-guides)
- [Post-Installation](#post-installation)

---

## Quick Start

**The fastest way to try:**

1. Download the latest release from [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases)
2. Set up a LAMP/LEMP stack with PHP 8.1+
3. Configure `.env` with your database credentials
4. Browse to `http://localhost/public`
5. Login with `admin` / `pointofsale`

> **Note:** For current releases (stable and unstable), the database is created automatically on first run. You do NOT need to import database.sql manually.

---

## Server Requirements

Before installing, ensure your server meets the [Minimum Server Requirements](Minimum-Server-Requirements).

### Summary

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.1 | 8.3 - 8.4 |
| MySQL/MariaDB | 5.7 / 10.x | 8.0 / 10.5+ |
| Apache | 2.4 | 2.4 |
| Web Browser | Firefox 34+, Chrome 40+ | Modern browser |

⚠️ **PHP ≤ 7.4 is NOT supported**

---

## Installation Methods

### Local Deploy Install for Unix/Linux Environments

#### Step 1: Open Terminal

Open a terminal window. On macOS, find it at `Finder → Accessories → Terminal`. On Linux, find it at `Menu → System Tools → Terminal`. For commands requiring root access, use `sudo su`.

#### Step 2: Install Dependencies

Install Apache2, MariaDB/MySQL, PHP, and required extensions:

**Debian/Ubuntu:**
```bash
sudo apt-get install apache2 mariadb-server
sudo apt-get install php-curl php-mysql php-gd php-intl php-mbstring php-xml php-bcmath
sudo a2enmod rewrite
```

**RHEL/CentOS/Fedora:**
```bash
sudo yum install httpd mysql-server php php-bcmath php-dba php-gd php-mbstring php-xml
```

> **Note:** PHP 8.1 to 8.4 is required. PHP 7.4 and below is NOT supported.

#### Step 3: Prepare Web Directory

Navigate to your web root:
```bash
cd /var/www/html
```

#### Step 4: Download

Download the latest release from [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases) and extract it to your web directory.

#### Step 5: Create Database

```bash
mysql -u root -e "CREATE SCHEMA ospos;"
mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%' IDENTIFIED BY 'pointofsale' WITH GRANT OPTION;"
mysql -u root -e "FLUSH PRIVILEGES;"
```

#### Step 6: Import Database Schema (Older Versions Only)

> **Important:** For current releases (stable and unstable), the database is created automatically on first run. **This step is only required for older versions.**

```bash
mysql -u admin -ppointofsale -D ospos < /var/www/html/app/Database/database.sql
```

#### Step 7: Configure Database Connection

Copy `.env.example` to `.env` and configure:
```ini
database.default.hostname = 'localhost'
database.default.database = 'ospos'
database.default.username = 'admin'
database.default.password = 'pointofsale'
```

#### Step 8: Set Permissions

```bash
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 750 /var/www/html/writable
```

#### Step 9: Access the application

Open your browser and navigate to:
- `http://localhost/public` or
- `http://127.0.0.1/public`

#### Step 10: Login

- **Username:** `admin`
- **Password:** `pointofsale`

---

### Local Docker Install

#### Step 1: Install Docker

Install Docker following the [official Docker documentation](https://docs.docker.com/get-docker/) for your platform.

#### Step 2: Prepare Directory

```bash
mkdir ~/osposdocker
cd ~/osposdocker
```

#### Step 3: Download and Extract

Download the latest release from [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases) and extract to your directory.

#### Step 4: Build and Run

```bash
docker-compose build
docker-compose up
```

> **Warning:** The default Docker setup is NOT suited for production. Change default passwords before exposing publicly.

---

### Cloud Deploy Installation

For cloud hosting, we recommend [**DigitalOcean**](https://m.do.co/c/ac38c262507b) where you can get a **$100 free credit** while supporting the project!

#### Step 1: Create Account

Create a [DigitalOcean account](https://m.do.co/c/ac38c262507b).

#### Step 2: Create Droplet

Choose a Debian/Ubuntu Droplet with LAMP or One-Click app.

#### Step 3: Connect to Server

```bash
ssh root@<your-droplet-ip>
```

#### Step 4: Install Dependencies

```bash
a2enmod rewrite
apt-get install php-intl php-mbstring php-xml php-bcmath php-curl php-gd
service apache2 restart
```

> **Note:** Ensure PHP 8.1+ is installed. PHP 7.4 and below is NOT supported.

#### Step 5: Download

Download the latest release from [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases).

#### Step 6: Create Database

```bash
mysql -u root -e "CREATE SCHEMA ospos;"
mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%';"
mysql -u root -e "FLUSH PRIVILEGES;"
```

#### Step 7: Import Database (Older Versions Only)

> **Important:** For current releases (stable and unstable), the database is created automatically on first run. **This step is only required for older versions.**

```bash
mysql -u admin -ppointofsale -D ospos < /var/www/html/app/Database/database.sql
```

#### Step 8: Access the application

Open your browser and navigate to: `http://<your-droplet-ip>/public`

#### Step 9: Login

- **Username:** `admin`
- **Password:** `pointofsale`

---

## Post-Installation

After successful installation:

1. Change the admin password immediately
2. Configure your store settings in **Store Config**
3. Set up employees and permissions
4. Add your inventory items
5. Configure taxes and payment methods

See [Getting Started Usage](Getting-Started-usage) for detailed configuration.

---

## Other Installation Guides

- [Local Deployment using LEMP (Nginx)](Local-Deployment-using-LEMP)
- [Local Deployment using MAMP for Windows](Local-Deployment-using-MAMP-for-Windows)
- [Local Deployment using XAMPP](XAMPP-Installation)
- [Raspberry Pi Guide](Raspberry-Pi-Installation)
- [Ubuntu 24.04/22.04 Install](Ubuntu-24.04-22.04-Installation)

---

## Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| "system folder missing" | Download a release build, not source code. Or run `npm install` and build. |
| Login error | Check database credentials in `.env` file |
| Blank page | Check PHP error logs, enable `display_errors` in development |
| Permission errors | Run `chmod -R 750 writable` and `chown -R www-data:www-data writable` |

For more help, see [FAQ in README](https://github.com/opensourcepos/opensourcepos#faq) or [create an issue](https://github.com/opensourcepos/opensourcepos/issues/new).

---

## See Also

- [Minimum Server Requirements](Minimum-Server-Requirements)
- [Getting Started Usage](Getting-Started-usage)
- [Development Index](Development-Index)
- [Hardware Support](Supported-hardware-datasheet)

---

_If you like the project and are making money out of it, consider [buying us a coffee](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)._