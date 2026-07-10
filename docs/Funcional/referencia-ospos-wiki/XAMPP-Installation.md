**NOTE: This guide is for local testing only. For production use, a proper LAMP/LEMP stack is recommended.**

**OSPOS requires PHP 8.1 or higher. Make sure your XAMPP version includes PHP 8.1+.**

## Installation Steps

1. Download and install XAMPP with PHP 8.1+ from https://www.apachefriends.org/download.html

2. Launch the XAMPP Control Panel and start Apache and MySQL.

3. From Apache select Config and edit the Php.ini file to enable the following extensions:
   
   - `extension=gd` - for image processing
   - `extension=intl` - for internationalization
   - `extension=bcmath` - for arbitrary precision math
   - `extension=mbstring` - for multibyte strings
   - `extension=curl` - for HTTP requests
   - `extension=xml` - for XML processing
   - `extension=mysqli` - for MySQL database connections

4. Download the latest OSPOS release from https://github.com/opensourcepos/opensourcepos/releases

5. If using Windows, right-click the downloaded zip file, select Properties, and click Unblock if the button is present. Then extract the file.

6. Place the extracted folder into the `htdocs` directory.

7. In XAMPP Control Panel go to MySQL - Admin to open phpMyAdmin.

8. Create a new database named `ospos` (or your preferred name).

9. **(Older versions only)** Import database schema:
   
   > **Note:** For current releases (stable and unstable), the database is created automatically on first run. This step is only needed for older versions.
   
   Select the database, go to Import, and browse to locate `app/Database/database.sql`. Select it for import.

10. Configure the database connection by copying `.env.example` to `.env` and editing the following lines:
    
    ```
    database.default.hostname = 'localhost'
    database.default.database = 'ospos'
    database.default.username = 'root'
    database.default.password = ''    # default XAMPP has no password
    ```

11. Set proper permissions on the `writable` directory. On Windows, this is usually not required.

12. Go to `http://localhost/opensourcepos/public` (replace `opensourcepos` with your folder name) to see the login screen.

13. Default credentials:
    - Username: `admin`
    - Password: `pointofsale`

## Troubleshooting

- If you get a "system folder missing" error, make sure you downloaded a release build, not the source code. Or run `npm install` and build the project.
- If extensions aren't loading, restart Apache after modifying php.ini.
- For detailed extension requirements, see [issue 1607](https://github.com/opensourcepos/opensourcepos/issues/1607).

## WAMP Users

If you are using WAMP instead of XAMPP, follow similar steps but ensure the ICU extension files are copied to the correct locations for intl support.