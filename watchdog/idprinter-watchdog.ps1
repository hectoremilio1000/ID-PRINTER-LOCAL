<#
.SYNOPSIS
  Supervisor del ID-Printer: arranca, vigila y recupera php + caddy, y revisa el
  Spooler y las impresoras compartidas. Lo corre el Programador de tareas como
  SYSTEM al encender Windows y cada minuto (ver instalar.ps1).

.DESCRIPTION
  Cada ejecución es corta y sin estado en memoria: observa, decide, actúa, escribe
  y termina. Lo que hay que recordar entre ejecuciones (fallas seguidas, cuándo se
  cayó algo, cuántas recuperaciones van) vive en logs\watchdog\estado.json.

  Qué NO hace, a propósito:
   - No imprime nada ni reenvía trabajos: el watchdog nunca genera tickets.
   - No mata procesos ajenos: solo el php.exe / caddy.exe de ESTA carpeta.
   - No pelea un puerto ocupado por otro programa: lo reporta.
   - No reintenta sin límite: tras MaxRecuperaciones en VentanaRecuperacionMin deja
     de recuperar ese componente y lo marca "requiere_intervencion" (backoff).

  Compatible con PowerShell 4 (Windows 8.1) en adelante.

.PARAMETER App
  Carpeta del ID-Printer (la que tiene php\, caddy.exe, Caddyfile). Por omisión, la
  carpeta padre de este script.

.PARAMETER SoloDiagnostico
  Observa y escribe el estado, pero no recupera nada. Para soporte.

.PARAMETER Biblioteca
  Solo define las funciones (las pruebas lo cargan con dot-sourcing).
