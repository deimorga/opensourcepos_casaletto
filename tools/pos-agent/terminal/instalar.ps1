# Deja una terminal de caja como la de Paraiso de la Canasta el 2026-10-19.
#
# Idempotente: correrlo dos veces deja lo mismo que correrlo una. Lo que ya
# esta bien no se toca, y lo que se reemplaza se respalda antes.
#
# Correr como administrador, sentado en la terminal o por SSH, desde la cuenta
# con la que la caja inicia sesion (la impresora predeterminada es de esa
# cuenta, no del equipo):
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File instalar.ps1 `
#     -Url https://NEGOCIO.ospos-saas.micronuba.net -Agente .\pos-agent.exe `
#     -LlavePublica .\soporte.pub
#
# Lo que NO hace, y por que, esta en el README de esta carpeta.

param(
    [Parameter(Mandatory = $true)]
    [string]$Url,
    [string]$Usuario = $env:USERNAME,
    [string]$Impresora = 'POS-58-Series',
    [string]$Agente = (Join-Path $PSScriptRoot 'pos-agent.exe'),
    [string]$LlavePublica = '',
    [switch]$SinSsh
)

$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
$pos = 'C:\POS'
$sello = Get-Date -Format 'yyyyMMdd-HHmmss'
$avisos = New-Object System.Collections.Generic.List[string]

function Paso($texto) { ''; "== $texto" }
function Aviso($texto) { "   AVISO: $texto"; $avisos.Add($texto) }
function Escribir-SinBom($ruta, $texto) {
    # PowerShell 5.1 escribe UTF-8 con BOM, y el lector JSON del agente lo rechaza.
    [IO.File]::WriteAllText($ruta, $texto, (New-Object Text.UTF8Encoding $false))
}

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Hay que correrlo como administrador.' }

$Url = $Url.TrimEnd('/')
if ($Url -notmatch '^https://[a-z0-9.-]+$') {
    throw "La direccion '$Url' no es valida: va completa, con https:// y sin ruta. Ejemplo: https://negocio.ospos-saas.micronuba.net"
}
if ($Usuario -ne $env:USERNAME) {
    Aviso "se corre como '$env:USERNAME' pero la caja entra como '$Usuario': la impresora predeterminada queda en la cuenta equivocada"
}

