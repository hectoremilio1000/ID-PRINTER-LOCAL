<#
  Después de reiniciar Windows: ¿cuánto tardó el ID-Printer en responder solo, y
  había alguien con sesión iniciada? Se corre (como administrador) una vez que la
  PC volvió. Lee la hora de arranque, la bitácora del watchdog y las sesiones.

  Uso: powershell -File medir-arranque.ps1 -App C:\gs-wd-test\app
#>
param([Parameter(Mandatory)][string]$App)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\idprinter-watchdog.ps1') -Biblioteca -App $App
$cfg = Get-Config $App
$arranque = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime
$log = Join-Path $App 'logs\watchdog\watchdog.log'
$lineas = @(Get-Content $log | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^(\S+) ' -and [datetime]::Parse($Matches[1]) -ge $arranque })
function Hora($patron) { $l = $lineas | Where-Object { $_ -match $patron } | Select-Object -First 1; if ($l -match '^(\S+) ') { [datetime]::Parse($Matches[1]) } }
$primera = Hora '.'
$php = Hora 'php: iniciar OK'
$caddy = Hora 'caddy: iniciar OK'
$disp = Hora 'estado: general=disponible'
$sesiones = @(quser 2>$null | Select-Object -Skip 1)
$procs = @(Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith($App, 'OrdinalIgnoreCase') } | ForEach-Object {
        $o = Invoke-CimMethod -InputObject $_ -MethodName GetOwner
        "{0} pid={1} usuario={2}\{3} sesion={4} creado={5:HH:mm:ss}" -f $_.Name, $_.ProcessId, $o.Domain, $o.User, $_.SessionId, $_.CreationDate })
$seg = { param($t) if ($t) { [Math]::Round(($t - $arranque).TotalSeconds, 1) } else { $null } }
$r = [ordered]@{
    arranqueWindows         = $arranque.ToString('s')
    primeraRevisionSeg      = & $seg $primera
    phpRespondeSeg          = & $seg $php
    caddyRespondeSeg        = & $seg $caddy
    disponibleSeg           = & $seg $disp
    sesionesIniciadasAhora  = $sesiones
    procesos                = $procs
    healthAhora             = (Test-Http "http://127.0.0.1:$($cfg.PuertoPhp)/nprint/health" 5000).ok
    lineasDesdeArranque     = $lineas
}
ConvertTo-Json -InputObject $r -Depth 4
