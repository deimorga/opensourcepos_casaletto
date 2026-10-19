# Revisa una terminal de caja punto por punto. SOLO LEE: no cambia nada, se
# puede correr en plena venta.
#
# Es la misma lista que deja instalar.ps1, y sirve de diagnostico cuando "no
# pesa" o "no abre el cajon": correr como administrador, por SSH o en la caja,
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\POS\verificar.ps1
#
# Sale con el numero de fallas (0 = todo bien).

param(
    [string]$Url = '',
    [string]$Usuario = $env:USERNAME,
    [string]$Impresora = 'POS-58-Series'
)

$ErrorActionPreference = 'Continue'
$pos = 'C:\POS'
$script:fallas = 0

function Bien($texto)  { "[ OK  ] $texto" }
function Mal($texto)   { "[FALLA] $texto"; $script:fallas++ }
function Nota($texto)  { "[INFO ] $texto" }
function Revisar($ok, $texto, $detalle = '') {
    if ($ok) { Bien $texto } else { Mal ("$texto" + $(if ($detalle) { " -- $detalle" })) }
}

$config = "$pos\agente\config.json"
$c = $null
if (Test-Path $config) {
    try { $c = Get-Content -Raw $config | ConvertFrom-Json } catch { }
}
if (-not $Url -and $c) { $Url = @($c.origenes_permitidos)[0] }
"Terminal $env:COMPUTERNAME  |  negocio: $Url  |  $(Get-Date -Format 'yyyy-MM-dd HH:mm')"

''
'-- Agente'
Revisar (Test-Path "$pos\agente\pos-agent.exe") 'pos-agent.exe instalado'
Revisar ($null -ne $c) 'config.json existe y se lee' 'ausente o con JSON roto: el agente arranca con valores de fabrica y no deja entrar a nadie'
if ($c) {
    Revisar (@($c.origenes_permitidos) -contains $Url) "config.json permite $Url" "permite: $(@($c.origenes_permitidos) -join ', ')"
    Revisar ($c.impresora.nombre -eq $Impresora) "config.json imprime en '$Impresora'" "dice '$($c.impresora.nombre)'"
    Nota "bascula: puerto '$($c.bascula.puerto)' a $($c.bascula.baudios) baudios ('' = esta caja no tiene bascula)"
}

$t = Get-ScheduledTask -TaskName AgentePOS -ErrorAction SilentlyContinue
Revisar ($null -ne $t) 'tarea AgentePOS existe'
if ($t) {
    Revisar ($t.Principal.UserId -match 'SYSTEM') 'AgentePOS corre como SYSTEM (sin ventana que cerrar)' "corre como $($t.Principal.UserId)"
    $tipos = @($t.Triggers | ForEach-Object { $_.CimClass.CimClassName })
    Revisar (($tipos -contains 'MSFT_TaskBootTrigger') -and ($tipos -contains 'MSFT_TaskDailyTrigger')) 'AgentePOS arranca con el equipo y tiene vigilante' "disparadores: $($tipos -join ', ')"
    Revisar ($t.Settings.MultipleInstances -eq 'IgnoreNew') 'el vigilante no duplica el agente'
}

$proc = Get-CimInstance Win32_Process -Filter "Name='pos-agent.exe'"
if ($proc) {
    $duenno = (Invoke-CimMethod -InputObject @($proc)[0] -MethodName GetOwner).User
    Revisar (@($proc).Count -eq 1) 'una sola copia del agente' "hay $(@($proc).Count)"
    Revisar (@($proc)[0].SessionId -eq 0) "agente en la sesion 0 (PID $(@($proc)[0].ProcessId), usuario $duenno)" "esta en la sesion $(@($proc)[0].SessionId): tiene ventana y se puede cerrar"
} else {
    Mal 'el agente NO esta corriendo (el vigilante lo levanta en 5 min como mucho)'
}
try {
    $estado = (Invoke-WebRequest -Uri 'http://127.0.0.1:7878/estado' -UseBasicParsing -TimeoutSec 5).Content
    Bien 'el agente contesta en 127.0.0.1:7878'
    Nota "estado: $estado"
} catch {
    Mal "el agente no contesta en 127.0.0.1:7878 -- $($_.Exception.Message)"
}
if (Test-Path "$pos\agente\pos-agent.log") {
    Nota 'ultimas lineas de la bitacora:'
    Get-Content "$pos\agente\pos-agent.log" -Tail 5 | ForEach-Object { "          $_" }
}

