
[← Back to Home](Home) | [Installation](Getting-Started-installations) | [Error Logging](Error-Logging)

---

## Table of Contents

- [Overview](#overview)
- [Server Requirements](#server-requirements)
- [Architecture](#architecture)
  - [MVC Pattern](#mvc-pattern)
  - [Controllers](#controllers)
  - [Models](#models)
  - [Filters](#filters)
- [Development Setup](#development-setup)
- [Contributing](#contributing)
  - [Workflow](#workflow-contributions)
  - [Code Style](#code-style)
- [Development Tips](#development-tips)

---

## Overview

Open Source Point of Sale is an open source application evolved from [PHP Point Of Sale](https://github.com/daN4cat/PHP-Point-Of-Sale). [![GitHub version](https://badge.fury.io/gh/jekkos%2Fopensourcepos.svg)](https://badge.fury.io/gh/jekkos%2Fopensourcepos)

[![Translation status](https://translate.opensourcepos.org/widgets/opensourcepos/-/svg-badge.svg)](https://translate.opensourcepos.org/engage/opensourcepos/?utm_source=widget)

### How to Contribute

- **Report bugs:** [Create an issue](https://github.com/opensourcepos/opensourcepos/issues/new)
- **Translate:** [Weblate](https://translate.opensourcepos.org)
- **Code:** Read this page, then submit pull requests

---

## Server Requirements

For full details, see [Minimum Server Requirements](Minimum-Server-Requirements).

### Summary Table

| Component | Minimum | Recommended | Notes |
|-----------|---------|-------------|-------|
| **Web Server** | Apache 2.4 | Apache 2.4 | Nginx also works |
| **Database** | MySQL 5.7 / MariaDB 10.x | MySQL 8.0 / MariaDB 10.5+ | Percona compatible |
| **PHP** | 8.1 | 8.3 - 8.4 | PHP ≤7.4 NOT supported |
| **Hardware** | 64-bit capable | Modern | Raspberry Pi supported |

### Required PHP Extensions

| Extension | Purpose |
|-----------|---------|
| `php-json` | JSON data handling |
| `php-gd` | Image processing |
| `php-bcmath` | Arbitrary precision math |
| `php-intl` | Internationalization |
| `php-openssl` | SSL/TLS encryption |
| `php-mbstring` | Multibyte string support |
| `php-curl` | HTTP requests |
| `php-xml` | XML processing |

### Client Side Requirements

| Browser | Minimum Version | Status |
|---------|-----------------|--------|
| Firefox / Pale Moon | 34+ (ESR) | ✅ Recommended |
| Chrome / Chromium | 40+ | ✅ Supported |
| Safari / Edge | Modern | ⚠️ May work |
| Others | - | ❌ Not supported |

---

## Architecture

The application is built on **CodeIgniter 4** framework. Read the [CodeIgniter 4 documentation](https://codeigniter4.github.io/userguide4/) for framework reference.

Additional technologies used:
- **jQuery** - JavaScript library
- **Bootstrap 3** - CSS framework with Bootswatch themes

### Directory Structure

```
opensourcepos/
├── app/
│   ├── Config/        # Configuration files
│   ├── Controllers/   # Controllers (MVC)
│   ├── Models/        # Models (MVC)
│   ├── Views/         # Views (MVC)
│   ├── Helpers/       # Helper functions
│   ├── Libraries/     # Custom libraries
│   ├── Language/      # Translation files
│   ├── Database/      # Database schema
│   └── Filters/       # Request filters
├── public/            # Web root
├── writable/          # Logs, cache, uploads
├── tests/             # PHPUnit tests
└── vendor/            # Composer dependencies
```

### MVC Pattern

The application follows the Model-View-Controller (MVC) pattern managed by CodeIgniter 4.

- **Controllers** handle HTTP requests and business logic
- **Models** interact with the database
- **Views** render HTML output

Read the [CodeIgniter 4 MVC documentation](https://codeigniter4.github.io/userguide4/incoming/controllers.html) for details.

### Controllers

Key controllers:

| Controller | Purpose |
|------------|---------|
| `BaseController` | Base class for authenticated controllers |
| `Login` | Handles user authentication |
| `Home` | Dashboard and main functionality |
| `Sales` | POS sales operations |
| `Items` | Inventory management |
| `Employees` | Employee/user management |
| `Reports` | Reporting functionality |

Each controller has a corresponding view directory in `app/Views/`.

### Models

All models are loaded automatically. See `app/Config/Autoload.php` for load order.

### Filters

The application uses CodeIgniter 4 filters for:

- **Session/Authentication** - Verify logged-in user
- **Configuration loading** - Make config available globally
- **Database logging** - Optional SQL query logging

---

## Development Setup

### Prerequisites

1. Install `git`, `npm`, `composer`, `apache`, `mysql`, `php` (8.1+)
2. Understand web request/response concepts
3. Read the [CodeIgniter 4 documentation](https://codeigniter4.github.io/userguide4/)

### Steps

```bash
# 1. Clone the repository
git clone https://github.com/opensourcepos/opensourcepos.git
cd opensourcepos

# 2. Install dependencies
composer install
npm install

# 3. Configure environment
cp .env.example .env
# Edit .env with your database credentials

# 4. (Older versions only) Import database
# Note: For current releases, database is created automatically on first run
mysql -u root -p ospos < app/Database/database.sql

# 5. Run development server
php spark serve
```

For detailed setup instructions, see [Development Environment](Development-Environment).

---

## Contributing

### Workflow Contributions

1. **Fork** the repository on GitHub
2. **Create a branch** for your changes
3. **Make your changes** following code style guidelines
4. **Test thoroughly**
5. **Submit a pull request** to the `opensourcepos/opensourcepos` repository

![Pull Request Workflow](https://s3.amazonaws.com/github-images/blog/2012/easy-pull-request-creation.png)

### Code Style

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Use [CodeIgniter 4 style guide](https://codeigniter4.github.io/userguide4/extending/styleguide.html)
- Use meaningful variable names
- Add proper PHPDoc comments
- **Always use translations** (see below)

---

## Development Tips

### Get Current User Information

```php
// Get logged-in employee object
$employee_object = $this->Employee->get_logged_in_employee_info();

// Get employee ID
$employee_id = $this->Employee->get_logged_in_employee_info()->person_id;

// Recommended: Store in variable to avoid multiple calls
$employee = $this->Employee->get_logged_in_employee_info();
$employee_id = $employee->person_id;
```

### Modal Dialog Links

Use the `modal-dlg` CSS class to open links in modal dialogs:

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

### Always Use Translations

**Never hardcode strings.** Always use translation functions:

```php
// BAD
echo "Change Password";

// GOOD
echo $this->lang->line('employees_change_password');

// In views with anchor()
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

Translation files are located in:
- `app/Language/en/Employees.php` (English)
- `app/Language/es/Employees.php` (Spanish)
- etc.

---

## Technical Specifications

Detailed documentation for specific features:

| Topic | Link |
|-------|------|
| Localisation | [Localisation Support](Localisation-support) |
| Menu and Permissions | [Menu and Permissions](Menu-and-Permissions) |
| Sales | [Sales](Sales) |
| Taxes | [Taxes](Taxes) |
| Work Orders | [Work Orders](Work-Orders) |
| Items | [Items](Items) |
| Item Kits | [Item Kits](Item-Kits) |
| Purchasing | [Purchasing](Purchasing) |

---

## See Also

- [Development Environment Setup](Development-Environment)
- [Error Logging](Error-Logging)
- [Adding Translations](Adding-translations)
- [Minimum Server Requirements](Minimum-Server-Requirements)
- [Getting Started - Installation](Getting-Started-installations)

---

_If you like the project and are making money out of it, consider [buying us a coffee](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MUN6AEG7NY6H8)._