$chromeExe = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$argsChrome = "--kiosk --kiosk-printing --user-data-dir=`"$pos\perfil-chrome`" --disable-features=TranslateUI --no-first-run $Url"

# ---------------------------------------------------------------------------
Paso 'Carpetas y archivos de apoyo'
foreach ($d in $pos, "$pos\agente", "$pos\respaldo-tareas", "$pos\perfil-chrome") {
    New-Item -ItemType Directory -Force -Path $d | Out-Null
}
# Aqui viven programas que corren como SYSTEM. Una carpeta en la raiz de C:
# hereda "modificar" para cualquier usuario autenticado, y quien pudiera
# reemplazar pos-agent.exe o arreglar-puertos.ps1 correria como SYSTEM. Se
# cierra: escriben solo Administradores y SYSTEM; los demas leen y ejecutan.
icacls $pos /inheritance:r /grant '*S-1-5-32-544:(OI)(CI)F' /grant '*S-1-5-18:(OI)(CI)F' /grant '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
if ($LASTEXITCODE -ne 0) { throw "no se pudieron cerrar los permisos de $pos" }
# Menos el perfil de Chrome, que el quiosco escribe sin elevar.
icacls "$pos\perfil-chrome" /grant "$env:COMPUTERNAME\${Usuario}:(OI)(CI)M" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "no se pudo dar a $Usuario el perfil de Chrome" }
foreach ($f in 'arreglar-puertos.ps1', 'verificar.ps1', 'ver-mi-ip.cmd', 'ssh-encender.cmd', 'ssh-apagar.cmd') {
    Copy-Item -Path (Join-Path $aqui $f) -Destination (Join-Path $pos $f) -Force
}
$cmd = (Get-Content -Raw (Join-Path $aqui 'abrir-caja.cmd')) -replace 'https://NEGOCIO\.ospos-saas\.micronuba\.net', $Url
[IO.File]::WriteAllText("$pos\abrir-caja.cmd", $cmd, [Text.Encoding]::ASCII)
"   $pos listo"
if (-not (Test-Path $chromeExe)) { Aviso "no esta Google Chrome en ${chromeExe}: instalarlo antes de usar la caja" }

# ---------------------------------------------------------------------------
Paso 'Agente'
$destino = "$pos\agente\pos-agent.exe"
if (Test-Path $Agente) {
    $nuevo = (Get-FileHash $Agente -Algorithm SHA256).Hash
    $actual = if (Test-Path $destino) { (Get-FileHash $destino -Algorithm SHA256).Hash } else { '' }
    if ($nuevo -eq $actual) {
        '   el ejecutable instalado ya es ese; sin cambios'
    } else {
        Stop-ScheduledTask -TaskName AgentePOS -ErrorAction SilentlyContinue
        Get-Process pos-agent -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 1
        if (Test-Path $destino) { Copy-Item $destino "$destino.antes-$sello" -Force }
        Copy-Item $Agente $destino -Force
        "   instalado: $(& $destino -version)"
    }
} elseif (Test-Path $destino) {
    "   no se paso -Agente; se deja el instalado: $(& $destino -version)"
} else {
    Aviso "no hay agente: pasar -Agente con la ruta de pos-agent.exe (se compila segun tools/pos-agent/README.md)"
}

# config.json es estado de produccion: si existe NO se reemplaza. Se lee y se avisa.
$config = "$pos\agente\config.json"
if (-not (Test-Path $config)) {
    $c = Get-Content -Raw (Join-Path $aqui 'config.ejemplo.json') | ConvertFrom-Json
    $c.origenes_permitidos = @($Url)
    $c.impresora.nombre = $Impresora
    Escribir-SinBom $config ($c | ConvertTo-Json -Depth 5)
    "   config.json creado para $Url"
} else {
    $c = Get-Content -Raw $config | ConvertFrom-Json
    if ($c.origenes_permitidos -notcontains $Url) {
        Aviso "config.json existe y NO permite ${Url}: sin eso la pagina no llega al agente. Agregarlo a origenes_permitidos a mano (se edita el campo, no se reemplaza el archivo)"
    } else {
        '   config.json existe y ya permite la direccion; no se toca'
    }
}

# ---------------------------------------------------------------------------
Paso 'Tareas de arranque (sin ventanas)'
foreach ($n in 'AgentePOS', 'ArreglarPuertos', 'PuntoDeVenta') {
    if (Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue) {
        Export-ScheduledTask -TaskName $n | Out-File -FilePath "$pos\respaldo-tareas\$n-$sello.xml" -Encoding Unicode
    }
}

# 1. El agente: SYSTEM en la sesion 0, donde ningun cajero lo ve ni lo puede
#    cerrar. Vigilante cada 5 minutos: con IgnoreNew, si esta vivo no pasa
#    nada; si murio, lo levanta.
Stop-ScheduledTask -TaskName AgentePOS -ErrorAction SilentlyContinue
Get-Process pos-agent -ErrorAction SilentlyContinue | Stop-Process -Force
$vigilante = New-ScheduledTaskTrigger -Daily -At '00:00'
$vigilante.Repetition = (New-ScheduledTaskTrigger -Once -At '00:00' -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
Register-ScheduledTask -TaskName AgentePOS -Force `
    -Action (New-ScheduledTaskAction -Execute $destino -WorkingDirectory "$pos\agente") `
    -Trigger @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -AtLogOn), $vigilante) `
    -Principal (New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest) `
    -Settings (New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)) `
    -Description 'Programa de la caja (bascula y cajon). Corre como SYSTEM, sin ventana: no se puede cerrar por error. Vigilante cada 5 min.' | Out-Null
