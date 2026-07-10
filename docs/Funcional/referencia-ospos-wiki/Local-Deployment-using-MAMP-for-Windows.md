
Download and Install [MAMP](https://tracker.iplocation.net/jaxn/) then go to C:\MAMP\htdocs and place the extracted folder of masterpos there. Now go to localhost/MAMP and then go to phpMyAdmin and create a database with any name and create a user with all privileges for the created Database. Now import the database.sql  file from your database folder of your pos directory and click Go. Database is created. Now go to config folder and change database.php.tmpl to database.php and set the database name, username and password for the database you created through phpMyAdmin.    

Go to your browser and write localhost and select your project from the list. OSPOS will be loaded. Give the the username and password which is "admin" and "pointofsale" respectively.   
 
# ISSUES

Go to Sales Module and if your UI is blocked then you have to do some extra work. Run your MAMP application which is most probably already running. Click on "Preferences", now go to php tab and check which php version is loaded in your MAMP by clicking on dropdown list. Select a php version with 5.6.** (e.g. 5.6.13 or 5.6.24).    

if none of 5.6.** version is available in the list then follow the instruction below
Goto C:\MAMP\bin\php and rename all the non-required php folder to some other name. (e.g. rename php5.4.1 to x_php5.4.1). only 2 folders from here will show in your php dropdown at MAMP. 

Now go to C:\MAMP\bin\php\php5.6.24\ and copy all the icu**53.dll files from here and paste them to C:\MAMP\bin\apache\bin. 

Now go to C:\MAMP\conf\php5.6.24 and open php.ini file and find ;extension=php_intl.dll and remove the semicolon in the start and make sure bcmath is not commented out as well. If it doesn't work please read [issue 1607](https://github.com/opensourcepos/opensourcepos/issues/1607) for a detailed list of extensions.

Restart your MAMP and run the ospos again. It should work now. Enjoy. :) 

