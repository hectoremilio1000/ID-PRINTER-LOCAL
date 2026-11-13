# Compila HuellaZK.exe con el csc de .NET Framework 4 (viene con Windows; no hace falta Visual Studio).
# Uso: powershell -ExecutionPolicy Bypass -File compilar.ps1 [-Salida <carpeta>]
param([string]$Salida = (Join-Path $PSScriptRoot '..\..\..\public\nprint\bin\finger'))
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path $csc)) { $csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe' }
$fx = Split-Path $csc
$out = Join-Path (Resolve-Path $Salida) 'HuellaZK.exe'
& $csc /nologo /target:winexe /platform:anycpu /optimize+ /codepage:65001 /out:$out `
  /reference:"$fx\System.Web.Extensions.dll" /reference:"$fx\System.Windows.Forms.dll" /reference:"$fx\System.Drawing.dll" `
  (Join-Path $PSScriptRoot 'HuellaZK.cs')
if ($LASTEXITCODE -eq 0) { "compilado: $out" } else { "fallo la compilacion ($LASTEXITCODE)"; exit 1 }
