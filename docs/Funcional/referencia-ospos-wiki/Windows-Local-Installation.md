
[← Back to Installation Guides](Getting-Started-installations) | [Home](Home)

---

This guide covers installation on Windows for local testing only. For production use, a proper server is recommended.

> **Note:** For current releases (stable and unstable), the database is created automatically on first run. You do NOT need to import `app/Database/database.sql` manually.

## Quick Steps

1. Install XAMPP with PHP 8.1+ from [Apache Friends](https://www.apachefriends.org/)
2. Start Apache and MySQL from XAMPP Control Panel
3. Create database in phpMyAdmin
4. Download latest release and extract to `htdocs`
5. Configure `.env` with database credentials
6. Browse to `http://localhost/opensourcepos/public`

For detailed XAMPP installation, see [XAMPP Installation Guide](XAMPP-Installation).