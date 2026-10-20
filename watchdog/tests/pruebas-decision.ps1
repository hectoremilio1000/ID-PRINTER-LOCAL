<#
  Pruebas unitarias de la decisión del watchdog (Get-Decision / Get-EstadoGeneral).
  No tocan el sistema: alimentan observaciones sintéticas y revisan qué haría.
  Uso: powershell -ExecutionPolicy Bypass -File watchdog\tests\pruebas-decision.ps1
  Sale con código 0 si todo pasa, 1 si algo falla.
#>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\idprinter-watchdog.ps1') -Biblioteca -App (Join-Path $PSScriptRoot '..\..')

$script:fallas = 0; $script:total = 0
function Afirmar([bool]$cond, [string]$nombre) {
    $script:total++
    if ($cond) { Write-Host "  ok   $nombre" -ForegroundColor Green }
    else { $script:fallas++; Write-Host "  FALLA $nombre" -ForegroundColor Red }
}

$cfg = Get-ConfigPorOmision
$T0 = [long]1800000000000

function Obs([hashtable]$cambios) {
    $o = @{
        pausado = $false; configFaltante = @(); trabajosActivos = 0
        php = @{ pids = @(100); vivo = $true; duenoPuerto = 100; puertoAjeno = $false; http = @{ ok = $true; error = $null } }
        caddy = @{ pids = @(200); vivo = $true; duenoPuerto = 200; puertoAjeno = $false; http = @{ ok = $true; error = $null } }
        spooler = @{ servicio = 'Running'; responde = $true; impresoras = @() }
    }
    foreach ($k in $cambios.Keys) {
        if ($cambios[$k] -is [hashtable] -and $o[$k] -is [hashtable]) { foreach ($k2 in $cambios[$k].Keys) { $o[$k][$k2] = $cambios[$k][$k2] } }
        else { $o[$k] = $cambios[$k] }
    }
    $o
}
function Tipos($d) { @($d.acciones | ForEach-Object { "$($_.tipo):$($_.componente)" }) -join ',' }

Write-Host 'Sano'
$d = Get-Decision (Obs @{}) @{} $cfg $T0
Afirmar ((Tipos $d) -eq '') 'todo sano: ninguna accion'
Afirmar ((Get-EstadoGeneral (Obs @{}) $d) -eq 'disponible') 'todo sano: disponible'

Write-Host 'php caido'
$o = Obs @{ php = @{ pids = @(); vivo = $false; duenoPuerto = $null; http = @{ ok = $false; error = 'sin proceso' } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq 'iniciar:php') 'php sin proceso: iniciar php (de inmediato, sin esperar fallas seguidas)'
Afirmar ((Get-EstadoGeneral $o $d) -eq 'recuperando') 'php sin proceso: recuperando'
Afirmar ($d.estado.php.caidoDesde -eq $T0) 'marca caidoDesde'

Write-Host 'php colgado'
$o = Obs @{ php = @{ http = @{ ok = $false; error = 'Timeout' } }; caddy = @{ http = @{ ok = $false; error = 'BadGateway' } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq 'reiniciar:php') 'php vivo sin responder: reiniciar php; caddy NO se reinicia por culpa de php'

Write-Host 'php colgado con trabajo a medias'
$o.trabajosActivos = 1
$d1 = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d1) -eq '') 'trabajo activo: aplaza (1)'
$d2 = Get-Decision $o $d1.estado $cfg ($T0 + 60000)
Afirmar ((Tipos $d2) -eq '') 'trabajo activo: aplaza (2)'
$d3 = Get-Decision $o $d2.estado $cfg ($T0 + 120000)
Afirmar ((Tipos $d3) -eq 'reiniciar:php') 'tras MaxAplazamientos: reinicia aunque haya trabajo (no espera para siempre)'

