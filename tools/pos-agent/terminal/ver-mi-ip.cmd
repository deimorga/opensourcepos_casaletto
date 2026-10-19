@echo off
rem 1 - VER MI IP: direccion de este equipo en la red del negocio, y si el
rem acceso remoto esta encendido. No cambia nada.
echo Equipo: %COMPUTERNAME%
echo.
ipconfig | findstr /i "IPv4"
echo.
sc query sshd | findstr /i "ESTADO STATE"
echo.
pause