''
'-- Permisos de lo que corre como SYSTEM'
# Si alguien que no es administrador puede escribir aqui, puede correr lo que
# quiera como SYSTEM. Se aceptan Administradores, SYSTEM y TrustedInstaller.
$confiables = @('S-1-5-32-544', 'S-1-5-18', 'S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464')
foreach ($ruta in $pos, "$pos\agente", "$pos\agente\pos-agent.exe", "$pos\arreglar-puertos.ps1") {
    if (-not (Test-Path $ruta)) { continue }
    $quienes = (Get-Acl $ruta).Access | Where-Object {
        $_.AccessControlType -eq 'Allow' -and
        ($_.FileSystemRights -match 'FullControl|Modify|Write|Delete|ChangePermissions|TakeOwnership')
    } | ForEach-Object {
        try { $sid = $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } catch { $sid = "$($_.IdentityReference)" }
        if ($confiables -notcontains $sid) { "$($_.IdentityReference)" }
    } | Select-Object -Unique
    Revisar (-not $quienes) "solo administradores escriben en $ruta" "tambien puede escribir: $($quienes -join ', ')"
}

''
'-- Arranque sin ventanas'
$t = Get-ScheduledTask -TaskName ArreglarPuertos -ErrorAction SilentlyContinue
Revisar ($t -and $t.Principal.UserId -match 'SYSTEM') 'ArreglarPuertos existe y corre como SYSTEM' $(if ($t) { "corre como $($t.Principal.UserId)" } else { 'no existe' })
$t = Get-ScheduledTask -TaskName PuntoDeVenta -ErrorAction SilentlyContinue
Revisar ($null -ne $t) 'tarea PuntoDeVenta existe'
if ($t) {
    $a = $t.Actions[0]
    Revisar ($a.Execute -like '*chrome.exe') 'PuntoDeVenta lanza Chrome directo (sin ventana de cuenta regresiva)' "lanza $($a.Execute)"
    Revisar (($a.Arguments -match '--kiosk(\s|$)') -and ($a.Arguments -match '--kiosk-printing')) 'Chrome en quiosco e impresion sin dialogo' $a.Arguments
    Revisar ($a.Arguments -match [regex]::Escape($Url)) "Chrome abre $Url" $a.Arguments
    Revisar ($t.Triggers[0].Delay -eq 'PT40S') 'espera de 40 s en el disparador' "espera: '$($t.Triggers[0].Delay)'"
    Revisar ($t.Principal.LogonType -eq 'Interactive') "PuntoDeVenta en la sesion de $($t.Principal.UserId) (tiene que verse)"
}

''
'-- Chrome'
foreach ($p in 'LoopbackNetworkAllowedForUrls', 'LocalNetworkAccessAllowedForUrls') {
    $k = "HKLM:\SOFTWARE\Policies\Google\Chrome\$p"
    $valores = if (Test-Path $k) { (Get-Item $k).Property | ForEach-Object { (Get-ItemProperty $k).$_ } } else { @() }
    Revisar (@($valores) -contains $Url) "politica $p incluye el negocio" 'sin ella la pagina no llega al agente y falla en silencio'
}

''
'-- Impresora'
$cola = Get-Printer -Name $Impresora -ErrorAction SilentlyContinue
Revisar ($null -ne $cola) "existe la impresora '$Impresora'"
if ($cola) {
    $presente = Get-PnpDevice -ErrorAction SilentlyContinue | Where-Object { $_.InstanceId -like 'USBPRINT*' -and $_.Status -eq 'OK' } | Select-Object -First 1
    if ($presente) {
        $puerto = ($presente.InstanceId -split '&')[-1]
        Revisar ($cola.PortName -eq $puerto) "la cola apunta al puerto donde esta la impresora ($puerto)" "apunta a $($cola.PortName); lo corrige ArreglarPuertos al entrar"
    } else {
        Mal 'la impresora no esta conectada o esta apagada'
    }
    $cajon = (Get-PrinterProperty -PrinterName $Impresora -PropertyName 'Config:zjCashDrawer' -ErrorAction SilentlyContinue).Value
    if ($null -ne $cajon) {
        Revisar ($cajon -eq 'zjNoCashDrawer') 'el driver NO abre el cajon al imprimir' "esta en ${cajon}: cada impresion abre la caja"
    } else {
        Nota 'el driver no tiene la opcion zjCashDrawer (otra impresora): probar que imprimir no abra la caja'
    }
    $papel = (Get-PrinterProperty -PrinterName $Impresora -PropertyName 'Config:PaperSize' -ErrorAction SilentlyContinue).Value
    Nota "papel declarado en el driver: '$papel' (el ancho lo fija el POS: Configuracion > Recibo > 58 mm)"
    $pred = Get-CimInstance Win32_Printer -Filter 'Default=True'
    Revisar ($pred.Name -eq $Impresora) "es la predeterminada de $env:USERNAME (--kiosk-printing imprime ahi)" "la predeterminada es '$($pred.Name)'"
    $modo = (Get-ItemProperty 'HKCU:\Software\Microsoft\Windows NT\CurrentVersion\Windows' -ErrorAction SilentlyContinue).LegacyDefaultPrinterMode
    Revisar ($modo -eq 1) 'Windows no cambia la predeterminada por su cuenta'
}

