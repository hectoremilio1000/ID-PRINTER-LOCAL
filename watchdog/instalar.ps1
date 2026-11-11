<#
.SYNOPSIS
  Instala, verifica, pausa o desinstala el watchdog del ID-Printer.

.DESCRIPTION
  Se corre UNA vez por PC, en PowerShell como administrador, desde la carpeta del
  ID-Printer ya actualizada:

    powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1            # instalar / reinstalar
    powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1 -Accion Estado
    powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1 -Accion Desinstalar

  Instalar:
   1. Revisa el entorno (Windows, PowerShell >= 4, administrador, archivos del ID-Printer).
   2. Respalda en logs\watchdog\respaldos\<fecha>\ las tareas de arranque que ya
      existan (XML) y un manifiesto para poder regresar.
   3. DESHABILITA (no borra) las tareas viejas que lanzan iniciar_servidor.vbs,
      para que no compitan con el watchdog al arrancar.
   4. Registra la tarea "\GrowthSuite\ID-Printer Watchdog" (SYSTEM, al encender
      Windows y cada minuto).
   5. Activa el registro de impresión de Windows (evento 307), si estaba apagado.
   6. Corre el watchdog una vez y muestra el resultado.

  No reinicia nada que esté funcionando: si php/caddy ya contestan (aunque los
  haya abierto una persona), el watchdog los deja como están. Desde el siguiente
  arranque de Windows los levanta él como SYSTEM.

  Desinstalar: quita la tarea del watchdog y vuelve a habilitar las tareas que
  esta instalación había deshabilitado. php/caddy siguen corriendo.

  No modifica certificados, impresoras, php.ini, Caddyfile ni la bitácora de impresión.
