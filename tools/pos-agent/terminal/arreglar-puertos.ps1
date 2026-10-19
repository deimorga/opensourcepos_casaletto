# Reapunta la cola de impresion al puerto USB donde REALMENTE esta la impresora.
#
# Windows numera los puertos por conector fisico y NO reasigna la cola cuando el
# aparato cambia de sitio: crea un puerto nuevo y deja la cola hablandole al
# viejo. El sintoma --"Error" en Configuracion-- es identico al de un cable
# danado, y el cajero no tiene por que saber la diferencia.
#
# Corre al iniciar sesion, como SYSTEM y sin ventana (tarea ArreglarPuertos).
# Es idempotente: si ya esta bien, no toca nada.

param([string]$Impresora = 'POS-58-Series')

$registro = 'C:\POS\arreglar-puertos.log'

function Anotar($texto) {
    "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $texto | Add-Content -Path $registro -Encoding UTF8
}

try {
    $cola = Get-Printer -Name $Impresora -ErrorAction Stop
} catch {
    Anotar "no existe la cola '$Impresora'; no hay nada que reapuntar"
    exit 0
}

# De todos los puertos USBPRINT, el que este PRESENTE es donde esta la impresora.
$presente = Get-PnpDevice -ErrorAction SilentlyContinue |
    Where-Object { $_.InstanceId -like 'USBPRINT*' -and $_.Status -eq 'OK' } |
    Select-Object -First 1

if (-not $presente) {
    Anotar "no hay ningun puerto USBPRINT presente: la impresora esta desconectada o apagada"
    exit 0
}

# El nombre del puerto es el ultimo tramo del identificador:
#   USBPRINT\UNKNOWNPRINTER\6&286FE4F3&0&USB001  ->  USB001
$puerto = ($presente.InstanceId -split '&')[-1]

if ($cola.PortName -eq $puerto) {
    Anotar "la cola ya apunta a $puerto; sin cambios"
    exit 0
}

Anotar "la impresora esta en $puerto pero la cola apuntaba a $($cola.PortName): se corrige"
Get-PrintJob -PrinterName $Impresora -ErrorAction SilentlyContinue | Remove-PrintJob -ErrorAction SilentlyContinue
Set-Printer -Name $Impresora -PortName $puerto
Start-Sleep -Seconds 1
$ahora = (Get-Printer -Name $Impresora).PortName
Anotar "quedo en $ahora"
