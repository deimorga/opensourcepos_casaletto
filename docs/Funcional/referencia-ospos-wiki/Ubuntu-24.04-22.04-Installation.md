
[← Back to Installation Guides](Getting-Started-installations) | [Home](Home)

---

This guide covers installation on:
- Ubuntu 24.04 LTS
- Ubuntu 22.04 LTS
- Linux Mint 21.3

⚠️ **PHP 8.1 to 8.4 is required.** PHP 7.4 and below is NOT supported.

---

## Step 1: Install LAMP Stack

Add PHP repository and install required packages:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt-get install -y apache2 mariadb-server php php-curl php-mysql php-gd php-intl php-bcmath php-mbstring php-xml php-mysqli
```

Enable required modules:

```bash
sudo a2enmod rewrite
sudo phpenmod intl gd bcmath curl
```

---

## Step 2: Configure Apache

Edit the Apache configuration:

```bash
sudo nano /etc/apache2/apache2.conf
```

Find the `<Directory /var/www/>` section and change `AllowOverride` to `All`:

```apache
<Directory /var/www/>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Restart Apache:

```bash
sudo systemctl restart apache2
```

Remove default page:

```bash
sudo rm /var/www/html/index.html
```

---

## Step 3: Create Database

Create the database and user:

```bash
sudo mysql -u root -e "CREATE SCHEMA ospos;"
sudo mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
sudo mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%';"
sudo mysql -u root -e "FLUSH PRIVILEGES;"
```

---

## Step 4: Download and Install

1. Download the latest release from [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases)
2. Rename the downloaded file to `ospos.zip`
3. Extract to the web directory:

```bash
sudo unzip ~/Downloads/ospos.zip -d /var/www/html/
```

4. Set ownership:

```bash
sudo chown -R www-data:www-data /var/www/html
```

---

## Step 5: Import Database (Older Versions Only)

⚠️ **Important:** For current releases (stable and unstable), the database is created automatically on first run. **You do NOT need to import database.sql manually.**

This step is only required for older versions:

```bash
mysql -u admin -ppointofsale -D ospos < /var/www/html/app/Database/database.sql
```

---

## Step 6: Set Permissions

Secure the writable directories:

```bash
sudo chmod 750 /var/www/html/writable/logs
sudo chmod 750 /var/www/html/writable/uploads
sudo chmod 750 /var/www/html/writable/cache
sudo chmod 750 /var/www/html/public/uploads/item_pics
sudo chown -R www-data:www-data /var/www/html/writable
```

---

## Step 7: Access the Application

Open your browser and navigate to:

```
http://localhost/public
```

**Default credentials:**
- Username: `admin`
- Password: `pointofsale`

The application will upgrade the database on first login, then redirect you to log in again.

---

## Optional: Email Setup

For email functionality, see [Email Configuration](Email-Configuration).

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Blank page | Check `writable/logs/` for errors, ensure permissions are correct |
| Database error | Verify database credentials in `.env` file |
| Permission denied | Run `sudo chown -R www-data:www-data /var/www/html` |
| PHP extensions missing | Run `sudo apt-get install php-curl php-gd php-intl php-bcmath php-mbstring php-xml` |

---

## See Also

- [Minimum Server Requirements](Minimum-Server-Requirements)
- [Getting Started - Usage](Getting-Started-usage)
- [Troubleshooting Guide](Error-Logging)