# Instalación en Raspberry Pi

[← Volver a Guías de Instalación](Getting-Started-installations) | [Inicio](Home)

---

Esta guía cubre la instalación en Raspberry Pi o dispositivos similares basados en ARM (Orange Pi, etc.).

## Inicio Rápido con Docker (Recomendado)

Docker es la forma más fácil de ejecutar en Raspberry Pi, ya que ahora hay disponibles imágenes arm64/aarch64.

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

Accede en: `http://your-pi-ip:8080`

Credenciales por defecto: `admin` / `pointofsale`

## Instalación LAMP Estándar

Raspberry Pi OS está basado en Debian, por lo que la instalación es similar a Ubuntu/Debian. Sigue la [Guía de Instalación de Ubuntu 24.04/22.04](Ubuntu-24.04-22.04-Installation).

### Comandos Rápidos

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

Descarga la última versión desde [GitHub Releases](https://github.com/opensourcepos/opensourcepos/releases) y extráela en `/var/www/html/`.

> **Nota:** Para las versiones actuales, la base de datos se crea automáticamente en la primera ejecución.

## Stack LEMP (Nginx)

Si prefieres Nginx en lugar de Apache, sigue la [Guía de Despliegue LEMP](Local-Deployment-using-LEMP).

## Consejos de Rendimiento

Para un mejor rendimiento en Raspberry Pi:

1. **Usa una tarjeta SD rápida** - Clase 10 o superior
2. **Aumenta el archivo swap** - Ayuda con operaciones que consumen mucha memoria
   ```bash
   sudo dphys-swapfile swapoff
   sudo nano /etc/dphys-swapfile
   # Set CONF_SWAPSIZE=2048
   sudo dphys-swapfile setup
   sudo dphys-swapfile swapon
   ```
3. **Deshabilita servicios innecesarios** - Libera recursos
4. **Usa Docker** - Generalmente más ligero que un stack LAMP completo

## Recomendaciones de Hardware

| Modelo de Pi | RAM | Rendimiento |
|----------|-----|-------------|
| Raspberry Pi 4 | 4GB+ | Recomendado para producción |
| Raspberry Pi 4 | 2GB | Funciona bien para uso ligero |
| Raspberry Pi 3 B+ | 1GB | Funcional pero más lento |
| Raspberry Pi Zero W | 512MB | No recomendado |

## Ver También

- [Guía de Instalación de Ubuntu](Ubuntu-24.04-22.04-Installation)
- [Guía de Instalación LEMP](Local-Deployment-using-LEMP)
- [Configuración de Docker](Getting-Started-installations#local-docker-install)
- [Requisitos Mínimos del Servidor](Minimum-Server-Requirements)
