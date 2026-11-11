<#
  Pruebas de integración del watchdog contra una instalación REAL en Windows
  (laboratorio). Rompen cosas a propósito: matan y congelan php/caddy, detienen
  y congelan el Spooler, desconectan una impresora. NUNCA correr en un restaurante.

  Requisitos: PowerShell como administrador; el watchdog ya instalado en -App con
  instalar.ps1 (tarea como SYSTEM). Crea y luego quita una impresora falsa
  "PRUEBA-WD" (puerto TCP 127.0.0.1:9101) para contar tickets sin papel.

  Uso:
    powershell -ExecutionPolicy Bypass -File pruebas-integracion.ps1 -App C:\gs-wd-test\app `
      -NombreTarea 'ID-Printer Watchdog PRUEBA' [-Escenarios base,matar_php,...] [-ImpresoraReal Caja]

  Resultado: tabla en pantalla y JSON en <App>\logs\watchdog\pruebas\<fecha>.json
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$App,
    [string]$NombreTarea = 'ID-Printer Watchdog',
    [string[]]$Escenarios = @('base', 'reenvio', 'legacy', 'matar_php', 'matar_caddy', 'colgar_php', 'trabajo_activo', 'concurrencia', 'previo_activo', 'puerto_ajeno', 'impresora_desconectada', 'spooler_detenido', 'spooler_colgado'),
    [string]$ImpresoraReal,
    [int]$PuertoFalso = 9101,
    [switch]$SinLimpiar
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
# Con -File una lista llega como un solo texto "a,b,c".
$Escenarios = @($Escenarios | ForEach-Object { $_ -split ',' } | Where-Object { $_ })
. (Join-Path $PSScriptRoot '..\idprinter-watchdog.ps1') -Biblioteca -App $App
$cfg = Get-Config $App
$DirWd = Join-Path $App 'logs\watchdog'
$LogWd = Join-Path $DirWd 'watchdog.log'
$Tarea = "\GrowthSuite\$NombreTarea"
$UrlPhp = "http://127.0.0.1:$($cfg.PuertoPhp)"
$UrlCaddy = "https://127.0.0.1:$($cfg.PuertoCaddy)"
$DirFalsa = Join-Path (Split-Path $App) 'impresora'
$Impresora = 'PRUEBA-WD'
$Resultados = New-Object System.Collections.ArrayList
$Inicio = Get-Date

# ── utilidades ───────────────────────────────────────────────────────────────
function Log([string]$m) { Write-Host ("[{0}] {1}" -f (Get-Date -Format HH:mm:ss), $m) }

function Invoke-ComoSystem([string]$codigo, [int]$esperaSeg = 60, [switch]$SinEsperar) {
    $id = 'gs-wdtest-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
    $ps1 = Join-Path $env:TEMP "$id.ps1"; $out = Join-Path $env:TEMP "$id.out"
    Set-Content -LiteralPath $ps1 -Value ("`$ErrorActionPreference='Continue'`r`n" + $codigo + "`r`n") -Encoding UTF8
    $a = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command `"& '$ps1' *> '$out'`""
    $set = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Hours 2) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    Register-ScheduledTask -TaskName $id -Action $a -User SYSTEM -RunLevel Highest -Settings $set -Force | Out-Null
    Start-ScheduledTask -TaskName $id
    if ($SinEsperar) { Start-Sleep 2; return $id }
    $l = (Get-Date).AddSeconds($esperaSeg)
    do { Start-Sleep -Milliseconds 500 } while ((Get-ScheduledTask -TaskName $id).State -eq 'Running' -and (Get-Date) -lt $l)
    Unregister-ScheduledTask -TaskName $id -Confirm:$false
    $r = if (Test-Path $out) { Get-Content $out -Raw } else { '' }
    Remove-Item $ps1, $out -ErrorAction SilentlyContinue
    $r
}
function Stop-TareaSystem([string]$id) {
    try { Stop-ScheduledTask -TaskName $id -ErrorAction SilentlyContinue; Unregister-ScheduledTask -TaskName $id -Confirm:$false -ErrorAction SilentlyContinue } catch { }
}

