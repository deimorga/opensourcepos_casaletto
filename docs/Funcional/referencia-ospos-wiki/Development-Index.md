
[← Volver a Inicio](Home) | [Instalación](Getting-Started-installations) | [Registro de Errores](Error-Logging)

---

## Tabla de Contenidos

- [Resumen](#overview)
- [Requisitos del Servidor](#server-requirements)
- [Arquitectura](#architecture)
  - [Patrón MVC](#mvc-pattern)
  - [Controladores](#controllers)
  - [Modelos](#models)
  - [Filtros](#filters)
- [Configuración del Entorno de Desarrollo](#development-setup)
- [Contribuir](#contributing)
  - [Flujo de trabajo](#workflow-contributions)
  - [Estilo de Código](#code-style)
- [Consejos de Desarrollo](#development-tips)

---

## Resumen

Open Source Point of Sale es una aplicación de código abierto evolucionada a partir de [PHP Point Of Sale](https://github.com/daN4cat/PHP-Point-Of-Sale). [![GitHub version](https://badge.fury.io/gh/jekkos%2Fopensourcepos.svg)](https://badge.fury.io/gh/jekkos%2Fopensourcepos)

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/svg-badge.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

### Cómo Contribuir

- **Reportar errores:** [Crea un issue](https://github.com/opensourcepos/opensourcepos/issues/new)
- **Traducir:** [Weblate](https://translate.opensourcepos.org)
- **Código:** Lee esta página, luego envía pull requests

---

## Requisitos del Servidor

Para más detalles, consulta [Requisitos Mínimos del Servidor](Minimum-Server-Requirements).

### Tabla Resumen

| Componente | Mínimo | Recomendado | Notas |
|-----------|---------|-------------|-------|
| **Servidor Web** | Apache 2.4 | Apache 2.4 | Nginx también funciona |
| **Base de Datos** | MySQL 5.7 / MariaDB 10.x | MySQL 8.0 / MariaDB 10.5+ | Compatible con Percona |
| **PHP** | 8.1 | 8.3 - 8.4 | PHP ≤7.4 NO está soportado |
| **Hardware** | Capaz de 64 bits | Moderno | Raspberry Pi soportado |

### Extensiones de PHP Requeridas

| Extensión | Propósito |
|-----------|---------|
| `php-json` | Manejo de datos JSON |
| `php-gd` | Procesamiento de imágenes |
| `php-bcmath` | Matemática de precisión arbitraria |
| `php-intl` | Internacionalización |
| `php-openssl` | Cifrado SSL/TLS |
| `php-mbstring` | Soporte de cadenas multibyte |
| `php-curl` | Solicitudes HTTP |
| `php-xml` | Procesamiento de XML |

### Requisitos del Lado del Cliente

| Navegador | Versión Mínima | Estado |
|---------|-----------------|--------|
| Firefox / Pale Moon | 34+ (ESR) | ✅ Recomendado |
| Chrome / Chromium | 40+ | ✅ Soportado |
| Safari / Edge | Moderno | ⚠️ Podría funcionar |
| Otros | - | ❌ No soportado |

---

## Arquitectura

La aplicación está construida sobre el framework **CodeIgniter 4**. Lee la [documentación de CodeIgniter 4](https://codeigniter4.github.io/userguide4/) como referencia del framework.

Tecnologías adicionales usadas:
- **jQuery** - Librería JavaScript
- **Bootstrap 3** - Framework CSS con temas Bootswatch

### Estructura de Directorios

```
opensourcepos/
├── app/
│   ├── Config/        # Archivos de configuración
│   ├── Controllers/   # Controladores (MVC)
│   ├── Models/        # Modelos (MVC)
│   ├── Views/         # Vistas (MVC)
│   ├── Helpers/       # Funciones helper
│   ├── Libraries/     # Librerías personalizadas
│   ├── Language/      # Archivos de traducción
│   ├── Database/      # Esquema de base de datos
│   └── Filters/       # Filtros de solicitud
├── public/            # Raíz web
├── writable/          # Logs, caché, subidas
├── tests/             # Pruebas PHPUnit
└── vendor/            # Dependencias de Composer
```

### Patrón MVC

La aplicación sigue el patrón Modelo-Vista-Controlador (MVC) gestionado por CodeIgniter 4.

- Los **Controladores** manejan las solicitudes HTTP y la lógica de negocio
- Los **Modelos** interactúan con la base de datos
- Las **Vistas** renderizan la salida HTML

Lee la [documentación de MVC de CodeIgniter 4](https://codeigniter4.github.io/userguide4/incoming/controllers.html) para más detalles.

### Controladores

Controladores clave:

| Controlador | Propósito |
|------------|---------|
| `BaseController` | Clase base para controladores autenticados |
| `Login` | Maneja la autenticación de usuario |
| `Home` | Panel principal y funcionalidad general |
| `Sales` | Operaciones de venta del POS |
| `Items` | Gestión de inventario |
| `Employees` | Gestión de empleados/usuarios |
| `Reports` | Funcionalidad de reportes |

Cada controlador tiene un directorio de vistas correspondiente en `app/Views/`.

### Modelos

Todos los modelos se cargan automáticamente. Ver `app/Config/Autoload.php` para el orden de carga.

### Filtros

La aplicación usa filtros de CodeIgniter 4 para:

- **Sesión/Autenticación** - Verificar el usuario con sesión iniciada
- **Carga de configuración** - Hacer disponible la configuración de forma global
- **Registro de base de datos** - Registro opcional de consultas SQL

---

## Configuración del Entorno de Desarrollo

### Prerrequisitos

1. Instalar `git`, `npm`, `composer`, `apache`, `mysql`, `php` (8.1+)
2. Entender los conceptos de solicitud/respuesta web
3. Leer la [documentación de CodeIgniter 4](https://codeigniter4.github.io/userguide4/)

### Pasos

```bash
# 1. Clonar el repositorio
git clone https://github.com/opensourcepos/opensourcepos.git
cd opensourcepos

# 2. Instalar dependencias
composer install
npm install

# 3. Configurar el entorno
cp .env.example .env
# Edita .env con tus credenciales de base de datos

# 4. (Solo versiones antiguas) Importar base de datos
# Nota: Para versiones actuales, la base de datos se crea automáticamente en la primera ejecución
mysql -u root -p ospos < app/Database/database.sql

# 5. Ejecutar el servidor de desarrollo
php spark serve
```

Para instrucciones de configuración detalladas, consulta [Entorno de Desarrollo](Development-Environment).

---

## Contribuir

### Flujo de trabajo para Contribuciones

1. Haz un **Fork** del repositorio en GitHub
2. **Crea una rama** para tus cambios
3. **Realiza tus cambios** siguiendo las pautas de estilo de código
4. **Prueba a fondo**
5. **Envía un pull request** al repositorio `opensourcepos/opensourcepos`

![Pull Request Workflow](https://s3.amazonaws.com/github-images/blog/2012/easy-pull-request-creation.png)

### Estilo de Código

- Sigue los estándares de codificación [PSR-12](https://www.php-fig.org/psr/psr-12/)
- Usa la [guía de estilo de CodeIgniter 4](https://codeigniter4.github.io/userguide4/extending/styleguide.html)
- Usa nombres de variables significativos
- Agrega comentarios PHPDoc adecuados
- **Siempre usa traducciones** (ver abajo)

---

## Consejos de Desarrollo

### Obtener Información del Usuario Actual

```php
// Obtener el objeto del empleado con sesión iniciada
$employee_object = $this->Employee->get_logged_in_employee_info();

// Obtener el ID del empleado
$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;

// Recomendado: Guardar en una variable para evitar múltiples llamadas
$employee = $this->Employee->get_logged_in_employee_info();
$employee_id = $employee->person_id;
```

### Enlaces de Diálogo Modal

Usa la clase CSS `modal-dlg` para abrir enlaces en diálogos modales:

```php
echo anchor(
    'home/change_password/'.$user_info->person_id,
    $user_info->first_name,
    array(
        'class' => 'modal-dlg',
        'data-btn-submit' => 'Submit',
        'title' => 'Password'
    )
);
```

### Siempre Usa Traducciones

**Nunca escribas cadenas de texto de forma fija (hardcode).** Siempre usa las funciones de traducción:

```php
// MAL
echo "Change Password";

// BIEN
echo $this->lang->line('employees_change_password');

// En vistas con anchor()
echo anchor(
    'home/change_password/'.$user_info->person_id,
    $user_info->first_name,
    array(
        'class' => 'modal-dlg',
        'data-btn-submit' => $this->lang->line('employees_save'),
        'title' => $this->lang->line('employees_change_password')
    )
);
```

Los archivos de traducción se encuentran en:
- `app/Language/en/Employees.php` (Inglés)
- `app/Language/es/Employees.php` (Español)
- etc.

---

## Especificaciones Técnicas

Documentación detallada para características específicas:

| Tema | Enlace |
|-------|------|
| Localización | [Soporte de Localización](Localisation-support) |
| Menú y Permisos | [Menú y Permisos](Menu-and-Permissions) |
| Ventas | [Ventas](Sales) |
| Impuestos | [Impuestos](Taxes) |
| Órdenes de Trabajo | [Órdenes de Trabajo](Work-Orders) |
| Artículos | [Artículos](Items) |
| Kits de Artículos | [Kits de Artículos](Item-Kits) |
| Compras | [Compras](Purchasing) |

---

## Ver También

- [Configuración del Entorno de Desarrollo](Development-Environment)
- [Registro de Errores](Error-Logging)
- [Agregar Traducciones](Adding-translations)
- [Requisitos Mínimos del Servidor](Minimum-Server-Requirements)
- [Primeros Pasos - Instalación](Getting-Started-installations)

---

_Si te gusta el proyecto y estás generando dinero con él, considera [invitarnos un café](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)._