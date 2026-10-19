@echo off
rem 3 - SSH APAGAR: detiene el acceso remoto Y lo deja deshabilitado, no solo
rem parado, para que nada lo vuelva a levantar hasta la proxima visita.
net stop sshd
sc config sshd start= disabled >nul
echo.
echo Acceso remoto APAGADO.
echo.
pause
