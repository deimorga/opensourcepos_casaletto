# Raspberry Pi Installation

[← Back to Installation Guides](Getting-Started-installations) | [Home](Home)

---

This guide covers installing on Raspberry Pi or similar ARM-based devices (Orange Pi, etc.).

## Quick Start with Docker (Recommended)

Docker is the easiest way to run on Raspberry Pi since arm64/aarch64 images are now available.

```bash
# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add non-root user to docker group (optional)
sudo usermod -aG docker $USER

# Clone/download OSPOS
git clone https://github.com/opensourcepos/opensourcepos.git
cd opensourcepos

# Copy environment file
cp .env.example .env

# Edit .env with your database credentials
nano .env

# Run with Docker Compose
docker-compose up -d
```

Access at: `http://your-pi-ip:8080`

Default credentials: `admin` / `pointofsale`

## Standard LAMP Installation

Raspberry Pi OS is based on Debian, so installation is similar to Ubuntu/Debian. Follow the [Ubuntu 24.04/22.04 Installation Guide](Ubuntu-24.04-22.04-Installation).

### Quick Commands

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install LAMP stack
sudo apt install apache2 mariadb-server php php-curl php-mysql php-gd \
    php-intl php-bcmath php-mbstring php-xml php-mysqli libapache2-mod-php

# Enable rewrite module
sudo a2enmod rewrite
sudo systemctl restart apache2

# Set permissions
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 750 /var/www/html/writable

# Create database
sudo mysql -u root -e "CREATE SCHEMA ospos;"
sudo mysql -u root -e "CREATE USER 'admin'@'%' IDENTIFIED BY 'pointofsale';"
sudo mysql -u root -e "GRANT ALL PRIVILEGES ON ospos.* TO 'admin'@'%';"
sudo mysql -u root -e "FLUSH PRIVILEGES;"
```

Download the latest release from [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases) and extract to `/var/www/html/`.

> **Note:** For current releases, the database is created automatically on first run.

## LEMP Stack (Nginx)

If you prefer Nginx over Apache, follow the [LEMP Deployment Guide](Local-Deployment-using-LEMP).

## Performance Tips

For better performance on Raspberry Pi:

1. **Use a fast SD card** - Class 10 or better
2. **Increase swap file** - Helps with memory-intensive operations
   ```bash
   sudo dphys-swapfile swapoff
   sudo nano /etc/dphys-swapfile
   # Set CONF_SWAPSIZE=2048
   sudo dphys-swapfile setup
   sudo dphys-swapfile swapon
   ```
3. **Disable unnecessary services** - Free up resources
4. **Use Docker** - Generally more lightweight than full LAMP stack

## Hardware Recommendations

| Pi Model | RAM | Performance |
|----------|-----|-------------|
| Raspberry Pi 4 | 4GB+ | Recommended for production |
| Raspberry Pi 4 | 2GB | Works well for light use |
| Raspberry Pi 3 B+ | 1GB | Functional but slower |
| Raspberry Pi Zero W | 512MB | Not recommended |

## See Also

- [Ubuntu Installation Guide](Ubuntu-24.04-22.04-Installation)
- [LEMP Installation Guide](Local-Deployment-using-LEMP)
- [Docker Setup](Getting-Started-installations#local-docker-install)
- [Minimum Server Requirements](Minimum-Server-Requirements)