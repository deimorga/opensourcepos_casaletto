
[← Volver a Guías de Instalación](Getting-Started-installations) | [Inicio](Home)

---

Esta guía cubre el despliegue en Windows Server Internet Information Services (IIS).

Después de un poco de prueba y error, logré hacerlo funcionar y tengo algunos pasos simples para la próxima persona.

1. Apunta tu carpeta webroot directamente a "/ospos_path/public"
2. instala el módulo url_rewrite en el administrador de IIS
3. importa el .htaccess para crear un archivo web.config con las reglas de reescritura
4. todo lo demás se puede seguir en el install.md

Todo funciona exactamente como debería, me tomó más tiempo del que me gustaría admitir descubrirlo.
¡Sigan con el buen trabajo, a todos!


A continuación está mi archivo web.config en mi directorio /public/ por si alguien quiere editarlo/agregarlo al github.


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
