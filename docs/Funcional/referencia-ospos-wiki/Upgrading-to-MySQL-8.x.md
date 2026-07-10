
[← Volver a Configuración](Configuration) | [Inicio](Home)

---

La versión 3.4 y posteriores son compatibles con MySQL 8.x.

## Requisitos Previos

Si estás empezando con una instalación limpia, asegúrate de que la versión de MySQL que deseas esté instalada y lista para usar.

> **Importante:** Si estás actualizando desde un sitio existente que usa MySQL 5.6 o 5.7, primero actualiza tu sitio existente sin cambiar la versión de MySQL.

La migración realizará los cambios necesarios en la base de datos requeridos para la compatibilidad con MySQL 8.x.

## Pasos de Actualización

1. Respalda tu base de datos.

2. Exporta tu base de datos a un script SQL.

3. Instala la nueva versión de MySQL 8.x

4. Si estabas realizando una actualización "in place" (en el mismo lugar), entonces deberías estar bien. Consulta [MySQL In-Place Upgrade](https://dev.mysql.com/blog-archive/inplace-upgrade-from-mysql-5-7-to-mysql-8-0/) para más detalles.

5. Si no estás haciendo una actualización "in place" (es decir, ejecutas múltiples entornos de desarrollo/prueba), entonces importa tus datos desde el script de exportación creado en el paso 2.
