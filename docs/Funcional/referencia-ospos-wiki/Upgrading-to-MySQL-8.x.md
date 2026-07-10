
[← Back to Configuration](Configuration) | [Home](Home)

---

Version 3.4 and later support MySQL 8.x.

## Prerequisites

If you are starting with a clean install, ensure the version of MySQL you want is installed and ready to use.

> **Important:** If you are upgrading from an existing site using MySQL 5.6 or 5.7, first upgrade your existing site without changing your MySQL version.

The migration will make the necessary database changes needed for MySQL 8.x compatibility.

## Upgrade Steps

1. Save your database.

2. Export your database to an SQL script.

3. Install the new version of MySQL 8.x

4. If you were doing an "in place" upgrade then you should be okay. See [MySQL In-Place Upgrade](https://dev.mysql.com/blog-archive/inplace-upgrade-from-mysql-5-7-to-mysql-8-0/) for details.

5. If you are not doing "in place" (i.e. running multiple development/test environments), then import your data from the export script created in step 2.