$CodigoSuspender = @'
Add-Type -Namespace Gs -Name Nt -MemberDefinition '[DllImport("ntdll.dll")] public static extern int NtSuspendProcess(IntPtr h); [DllImport("ntdll.dll")] public static extern int NtResumeProcess(IntPtr h);'
'@
function Suspend-Proceso([int]$id) { Invoke-ComoSystem ($CodigoSuspender + "`r`n[Gs.Nt]::NtSuspendProcess((Get-Process -Id $id).Handle)") 30 | Out-Null }
function Resume-Proceso([int]$id) { Invoke-ComoSystem ($CodigoSuspender + "`r`n[Gs.Nt]::NtResumeProcess((Get-Process -Id $id).Handle)") 30 | Out-Null }

function Wait-Hasta([scriptblock]$cond, [int]$timeoutSeg, [int]$pasoMs = 1000) {
    $t0 = Get-Date
    do {
        try { if (& $cond) { return [Math]::Round(((Get-Date) - $t0).TotalSeconds, 1) } } catch { }
        Start-Sleep -Milliseconds $pasoMs
    } while (((Get-Date) - $t0).TotalSeconds -lt $timeoutSeg)
    $null
}
function Get-Health([string]$base = $UrlPhp) {
    try { (Invoke-WebRequest -UseBasicParsing -Uri "$base/nprint/health" -TimeoutSec 5).Content | ConvertFrom-Json } catch { $null }
}
function Get-Salud { Read-JsonArchivo (Join-Path $DirWd 'salud.json') }
# Se devuelve la variable (no la llamada) para que PowerShell desenvuelva la lista.
function Get-PidsPhp { $a = Get-ProcesosPropios (Join-Path $App 'php\php.exe') (Get-PatronPhp $cfg); $a }
function Get-PidsCaddy { $a = Get-ProcesosPropios (Join-Path $App 'caddy.exe') (Get-PatronCaddy $App); $a }
function Get-LineasLogDesde([datetime]$t) {
    if (-not (Test-Path $LogWd)) { return @() }
    @(Get-Content $LogWd | Where-Object { $_ -match '^(\S+) ' -and ([datetime]::Parse($Matches[1]) -ge $t) })
}
function Get-HoraLinea([string]$patron, [datetime]$desde) {
    $l = Get-LineasLogDesde $desde | Where-Object { $_ -match $patron } | Select-Object -First 1
    if ($l -and $l -match '^(\S+) ') { [datetime]::Parse($Matches[1]) } else { $null }
}
function Get-Tickets { @(Get-ChildItem -LiteralPath $DirFalsa -Filter 'ticket_*.bin' -ErrorAction SilentlyContinue).Count }
function Send-Comanda([string]$impresora = $Impresora, [string]$uid = ([guid]::NewGuid().ToString()), [string]$texto = 'TICKET DE PRUEBA WATCHDOG', [switch]$SinUid, [string]$base = $UrlPhp) {
    $job = @{ printerName = $impresora; data = @{ areaName = 'PRUEBA'; tableName = 'WATCHDOG'; items = @(@{ qty = '1'; name = $texto }) } }
    if (-not $SinUid) { $job.jobUid = $uid }
    $cuerpo = ConvertTo-Json -InputObject @($job) -Depth 6 -Compress
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri "$base/nprint/printers/print-comanda" -Method Post -Body ([Text.Encoding]::UTF8.GetBytes($cuerpo)) -ContentType 'application/json' -TimeoutSec 30
        @{ ok = $true; uid = $uid; cuerpo = ($r.Content | ConvertFrom-Json) }
    } catch {
        # Respuesta HTTP de error con cuerpo JSON (p. ej. 500 con success:0): se lee igual.
        $cuerpoErr = $null
        try { $sr = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream()); $cuerpoErr = $sr.ReadToEnd() | ConvertFrom-Json } catch { }
        if ($cuerpoErr) { @{ ok = $true; http = [int]$_.Exception.Response.StatusCode; uid = $uid; cuerpo = @($cuerpoErr) } }
        else { @{ ok = $false; uid = $uid; error = $_.Exception.Message; cuerpo = @(@{ success = 0 }) } }
    }
}
function Get-Trabajo([string]$uid) {
    try { @((Invoke-WebRequest -UseBasicParsing -Uri "$UrlPhp/nprint/jobs?uids=$uid" -TimeoutSec 10).Content | ConvertFrom-Json)[0] } catch { $null }
}
function Invoke-Watchdog-Ya { & schtasks.exe /Run /TN $Tarea | Out-Null }
function Wait-Sano([int]$timeoutSeg = 150) { Wait-Hasta { $h = Get-Health; $h -and $h.estadoGeneral -eq 'disponible' -and (Get-Health $UrlCaddy) } $timeoutSeg 2000 }

