
[← Volver a Guías de Instalación](Getting-Started-installations) | [Inicio](Home)

---

Esta página enumera los requisitos mínimos y recomendados del servidor para ejecutar Open Source Point of Sale.

## Requisitos de PHP

| Requisito | Mínimo | Recomendado |
|-------------|---------|-------------|
| Versión de PHP | 8.2 | 8.3 - 8.4 |
| PHP ≤ 8.1 | NO compatible | Usa PHP 8.2+ |

### Extensiones de PHP Requeridas

Las siguientes extensiones de PHP deben estar instaladas y habilitadas:

| Extensión | Propósito |
|-----------|---------|
| `php-json` | Manejo de datos JSON |
| `php-gd` | Procesamiento de imágenes |
| `php-bcmath` | Matemáticas de precisión arbitraria |
| `php-intl` | Soporte de internacionalización |
| `php-openssl` | Cifrado SSL/TLS |
| `php-mbstring` | Soporte de cadenas multibyte |
| `php-curl` | Solicitudes HTTP |
| `php-xml` | Procesamiento de XML |

## Requisitos de Base de Datos

| Base de Datos | Mínimo | Recomendado |
|----------|---------|-------------|
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.0 | 10.6+ |

**Nota:** Percona Server también es compatible como reemplazo de MySQL.

## Requisitos de Servidor Web

| Servidor Web | Estado |
|------------|--------|
| Apache | 2.4+ (oficialmente compatible) |
| Nginx | Funciona bien (consulta la [guía de despliegue LEMP](Local-Deployment-using-LEMP)) |
| IIS | Puede funcionar (consulta la [guía de despliegue IIS](IIS-Deployment)) |
| Lighttpd | Puede funcionar (compatible con soporte comunitario) |

## Requisitos de Hardware

| Plataforma | Notas |
|----------|-------|
| PC/Mac | Cualquier hardware moderno |
| Raspberry Pi | Compatible con los modelos 3 y 4 |
| Nube/VM | DigitalOcean, AWS, etc. |
| Android | Posible con la configuración adecuada |

### Especificaciones Mínimas de Hardware

- **RAM:** 512MB mínimo, 1GB+ recomendado
- **Almacenamiento:** 1GB para la aplicación, adicional para la base de datos
- **CPU:** Procesador con capacidad de 64 bits

## Requisitos del Navegador (Lado del Cliente)

| Navegador | Versión Mínima | Estado |
|---------|-----------------|--------|
| Firefox / Pale Moon | 34+ (ESR) | ✅ Recomendado |
| Chrome / Chromium | 40+ | ✅ Compatible |
| Safari, Edge | Moderno | ⚠️ Puede funcionar |
| Otros | - | ❌ No compatible |

---

## Ver También

- [Primeros Pasos - Instalación](Getting-Started-installations)
- [Índice de Desarrollo](Development-Index)
- [Instalación con Docker](INSTALL.md#local-install-using-docker)

---

_Si te gusta el proyecto y estás generando dinero con él, considera [invitarnos un café](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)._