''
'-- Energia'
$hb = (Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power').HiberbootEnabled
Revisar ($hb -eq 0) 'inicio rapido apagado (Apagar apaga de verdad)' 'encendido: al prender se reanuda y las tareas de arranque no corren'
# Se lee del registro y no de "powercfg /q", cuyo texto cambia con el idioma de Windows.
$esquema = ([regex]::Match((powercfg /getactivescheme), '[0-9a-f]{8}-[0-9a-f-]{27}')).Value
$k = "HKLM:\SYSTEM\CurrentControlSet\Control\Power\User\PowerSchemes\$esquema\4f971e89-eebd-4455-a8de-9e59040e7347\7648efa3-dd9c-4e3e-b566-50f929386280"
$boton = Get-ItemProperty $k -ErrorAction SilentlyContinue
Revisar (($boton.ACSettingIndex -eq 3) -and ($boton.DCSettingIndex -eq 3)) 'el boton de encendido apaga' "con corriente=$($boton.ACSettingIndex) con bateria=$($boton.DCSettingIndex) (3 = Apagar)"
$arranque = Get-WinEvent -FilterHashtable @{ LogName = 'System'; ProviderName = 'Microsoft-Windows-Kernel-Boot'; Id = 27 } -MaxEvents 1 -ErrorAction SilentlyContinue
if ($arranque) {
    $tipo = $arranque.Properties[0].Value
    $nombre = @{ 0 = 'en frio'; 1 = 'inicio rapido (reanudado)'; 2 = 'reanudado de hibernacion' }[[int]$tipo]
    Revisar ($tipo -eq 0) "ultimo arranque $nombre, $($arranque.TimeCreated)" 'el equipo no se apago de verdad'
}

''
'-- SSH de soporte'
$cfg = 'C:\ProgramData\ssh\sshd_config'
if (Test-Path $cfg) {
    $global = @()
    foreach ($l in Get-Content $cfg) { if ($l -match '^\s*Match\s') { break }; $global += $l }
    foreach ($d in @('PasswordAuthentication no', 'PermitEmptyPasswords no', 'KbdInteractiveAuthentication no', "AllowUsers $Usuario")) {
        $clave = $d.Split(' ')[0]
        $hay = $global | Where-Object { $_ -match "^\s*$clave\s" } | Select-Object -First 1
        Revisar ($hay -and $hay.Trim() -eq $d) "sshd: $d" "dice '$hay'"
    }
    $llaves = 'C:\ProgramData\ssh\administrators_authorized_keys'
    $n = if (Test-Path $llaves) { @(Get-Content $llaves | Where-Object { $_ -match '^ssh-' }).Count } else { 0 }
    Revisar ($n -gt 0) "hay $n llave(s) autorizada(s)"
    $svc = Get-Service sshd -ErrorAction SilentlyContinue
    Nota "servicio sshd: $($svc.Status), inicio $($svc.StartType) (lo normal fuera de una visita: Stopped / Disabled)"
} else {
    Nota 'OpenSSH Server no esta configurado en esta terminal'
}

''
'-- Escritorio'
$escritorios = @([Environment]::GetFolderPath('CommonDesktopDirectory'), [Environment]::GetFolderPath('Desktop'))
foreach ($n in '1 - VER MI IP', '2 - SSH ENCENDER', '3 - SSH APAGAR', 'PUNTO DE VENTA') {
    Revisar (@($escritorios | Where-Object { Test-Path "$_\$n.lnk" }).Count -gt 0) "acceso directo '$n'"
}

''
if ($script:fallas -eq 0) { 'TODO BIEN.' } else { "$($script:fallas) FALLA(S)." }
exit $script:fallas