function Add-Resultado([string]$id, [string]$nombre, [bool]$ok, [string]$evidencia, [hashtable]$tiempos = @{}, [string]$limite = '') {
    $r = [ordered]@{ id = $id; prueba = $nombre; resultado = $(if ($ok) { 'PASA' } else { 'FALLA' }); evidencia = $evidencia; tiempos = $tiempos; limitacion = $limite; hora = (Get-Date).ToString('s') }
    [void]$Resultados.Add($r)
    $color = if ($ok) { 'Green' } else { 'Red' }
    Write-Host ("  => {0} {1}: {2}" -f $r.resultado, $id, $evidencia) -ForegroundColor $color
}

# ── preparación ─────────────────────────────────────────────────────────────
function Initialize-Laboratorio {
    Log 'Preparando impresora falsa PRUEBA-WD'
    New-Item -ItemType Directory -Force $DirFalsa | Out-Null
    # Restos de una corrida interrumpida: impresora falsa viva, tareas auxiliares.
    New-Item -ItemType File -Force (Join-Path $DirFalsa 'DETENER') | Out-Null
    Start-Sleep 2
    Get-ScheduledTask -TaskName 'gs-wdtest-*' -ErrorAction SilentlyContinue | ForEach-Object { Stop-TareaSystem $_.TaskName }
    Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.CommandLine -match 'impresora-falsa|gs-wdtest-|TcpListener' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -match 'gs_ins' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Remove-Item (Join-Path $DirFalsa 'DETENER') -ErrorAction SilentlyContinue
    Get-ChildItem $DirFalsa -Filter 'ticket_*.bin' -ErrorAction SilentlyContinue | Remove-Item -Force
    $script:TareaFalsa = Invoke-ComoSystem "& powershell -NoProfile -ExecutionPolicy Bypass -File '$(Join-Path $PSScriptRoot 'impresora-falsa.ps1')' -Puerto $PuertoFalso -Dir '$DirFalsa'" -SinEsperar
    $puerto = "GSWD_127.0.0.1_$PuertoFalso"
    if (-not (Get-PrinterPort -Name $puerto -ErrorAction SilentlyContinue)) { Add-PrinterPort -Name $puerto -PrinterHostAddress '127.0.0.1' -PortNumber $PuertoFalso }
    if (-not (Get-Printer -Name $Impresora -ErrorAction SilentlyContinue)) { Add-Printer -Name $Impresora -DriverName 'Generic / Text Only' -PortName $puerto -Shared -ShareName $Impresora }
    Wait-Hasta { (Test-NetConnection 127.0.0.1 -Port $PuertoFalso -WarningAction SilentlyContinue).TcpTestSucceeded } 20 | Out-Null
    Remove-Item (Join-Path $DirWd 'PAUSA') -ErrorAction SilentlyContinue
    Log 'Esperando estado disponible'
    Invoke-Watchdog-Ya
    if ($null -eq (Wait-Sano 180)) { throw 'La instalacion no llega a "disponible": no se corren pruebas.' }
}
function Clear-Laboratorio {
    Log 'Limpiando'
    New-Item -ItemType File -Force (Join-Path $DirFalsa 'DETENER') | Out-Null
    Start-Sleep 2
    Stop-TareaSystem $script:TareaFalsa
    Get-PrintJob -PrinterName $Impresora -ErrorAction SilentlyContinue | Remove-PrintJob -ErrorAction SilentlyContinue
    Remove-Printer -Name $Impresora -ErrorAction SilentlyContinue
    Start-Sleep 2
    Remove-PrinterPort -Name "GSWD_127.0.0.1_$PuertoFalso" -ErrorAction SilentlyContinue
    Remove-Item (Join-Path $DirWd 'PAUSA') -ErrorAction SilentlyContinue
}

# Mide un ciclo falla → detección → recuperación a partir de la bitácora.
function Measure-Recuperacion([datetime]$t0, [string]$componente, [scriptblock]$sano, [int]$timeoutSeg = 200) {
    $seg = Wait-Hasta $sano $timeoutSeg 1000
    $det = Get-HoraLinea "$componente`: falla detectada" $t0
    $rec = Get-HoraLinea "$componente`: (iniciar|reiniciar) OK" $t0
    @{
        deteccionSeg   = if ($det) { [Math]::Round(($det - $t0).TotalSeconds, 1) } else { $null }
        recuperacionSeg = if ($rec -and $det) { [Math]::Round(($rec - $det).TotalSeconds, 1) } else { $null }
        hastaResponderSeg = $seg
    }
}