Write-Host 'puerto ajeno'
$o = Obs @{ php = @{ pids = @(); vivo = $false; duenoPuerto = 999; puertoAjeno = $true; nombreAjeno = 'otro'; http = @{ ok = $false; error = 'x' } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq '') 'puerto ocupado por otro programa: no se mata nada'
Afirmar ((Get-EstadoGeneral $o $d) -eq 'requiere_intervencion') 'puerto ajeno: requiere_intervencion'

Write-Host 'puerto ajeno pero el que contesta funciona'
$o = Obs @{ php = @{ duenoPuerto = 999; puertoAjeno = $true; http = @{ ok = $true } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq '') 'si contesta bien no se actua'

Write-Host 'instancia sobrante'
$o = Obs @{ php = @{ pids = @(100, 101); duenoPuerto = 100 } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq 'matar_sobrante:php') 'php duplicado sin puerto: se cierra el sobrante'
Afirmar ($d.acciones[0].pid -eq 101) 'cierra el que NO tiene el puerto'

Write-Host 'backoff'
$o = Obs @{ php = @{ pids = @(); vivo = $false; duenoPuerto = $null; http = @{ ok = $false; error = 'x' } } }
$e = @{}
$tipos = @()
for ($i = 0; $i -lt 5; $i++) { $d = Get-Decision $o $e $cfg ($T0 + $i * 60000); $tipos += (Tipos $d); $e = $d.estado }
Afirmar (($tipos[0..2] -join '|') -eq 'iniciar:php|iniciar:php|iniciar:php') '3 recuperaciones permitidas'
Afirmar ($tipos[3] -eq '' -and $tipos[4] -eq '') 'la 4.a se bloquea (backoff)'
Afirmar ($e.php.bloqueadoHasta -eq ($T0 + 3 * 60000 + 15 * 60000)) 'bloqueo de 15 min'
Afirmar ((Get-EstadoGeneral $o $d) -eq 'requiere_intervencion') 'en backoff: requiere_intervencion'
$d = Get-Decision $o $e $cfg ($T0 + 3 * 60000 + 15 * 60000 + 1)
Afirmar ((Tipos $d) -eq 'iniciar:php') 'al terminar el bloqueo se vuelve a intentar'
$e = $d.estado
for ($i = 1; $i -le 3; $i++) { $d = Get-Decision $o $e $cfg ($T0 + 20 * 60000 + $i * 60000); $e = $d.estado }
Afirmar ($e.php.bloqueadoHasta -eq ($T0 + 23 * 60000 + 30 * 60000)) 'segundo bloqueo: 30 min (exponencial)'

Write-Host 'fallas aisladas vs inestable'
$caido = Obs @{ php = @{ pids = @(); vivo = $false; duenoPuerto = $null; http = @{ ok = $false; error = 'x' } } }
$e = @{}; $t = $T0; $tipos = @()
for ($i = 0; $i -lt 4; $i++) {
    $d = Get-Decision $caido $e $cfg $t; $tipos += (Tipos $d); $e = $d.estado; $t += 60000
    # sano durante 11 min (más que EstableMin) antes de la siguiente falla
    for ($m = 0; $m -lt 11; $m++) { $d = Get-Decision (Obs @{}) $e $cfg $t; $e = $d.estado; $t += 60000 }
}
Afirmar ((@($tipos | Where-Object { $_ -eq 'iniciar:php' })).Count -eq 4) '4 fallas aisladas (con 11 min estables entre ellas): las 4 se recuperan'
$e = @{}; $t = $T0; $tipos = @()
for ($i = 0; $i -lt 4; $i++) {
    $d = Get-Decision $caido $e $cfg $t; $tipos += (Tipos $d); $e = $d.estado; $t += 60000
    $d = Get-Decision (Obs @{}) $e $cfg $t; $e = $d.estado; $t += 60000
}
Afirmar ($tipos[3] -eq '') 'falla cada 2 min (inestable): la 4.a ya no se intenta'

Write-Host 'recuperacion y duracion de la interrupcion'
$e = @{ php = (New-EstadoComponente) }; $e.php.caidoDesde = $T0
$d = Get-Decision (Obs @{}) $e $cfg ($T0 + 42000)
Afirmar ($null -eq $d.estado.php.caidoDesde) 'al responder se limpia caidoDesde'
Afirmar (@($d.eventos | Where-Object { $_.msg -match 'interrupcion de 42 s' }).Count -eq 1) 'registra la duracion (42 s)'

Write-Host 'caddy'
$o = Obs @{ caddy = @{ pids = @(); vivo = $false; duenoPuerto = $null; http = @{ ok = $false; error = 'x' } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq 'iniciar:caddy') 'caddy sin proceso: iniciar caddy'
$o = Obs @{ caddy = @{ http = @{ ok = $false; error = 'Timeout' } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq 'reiniciar:caddy') 'caddy colgado con php sano: reiniciar caddy'
Afirmar ((Get-EstadoGeneral (Obs @{ caddy = @{ http = @{ ok = $false } } }) @{ acciones = @(); intervencion = @() }) -eq 'https_caido') 'php ok + caddy mal: https_caido'

Write-Host 'spooler'
$o = Obs @{ spooler = @{ servicio = 'Stopped'; responde = $false } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq 'iniciar:spooler') 'spooler detenido: iniciarlo'
$o = Obs @{ spooler = @{ servicio = 'Running'; responde = $false; error = 'timeout' } }
$e = @{}; $tipos = @()
for ($i = 0; $i -lt 3; $i++) { $d = Get-Decision $o $e $cfg ($T0 + $i * 60000); $tipos += (Tipos $d); $e = $d.estado }
Afirmar ($tipos[0] -eq '' -and $tipos[1] -eq '' -and $tipos[2] -eq 'reiniciar:spooler') 'spooler colgado: reinicia solo a la 3.a revision seguida'
$cfg2 = Get-ConfigPorOmision; $cfg2.ReiniciarSpoolerColgado = $false
$e = @{}; for ($i = 0; $i -lt 4; $i++) { $d = Get-Decision $o $e $cfg2 ($T0 + $i * 60000); $e = $d.estado }
Afirmar ((Tipos $d) -eq '' -and @($d.intervencion).Count -gt 0) 'con reinicio de spooler desactivado: solo avisa'
Afirmar ((Get-EstadoGeneral (Obs @{ spooler = @{ servicio = 'Running'; responde = $false } }) @{ acciones = @(); intervencion = @() }) -eq 'spooler_falla') 'estado spooler_falla'

Write-Host 'impresoras'
$imp = @(@{ nombre = 'Cocina'; estado = 'no_disponible' })
Afirmar ((Get-EstadoGeneral (Obs @{ spooler = @{ impresoras = $imp } }) @{ acciones = @(); intervencion = @() }) -eq 'impresora_con_problema') 'impresora no disponible: impresora_con_problema'
Afirmar ((Get-ClasificacionImpresora @{ tipo = 'red'; alcanzable = $false; fueraDeLinea = $false; trabajosConError = 0 }) -eq 'no_disponible') 'red sin respuesta: no_disponible'
Afirmar ((Get-ClasificacionImpresora @{ tipo = 'red'; alcanzable = $true; fueraDeLinea = $false; trabajosConError = 0 }) -eq 'disponible') 'red alcanzable: disponible'
Afirmar ((Get-ClasificacionImpresora @{ tipo = 'usb'; fueraDeLinea = $true; trabajosConError = 0 }) -eq 'no_disponible') 'usb fuera de linea: no_disponible'
Afirmar ((Get-ClasificacionImpresora @{ tipo = 'usb'; fueraDeLinea = $false; trabajosConError = 0; errorDetectado = 'sin_papel' }) -eq 'con_error') 'sin papel (driver que lo reporta): con_error'
Afirmar ((Get-ClasificacionImpresora @{ tipo = 'usb'; fueraDeLinea = $false; trabajosConError = 0 }) -eq 'sin_senal') 'usb sin datos: sin_senal (no se afirma que funciona)'
Afirmar ((Get-ClasificacionImpresora @{ tipo = 'red'; alcanzable = $true; fueraDeLinea = $false; trabajosConError = 2 }) -eq 'con_error') 'trabajos en error en la cola: con_error'

$imp2 = @(@{ nombre = 'POS BARRA'; estado = 'ignorada' }, @{ nombre = 'Cocina'; estado = 'disponible' })
Afirmar ((Get-EstadoGeneral (Obs @{ spooler = @{ impresoras = $imp2 } }) @{ acciones = @(); intervencion = @() }) -eq 'disponible') 'impresora ignorada no afecta el estado general'

Write-Host 'pausa, diagnostico y configuracion'
$o = Obs @{ pausado = $true; php = @{ pids = @(); vivo = $false; http = @{ ok = $false } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq '' -and (Get-EstadoGeneral $o $d) -eq 'pausado') 'PAUSA: no actua, estado pausado'
$o = Obs @{ php = @{ pids = @(); vivo = $false; duenoPuerto = $null; http = @{ ok = $false } } }
$d = Get-Decision $o @{} $cfg $T0 -SoloDiagnostico
Afirmar ((Tipos $d) -eq '') 'SoloDiagnostico: no actua'
$o = Obs @{ configFaltante = @('cert\llave.pem'); php = @{ pids = @(); vivo = $false; duenoPuerto = $null; http = @{ ok = $false } } }
$d = Get-Decision $o @{} $cfg $T0
Afirmar ((Tipos $d) -eq '' -and (Get-EstadoGeneral $o $d) -eq 'requiere_intervencion') 'falta certificado: no intenta arrancar en bucle, pide intervencion'

Write-Host 'utilidades'
Afirmar ((Get-IdCache 'C:\A') -eq (Get-IdCache 'c:\a')) 'cache_id estable sin importar mayusculas'
Afirmar ((Get-IdCache 'C:\A') -ne (Get-IdCache 'C:\B')) 'cache_id distinto por carpeta'
Afirmar ((Get-IdCache 'C:\A') -match '^gsidp[0-9a-f]{8}$') 'cache_id con formato valido'

$srv = '"C:\app\php\php.exe"  -d openssl.cafile="C:\app\php\cert\cacert.pem" -d opcache.cache_id=gsidp1234abcd -S 0.0.0.0:8080 -t "C:\app\public"'
$cli = '"C:\app\php\php.exe" -c C:\app\php\php.ini -d opcache.enable_cli=0 C:\app\watchdog\trabajos_activos.php 60'
Afirmar ($srv -match (Get-PatronPhp $cfg)) 'patron php: reconoce el servidor -S :8080'
Afirmar ($cli -notmatch (Get-PatronPhp $cfg)) 'patron php: un php de consola NO es el servidor'
Afirmar ('"C:\app\php\php.exe" -S 0.0.0.0:18080 -t x' -notmatch (Get-PatronPhp $cfg)) 'patron php: otro puerto (18080) no es el de 8080'
Afirmar ('"C:\app\caddy.exe" run --config "C:\app\Caddyfile" --adapter caddyfile' -match (Get-PatronCaddy 'C:\app')) 'patron caddy: su Caddyfile'
Afirmar ('"C:\otra\caddy.exe" run --config "C:\otra\Caddyfile"' -notmatch (Get-PatronCaddy 'C:\app')) 'patron caddy: el de otra carpeta no'

Write-Host ("`n{0}/{1} pruebas OK" -f ($script:total - $script:fallas), $script:total)
if ($script:fallas) { exit 1 } else { exit 0 }
