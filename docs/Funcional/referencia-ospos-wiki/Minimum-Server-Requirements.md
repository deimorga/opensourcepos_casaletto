
[← Back to Installation Guides](Getting-Started-installations) | [Home](Home)

---

This page lists the minimum and recommended server requirements for running Open Source Point of Sale.

## PHP Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| PHP Version | 8.2 | 8.3 - 8.4 |
| PHP ≤ 8.1 | NOT supported | Use PHP 8.2+ |

### Required PHP Extensions

The following PHP extensions must be installed and enabled:

| Extension | Purpose |
|-----------|---------|
| `php-json` | JSON data handling |
| `php-gd` | Image processing |
| `php-bcmath` | Arbitrary precision mathematics |
| `php-intl` | Internationalization support |
| `php-openssl` | SSL/TLS encryption |
| `php-mbstring` | Multibyte string support |
| `php-curl` | HTTP requests |
| `php-xml` | XML processing |

## Database Requirements

| Database | Minimum | Recommended |
|----------|---------|-------------|
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.0 | 10.6+ |

**Note:** Percona Server is also compatible as a MySQL replacement.

## Web Server Requirements

| Web Server | Status |
|------------|--------|
| Apache | 2.4+ (officially supported) |
| Nginx | Works fine (see [LEMP deployment guide](Local-Deployment-using-LEMP)) |
| IIS | May work (see [IIS deployment guide](IIS-Deployment)) |
| Lighttpd | May work (community supported) |

## Hardware Requirements

| Platform | Notes |
|----------|-------|
| PC/Mac | Any modern hardware |
| Raspberry Pi | Models 3 and 4 supported |
| Cloud/VM | DigitalOcean, AWS, etc. |
| Android | Possible with appropriate setup |

### Minimum Hardware Specs

- **RAM:** 512MB minimum, 1GB+ recommended
- **Storage:** 1GB for application, additional for database
- **CPU:** 64-bit capable processor

## Browser Requirements (Client Side)

| Browser | Minimum Version | Status |
|---------|-----------------|--------|
| Firefox / Pale Moon | 34+ (ESR) | ✅ Recommended |
| Chrome / Chromium | 40+ | ✅ Supported |
| Safari, Edge | Modern | ⚠️ May work |
| Others | - | ❌ Not supported |

---

## See Also

- [Getting Started - Installation](Getting-Started-installations)
- [Development Index](Development-Index)
- [Docker Installation](INSTALL.md#local-install-using-docker)

---

_If you like the project and are making money out of it, consider [buying us a coffee](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)._