# ── escenarios ──────────────────────────────────────────────────────────────
$E = @{}

$E.base = {
    Log 'base: estado, health por http y https, ticket a impresora falsa'
    $h = Get-Health; $hs = Get-Health $UrlCaddy
    $antes = Get-Tickets
    $r = Send-Comanda
    $llego = Wait-Hasta { (Get-Tickets) -eq $antes + 1 } 30
    $impreso = Wait-Hasta { $j = Get-Trabajo $r.uid; $j -and $j.status -eq 'impreso' } 60 2000
    $j = Get-Trabajo $r.uid
    $usr = (Get-WinEvent -FilterHashtable @{ LogName = 'Microsoft-Windows-PrintService/Operational'; Id = 307; StartTime = (Get-Date).AddMinutes(-2) } -MaxEvents 5 -ErrorAction SilentlyContinue | ForEach-Object { ([xml]$_.ToXml()).Event.UserData.DocumentPrinted } | Where-Object { $_.Param5 -eq $Impresora } | Select-Object -First 1).Param3
    $ok = $h.estadoGeneral -eq 'disponible' -and $hs -and $r.ok -and $r.cuerpo[0].success -eq 1 -and $null -ne $llego -and $j.status -eq 'impreso'
    Add-Resultado 'S01' 'Servidor disponible e imprime como SYSTEM' $ok ("health=$($h.estadoGeneral) https=$([bool]$hs) success=$($r.cuerpo[0].success) ticketsFalsa=+$((Get-Tickets) - $antes) jobs=$($j.status) evento307.usuario=$usr") @{ entregaSeg = $llego; confirmadoImpresoSeg = $impreso }
}

$E.reenvio = {
    Log 'reenvio: mismo jobUid dos veces (red que reintenta) y dos jobUid distintos'
    $antes = Get-Tickets
    $uid = [guid]::NewGuid().ToString()
    $r1 = Send-Comanda -uid $uid
    Wait-Hasta { (Get-Tickets) -eq $antes + 1 } 30 | Out-Null
    $r2 = Send-Comanda -uid $uid
    Start-Sleep 8
    $dup = Get-Tickets
    $r3 = Send-Comanda
    Wait-Hasta { (Get-Tickets) -eq $dup + 1 } 30 | Out-Null
    $ok = $r1.cuerpo[0].success -eq 1 -and $r2.cuerpo[0].duplicate -eq $true -and $dup -eq $antes + 1 -and (Get-Tickets) -eq $antes + 2
    Add-Resultado 'S16' 'Reintento de una solicitud ya procesada no duplica' $ok "1.a success=$($r1.cuerpo[0].success); reenvio duplicate=$($r2.cuerpo[0].duplicate); tickets tras reenvio=+$($dup - $antes) (esperado +1); uid nuevo=+$((Get-Tickets) - $antes) (esperado +2)"
}

$E.legacy = {
    Log 'legacy: comanda sin jobUid (POS viejos), /printers, /hello, preflight CORS'
    $antes = Get-Tickets
    $r = Send-Comanda -SinUid
    $llego = Wait-Hasta { (Get-Tickets) -eq $antes + 1 } 30
    $lista = @(((Invoke-WebRequest -UseBasicParsing "$UrlPhp/nprint/printers" -TimeoutSec 30).Content | ConvertFrom-Json).printers)
    $enLista = @($lista | Where-Object { $_.name -eq $Impresora }).Count
    $hello = (Invoke-WebRequest -UseBasicParsing "$UrlCaddy/nprint/hello" -TimeoutSec 10).Content
    $pre = Invoke-WebRequest -UseBasicParsing "$UrlPhp/nprint/printers/print-comanda" -Method Options -Headers @{ Origin = 'https://pos.example'; 'Access-Control-Request-Method' = 'POST' } -TimeoutSec 10
    $ok = $r.cuerpo[0].success -eq 1 -and $null -ne $llego -and $enLista -eq 1 -and $hello -match 'Hello' -and $pre.StatusCode -eq 204
    Add-Resultado 'S17' 'Compatibilidad LEGACY (sin jobUid, endpoints existentes)' $ok "sin jobUid success=$($r.cuerpo[0].success) ticket=$([bool]$llego); /printers incluye PRUEBA-WD=$enLista; /hello por https='$hello'; preflight=$($pre.StatusCode)"
}

