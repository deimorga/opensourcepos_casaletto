@echo off
rem 2 - SSH ENCENDER: enciende el acceso remoto SOLO mientras alguien trabaja.
rem Necesita administrador (el acceso directo lo pide). Se apaga con 3 - SSH APAGAR.
rem Solo entra quien tenga la llave: la contrasena esta cerrada en sshd_config.
sc config sshd start= demand >nul
net start sshd
echo.
echo Acceso remoto ENCENDIDO. Direccion de esta terminal:
ipconfig | findstr /i "IPv4"
echo.
pause
