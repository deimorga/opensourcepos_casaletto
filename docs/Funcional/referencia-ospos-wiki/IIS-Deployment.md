
[← Back to Installation Guides](Getting-Started-installations) | [Home](Home)

---

This guide covers deployment on Windows Server Internet Information Services (IIS). 

After some trial and error, I succeeded in making it work and have some simple steps for the next person. 

1. Point your webroot folder to "/ospos_path/public" directly 
2. install the url_rewrite module in iis manager
3. import the .htaccess to make a web.config file with the rewrite rules
4. everything else can be followed in the install.md

Everything works exactly as it should, took me longer than i would like to admit to figure that out. 
Keep up the good work everyone! 


The following is my web.config file in my /public/ directory if anyone wants to edit/add it in to the github.


`<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="Imported Rule 1" stopProcessing="true">
                    <match url="^(.*)$" ignoreCase="false" />
                    <conditions logicalGrouping="MatchAll">
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" ignoreCase="false" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" ignoreCase="false" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.php?/{R:1}" appendQueryString="false" />
                </rule>
                <rule name="Imported Rule 1-1" stopProcessing="true">
                    <match url="^(.*)$" ignoreCase="false" />
                    <conditions logicalGrouping="MatchAll">
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" ignoreCase="false" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" ignoreCase="false" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="/index.php?/{R:1}" appendQueryString="false" />
                </rule>
            </rules>
        </rewrite>
    </system.webServer>
</configuration>`