#>
[CmdletBinding()]
param(
    [ValidateSet('Instalar', 'Estado', 'Verificar', 'Desinstalar', 'Pausar', 'Reanudar')]
    [string]$Accion = 'Instalar',
    [string]$App,
    [ValidateRange(1, 30)]
    [int]$IntervaloMin = 1,
    [switch]$SinRegistroImpresion,
    # Solo para laboratorio (varias copias en la misma PC). En restaurantes, el de omisión.
    [string]$NombreTarea = 'ID-Printer Watchdog'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$CarpetaTarea = '\GrowthSuite\'
$RutaTarea = "$CarpetaTarea$NombreTarea"

if (-not $App) { $App = Split-Path -Parent $PSScriptRoot }
$App = (Resolve-Path -LiteralPath $App).Path
$Watchdog = Join-Path $App 'watchdog\idprinter-watchdog.ps1'
$DirWd = Join-Path $App 'logs\watchdog'
. $Watchdog -Biblioteca -App $App

function Paso([string]$t) { Write-Host "`n== $t" -ForegroundColor Cyan }
function Ok([string]$t) { Write-Host "   OK  $t" -ForegroundColor Green }
function Aviso([string]$t) { Write-Host "   !!  $t" -ForegroundColor Yellow }
function Falla([string]$t) { Write-Host "   XX  $t" -ForegroundColor Red }

function Test-Admin {
    $p = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-TareasDeArranqueViejas {
    # Tareas (fuera de \Microsoft\) que lanzan el iniciar_servidor.vbs de ESTA carpeta.
    # Las de otras copias del ID-Printer en la misma PC no se tocan.
    $vbs = [regex]::Escape((Join-Path $App 'iniciar_servidor.vbs'))
    @(Get-ScheduledTask | Where-Object {
            $_.TaskPath -notlike '\Microsoft\*' -and -not ($_.TaskPath -eq $CarpetaTarea -and $_.TaskName -eq $NombreTarea) -and
            (@($_.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' ') -match $vbs
        })
}

function New-XmlTarea {
    $ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $inicio = (Get-Date).AddMinutes(1).ToString('yyyy-MM-ddTHH:mm:00')
    $esc = { param($s) [Security.SecurityElement]::Escape($s) }
    @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo>
    <Description>GrowthSuite: arranca, vigila y recupera el ID-Printer (php + caddy), el Spooler y las impresoras. Ver watchdog\idprinter-watchdog.ps1.</Description>
    <URI>$(& $esc $RutaTarea)</URI>
  </RegistrationInfo>
  <Triggers>
    <BootTrigger><Enabled>true</Enabled></BootTrigger>
    <TimeTrigger>
      <Repetition><Interval>PT${IntervaloMin}M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition>
      <StartBoundary>$inicio</StartBoundary>
      <Enabled>true</Enabled>
    </TimeTrigger>
  </Triggers>
  <Principals>
    <Principal id="Author">
      <UserId>S-1-5-18</UserId>
      <RunLevel>HighestAvailable</RunLevel>
    </Principal>
  </Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <IdleSettings><StopOnIdleEnd>false</StopOnIdleEnd><RestartOnIdle>false</RestartOnIdle></IdleSettings>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
    <Hidden>false</Hidden>
    <RunOnlyIfIdle>false</RunOnlyIfIdle>
    <WakeToRun>false</WakeToRun>
    <ExecutionTimeLimit>PT10M</ExecutionTimeLimit>
    <Priority>4</Priority>
  </Settings>
  <Actions Context="Author">
    <Exec>
      <Command>$(& $esc $ps)</Command>
      <Arguments>-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "$(& $esc $Watchdog)"</Arguments>
      <WorkingDirectory>$(& $esc $App)</WorkingDirectory>
    </Exec>
  </Actions>
</Task>
"@
}

function Show-Salud {
    $s = Read-JsonArchivo (Join-Path $DirWd 'salud.json')
    if (-not $s) { Aviso 'todavia no hay salud.json'; return $null }
    $edad = [Math]::Round(((Get-EpochMs) - [long]$s.generadoEnMs) / 1000)
    $color = if ($s.estadoGeneral -eq 'disponible') { 'Green' } else { 'Yellow' }
    Write-Host ("   estado general: {0}  (hace {1} s)" -f $s.estadoGeneral, $edad) -ForegroundColor $color
    Write-Host ("   php responde: {0}   caddy responde: {1}   spooler: {2}/{3}" -f $s.servidor.php.responde, $s.servidor.caddy.responde, $s.spooler.servicio, $s.spooler.responde)
    foreach ($i in @($s.impresoras)) { Write-Host ("   impresora {0} [{1}] -> {2}" -f $i.nombre, $i.tipo, $i.estado) }
    foreach ($r in @($s.requiereIntervencion)) { Falla "requiere intervencion: $r" }
    $s
}

switch ($Accion) {
    'Instalar' {
        Paso 'Entorno'
        $os = Get-CimInstance Win32_OperatingSystem
        Ok ("{0} (build {1}), PowerShell {2}, equipo {3}" -f $os.Caption, $os.BuildNumber, $PSVersionTable.PSVersion, $env:COMPUTERNAME)
        if ($PSVersionTable.PSVersion.Major -lt 4) { Falla 'Se necesita PowerShell 4 o superior (Windows 8.1+).'; exit 1 }
        if (-not (Test-Admin)) { Falla 'Abre PowerShell como ADMINISTRADOR y vuelve a correrlo.'; exit 1 }
        Ok 'administrador'
        $faltan = Get-ConfigFaltante $App
        if ($faltan.Count) { Falla "Faltan archivos del ID-Printer en ${App}: $($faltan -join ', ')"; exit 1 }
        Ok "ID-Printer completo en $App"
        $sp = Get-Service Spooler -ErrorAction SilentlyContinue
        if (-not $sp) { Falla 'No existe el servicio Cola de impresion (Spooler).'; exit 1 }
        Ok "Spooler: $($sp.Status) / $($sp.StartType)"
        if ($sp.StartType -ne 'Automatic') { Aviso 'El Spooler no esta en Automatico: el watchdog lo arrancara si lo encuentra detenido.' }

        Paso 'Respaldo'
        $fecha = Get-Date -Format 'yyyyMMdd-HHmmss'
        $dirResp = Join-Path $DirWd "respaldos\$fecha"
        New-Item -ItemType Directory -Path $dirResp -Force | Out-Null
        $viejas = @(Get-TareasDeArranqueViejas)
        $manifiesto = @{ fecha = $fecha; app = $App; equipo = $env:COMPUTERNAME; tareasDeshabilitadas = @(); tareaWatchdogPrevia = $false }
        foreach ($t in $viejas) {
            $archivo = Join-Path $dirResp (($t.TaskPath + $t.TaskName).Trim('\') -replace '[\\/:*?"<>|]', '_')
            Export-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-File -LiteralPath "$archivo.xml" -Encoding Unicode
            Ok "tarea respaldada: $($t.TaskPath)$($t.TaskName) [$($t.State)]"
        }
        $previa = Get-ScheduledTask -TaskName $NombreTarea -TaskPath $CarpetaTarea -ErrorAction SilentlyContinue
        if ($previa) {
            Export-ScheduledTask -TaskName $NombreTarea -TaskPath $CarpetaTarea | Out-File -LiteralPath (Join-Path $dirResp 'watchdog_previo.xml') -Encoding Unicode
            $manifiesto.tareaWatchdogPrevia = $true
            Ok 'habia un watchdog instalado: respaldado (se reinstala)'
        }
        foreach ($f in 'iniciar_servidor.vbs', 'watchdog\config.json') {
            $src = Join-Path $App $f
            if (Test-Path -LiteralPath $src) { Copy-Item -LiteralPath $src -Destination $dirResp -Force }
        }
        if (-not $viejas.Count) { Ok 'no habia tareas de arranque previas' }

        Paso 'Tareas de arranque anteriores'
        foreach ($t in $viejas) {
            if ($t.State -ne 'Disabled') {
                Disable-ScheduledTask -TaskName $t.TaskName -TaskPath $t.TaskPath | Out-Null
                $manifiesto.tareasDeshabilitadas += "$($t.TaskPath)$($t.TaskName)"
                Ok "deshabilitada (no borrada): $($t.TaskPath)$($t.TaskName)"
            } else { Ok "ya estaba deshabilitada: $($t.TaskPath)$($t.TaskName)" }
        }
        Write-JsonArchivo (Join-Path $dirResp 'manifiesto.json') $manifiesto

        Paso 'Tarea del watchdog'
        $xml = Join-Path $dirResp 'watchdog_nuevo.xml'
        [IO.File]::WriteAllText($xml, (New-XmlTarea), [Text.Encoding]::Unicode)
        $salida = & schtasks.exe /Create /TN $RutaTarea /XML $xml /RU SYSTEM /F 2>&1
        if ($LASTEXITCODE -ne 0) { Falla "schtasks: $salida"; exit 1 }
        Ok "registrada: $RutaTarea (SYSTEM, al encender y cada $IntervaloMin min)"

        if (-not $SinRegistroImpresion) {
            Paso 'Registro de impresion de Windows'
            $log = 'Microsoft-Windows-PrintService/Operational'
            if ((Get-WinEvent -ListLog $log).IsEnabled) { Ok 'ya estaba activo' }
            else {
                & wevtutil.exe sl $log /e:true /ms:20971520
                if ($LASTEXITCODE -eq 0) { Ok 'activado (evento 307: "documento impreso")' } else { Aviso "no se pudo activar (codigo $LASTEXITCODE)" }
            }
        }

        Paso 'Primera revision'
        $antes = Get-Date
        & schtasks.exe /Run /TN $RutaTarea | Out-Null
        $limite = (Get-Date).AddSeconds(90)
        $s = $null
        do {
            Start-Sleep -Seconds 2
            $s = Read-JsonArchivo (Join-Path $DirWd 'salud.json')
        } while ((-not $s -or [long]$s.generadoEnMs -lt (Get-EpochMs $antes)) -and (Get-Date) -lt $limite)
        if (-not $s -or [long]$s.generadoEnMs -lt (Get-EpochMs $antes)) {
            Falla 'el watchdog no reporto en 90 s. Revisa logs\watchdog\watchdog.log'
            exit 1
        }
        $s = Show-Salud
        Write-Host "`nRespaldo y manifiesto: $dirResp"
        Write-Host 'Bitacora: logs\watchdog\watchdog.log   Estado: /nprint/health'
        if ($s.estadoGeneral -in 'disponible', 'impresora_con_problema') { exit 0 } else { exit 2 }
    }

    { $_ -in 'Estado', 'Verificar' } {
        Paso 'Tarea'
        $t = Get-ScheduledTask -TaskName $NombreTarea -TaskPath $CarpetaTarea -ErrorAction SilentlyContinue
        if (-not $t) { Falla "no esta instalada ($RutaTarea)" } else {
            $i = $t | Get-ScheduledTaskInfo
            Ok ("{0}  estado={1}  ultima={2}  resultado=0x{3:X}  siguiente={4}" -f $RutaTarea, $t.State, $i.LastRunTime, $i.LastTaskResult, $i.NextRunTime)
        }
        foreach ($v in @(Get-TareasDeArranqueViejas)) { Write-Host "   tarea vieja: $($v.TaskPath)$($v.TaskName) [$($v.State)]" }
        if (Test-Path -LiteralPath (Join-Path $DirWd 'PAUSA')) { Aviso 'PAUSADO: existe logs\watchdog\PAUSA' }
        Paso 'Salud'
        $s = Show-Salud
        Paso 'Ultimas lineas de la bitacora'
        $log = Join-Path $DirWd 'watchdog.log'
        if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log -Tail 15 | ForEach-Object { "   $_" } }
        if ($Accion -eq 'Verificar') {
            $okTarea = $t -and $t.State -ne 'Disabled'
            $okSalud = $s -and ((Get-EpochMs) - [long]$s.generadoEnMs) -lt 180000 -and $s.estadoGeneral -in 'disponible', 'impresora_con_problema'
            if ($okTarea -and $okSalud) { Ok 'VERIFICADO'; exit 0 } else { Falla 'NO verificado'; exit 2 }
        }
    }

    'Pausar' {
        New-Item -ItemType File -Path (Join-Path $DirWd 'PAUSA') -Force | Out-Null
        Ok 'watchdog en pausa: no arrancara ni reiniciara nada hasta -Accion Reanudar (sigue escribiendo el estado).'
    }

    'Reanudar' {
        Remove-Item -LiteralPath (Join-Path $DirWd 'PAUSA') -Force -ErrorAction SilentlyContinue
        Ok 'watchdog reanudado.'
    }

    'Desinstalar' {
        if (-not (Test-Admin)) { Falla 'Abre PowerShell como ADMINISTRADOR.'; exit 1 }
        Paso 'Quitar la tarea del watchdog'
        if (Get-ScheduledTask -TaskName $NombreTarea -TaskPath $CarpetaTarea -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $NombreTarea -TaskPath $CarpetaTarea -Confirm:$false
            Ok "quitada: $RutaTarea"
        } else { Ok 'no estaba instalada' }
        Paso 'Restaurar tareas anteriores'
        $ultimo = Get-ChildItem -LiteralPath (Join-Path $DirWd 'respaldos') -Directory -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1
        $m = if ($ultimo) { Read-JsonArchivo (Join-Path $ultimo.FullName 'manifiesto.json') } else { $null }
        if (-not $m) { Aviso 'no hay manifiesto de instalacion: nada que restaurar' }
        foreach ($ruta in @($m.tareasDeshabilitadas)) {
            if (-not $ruta) { continue }
            $p = $ruta.LastIndexOf('\')
            Enable-ScheduledTask -TaskPath $ruta.Substring(0, $p + 1) -TaskName $ruta.Substring($p + 1) | Out-Null
            Ok "habilitada otra vez: $ruta"
        }
        Write-Host '   php/caddy siguen corriendo; la bitacora y los respaldos se conservan en logs\watchdog\.'
    }
}