$E.matar_php = {
    Log 'matar_php: Stop-Process del php de esta instalacion'
    $viejo = @(Get-PidsPhp)
    $t0 = Get-Date
    foreach ($p in $viejo) { Stop-Process -Id $p -Force }
    $m = Measure-Recuperacion $t0 'php' { (Get-Health) -and @(Get-PidsPhp).Count -eq 1 }
    $nuevo = @(Get-PidsPhp)
    $ok = $null -ne $m.hastaResponderSeg -and $nuevo.Count -eq 1 -and ($viejo -notcontains $nuevo[0])
    Add-Resultado 'S03' 'Cierre forzado de PHP' $ok "pid $($viejo -join ',') -> $($nuevo -join ','); procesos php=$($nuevo.Count)" $m 'La deteccion depende del intervalo de la tarea (1 min).'
}

$E.matar_caddy = {
    Log 'matar_caddy: Stop-Process del caddy de esta instalacion'
    $viejo = @(Get-PidsCaddy)
    $t0 = Get-Date
    foreach ($p in $viejo) { Stop-Process -Id $p -Force }
    $m = Measure-Recuperacion $t0 'caddy' { (Get-Health $UrlCaddy) -and @(Get-PidsCaddy).Count -ge 1 }
    $nuevo = @(Get-PidsCaddy)
    $ok = $null -ne $m.hastaResponderSeg -and ($viejo -notcontains $nuevo[0])
    Add-Resultado 'S04' 'Cierre forzado de Caddy' $ok "pid $($viejo -join ',') -> $($nuevo -join ',')" $m
}

$E.colgar_php = {
    Log 'colgar_php: NtSuspendProcess al php (vivo pero sin responder)'
    $viejo = @(Get-PidsPhp)
    $t0 = Get-Date
    Suspend-Proceso $viejo[0]
    $m = Measure-Recuperacion $t0 'php' { (Get-Health) -and @(Get-PidsPhp).Count -eq 1 -and @(Get-PidsPhp)[0] -ne $viejo[0] } 240
    $nuevo = @(Get-PidsPhp)
    $quedo = Get-Process -Id $viejo[0] -ErrorAction SilentlyContinue
    $ok = $null -ne $m.hastaResponderSeg -and -not $quedo -and $nuevo.Count -eq 1
    Add-Resultado 'S05' 'PHP activo pero bloqueado (endpoint sin respuesta)' $ok "pid congelado $($viejo[0]) eliminado=$(-not $quedo); nuevo=$($nuevo -join ',')" $m 'Cubre tambien S06 (endpoint sin respuesta): la sonda es HTTP real, no solo el proceso.'
    if ($quedo) { Resume-Proceso $viejo[0] }
}

$E.trabajo_activo = {
    Log 'trabajo_activo: php congelado con un trabajo "recibido" reciente -> debe aplazar el reinicio'
    $db = Join-Path $App 'public\nprint\data\print_jobs.sqlite'
    $uid = [guid]::NewGuid().ToString()
    $php = Join-Path $App 'php\php.exe'
    $sql = Join-Path $env:TEMP 'gs_ins.php'
    # Mantiene el trabajo "recibido" fresco durante toda la prueba (simula un ticket en curso).
    Set-Content $sql -Encoding ASCII -Value "<?php `$p=new PDO('sqlite:'.`$argv[1]); for(`$i=0;`$i<60;`$i++){ `$p->prepare(""INSERT OR REPLACE INTO print_jobs(job_uid,endpoint,status,received_ms,created_ms) VALUES(?,?,'recibido',?,?)"")->execute([`$argv[2],'/printers/print-comanda',(int)(microtime(true)*1000),(int)(microtime(true)*1000)]); sleep(5);} "
    $tInsert = Invoke-ComoSystem "& '$php' -d opcache.enable_cli=0 '$sql' '$db' '$uid'" -SinEsperar
    Start-Sleep 3
    $viejo = @(Get-PidsPhp)
    $t0 = Get-Date
    Suspend-Proceso $viejo[0]
    $m = Measure-Recuperacion $t0 'php' { (Get-Health) -and @(Get-PidsPhp)[0] -ne $viejo[0] } 360
    Stop-TareaSystem $tInsert
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -match 'gs_ins' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    $aplazo = @(Get-LineasLogDesde $t0 | Where-Object { $_ -match 'se aplaza el reinicio' }).Count
    $ok = $aplazo -eq $cfg.MaxAplazamientos -and $null -ne $m.hastaResponderSeg
    Add-Resultado 'S15' 'Trabajo pendiente durante una falla de PHP' $ok "aplazamientos registrados=$aplazo (esperado $($cfg.MaxAplazamientos)); despues reinicia y responde" $m 'El trabajo "recibido" se simula en la bitacora; el comportamiento con un ticket real a medias lo cubre E-15 (buffer parcial descartado) + reenvio por jobUid.'
}

