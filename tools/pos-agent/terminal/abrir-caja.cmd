@echo off
rem PUNTO DE VENTA, a mano. Solo para el acceso directo del escritorio: si
rem alguien cierra el quiosco, esto lo vuelve a abrir sin reiniciar el equipo.
rem
rem En el arranque NO se usa: la tarea PuntoDeVenta lanza Chrome directo, y la
rem espera de 40 s vive en el disparador. Hasta el 2026-10-19 la tarea corria
rem este archivo con un "timeout /t 40" y dejaba 40 segundos una ventana negra
rem con la cuenta regresiva, que los cajeros cerraban.
rem
rem instalar.ps1 reemplaza NEGOCIO por la direccion del negocio.
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing --user-data-dir="C:\POS\perfil-chrome" --disable-features=TranslateUI --no-first-run https://NEGOCIO.ospos-saas.micronuba.net
