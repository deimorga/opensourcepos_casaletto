
[← Back to Development Index](Development-Index)

---

Logging is not activated by default. There are two places to check for errors: **server side** and **client side**.

## Table of Contents

- [Client Side Error Log](#error-log-on-client-side)
- [Server Side Error Log](#error-log-on-server-side)
  - [Application Error Log](#application-error-log)
  - [Web Server Error Log](#webserver-error-log)

## Error Log on Client Side

Depending on the browser, this applies to Firefox/Pale Moon and Chrome/Chromium.

1. Before loading the page with an issue, press the F12 key
2. The screen will split in two parts - the second part is the web browser developer tool
3. Click on the "Network" tab in the developer tool
4. All web traffic will be displayed, showing each request and response
5. Each request will have a line entry, same for each response

![hot F12 at the web browser and see the new split window](https://github.com/venenux/osposos/raw/master/debianOspos/screenshot-ospos-devel-f12-client-log-error.png)

## Error Log on Server Side

Here are various components in action: the application log (disabled by default) and the system components log (Apache log that includes PHP error log, database error log).

### Application Error Log

It's disabled by default. In CodeIgniter 4, logging can be configured in `app/Config/App.php` or via the `.env` file. Set the `CI_ENVIRONMENT` variable to `development` for debugging or `production` for production use.

The log threshold can be configured via the `logger.threshold` setting in `.env` (0 = disabled, up to 9 = all messages). For reporting and submitting issues, please set to `4` or higher and attach only relevant parts.

The default location for log files is `writable/logs/`. Make sure the `writable` directory has proper write permissions for the web server user (e.g., `www-data`).

> **Important:** Check directory permissions. The web server user must have write access to `writable/logs/`. If permissions are incorrect, log files will not be created.

#### Database SQL Logging

Database query logging can be enabled in `app/Config/Database.php` or via the `.env` file. The logs will be written to `writable/logs/` along with application logs.
  
### Web Server Error Log

In standard distributions, a general error log file that registers PHP script processing errors is always present.

- On standard Linux installations: `/var/log/<webserver>/error.log`
- On Apache2 binary installations: `${INSTALL_DIR}/logs/error.log`
- On Nginx/Lighttpd binary installations: `${INSTALL_DIR}/logs/error.log`

For example, you can customize this in the Apache configuration file (`apache2.conf` or `http.conf`):

```
ErrorLog "${INSTALL_DIR}/logs/apache_error.log"
CustomLog "${INSTALL_DIR}/logs/access.log" common
```

Refer to your web server documentation for customization options.

#### PHP Errors

If the error is due to application code issues, you might need to activate the PHP error log.

There are two aspects: identifying where the log is created, and establishing what should be written to the log. The application also provides the option to display errors directly in the web page, which is good for debugging but not recommended for production (security reasons). To display PHP errors in your page, set `CI_ENVIRONMENT = development` in your `.env` file.

The PHP configuration file (`php.ini`) controls error logging. The `php.ini` file is typically found in the PHP installation folder (see [PHP documentation](http://php.net/manual/en/configuration.file.php)).

Modern PHP installations set the error log path to empty, so it's managed by the web server. Consult the PHP documentation for your specific distribution.