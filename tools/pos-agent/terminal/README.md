# terminal — cómo se deja una caja

Lo que hay que hacerle a un Windows para que sea una caja: el agente, las tareas
de arranque, Chrome, la impresora, la energía y el acceso de soporte. **No hay
nada de esto en el código del POS**: todo vive en el equipo del cliente, y por
eso se versiona aquí, para que la caja siguiente no dependa de que alguien se
acuerde.

Es la configuración que quedó funcionando en Paraíso de la Canasta el
2026-10-19, después de dos visitas. El porqué de cada pieza está en
`docs/Tecnico/venta-por-peso-y-hardware-de-caja.md`, §7b-bis.

| Archivo | Para qué |
|---|---|
| `instalar.ps1` | Deja la caja lista. Idempotente: se puede volver a correr |
| `verificar.ps1` | Revisa todo punto por punto, **sin cambiar nada**. Es también el diagnóstico |
| `config.ejemplo.json` | De donde sale `config.json` del agente cuando no existe |
| `arreglar-puertos.ps1` | Reapunta la cola de la impresora al USB donde está conectada |
| `abrir-caja.cmd` | El acceso directo «PUNTO DE VENTA», para reabrir el quiosco a mano |
| `ver-mi-ip.cmd`, `ssh-encender.cmd`, `ssh-apagar.cmd` | Los tres accesos directos de soporte |

## Antes del script, a mano

Esto no se automatiza, porque cada cosa depende del aparato o de una decisión:

1. **Windows 10/11 Pro, activado**, con la cuenta con la que va a entrar la caja.
   Esa cuenta es administradora y entra sola. En Paraíso, la cuenta `BTS` no
   tiene contraseña y por eso entra sola. Eso solo es aceptable porque el SSH
   no acepta contraseñas (lo cierra el script).
2. **Google Chrome**.
3. **Driver de la impresora** (XP-58IIT: `POS-58-Setup.exe`). La cola debe
   llamarse `POS-58-Series`, o se pasa `-Impresora` con el nombre que tenga.
4. **Driver de la báscula** si va por USB-serie (CH340: `CH341SER`).
5. **El agente compilado**, según `tools/pos-agent/README.md` → *Compilar*.
6. **La llave pública de soporte** (`.pub`). La privada **nunca** va al equipo.

Y en el POS, no en la terminal: **Configuración → Recibo → papel 58 mm**, y el
ajuste del cajón (*solo con efectivo*).

## Instalar

En la terminal, PowerShell **como administrador**, desde la cuenta de la caja:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\instalar.ps1 `
  -Url https://NEGOCIO.ospos-saas.micronuba.net `
  -Agente .\pos-agent.exe `
  -LlavePublica .\soporte.pub
```

Termina corriendo `verificar.ps1` y avisa de lo que no pudo hacer. Una caja sin
báscula funciona igual: en `C:\POS\agente\config.json` se deja `"puerto": ""`.

**`config.json` no se reemplaza si ya existe.** En una caja que ya vende es
estado de producción: si no permite la dirección, el script lo dice y se edita
ese campo a mano, después de leerlo.

Después, **la prueba que ningún script hace**:

1. Apagar con el botón físico (pulsación corta).
2. Encender.
3. Comprobar que no sale ninguna ventana negra y que el quiosco aparece solo.
4. Pesar algo, cobrarlo en efectivo y ver que abre el cajón sin imprimir.
5. Anular esa venta.

## Qué deja y por qué

| Pieza | Cómo queda | Lo que evita |
|---|---|---|
| Agente | Tarea `AgentePOS` como **SYSTEM**, sesión 0: al arrancar, al entrar y vigilante cada 5 min | La ventana negra que el cajero cerraba y dejaba la caja sin báscula ni cajón |
| Arreglo de puertos | Tarea `ArreglarPuertos` como SYSTEM, oculta | La segunda ventana negra |
| Quiosco | Tarea `PuntoDeVenta` en la sesión del cajero; Chrome directo, 40 s después de entrar | La tercera ventana, con la cuenta regresiva |
| Chrome | Políticas `LoopbackNetworkAllowedForUrls` y `LocalNetworkAccessAllowedForUrls` con la dirección del negocio | Que la página no llegue al agente, sin dar ningún error |
| Impresora | Predeterminada fija; driver con `zjCashDrawer = zjNoCashDrawer` | Imprimir en otra impresora; que cada impresión abra el cajón |
| Energía | Inicio rápido apagado; botón de encendido = Apagar | Que «apagar» hibernara, y al encender el agente no arrancara |
| SSH | Solo llave, solo `AllowUsers` de la cuenta, apagado y deshabilitado | Una puerta abierta permanente en la red del cliente |
| `C:\POS` | Escriben solo Administradores y SYSTEM; la cuenta de la caja, solo en `perfil-chrome` | Que alguien sin ser administrador cambie `pos-agent.exe` o `arreglar-puertos.ps1`, que corren como SYSTEM, y se vuelva SYSTEM. Una carpeta en la raíz de C: hereda «modificar» para todo usuario |

**El SSH no vuelve solo, a propósito.** Después de cualquier reinicio está
apagado: alguien en el local toca **2 - SSH ENCENDER** y dicta la IP que
aparece. Al terminar, **3 - SSH APAGAR**.

## Diagnóstico

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File C:\POS\verificar.ps1
```

Solo lee: se puede correr en plena venta. Cada línea dice `OK` o `FALLA` con el
motivo. Al final están las cinco últimas líneas de la bitácora del agente.
Un `cerrando` sin que nadie apagara el equipo quiere decir que alguien cerró
el agente.

## Volver atrás

- **Tareas**: antes de reemplazarlas, el script guarda cada una en
  `C:\POS\respaldo-tareas\<Tarea>-<fecha>.xml`. Para recuperar una:
  `Register-ScheduledTask -Xml (Get-Content <archivo> -Raw) -TaskName <Tarea> -Force`.
- **sshd_config**: queda la copia `sshd_config.antes-<fecha>` junto al original.
- **Agente**: queda `pos-agent.exe.antes-<fecha>` cuando se reemplaza.
- **Energía**:

  ```
  reg add "HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Power" /v HiberbootEnabled /t REG_DWORD /d 1 /f
  ```

  y el botón vuelve con `powercfg /setacvalueindex SCHEME_CURRENT SUB_BUTTONS PBUTTONACTION <n>`.
  Los valores de fábrica en Paraíso eran 0 con corriente y 1 con batería.