'   AgentePOS: SYSTEM, al arrancar + al entrar + vigilante cada 5 min'

# 2. Arreglo de puertos: SYSTEM, invisible. No necesita sesion de nadie.
Register-ScheduledTask -TaskName ArreglarPuertos -Force `
    -Action (New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File $pos\arreglar-puertos.ps1 -Impresora `"$Impresora`"") `
    -Trigger (New-ScheduledTaskTrigger -AtLogOn) `
    -Principal (New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest) `
    -Settings (New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 5)) `
    -Description 'Reapunta la cola de la impresora al puerto USB donde esta conectada. Bitacora en C:\POS\arreglar-puertos.log.' | Out-Null
'   ArreglarPuertos: SYSTEM, al entrar'

# 3. Chrome en quiosco, en la sesion del cajero (tiene que verse). Chrome
#    directo: la espera de 40 s, que da tiempo a la red y al agente, vive en el
#    disparador y no en una ventana con cuenta regresiva.
$alEntrar = New-ScheduledTaskTrigger -AtLogOn -User "$env:COMPUTERNAME\$Usuario"
$alEntrar.Delay = 'PT40S'
Register-ScheduledTask -TaskName PuntoDeVenta -Force `
    -Action (New-ScheduledTaskAction -Execute $chromeExe -Argument $argsChrome) `
    -Trigger $alEntrar `
    -Principal (New-ScheduledTaskPrincipal -UserId "$env:COMPUTERNAME\$Usuario" -LogonType Interactive -RunLevel Limited) `
    -Settings (New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero)) `
    -Description "Abre el punto de venta en quiosco 40 s despues de entrar. Acceso directo manual: $pos\abrir-caja.cmd" | Out-Null
"   PuntoDeVenta: $Usuario, al entrar + 40 s, Chrome directo"
"   respaldo de las anteriores: $pos\respaldo-tareas\*-$sello.xml"

if (Test-Path $destino) {
    Start-ScheduledTask -TaskName AgentePOS
    '   AgentePOS iniciada'
}

# ---------------------------------------------------------------------------
Paso 'Chrome: dejar que la pagina hable con el agente'
# Con Local Network Access, Chrome no deja que una pagina publica llegue a
# 127.0.0.1 sin permiso, y en quiosco nadie puede contestar la pregunta: falla
# en silencio. La politica da el permiso solo a la direccion del negocio.
foreach ($p in 'LoopbackNetworkAllowedForUrls', 'LocalNetworkAccessAllowedForUrls') {
    $k = "HKLM:\SOFTWARE\Policies\Google\Chrome\$p"
    New-Item -Path $k -Force | Out-Null
    $valores = (Get-Item $k).Property | ForEach-Object { (Get-ItemProperty $k).$_ }
    if ($valores -contains $Url) {
        "   ${p}: ya esta"
    } else {
        $siguiente = 1
        while ((Get-Item $k).Property -contains "$siguiente") { $siguiente++ }
        New-ItemProperty -Path $k -Name "$siguiente" -Value $Url -PropertyType String -Force | Out-Null
        "   ${p}: agregado"
    }
}

# ---------------------------------------------------------------------------
Paso "Impresora '$Impresora'"
if (Get-Printer -Name $Impresora -ErrorAction SilentlyContinue) {
    # El driver de la XP-58IIT abre el cajon ANTES DE CADA IMPRESION de fabrica:
    # una reimpresion o un recibo con tarjeta abren la caja. Quien abre el cajon
    # es el POS, por el agente.
    try {
        Set-PrinterProperty -PrinterName $Impresora -PropertyName 'Config:zjCashDrawer' -Value 'zjNoCashDrawer'
        '   cajon por driver: apagado'
    } catch {
        Aviso "no se pudo apagar el cajon del driver (otro driver?): revisar que imprimir no abra la caja"
    }
    # --kiosk-printing imprime en la predeterminada. Windows la cambia solo a la
    # ultima usada si no se le dice lo contrario.
    $k = 'HKCU:\Software\Microsoft\Windows NT\CurrentVersion\Windows'
    Set-ItemProperty -Path $k -Name LegacyDefaultPrinterMode -Value 1 -Type DWord
    Get-CimInstance Win32_Printer -Filter "Name='$Impresora'" | Invoke-CimMethod -MethodName SetDefaultPrinter | Out-Null
    "   predeterminada de $env:USERNAME, fija"
    & "$pos\arreglar-puertos.ps1" -Impresora $Impresora
    "   puerto: $((Get-Printer -Name $Impresora).PortName)"
} else {
    Aviso "no existe la impresora '$Impresora': instalar el driver y volver a correr este script"
}

