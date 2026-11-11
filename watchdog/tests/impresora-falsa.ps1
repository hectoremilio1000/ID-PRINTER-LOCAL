<#
  Impresora falsa para el laboratorio: escucha en TCP (RAW, como el puerto 9100
  de una térmica) y guarda cada conexión del Spooler como un ticket en
  <Dir>\ticket_<n>.bin. Así las pruebas cuentan cuántos tickets entregó Windows
  de verdad (y si hubo duplicados) sin gastar papel.

  Termina cuando aparece <Dir>\DETENER.
  Uso: powershell -File impresora-falsa.ps1 -Puerto 9101 -Dir C:\gs-wd-test\impresora
#>
param([int]$Puerto = 9101, [string]$Dir = 'C:\gs-wd-test\impresora')
New-Item -ItemType Directory -Force -Path $Dir | Out-Null
Remove-Item -LiteralPath (Join-Path $Dir 'DETENER') -ErrorAction SilentlyContinue
$l = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Puerto)
$l.Start()
"$(Get-Date -Format o) escuchando en 127.0.0.1:$Puerto (pid $PID)" | Add-Content (Join-Path $Dir 'impresora.log')
try {
    while (-not (Test-Path -LiteralPath (Join-Path $Dir 'DETENER'))) {
        if (-not $l.Pending()) { Start-Sleep -Milliseconds 200; continue }
        $c = $l.AcceptTcpClient()
        $c.ReceiveTimeout = 5000
        $s = $c.GetStream()
        $ms = New-Object IO.MemoryStream
        $buf = New-Object byte[] 8192
        try { while (($n = $s.Read($buf, 0, $buf.Length)) -gt 0) { $ms.Write($buf, 0, $n) } } catch { }
        $c.Close()
        if ($ms.Length -gt 0) {
            $num = @(Get-ChildItem -LiteralPath $Dir -Filter 'ticket_*.bin').Count + 1
            [IO.File]::WriteAllBytes((Join-Path $Dir ('ticket_{0:D4}.bin' -f $num)), $ms.ToArray())
            "$(Get-Date -Format o) ticket $num ($($ms.Length) bytes)" | Add-Content (Join-Path $Dir 'impresora.log')
        }
    }
} finally { $l.Stop() }
