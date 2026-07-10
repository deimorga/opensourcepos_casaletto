
[← Volver a Guías de Instalación](Getting-Started-installations) | [Inicio](Home)

---

Esta guía cubre la instalación en Windows solo para pruebas locales. Para uso en producción, se recomienda un servidor adecuado.

> **Nota:** Para las versiones actuales (estables e inestables), la base de datos se crea automáticamente en la primera ejecución. NO necesitas importar `app/Database/database.sql` manualmente.

## Pasos Rápidos

1. Instala XAMPP con PHP 8.1+ desde [Apache Friends](https://www.apachefriends.org/)
2. Inicia Apache y MySQL desde el Panel de Control de XAMPP
3. Crea la base de datos en phpMyAdmin
4. Descarga la última versión y extráela en `htdocs`
5. Configura `.env` con las credenciales de la base de datos
6. Navega a `http://localhost/opensourcepos/public`

Para una instalación detallada de XAMPP, consulta la [Guía de Instalación de XAMPP](XAMPP-Installation).