# ---------------------------------------------------------------------------
Paso 'Energia: apagar es apagar'
# Con inicio rapido, "Apagar" hiberna el nucleo y al encender se reanuda: las
# tareas de arranque no corren y el agente quedaba muerto. Y el boton fisico
# hace lo mismo que el menu.
Set-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -Name HiberbootEnabled -Value 0 -Type DWord
powercfg /setacvalueindex SCHEME_CURRENT SUB_BUTTONS PBUTTONACTION 3
powercfg /setdcvalueindex SCHEME_CURRENT SUB_BUTTONS PBUTTONACTION 3
powercfg /setactive SCHEME_CURRENT
'   inicio rapido apagado; boton de encendido = Apagar'

# ---------------------------------------------------------------------------
if ($SinSsh) {
    Paso 'SSH: omitido (-SinSsh)'
} else {
    Paso 'SSH de soporte: solo llave, solo cuando alguien lo enciende'
    $sshd = "$env:SystemRoot\System32\OpenSSH\sshd.exe"
    if (-not (Test-Path $sshd)) {
        '   instalando OpenSSH Server (necesita internet)...'
        Add-WindowsCapability -Online -Name (Get-WindowsCapability -Online -Name 'OpenSSH.Server*' | Select-Object -First 1).Name | Out-Null
    }
    $cfg = 'C:\ProgramData\ssh\sshd_config'
    if (-not (Test-Path $cfg)) {
        # sshd escribe su configuracion y sus llaves de equipo al arrancar la primera vez.
        Start-Service sshd
        Start-Sleep -Seconds 2
    }

    # Cada directiva se deja con su valor en la seccion global (antes del primer
    # Match): sshd se queda con la PRIMERA aparicion, asi que una linea con otro
    # valor se corrige en su sitio y una que falta se agrega antes del Match.
    $quiero = [ordered]@{
        PasswordAuthentication       = 'no'
        PermitEmptyPasswords         = 'no'
        KbdInteractiveAuthentication = 'no'
        AllowUsers                   = $Usuario
        MaxAuthTries                 = '3'
        LoginGraceTime               = '30'
    }
    $lineas = [Collections.Generic.List[string]]@(Get-Content $cfg)
    $finGlobal = $lineas.FindIndex([Predicate[string]] { param($l) $l -match '^\s*Match\s' })
    if ($finGlobal -lt 0) { $finGlobal = $lineas.Count }
    $cambio = $false
    foreach ($clave in $quiero.Keys) {
        $buena = "$clave $($quiero[$clave])"
        $i = -1
        for ($j = 0; $j -lt $finGlobal; $j++) {
            if ($lineas[$j] -match "^\s*$clave\s") { $i = $j; break }
        }
        if ($i -ge 0) {
            if ($lineas[$i].Trim() -ne $buena) { $lineas[$i] = $buena; $cambio = $true }
        } else {
            $lineas.Insert($finGlobal, $buena); $finGlobal++; $cambio = $true
        }
    }
    if ($cambio) {
        Copy-Item $cfg "$cfg.antes-$sello" -Force
        [IO.File]::WriteAllLines($cfg, $lineas, [Text.Encoding]::ASCII)
        & $sshd -t
        if ($LASTEXITCODE -ne 0) {
            Copy-Item "$cfg.antes-$sello" $cfg -Force
            throw "sshd_config quedo invalido; se restauro el anterior ($cfg.antes-$sello)"
        }
        "   sshd_config endurecido (anterior: $cfg.antes-$sello)"
    } else {
        '   sshd_config ya estaba endurecido'
    }

    # Las cuentas administradoras leen SOLO este archivo, y sshd lo ignora si
    # alguien mas que Administradores y SYSTEM puede tocarlo. SIDs y no nombres:
    # en un Windows en espanol el grupo se llama "Administradores".
    $llaves = 'C:\ProgramData\ssh\administrators_authorized_keys'
    if ($LlavePublica) {
        $llave = (Get-Content -Raw $LlavePublica).Trim()
        $ya = if (Test-Path $llaves) { Get-Content $llaves } else { @() }
        if ($ya -contains $llave) {
            '   la llave ya estaba autorizada'
        } else {
            Add-Content -Path $llaves -Value $llave -Encoding ASCII
            '   llave autorizada'
        }
    }
    if (Test-Path $llaves) {
        icacls $llaves /inheritance:r /grant '*S-1-5-32-544:F' /grant '*S-1-5-18:F' | Out-Null
    } else {
        Aviso 'no hay ninguna llave autorizada: nadie podra entrar por SSH. Pasar -LlavePublica'
    }

    # Queda apagado y deshabilitado, igual que despues de "3 - SSH APAGAR". Si
    # este script corre por SSH, apagarlo cortaria la sesion: se deshabilita y
    # se apaga en el proximo reinicio.
    Set-Service sshd -StartupType Disabled
    if ($env:SSH_CONNECTION) {
        '   SSH deshabilitado; sigue encendido hasta terminar esta sesion (o reiniciar)'
    } else {
        Stop-Service sshd -ErrorAction SilentlyContinue
        '   SSH apagado y deshabilitado'
    }
}