#>
[CmdletBinding()]
param(
    [string]$App,
    [switch]$SoloDiagnostico,
    [switch]$Biblioteca
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$script:VersionWatchdog = '1.0.0'

# ── Configuración ─────────────────────────────────────────────────────────────

function Get-ConfigPorOmision {
    @{
        PuertoPhp                     = 8080
        PuertoCaddy                   = 9443
        RutaSonda                     = '/nprint/hello'
        TimeoutHttpMs                 = 5000
        # Una sonda que falla se repite antes de declarar el componente colgado:
        # un php ocupado con una petición larga (la 1.a lista de impresoras en frío
        # tarda ~6 s) no debe confundirse con uno muerto.
        ReintentosSonda               = 3
        PausaSondaMs                  = 4000
        # Antes de matar un php colgado que tiene un trabajo a medias, se espera.
        GraciaTrabajoActivoSeg        = 60
        MaxAplazamientos              = 2
        # Backoff de recuperaciones por componente.
        MaxRecuperaciones             = 3
        VentanaRecuperacionMin        = 15
        BackoffInicialMin             = 15
        BackoffMaxMin                 = 60
        # Tras este tiempo estable se olvidan las recuperaciones anteriores: tres
        # fallas aisladas en un día no son "inestable"; un php que cae cada 2 min, sí.
        EstableMin                    = 10
        EsperaArranqueSeg             = 15
        # Impresoras.
        RevisarRedImpresoras          = $true
        # Impresoras compartidas que ya no se usan (p. ej. apuntan a una red vieja):
        # se listan como "ignorada" y no afectan el estado general.
        ImpresorasIgnoradas           = @()
        TimeoutRedImpresoraMs         = 1500
        TimeoutWmiSeg                 = 20
        # Spooler: si está detenido se arranca. Si está colgado (WMI no contesta),
        # se reinicia solo tras N revisiones seguidas, porque reiniciarlo puede
        # repetir desde el inicio el trabajo que se estaba imprimiendo.
        ReiniciarSpoolerColgado       = $true
        ConfirmacionesSpoolerColgado  = 3
        # Bitácora.
        LatidoLogMin                  = 30
        LogMaxKB                      = 1024
        LogArchivos                   = 5
        # salud.json más viejo que esto = el watchdog no está corriendo.
        SaludVigenciaSeg              = 180
    }
}

function Get-Config([string]$app) {
    $cfg = Get-ConfigPorOmision
    $archivo = Join-Path $app 'watchdog\config.json'
    if (Test-Path -LiteralPath $archivo) {
        try {
            $propio = ConvertTo-Hashtable (Get-Content -LiteralPath $archivo -Raw | ConvertFrom-Json)
            foreach ($k in @($propio.Keys)) { if ($cfg.ContainsKey($k)) { $cfg[$k] = $propio[$k] } }
        } catch {
            # Una config rota no debe dejar sin supervisión: se sigue con la de omisión.
            $script:AvisoConfig = "config.json ilegible, se usan valores por omision: $($_.Exception.Message)"
        }
    }
    $cfg
}

# ── Utilidades ────────────────────────────────────────────────────────────────

function ConvertTo-Hashtable($obj) {
    if ($null -eq $obj) { return $null }
    if ($obj -is [System.Collections.IDictionary]) {
        $h = @{}; foreach ($k in $obj.Keys) { $h[$k] = ConvertTo-Hashtable $obj[$k] }; return $h
    }
    if ($obj -is [System.Management.Automation.PSCustomObject]) {
        $h = @{}; foreach ($p in $obj.PSObject.Properties) { $h[$p.Name] = ConvertTo-Hashtable $p.Value }; return $h
    }
    if ($obj -is [System.Collections.IEnumerable] -and $obj -isnot [string]) {
        $l = New-Object System.Collections.ArrayList
        foreach ($i in $obj) { [void]$l.Add((ConvertTo-Hashtable $i)) }
        return ,$l.ToArray()
    }
    $obj
}

function Get-EpochMs([datetime]$t = (Get-Date)) {
    [long](($t.ToUniversalTime() - (New-Object DateTime 1970, 1, 1, 0, 0, 0, ([DateTimeKind]::Utc))).TotalMilliseconds)
}

function Get-Iso([datetime]$t = (Get-Date)) { $t.ToString('yyyy-MM-ddTHH:mm:ss.fffzzz') }

function Read-JsonArchivo([string]$ruta) {
    if (-not (Test-Path -LiteralPath $ruta)) { return $null }
    try { ConvertTo-Hashtable (Get-Content -LiteralPath $ruta -Raw | ConvertFrom-Json) } catch { $null }
}

# Escritura atómica: se escribe a un temporal y se reemplaza, para que el PHP
# nunca lea un JSON a medias.
function Write-JsonArchivo([string]$ruta, $datos) {
    $tmp = "$ruta.tmp"
    $json = ConvertTo-Json -InputObject $datos -Depth 8 -Compress
    [IO.File]::WriteAllText($tmp, $json, (New-Object System.Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $tmp -Destination $ruta -Force
}

# ── Bitácora con rotación ─────────────────────────────────────────────────────

function Write-Bitacora([string]$nivel, [string]$mensaje) {
    if (-not $script:RutaLog) { return }
    try {
        $f = Get-Item -LiteralPath $script:RutaLog -ErrorAction SilentlyContinue
        if ($f -and $f.Length -gt ($script:Cfg.LogMaxKB * 1KB)) {
            for ($i = $script:Cfg.LogArchivos - 1; $i -ge 1; $i--) {
                $de = "$($script:RutaLog).$i"; $a = "$($script:RutaLog).$($i + 1)"
                if (Test-Path -LiteralPath $de) { Move-Item -LiteralPath $de -Destination $a -Force }
            }
            Move-Item -LiteralPath $script:RutaLog -Destination "$($script:RutaLog).1" -Force
            $viejo = "$($script:RutaLog).$($script:Cfg.LogArchivos + 1)"
            if (Test-Path -LiteralPath $viejo) { Remove-Item -LiteralPath $viejo -Force }
        }
        $linea = '{0} [{1}] {2}{3}' -f (Get-Iso), $nivel, $mensaje, [Environment]::NewLine
        [IO.File]::AppendAllText($script:RutaLog, $linea, (New-Object System.Text.UTF8Encoding($false)))
    } catch { }
}

# ── Observación ───────────────────────────────────────────────────────────────

# Procesos del SERVIDOR de esta carpeta: mismo ejecutable y, si se da, una línea
# de comando que lo identifique (php -S ...:<puerto>, caddy run --config <este
# Caddyfile>). Solo con la ruta no basta: un php de consola que use el mismo
# php.exe (scripts, la consulta de trabajos activos) no es el servidor y no se toca.
function Get-ProcesosPropios([string]$exe, [string]$patronComando) {
    $nombre = [IO.Path]::GetFileName($exe)
    $propios = @()
    $porCim = $null
    try { $porCim = @(Get-CimInstance Win32_Process -Filter "Name='$nombre'" -OperationTimeoutSec 15 -ErrorAction Stop) } catch { }
    if ($null -ne $porCim) {
        foreach ($p in $porCim) {
            if (-not ($p.ExecutablePath -and ($p.ExecutablePath -ieq $exe))) { continue }
            if ($patronComando -and ([string]$p.CommandLine) -notmatch $patronComando) { continue }
            $propios += [int]$p.ProcessId
        }
        return ,$propios
    }
    # Sin WMI (arranque muy temprano): solo por ruta.
    foreach ($p in @(Get-Process -Name ([IO.Path]::GetFileNameWithoutExtension($exe)) -ErrorAction SilentlyContinue)) {
        $ruta = $null
        try { $ruta = $p.Path } catch { }
        if ($ruta -and ($ruta -ieq $exe)) { $propios += $p.Id }
    }
    ,$propios
}

function Get-PatronPhp($cfg) { "\s-S\s+\S*:$($cfg.PuertoPhp)(\s|$)" }
function Get-PatronCaddy([string]$app) { [regex]::Escape((Join-Path $app 'Caddyfile')) }

function Get-DuenoPuerto([int]$puerto) {
    try {
        $c = @(Get-NetTCPConnection -LocalPort $puerto -State Listen -ErrorAction Stop)
        if ($c.Count -gt 0) { return [int]$c[0].OwningProcess }
        return $null
    } catch {
        # Sin el módulo NetTCPIP (o sin conexiones): netstat.
        foreach ($l in @(netstat -ano -p TCP)) {
            if ($l -match "^\s*TCP\s+\S+:$puerto\s+\S+\s+LISTENING\s+(\d+)") { return [int]$Matches[1] }
        }
        return $null
    }
}

function Initialize-Tls {
    if ($script:TlsListo) { return }
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072 } catch { }
    # El certificado de Caddy es para la IP de la LAN, no para 127.0.0.1: la sonda
    # local no valida el certificado (solo comprueba que Caddy conteste). Se
    # compila en C# porque un scriptblock como callback puede correr en otro hilo.
    if (-not ('GsSondaTls' -as [type])) {
        Add-Type -TypeDefinition @'
public static class GsSondaTls {
    public static bool Aceptar(object s, System.Security.Cryptography.X509Certificates.X509Certificate c,
        System.Security.Cryptography.X509Certificates.X509Chain ch, System.Net.Security.SslPolicyErrors e) { return true; }
}
'@
    }
    $script:TlsListo = $true
}

function Test-Http([string]$url, [int]$timeoutMs) {
    $inicio = Get-Date
    $r = @{ ok = $false; codigo = $null; ms = $null; error = $null }
    try {
        if ($url -like 'https:*') { Initialize-Tls }
        $req = [Net.HttpWebRequest]::Create($url)
        $req.Timeout = $timeoutMs
        $req.ReadWriteTimeout = $timeoutMs
        $req.KeepAlive = $false
        $req.Proxy = $null
        if ($url -like 'https:*') {
            $req.ServerCertificateValidationCallback = [Delegate]::CreateDelegate([Net.Security.RemoteCertificateValidationCallback], [GsSondaTls].GetMethod('Aceptar'))
        }
        $resp = $req.GetResponse()
        $r.codigo = [int]$resp.StatusCode
        $resp.Close()
        $r.ok = ($r.codigo -ge 200 -and $r.codigo -lt 300)
    } catch [Net.WebException] {
        $e = $_.Exception
        if ($e.Response) { try { $r.codigo = [int]$e.Response.StatusCode; $e.Response.Close() } catch { } }
        $r.error = [string]$e.Status
    } catch {
        $r.error = $_.Exception.Message
    }
    $r.ms = [int]((Get-Date) - $inicio).TotalMilliseconds
    $r
}

# Sonda con reintentos: devuelve el último resultado y cuántos intentos hizo.
function Test-HttpConReintentos([string]$url, $cfg) {
    $intentos = 0
    do {
        $intentos++
        $r = Test-Http $url $cfg.TimeoutHttpMs
        if ($r.ok) { break }
        if ($intentos -lt $cfg.ReintentosSonda) { Start-Sleep -Milliseconds $cfg.PausaSondaMs }
    } while ($intentos -lt $cfg.ReintentosSonda)
    $r.intentos = $intentos
    $r
}

function Get-EstadoComponente([string]$app, [string]$exe, [int]$puerto, [string]$url, $cfg, [string]$patron, [switch]$SinReintentos) {
    $pids = Get-ProcesosPropios $exe $patron
    $dueno = Get-DuenoPuerto $puerto
    $o = @{
        pids        = $pids
        vivo        = ($pids.Count -gt 0)
        duenoPuerto = $dueno
        puertoAjeno = ($null -ne $dueno -and ($pids -notcontains $dueno))
        http        = $null
    }
    if ($null -ne $dueno -or $o.vivo) {
        if ($SinReintentos) { $o.http = Test-Http $url $cfg.TimeoutHttpMs; $o.http.intentos = 1 }
        else { $o.http = Test-HttpConReintentos $url $cfg }
    } else {
        $o.http = @{ ok = $false; codigo = $null; ms = 0; error = 'sin proceso ni puerto'; intentos = 0 }
    }
    if ($o.puertoAjeno) {
        try { $o.nombreAjeno = (Get-Process -Id $dueno -ErrorAction Stop).ProcessName } catch { $o.nombreAjeno = "pid $dueno" }
    }
    $o
}

function Get-ConfigFaltante([string]$app) {
    $faltan = @()
    foreach ($rel in @('php\php.exe', 'php\php.ini', 'caddy.exe', 'Caddyfile', 'cert\certificado.pem', 'cert\llave.pem', 'php\cert\cacert.pem', 'public\nprint\index.php')) {
        if (-not (Test-Path -LiteralPath (Join-Path $app $rel))) { $faltan += $rel }
    }
    ,$faltan
}

$script:EstadoImpresoraTxt = @{ 1 = 'otro'; 2 = 'desconocido'; 3 = 'inactiva'; 4 = 'imprimiendo'; 5 = 'calentando'; 6 = 'detenida'; 7 = 'fuera_de_linea' }
$script:ErrorImpresoraTxt = @{ 0 = 'desconocido'; 1 = 'otro'; 2 = 'sin_error'; 3 = 'poco_papel'; 4 = 'sin_papel'; 5 = 'poco_toner'; 6 = 'sin_toner'; 7 = 'puerta_abierta'; 8 = 'atasco'; 9 = 'fuera_de_linea'; 10 = 'servicio_requerido'; 11 = 'bandeja_llena' }

function Get-PuertoTcpImpresora([string]$puerto) {
    $k = "HKLM:\SYSTEM\CurrentControlSet\Control\Print\Monitors\Standard TCP/IP Port\Ports\$puerto"
    if (-not (Test-Path -LiteralPath $k)) { return $null }
    $p = Get-ItemProperty -LiteralPath $k
    $host_ = if ($p.HostName) { [string]$p.HostName } else { [string]$p.IPAddress }
    if (-not $host_) { return $null }
    @{ host = $host_; puerto = if ($p.PortNumber) { [int]$p.PortNumber } else { 9100 } }
}

# Prueba de alcance TCP a varias impresoras a la vez. Solo abre y cierra la
# conexión: no manda bytes, así que no imprime nada.
function Test-TcpVarios($destinos, [int]$timeoutMs) {
    $pend = @()
    foreach ($d in $destinos) {
        $c = New-Object Net.Sockets.TcpClient
        try { $ar = $c.BeginConnect($d.host, $d.puerto, $null, $null); $pend += @{ d = $d; c = $c; ar = $ar } }
        catch { $d.alcanzable = $false; $d.errorRed = $_.Exception.Message; $c.Close() }
    }
    $limite = (Get-Date).AddMilliseconds($timeoutMs)
    foreach ($p in $pend) {
        $resta = [int][Math]::Max(0, ($limite - (Get-Date)).TotalMilliseconds)
        $ok = $p.ar.AsyncWaitHandle.WaitOne($resta)
        if ($ok -and $p.c.Connected) { $p.d.alcanzable = $true }
        else { $p.d.alcanzable = $false; $p.d.errorRed = 'sin respuesta en el puerto' }
        try { $p.c.Close() } catch { }
    }
}

# Corre un bloque en otro runspace y lo abandona si pasa el límite. Un hilo
# abandonado muere con el proceso del watchdog al terminar la revisión.
function Invoke-ConLimite([scriptblock]$bloque, [object[]]$argumentos, [int]$limiteSeg) {
    $ps = [PowerShell]::Create()
    [void]$ps.AddScript($bloque.ToString())
    foreach ($a in $argumentos) { [void]$ps.AddArgument($a) }
    $h = $ps.BeginInvoke()
    if (-not $h.AsyncWaitHandle.WaitOne($limiteSeg * 1000)) {
        try { [void]$ps.BeginStop($null, $null) } catch { }
        return @{ ok = $false; timeout = $true; error = 'timeout' }
    }
    try {
        $salida = $ps.EndInvoke($h)
        if ($ps.HadErrors -and $ps.Streams.Error.Count) { return @{ ok = $false; timeout = $false; error = [string]$ps.Streams.Error[0] } }
        return @{ ok = $true; resultado = $(if ($salida.Count) { $salida[0] } else { $null }) }
    } catch {
        return @{ ok = $false; timeout = $false; error = $_.Exception.Message }
    } finally { $ps.Dispose() }
}

function Get-EstadoSpooler($cfg) {
    $s = @{ servicio = $null; responde = $null; ms = $null; error = $null; impresoras = @() }
    try { $s.servicio = [string](Get-Service -Name Spooler -ErrorAction Stop).Status } catch { $s.servicio = 'no_existe'; $s.error = $_.Exception.Message }
    if ($s.servicio -ne 'Running') { $s.responde = $false; return $s }

    $inicio = Get-Date
    # Las consultas de impresoras pasan por el Spooler: si está colgado pueden no
    # volver nunca (el README ya advierte que Get-Printer cuelga la sesión). Se
    # corren en un runspace aparte con límite duro, para que el watchdog siempre
    # termine su revisión y la siguiente no quede bloqueada.
    $r = Invoke-ConLimite {
        param($seg)
        $i = @(Get-CimInstance -ClassName Win32_Printer -OperationTimeoutSec $seg -ErrorAction Stop)
        $t = @(Get-CimInstance -ClassName Win32_PrintJob -OperationTimeoutSec $seg -ErrorAction Stop)
        @{ imps = $i; trabajos = $t }
    } @($cfg.TimeoutWmiSeg) ($cfg.TimeoutWmiSeg + 5)
    $s.ms = [int]((Get-Date) - $inicio).TotalMilliseconds
    if (-not $r.ok) {
        $s.responde = $false
        $s.error = if ($r.timeout) { "sin respuesta en $($cfg.TimeoutWmiSeg + 5) s" } else { $r.error }
        return $s
    }
    $imps = @($r.resultado.imps)
    $trabajos = @($r.resultado.trabajos)
    $s.responde = $true

    $lista = @()
    foreach ($i in $imps) {
        # Solo las compartidas: el ID-Printer imprime a \\PC\recurso, así que una
        # impresora sin compartir no le sirve (y deja fuera PDF, XPS, Fax...).
        if (-not $i.Shared) { continue }
        $suyos = @($trabajos | Where-Object { ($_.Name -split ',')[0] -eq $i.Name })
        $conError = @($suyos | Where-Object { $_.Status -and $_.Status -notmatch '^(OK|Spooling|Printing)$' -or ($_.StatusMask -band (2 -bor 4 -bor 32 -bor 64 -bor 1024)) })
        $imp = @{
            nombre         = [string]$i.Name
            recurso        = [string]$i.ShareName
            puerto         = [string]$i.PortName
            fueraDeLinea   = [bool]$i.WorkOffline
            estadoWindows  = $script:EstadoImpresoraTxt[[int]$i.PrinterStatus]
            errorDetectado = $script:ErrorImpresoraTxt[[int]$i.DetectedErrorState]
            trabajosEnCola = $suyos.Count
            trabajosConError = $conError.Count
            tipo           = 'local'
        }
        $tcp = Get-PuertoTcpImpresora $imp.puerto
        if ($tcp) { $imp.tipo = 'red'; $imp.host = $tcp.host; $imp.puertoRed = $tcp.puerto }
        elseif ($imp.puerto -match '^USB') { $imp.tipo = 'usb' }
        elseif ($imp.puerto -match '^(LPT|COM)') { $imp.tipo = 'paralelo_serie' }
        $lista += $imp
    }
    if ($cfg.RevisarRedImpresoras) {
        $ign = @($cfg.ImpresorasIgnoradas | ForEach-Object { ([string]$_).ToLowerInvariant() })
        $red = @($lista | Where-Object { $_.tipo -eq 'red' -and $ign -notcontains $_.nombre.ToLowerInvariant() } | ForEach-Object { @{ ref = $_; host = $_.host; puerto = $_.puertoRed } })
        if ($red.Count) {
            Test-TcpVarios $red $cfg.TimeoutRedImpresoraMs
            foreach ($d in $red) { $d.ref.alcanzable = $d.alcanzable; if ($d.errorRed) { $d.ref.errorRed = $d.errorRed } }
        }
    }
    $ignoradas = @($cfg.ImpresorasIgnoradas | ForEach-Object { ([string]$_).ToLowerInvariant() })
    foreach ($imp in $lista) {
        $imp.estado = Get-ClasificacionImpresora $imp
        if ($ignoradas -contains $imp.nombre.ToLowerInvariant() -or ($imp.recurso -and $ignoradas -contains $imp.recurso.ToLowerInvariant())) {
            # A las de red ignoradas no se les hace la prueba TCP: no hay estado real que dar.
            $imp.estadoReal = if ($imp.tipo -eq 'red') { 'no_revisada' } else { $imp.estado }
            $imp.estado = 'ignorada'
        }
    }
    $s.impresoras = $lista
    $s
}

# Lo que se le dice al POS de cada impresora. Honesto con los límites: con
# drivers sin bidireccional (Generic / Text Only, la mayoría de las térmicas
# genéricas) Windows no sabe si hay papel; solo se sabe si la red contesta.
function Get-ClasificacionImpresora($imp) {
    if ($imp.fueraDeLinea -or $imp.estadoWindows -eq 'fuera_de_linea' -or $imp.errorDetectado -eq 'fuera_de_linea') { return 'no_disponible' }
    if ($imp.tipo -eq 'red' -and $imp.alcanzable -eq $false) { return 'no_disponible' }
    if ($imp.trabajosConError -gt 0) { return 'con_error' }
    if (@('sin_papel', 'atasco', 'puerta_abierta', 'servicio_requerido', 'sin_toner') -contains $imp.errorDetectado) { return 'con_error' }
    if ($imp.estadoWindows -eq 'detenida') { return 'con_error' }
    if ($imp.tipo -eq 'red' -and $imp.alcanzable -eq $true) { return 'disponible' }
    # USB / paralelo sin señal de error: Windows no reporta nada malo, pero
    # tampoco hay prueba de vida.
    'sin_senal'
}

# ¿php está en medio de un trabajo de impresión? Se pregunta a la bitácora
# SQLite con el mismo php.exe en modo CLI (no depende del servidor colgado).
function Get-TrabajosActivos([string]$app, [int]$graciaSeg) {
    $php = Join-Path $app 'php\php.exe'
    $script_ = Join-Path $app 'watchdog\trabajos_activos.php'
    if (-not (Test-Path -LiteralPath $script_)) { return $null }
    try {
        # Sin opcache: un php de consola como SYSTEM chocaría con el opcache del servidor.
        $salida = & $php -c (Join-Path $app 'php\php.ini') -d opcache.enable_cli=0 $script_ $graciaSeg 2>$null
        $n = 0
        if ([int]::TryParse(([string]$salida).Trim(), [ref]$n)) { return $n }
    } catch { }
    $null
}

# ── Decisión (pura: no toca el sistema; la prueban las pruebas unitarias) ─────

function New-EstadoComponente { @{ fallasSeguidas = 0; caidoDesde = $null; sanoDesde = $null; recuperaciones = @(); bloqueadoHasta = $null; nivelBackoff = 0; aplazamientos = 0 } }

function Get-Decision($obs, $estado, $cfg, [long]$ahoraMs, [switch]$SoloDiagnostico) {
    $acciones = New-Object System.Collections.ArrayList
    $eventos = New-Object System.Collections.ArrayList
    $nuevo = ConvertTo-Hashtable (ConvertTo-Json -InputObject $estado -Depth 8 | ConvertFrom-Json)
    foreach ($c in 'php', 'caddy', 'spooler') { if (-not $nuevo[$c]) { $nuevo[$c] = New-EstadoComponente } }
    if (-not $nuevo.spooler.ContainsKey('colgadoSeguidas')) { $nuevo.spooler.colgadoSeguidas = 0 }

    $ventanaMs = [long]$cfg.VentanaRecuperacionMin * 60000

    # ¿Se permite otra recuperación de este componente? (límite + backoff)
    $puede = {
        param($nombre)
        $e = $nuevo[$nombre]
        $e.recuperaciones = @($e.recuperaciones | Where-Object { $ahoraMs - [long]$_ -lt $ventanaMs })
        if ($e.bloqueadoHasta -and $ahoraMs -lt [long]$e.bloqueadoHasta) { return $false }
        if ($e.bloqueadoHasta -and $ahoraMs -ge [long]$e.bloqueadoHasta) {
            $e.bloqueadoHasta = $null
            [void]$eventos.Add(@{ nivel = 'INFO'; msg = "$nombre`: termina la espera de backoff; se vuelve a intentar." })
        }
        if ($e.recuperaciones.Count -ge $cfg.MaxRecuperaciones) {
            $min = [Math]::Min([int]$cfg.BackoffMaxMin, [int]$cfg.BackoffInicialMin * [Math]::Pow(2, [int]$e.nivelBackoff))
            $e.bloqueadoHasta = $ahoraMs + [long]$min * 60000
            $e.nivelBackoff = [int]$e.nivelBackoff + 1
            $e.recuperaciones = @()
            [void]$eventos.Add(@{ nivel = 'ERROR'; msg = "$nombre`: $($cfg.MaxRecuperaciones) recuperaciones en $($cfg.VentanaRecuperacionMin) min sin quedar estable. Se deja de intentar por $min min: REQUIERE INTERVENCION." })
            return $false
        }
        return $true
    }
    $registrar = { param($nombre) $nuevo[$nombre].recuperaciones = @($nuevo[$nombre].recuperaciones) + $ahoraMs }

    $marcarCaida = {
        param($nombre, $motivo)
        $nuevo[$nombre].sanoDesde = $null
        if (-not $nuevo[$nombre].caidoDesde) {
            $nuevo[$nombre].caidoDesde = $ahoraMs
            [void]$eventos.Add(@{ nivel = 'WARN'; msg = "$nombre`: falla detectada ($motivo)." })
        }
    }
    $marcarSano = {
        param($nombre)
        $e = $nuevo[$nombre]
        if ($e.caidoDesde) {
            $seg = [Math]::Round(($ahoraMs - [long]$e.caidoDesde) / 1000)
            [void]$eventos.Add(@{ nivel = 'INFO'; msg = "$nombre`: funcionando otra vez; interrupcion de $seg s." })
        }
        $e.caidoDesde = $null; $e.fallasSeguidas = 0; $e.aplazamientos = 0
        if (-not $e.sanoDesde) { $e.sanoDesde = $ahoraMs }
        # Estable el tiempo suficiente: se olvidan recuperaciones y nivel de backoff.
        if ($ahoraMs - [long]$e.sanoDesde -ge [long]$cfg.EstableMin * 60000) {
            $e.recuperaciones = @(); $e.nivelBackoff = 0
        }
    }

    $intervencion = New-Object System.Collections.ArrayList

    if ($obs.pausado) {
        return @{ acciones = @(); eventos = @(); estado = $nuevo; intervencion = @(); pausado = $true }
    }

    if ($obs.configFaltante.Count -gt 0) {
        [void]$intervencion.Add("faltan archivos: $($obs.configFaltante -join ', ')")
    }

    # PHP ─────────────────────────────────────────────────────────────
    $php = $obs.php
    if ($php.http.ok) {
        & $marcarSano 'php'
        # Sobrante = otro php NUESTRO que no tiene el puerto (un arranque doble cuyo
        # bind falló). Si el puerto lo tiene un proceso ajeno, no se toca nada.
        if (@($php.pids) -contains $php.duenoPuerto) {
            foreach ($p in $php.pids) { if ($p -ne $php.duenoPuerto) { [void]$acciones.Add(@{ tipo = 'matar_sobrante'; componente = 'php'; pid = $p }) } }
        }
    } elseif ($php.puertoAjeno) {
        & $marcarCaida 'php' "puerto $($cfg.PuertoPhp) ocupado por $($php.nombreAjeno)"
        [void]$intervencion.Add("el puerto $($cfg.PuertoPhp) lo ocupa otro programa ($($php.nombreAjeno)); no se toca")
    } elseif ($obs.configFaltante.Count -gt 0) {
        & $marcarCaida 'php' 'configuracion incompleta'
    } elseif (-not $php.vivo) {
        & $marcarCaida 'php' 'proceso inexistente'
        if (& $puede 'php') { [void]$acciones.Add(@{ tipo = 'iniciar'; componente = 'php' }); & $registrar 'php' }
        else { [void]$intervencion.Add('php no arranca de forma estable (en espera de backoff)') }
    } else {
        # Vivo pero sin responder tras los reintentos: colgado.
        $nuevo.php.fallasSeguidas = [int]$nuevo.php.fallasSeguidas + 1
        & $marcarCaida 'php' "proceso vivo sin responder ($($php.http.error))"
        if ($obs.trabajosActivos -gt 0 -and [int]$nuevo.php.aplazamientos -lt $cfg.MaxAplazamientos) {
            $nuevo.php.aplazamientos = [int]$nuevo.php.aplazamientos + 1
            [void]$eventos.Add(@{ nivel = 'WARN'; msg = "php: colgado pero con $($obs.trabajosActivos) trabajo(s) recientes a medias; se aplaza el reinicio ($($nuevo.php.aplazamientos)/$($cfg.MaxAplazamientos))." })
        } elseif (& $puede 'php') {
            [void]$acciones.Add(@{ tipo = 'reiniciar'; componente = 'php' }); & $registrar 'php'; $nuevo.php.aplazamientos = 0
        } else { [void]$intervencion.Add('php colgado y en espera de backoff') }
    }

    # CADDY ───────────────────────────────────────────────────────────
    $caddy = $obs.caddy
    if ($caddy.http.ok) {
        & $marcarSano 'caddy'
    } elseif ($caddy.puertoAjeno) {
        & $marcarCaida 'caddy' "puerto $($cfg.PuertoCaddy) ocupado por $($caddy.nombreAjeno)"
        [void]$intervencion.Add("el puerto $($cfg.PuertoCaddy) lo ocupa otro programa ($($caddy.nombreAjeno)); no se toca")
    } elseif ($obs.configFaltante.Count -gt 0) {
        & $marcarCaida 'caddy' 'configuracion incompleta'
    } elseif (-not $caddy.vivo) {
        & $marcarCaida 'caddy' 'proceso inexistente'
        if (& $puede 'caddy') { [void]$acciones.Add(@{ tipo = 'iniciar'; componente = 'caddy' }); & $registrar 'caddy' }
        else { [void]$intervencion.Add('caddy no arranca de forma estable (en espera de backoff)') }
    } elseif (-not $php.http.ok) {
        # Caddy solo reenvía a php: si php está mal, la sonda HTTPS también falla.
        # No se reinicia caddy por culpa de php.
        & $marcarCaida 'caddy' 'sin respuesta (php tampoco responde)'
    } else {
        & $marcarCaida 'caddy' "proceso vivo sin responder ($($caddy.http.error))"
        if (& $puede 'caddy') { [void]$acciones.Add(@{ tipo = 'reiniciar'; componente = 'caddy' }); & $registrar 'caddy' }
        else { [void]$intervencion.Add('caddy colgado y en espera de backoff') }
    }

    # SPOOLER ─────────────────────────────────────────────────────────
    $sp = $obs.spooler
    if ($sp.servicio -eq 'Running' -and $sp.responde) {
        & $marcarSano 'spooler'; $nuevo.spooler.colgadoSeguidas = 0
    } elseif ($sp.servicio -eq 'no_existe') {
        & $marcarCaida 'spooler' 'servicio inexistente'
        [void]$intervencion.Add('no existe el servicio Cola de impresion (Spooler)')
    } elseif ($sp.servicio -ne 'Running') {
        & $marcarCaida 'spooler' "servicio $($sp.servicio)"
        if (& $puede 'spooler') { [void]$acciones.Add(@{ tipo = 'iniciar'; componente = 'spooler' }); & $registrar 'spooler' }
        else { [void]$intervencion.Add('el Spooler no se mantiene arriba (en espera de backoff)') }
    } else {
        $nuevo.spooler.colgadoSeguidas = [int]$nuevo.spooler.colgadoSeguidas + 1
        & $marcarCaida 'spooler' "no responde a consultas ($($sp.error))"
        if (-not $cfg.ReiniciarSpoolerColgado) {
            [void]$intervencion.Add('Spooler colgado (reinicio automatico desactivado)')
        } elseif ($nuevo.spooler.colgadoSeguidas -lt $cfg.ConfirmacionesSpoolerColgado) {
            [void]$eventos.Add(@{ nivel = 'WARN'; msg = "spooler: no responde ($($nuevo.spooler.colgadoSeguidas)/$($cfg.ConfirmacionesSpoolerColgado) revisiones antes de reiniciarlo)." })
        } elseif (& $puede 'spooler') {
            [void]$acciones.Add(@{ tipo = 'reiniciar'; componente = 'spooler' }); & $registrar 'spooler'; $nuevo.spooler.colgadoSeguidas = 0
        } else { [void]$intervencion.Add('Spooler colgado y en espera de backoff') }
    }

    if ($SoloDiagnostico) { $acciones.Clear() }
    @{ acciones = $acciones.ToArray(); eventos = $eventos.ToArray(); estado = $nuevo; intervencion = $intervencion.ToArray(); pausado = $false }
}

# Resumen para el POS: un solo estado general + detalle.
function Get-EstadoGeneral($obs, $decision) {
    if ($decision.pausado) { return 'pausado' }
    if (@($decision.intervencion).Count -gt 0) { return 'requiere_intervencion' }
    if (@($decision.acciones | Where-Object { $_.tipo -ne 'matar_sobrante' }).Count -gt 0) { return 'recuperando' }
    if (-not $obs.php.http.ok) { return 'servidor_caido' }
    if (-not $obs.caddy.http.ok) { return 'https_caido' }
    if (-not ($obs.spooler.servicio -eq 'Running' -and $obs.spooler.responde)) { return 'spooler_falla' }
    if (@($obs.spooler.impresoras | Where-Object { @('no_disponible', 'con_error') -contains $_.estado }).Count -gt 0) { return 'impresora_con_problema' }
    'disponible'
}

# ── Acciones ──────────────────────────────────────────────────────────────────

# Identificador estable por carpeta para opcache.cache_id (ver Start-Componente).
function Get-IdCache([string]$app) {
    $md5 = [Security.Cryptography.MD5]::Create()
    $h = $md5.ComputeHash([Text.Encoding]::UTF8.GetBytes($app.ToLowerInvariant()))
    'gsidp' + ([BitConverter]::ToString($h, 0, 4) -replace '-', '').ToLowerInvariant()
}

function Start-Componente([string]$componente, [string]$app, $cfg) {
    switch ($componente) {
        'php' {
            $php = Join-Path $app 'php\php.exe'
            $ca = Join-Path $app 'php\cert\cacert.pem'
            $doc = Join-Path $app 'public'
            # Mismo comando que iniciar_servidor.vbs, más un opcache.cache_id propio de
            # esta carpeta: en Windows el opcache se comparte entre procesos del mismo
            # usuario y SAPI, y un segundo php como SYSTEM (otra copia del ID-Printer,
            # o un php que aún no termina de morir) aborta con "Opcode handlers are
            # unusable due to ASLR" (reproducido en el laboratorio 2026-10-20).
            $args_ = "-d openssl.cafile=`"$ca`" -d curl.cainfo=`"$ca`" -d opcache.cache_id=$(Get-IdCache $app) -S 0.0.0.0:$($cfg.PuertoPhp) -t `"$doc`""
            Start-Process -FilePath $php -ArgumentList $args_ -WorkingDirectory $app -WindowStyle Hidden | Out-Null
        }
        'caddy' {
            $caddy = Join-Path $app 'caddy.exe'
            # WorkingDirectory obligatorio: el Caddyfile usa rutas relativas.
            Start-Process -FilePath $caddy -ArgumentList "run --config `"$(Join-Path $app 'Caddyfile')`" --adapter caddyfile" -WorkingDirectory $app -WindowStyle Hidden | Out-Null
        }
        'spooler' { Start-Service -Name Spooler }
    }
}

function Stop-Componente([string]$componente, [string]$app, $cfg) {
    switch ($componente) {
        'php' { foreach ($p in (Get-ProcesosPropios (Join-Path $app 'php\php.exe') (Get-PatronPhp $cfg))) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue } }
        'caddy' { foreach ($p in (Get-ProcesosPropios (Join-Path $app 'caddy.exe') (Get-PatronCaddy $app))) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue } }
        'spooler' {
            # Solo se reinicia un Spooler ya confirmado como colgado (3 revisiones). Pedirle
            # que se detenga no sirve: no atiende el control y `sc stop` se queda ~2 min
            # esperando (medido). Se cierra spoolsv.exe directo; el PID lo da el
            # administrador de servicios, que no depende del Spooler.
            $t0 = Get-Date
            $q = & sc.exe queryex Spooler | Out-String
            if ($q -match 'PID\s*:\s*(\d+)' -and [int]$Matches[1] -gt 0) {
                Stop-Process -Id ([int]$Matches[1]) -Force -ErrorAction SilentlyContinue
            }
            $limite = (Get-Date).AddSeconds(15)
            while ((Get-Service Spooler).Status -ne 'Stopped' -and (Get-Date) -lt $limite) { Start-Sleep -Milliseconds 300 }
            Write-Bitacora 'INFO' ("spooler: spoolsv.exe cerrado; servicio {0} en {1:N1} s." -f (Get-Service Spooler).Status, ((Get-Date) - $t0).TotalSeconds)
        }
    }
}

function Wait-Componente([string]$componente, [string]$app, $cfg) {
    $limite = (Get-Date).AddSeconds($cfg.EsperaArranqueSeg)
    do {
        switch ($componente) {
            'php' { $ok = (Test-Http "http://127.0.0.1:$($cfg.PuertoPhp)$($cfg.RutaSonda)" 3000).ok }
            'caddy' { $ok = (Test-Http "https://127.0.0.1:$($cfg.PuertoCaddy)$($cfg.RutaSonda)" 3000).ok }
            'spooler' { $ok = ((Get-Service Spooler).Status -eq 'Running') }
        }
        if ($ok) { return $true }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $limite)
    $false
}

# Fase 1 de una acción: lanzar (sin esperar). Devuelve lo necesario para esperar.
function Start-Accion($accion, [string]$app, $cfg) {
    $c = $accion.componente
    $r = @{ accion = $accion; inicio = (Get-Date); lanzada = $false }
    try {
        switch ($accion.tipo) {
            'matar_sobrante' {
                Stop-Process -Id $accion.pid -Force -ErrorAction SilentlyContinue
                Write-Bitacora 'WARN' "$c`: se cerro una instancia sobrante (pid $($accion.pid)) que no tenia el puerto."
                return $r
            }
            'iniciar' { Start-Componente $c $app $cfg }
            'reiniciar' {
                Stop-Componente $c $app $cfg
                if ($c -ne 'spooler') {
                    $puerto = if ($c -eq 'php') { $cfg.PuertoPhp } else { $cfg.PuertoCaddy }
                    $l = (Get-Date).AddSeconds(5)
                    while ($null -ne (Get-DuenoPuerto $puerto) -and (Get-Date) -lt $l) { Start-Sleep -Milliseconds 250 }
                }
                Start-Componente $c $app $cfg
            }
        }
        $r.lanzada = $true
    } catch {
        Write-Bitacora 'ERROR' "$c`: fallo al $($accion.tipo): $($_.Exception.Message)"
    }
    $r
}

# Fase 2: esperar a que responda y registrar el resultado.
function Wait-Accion($r, [string]$app, $cfg) {
    if (-not $r.lanzada) { return }
    $c = $r.accion.componente
    $ok = Wait-Componente $c $app $cfg
    $seg = [Math]::Round(((Get-Date) - $r.inicio).TotalSeconds, 1)
    if ($ok) { Write-Bitacora 'INFO' "$c`: $($r.accion.tipo) OK, responde en $seg s." }
    else { Write-Bitacora 'ERROR' "$c`: $($r.accion.tipo) ejecutado pero no responde tras $seg s." }
}

# ── Principal ─────────────────────────────────────────────────────────────────

function Invoke-Watchdog([string]$app, [switch]$SoloDiagnostico) {
    $script:Cfg = Get-Config $app
    $cfg = $script:Cfg
    $dir = Join-Path $app 'logs\watchdog'
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $script:RutaLog = Join-Path $dir 'watchdog.log'
    $rutaEstado = Join-Path $dir 'estado.json'
    $rutaSalud = Join-Path $dir 'salud.json'
    if ($script:AvisoConfig) { Write-Bitacora 'WARN' $script:AvisoConfig }

    # Una sola ejecución a la vez (además de IgnoreNew en la tarea).
    # Global\ exige privilegio (SYSTEM/administrador); un -SoloDiagnostico sin
    # privilegios usa el espacio local de su sesión.
    try { $mutex = New-Object Threading.Mutex($false, 'Global\GrowthSuite-IDPrinter-Watchdog') }
    catch { $mutex = New-Object Threading.Mutex($false, 'GrowthSuite-IDPrinter-Watchdog') }
    $tengo = $false
    try { $tengo = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $tengo = $true }
    if (-not $tengo) { Write-Bitacora 'INFO' 'otra ejecucion del watchdog sigue en curso; esta termina sin hacer nada.'; return }

    try {
        $inicio = Get-Date
        $ahoraMs = Get-EpochMs $inicio
        $estado = Read-JsonArchivo $rutaEstado
        if (-not $estado) { $estado = @{}; Write-Bitacora 'INFO' "watchdog ${script:VersionWatchdog}: primera ejecucion en $app (usuario $([Environment]::UserName))." }

        $obs = @{
            pausado        = (Test-Path -LiteralPath (Join-Path $dir 'PAUSA'))
            configFaltante = Get-ConfigFaltante $app
        }
        $obs.php = Get-EstadoComponente $app (Join-Path $app 'php\php.exe') $cfg.PuertoPhp "http://127.0.0.1:$($cfg.PuertoPhp)$($cfg.RutaSonda)" $cfg (Get-PatronPhp $cfg)
        # Si php no contesta, no se gastan reintentos en caddy (fallaría igual).
        $obs.caddy = Get-EstadoComponente $app (Join-Path $app 'caddy.exe') $cfg.PuertoCaddy "https://127.0.0.1:$($cfg.PuertoCaddy)$($cfg.RutaSonda)" $cfg (Get-PatronCaddy $app) -SinReintentos:(-not $obs.php.http.ok)
        $obs.spooler = Get-EstadoSpooler $cfg
        $obs.trabajosActivos = 0
        if ($obs.php.vivo -and -not $obs.php.http.ok) {
            $n = Get-TrabajosActivos $app $cfg.GraciaTrabajoActivoSeg
            if ($null -ne $n) { $obs.trabajosActivos = $n }
        }

        $decision = Get-Decision $obs $estado $cfg $ahoraMs -SoloDiagnostico:$SoloDiagnostico
        foreach ($e in $decision.eventos) { Write-Bitacora $e.nivel $e.msg }
        # Se lanzan todas (php y caddy arrancan a la vez, p. ej. al encender Windows) y
        # luego se espera a cada una.
        $lanzadas = @(foreach ($a in $decision.acciones) { Start-Accion $a $app $cfg })
        foreach ($r in $lanzadas) { Wait-Accion $r $app $cfg }

        # Tras actuar se vuelve a mirar (una sola sonda, sin reintentos) para que
        # salud.json diga cómo quedó y no cómo estaba.
        $accionReal = @($decision.acciones | Where-Object { $_.tipo -ne 'matar_sobrante' })
        $decisionFinal = $decision
        if ($accionReal.Count -gt 0) {
            $obs.php = Get-EstadoComponente $app (Join-Path $app 'php\php.exe') $cfg.PuertoPhp "http://127.0.0.1:$($cfg.PuertoPhp)$($cfg.RutaSonda)" $cfg (Get-PatronPhp $cfg) -SinReintentos
            $obs.caddy = Get-EstadoComponente $app (Join-Path $app 'caddy.exe') $cfg.PuertoCaddy "https://127.0.0.1:$($cfg.PuertoCaddy)$($cfg.RutaSonda)" $cfg (Get-PatronCaddy $app) -SinReintentos
            if (@($accionReal | Where-Object { $_.componente -eq 'spooler' }).Count) { $obs.spooler = Get-EstadoSpooler $cfg }
            $decisionFinal = @{ acciones = @(); intervencion = $decision.intervencion; pausado = $decision.pausado }
        }
        $general = Get-EstadoGeneral $obs $decisionFinal
        $impResumen = @($obs.spooler.impresoras | ForEach-Object { "$($_.nombre)=$($_.estado)" }) -join ' '
        $resumen = "general=$general php=$($obs.php.http.ok) caddy=$($obs.caddy.http.ok) spooler=$($obs.spooler.servicio)/$($obs.spooler.responde) $impResumen"
        $latidoMs = [long]$cfg.LatidoLogMin * 60000
        if ($resumen -ne $estado.ultimoResumen) {
            Write-Bitacora $(if ($general -eq 'disponible') { 'INFO' } else { 'WARN' }) "estado: $resumen"
            $decision.estado.ultimoResumen = $resumen
            $decision.estado.ultimoLatido = $ahoraMs
        } elseif (-not $estado.ultimoLatido -or $ahoraMs - [long]$estado.ultimoLatido -ge $latidoMs) {
            Write-Bitacora 'INFO' "latido: $resumen"
            $decision.estado.ultimoLatido = $ahoraMs
        } else {
            $decision.estado.ultimoLatido = $estado.ultimoLatido
        }
        foreach ($i in $decision.intervencion) {
            if ($resumen -ne $estado.ultimoResumen) { Write-Bitacora 'ERROR' "requiere intervencion: $i" }
        }
        Write-JsonArchivo $rutaEstado $decision.estado

        $salud = [ordered]@{
            esquema            = 'gs.idprinter.salud/v1'
            generadoEn         = Get-Iso
            generadoEnMs       = Get-EpochMs
            vigenciaSeg        = $cfg.SaludVigenciaSeg
            versionWatchdog    = $script:VersionWatchdog
            estadoGeneral      = $general
            requiereIntervencion = @($decision.intervencion)
            accionesTomadas    = @($decision.acciones | ForEach-Object { "$($_.tipo):$($_.componente)" })
            servidor           = [ordered]@{
                php   = [ordered]@{ responde = $obs.php.http.ok; ms = $obs.php.http.ms; error = $obs.php.http.error; pids = $obs.php.pids; caidoDesde = $decision.estado.php.caidoDesde }
                caddy = [ordered]@{ responde = $obs.caddy.http.ok; ms = $obs.caddy.http.ms; error = $obs.caddy.http.error; pids = $obs.caddy.pids; caidoDesde = $decision.estado.caddy.caidoDesde }
            }
            spooler            = [ordered]@{ servicio = $obs.spooler.servicio; responde = $obs.spooler.responde; ms = $obs.spooler.ms; error = $obs.spooler.error }
            impresoras         = @($obs.spooler.impresoras)
            duracionMs         = [int]((Get-Date) - $inicio).TotalMilliseconds
        }
        Write-JsonArchivo $rutaSalud $salud
        $salud
    } catch {
        Write-Bitacora 'ERROR' "fallo inesperado del watchdog: $($_.Exception.Message) @ $($_.InvocationInfo.ScriptLineNumber)"
        throw
    } finally {
        if ($tengo) { $mutex.ReleaseMutex() }
        $mutex.Dispose()
    }
}

if ($Biblioteca) { return }
if (-not $App) { $App = Split-Path -Parent $PSScriptRoot }
$App = (Resolve-Path -LiteralPath $App).Path
$resultado = Invoke-Watchdog $App -SoloDiagnostico:$SoloDiagnostico
if ($SoloDiagnostico -and $resultado) { ConvertTo-Json -InputObject $resultado -Depth 8 }