$E.concurrencia = {
    Log 'concurrencia: tarea + 3 ejecuciones manuales a la vez'
    $t0 = Get-Date
    $ids = @()
    for ($i = 0; $i -lt 3; $i++) { $ids += Invoke-ComoSystem "& powershell -NoProfile -ExecutionPolicy Bypass -File '$(Join-Path $App 'watchdog\idprinter-watchdog.ps1')'" -SinEsperar }
    Invoke-Watchdog-Ya
    Start-Sleep 30
    foreach ($i in $ids) { Stop-TareaSystem $i }
    $otra = @(Get-LineasLogDesde $t0 | Where-Object { $_ -match 'otra ejecucion del watchdog' }).Count
    $php = @(Get-PidsPhp).Count; $cad = @(Get-PidsCaddy).Count
    $ok = $otra -ge 1 -and $php -eq 1 -and $cad -ge 1 -and (Get-Health)
    Add-Resultado 'S12' 'Multiples ejecuciones del watchdog' $ok "ejecuciones que cedieron por el candado=$otra; php=$php caddy=$cad; responde=$([bool](Get-Health))"
}

$E.previo_activo = {
    Log 'previo_activo: lanzar el iniciar_servidor.vbs (doble clic / tarea vieja) con el servidor ya arriba'
    $antes = @(Get-PidsPhp)
    Invoke-ComoSystem "Start-Process wscript.exe -ArgumentList '`"$(Join-Path $App 'iniciar_servidor.vbs')`" /silent' -Wait" 30 | Out-Null
    Start-Sleep 3
    $despues = @(Get-PidsPhp)
    $ok = ($antes -join ',') -eq ($despues -join ',') -and (Get-Health)
    Add-Resultado 'S14' 'Procesos previamente activos (no duplica)' $ok "php antes=$($antes -join ',') despues=$($despues -join ',')"
}

$E.puerto_ajeno = {
    Log 'puerto_ajeno: otro programa toma el puerto de php -> no se pelea, se reporta'
    New-Item -ItemType File -Force (Join-Path $DirWd 'PAUSA') | Out-Null
    foreach ($p in @(Get-PidsPhp)) { Stop-Process -Id $p -Force }
    Start-Sleep 2
    $intruso = Invoke-ComoSystem "`$l=New-Object Net.Sockets.TcpListener([Net.IPAddress]::Any,$($cfg.PuertoPhp)); `$l.Start(); Start-Sleep 600" -SinEsperar
    Start-Sleep 4
    $duenoIntruso = Get-DuenoPuerto $cfg.PuertoPhp
    Remove-Item (Join-Path $DirWd 'PAUSA')
    $t0 = Get-Date
    Invoke-Watchdog-Ya
    $vio = Wait-Hasta { $s = Get-Salud; $s -and [long]$s.generadoEnMs -gt (Get-EpochMs $t0) } 120
    $s = Get-Salud
    $sigue = Get-Process -Id $duenoIntruso -ErrorAction SilentlyContinue
    $okAjeno = $s.estadoGeneral -eq 'requiere_intervencion' -and (@($s.requiereIntervencion) -join ' ') -match 'puerto' -and $sigue -and @(Get-PidsPhp).Count -eq 0
    Stop-TareaSystem $intruso
    if ($sigue) { Stop-Process -Id $duenoIntruso -Force -ErrorAction SilentlyContinue }
    $t1 = Get-Date
    Invoke-Watchdog-Ya
    $rec = Wait-Hasta { Get-Health } 120
    Add-Resultado 'S06b' 'Conflicto de puerto con otro programa' ($okAjeno -and $null -ne $rec) "estado=$($s.estadoGeneral) motivo='$(@($s.requiereIntervencion) -join '; ')' intruso intacto=$([bool]$sigue); al liberarse el puerto php vuelve en $rec s" @{ reporteSeg = $vio; recuperacionTrasLiberarSeg = $rec }
}

$E.impresora_desconectada = {
    Log 'impresora_desconectada: la impresora falsa deja de contestar, se manda un ticket, vuelve'
    New-Item -ItemType File -Force (Join-Path $DirFalsa 'DETENER') | Out-Null
    Wait-Hasta { -not (Test-NetConnection 127.0.0.1 -Port $PuertoFalso -WarningAction SilentlyContinue).TcpTestSucceeded } 20 | Out-Null
    Stop-TareaSystem $script:TareaFalsa
    $t0 = Get-Date
    Invoke-Watchdog-Ya
    $det = Wait-Hasta { $h = Get-Health; $h -and @($h.impresoras | Where-Object { $_.nombre -eq $Impresora -and $_.estado -eq 'no_disponible' }).Count } 120 2000
    $hDesc = Get-Health
    $antes = Get-Tickets
    $r = Send-Comanda -texto 'TICKET MANDADO CON LA IMPRESORA DESCONECTADA'
    Start-Sleep 10
    $jEnCola = Get-Trabajo $r.uid
    # Reconexión
    Remove-Item (Join-Path $DirFalsa 'DETENER') -ErrorAction SilentlyContinue
    $script:TareaFalsa = Invoke-ComoSystem "& powershell -NoProfile -ExecutionPolicy Bypass -File '$(Join-Path $PSScriptRoot 'impresora-falsa.ps1')' -Puerto $PuertoFalso -Dir '$DirFalsa'" -SinEsperar
    $t1 = Get-Date
    $salio = Wait-Hasta { (Get-Tickets) -ge $antes + 1 } 300 2000
    Invoke-Watchdog-Ya
    $vuelve = Wait-Hasta { $h = Get-Health; $h -and @($h.impresoras | Where-Object { $_.nombre -eq $Impresora -and $_.estado -eq 'disponible' }).Count } 120 2000
    Start-Sleep 20
    $total = (Get-Tickets) - $antes
    $okDet = $null -ne $det -and $hDesc.estadoGeneral -eq 'impresora_con_problema'
    Add-Resultado 'S09' 'Impresora desconectada (red)' $okDet "health=$($hDesc.estadoGeneral); PRUEBA-WD=no_disponible en $det s; el ticket quedo '$($jEnCola.status)' ($($jEnCola.windowsStatus))" @{ deteccionSeg = $det } 'Simulada: impresora de red que deja de contestar en su puerto TCP. Desconectar fisicamente una USB/red queda como prueba en sitio.'
    Add-Resultado 'S10' 'Reconexion de impresora: el ticket pendiente sale UNA vez' ($null -ne $salio -and $total -eq 1 -and $null -ne $vuelve) "ticket en cola entregado tras reconectar en $salio s; tickets recibidos=$total (esperado 1); estado vuelve a disponible en $vuelve s" @{ entregaTrasReconexionSeg = $salio; estadoDisponibleSeg = $vuelve } 'El reintento lo hace el Spooler de Windows (el watchdog no reenvia nada).'
}

$E.spooler_detenido = {
    Log 'spooler_detenido: Stop-Service Spooler (afecta a toda la PC unos segundos)'
    $t0 = Get-Date
    Stop-Service Spooler -Force
    $antes = Get-Tickets
    $r = Send-Comanda -texto 'TICKET CON EL SPOOLER DETENIDO'
    $m = Measure-Recuperacion $t0 'spooler' { (Get-Service Spooler).Status -eq 'Running' -and (Get-Health).spooler.responde } 200
    Invoke-Watchdog-Ya; Start-Sleep 25
    $r2 = Send-Comanda -uid $r.uid -texto 'TICKET CON EL SPOOLER DETENIDO'
    $llego = Wait-Hasta { (Get-Tickets) -ge $antes + 1 } 60
    Start-Sleep 10
    $total = (Get-Tickets) - $antes
    $ok = $null -ne $m.hastaResponderSeg -and $r.cuerpo[0].success -eq 0 -and $r2.cuerpo[0].success -eq 1 -and $total -eq 1
    Add-Resultado 'S07-08' 'Caida y recuperacion del Spooler' $ok "con spooler abajo el ticket se rechaza (success=$($r.cuerpo[0].success)) y NO queda como impreso; el reintento del POS con el mismo jobUid sale (success=$($r2.cuerpo[0].success)); tickets=$total (esperado 1)" $m
}

$E.spooler_colgado = {
    Log 'spooler_colgado: NtSuspendProcess a spoolsv.exe (vivo pero no responde)'
    $sp = (Get-CimInstance Win32_Service -Filter "Name='Spooler'").ProcessId
    $t0 = Get-Date
    Suspend-Proceso $sp
    $m = Measure-Recuperacion $t0 'spooler' { $s = Get-CimInstance Win32_Service -Filter "Name='Spooler'"; $s.State -eq 'Running' -and $s.ProcessId -ne $sp -and (Get-Health).spooler.responde } 420
    $avisos = @(Get-LineasLogDesde $t0 | Where-Object { $_ -match 'spooler: no responde \(\d/' }).Count
    $quedo = Get-Process -Id $sp -ErrorAction SilentlyContinue
    if ($quedo) { Resume-Proceso $sp }
    $ok = $null -ne $m.hastaResponderSeg -and $avisos -ge 1 -and -not $quedo
    Add-Resultado 'S07b' 'Spooler colgado: reinicio tras confirmaciones' $ok "revisiones previas al reinicio=$avisos; spoolsv $sp eliminado=$(-not $quedo)" $m 'Reiniciar el Spooler puede repetir desde el inicio un trabajo que se estuviera imprimiendo; por eso exige 3 revisiones seguidas.'
}

$E.impresora_real = {
    if (-not $ImpresoraReal) { return }
    Log "impresora_real: UN ticket a la impresora fisica '$ImpresoraReal' desde la instancia SYSTEM"
    $r = Send-Comanda -impresora $ImpresoraReal -texto 'PRUEBA WATCHDOG - NO SACAR'
    $impreso = Wait-Hasta { $j = Get-Trabajo $r.uid; $j -and $j.status -eq 'impreso' } 90 2000
    $j = Get-Trabajo $r.uid
    Add-Resultado 'S18' "Impresion en hardware real ($ImpresoraReal)" ($r.cuerpo[0].success -eq 1 -and $j.status -eq 'impreso') "success=$($r.cuerpo[0].success); Windows: $($j.status) (evento 307) en $impreso s" @{ confirmadoWindowsSeg = $impreso } 'Windows confirma que lo entrego a la impresora (driver Generic/Text Only no reporta papel). La salida fisica del papel requiere que una persona lo vea.'
}

# ── ejecución ───────────────────────────────────────────────────────────────
try {
    Initialize-Laboratorio
    foreach ($nombre in ($Escenarios + $(if ($ImpresoraReal) { 'impresora_real' }))) {
        if (-not $E.ContainsKey($nombre)) { Write-Warning "escenario desconocido: $nombre"; continue }
        # Aislamiento: cada escenario empieza sin historial de recuperaciones (el
        # backoff tiene sus pruebas propias en pruebas-decision.ps1).
        New-Item -ItemType File -Force (Join-Path $DirWd 'PAUSA') | Out-Null
        Start-Sleep 1
        Remove-Item (Join-Path $DirWd 'estado.json') -ErrorAction SilentlyContinue
        Remove-Item (Join-Path $DirWd 'PAUSA') -ErrorAction SilentlyContinue
        try { & $E[$nombre] } catch { Add-Resultado $nombre "Escenario $nombre" $false "error del arnes: $($_.Exception.Message) @ $($_.InvocationInfo.ScriptLineNumber)" }
        # Cada escenario arranca de un estado sano.
        if ($null -eq (Wait-Sano 240)) { Write-Warning "tras $nombre no volvio a 'disponible' en 4 min" }
    }
} finally {
    if (-not $SinLimpiar) { Clear-Laboratorio }
    $dirOut = Join-Path $DirWd 'pruebas'
    New-Item -ItemType Directory -Force $dirOut | Out-Null
    $archivo = Join-Path $dirOut ("integracion_{0}.json" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
    Write-JsonArchivo $archivo @{ equipo = $env:COMPUTERNAME; app = $App; inicio = $Inicio.ToString('s'); fin = (Get-Date).ToString('s'); resultados = $Resultados.ToArray() }
    Write-Host "`n==== RESUMEN ===="
    $Resultados | ForEach-Object { "{0,-7} {1,-6} {2}" -f $_.id, $_.resultado, $_.prueba }
    Write-Host "JSON: $archivo"
}
if (@($Resultados | Where-Object { $_.resultado -ne 'PASA' }).Count) { exit 1 } else { exit 0 }