# ---------------------------------------------------------------------------
Paso 'Accesos directos del escritorio'
$publico = [Environment]::GetFolderPath('CommonDesktopDirectory')
$propio = [Environment]::GetFolderPath('Desktop')
$shell = New-Object -ComObject WScript.Shell
$atajos = @(
    @{ Nombre = '1 - VER MI IP';    Destino = "$pos\ver-mi-ip.cmd";    Admin = $false },
    @{ Nombre = '2 - SSH ENCENDER'; Destino = "$pos\ssh-encender.cmd"; Admin = $true },
    @{ Nombre = '3 - SSH APAGAR';   Destino = "$pos\ssh-apagar.cmd";   Admin = $true },
    @{ Nombre = 'PUNTO DE VENTA';   Destino = "$pos\abrir-caja.cmd";   Admin = $false }
)
foreach ($a in $atajos) {
    if ((Test-Path "$propio\$($a.Nombre).lnk") -or (Test-Path "$publico\$($a.Nombre).lnk")) {
        "   $($a.Nombre): ya esta"
        continue
    }
    $ruta = "$publico\$($a.Nombre).lnk"
    $lnk = $shell.CreateShortcut($ruta)
    $lnk.TargetPath = $a.Destino
    $lnk.WorkingDirectory = $pos
    $lnk.Save()
    if ($a.Admin) {
        # "Ejecutar como administrador" es un bit del propio .lnk (byte 0x15, 0x20).
        $b = [IO.File]::ReadAllBytes($ruta)
        $b[0x15] = $b[0x15] -bor 0x20
        [IO.File]::WriteAllBytes($ruta, $b)
    }
    "   $($a.Nombre): creado"
}

# ---------------------------------------------------------------------------
Paso 'Comprobacion'
& "$pos\verificar.ps1" -Url $Url -Usuario $Usuario -Impresora $Impresora
$fallas = $LASTEXITCODE

''
if ($avisos.Count) {
    'AVISOS:'
    $avisos | ForEach-Object { " - $_" }
}
'Falta lo que ningun script prueba: apagar con el boton, encender, y vender algo pesado (y anularlo).'
exit $fallas
