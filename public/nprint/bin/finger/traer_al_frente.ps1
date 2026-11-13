# Pone al frente la ventana del lector de huella apenas aparece.
#
# Por qué: el lector DigitalPersona solo le entrega la huella a la ventana ACTIVA, y Windows no deja que un programa
# abierto en segundo plano (lo abre PHP, no la persona) se ponga al frente solo. Sin esto había que darle clic.
#
# Uso (lo lanza FingerprintController.php sin esperar): traer_al_frente.ps1 -Proceso FingerPrintAuth -Segundos 20
param(
    [string]$Proceso = 'FingerPrintAuth',
    [int]$Segundos = 20,
    [string]$Log = 'C:\temp\fp_focus.log'
)

$ErrorActionPreference = 'SilentlyContinue'
$inicio = Get-Date

Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class GsVentana {
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr h);
    [DllImport("user32.dll")] public static extern bool BringWindowToTop(IntPtr h);
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr h, int n);
    [DllImport("user32.dll")] public static extern bool IsIconic(IntPtr h);
    [DllImport("user32.dll")] public static extern bool SetWindowPos(IntPtr h, IntPtr after, int x, int y, int cx, int cy, uint f);
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, IntPtr pid);
    [DllImport("user32.dll")] public static extern bool AttachThreadInput(uint a, uint b, bool unir);
    [DllImport("kernel32.dll")] public static extern uint GetCurrentThreadId();
    [DllImport("user32.dll")] public static extern void keybd_event(byte vk, byte scan, uint flags, UIntPtr extra);

    public static bool AlFrente(IntPtr h) {
        if (IsIconic(h)) ShowWindow(h, 9); // restaurar si está minimizada
        // Arriba de todo un instante y luego normal: la deja visible sin quedarse "siempre encima".
        SetWindowPos(h, new IntPtr(-1), 0, 0, 0, 0, 0x0003 | 0x0040);
        SetWindowPos(h, new IntPtr(-2), 0, 0, 0, 0, 0x0003 | 0x0040);

        IntPtr actual = GetForegroundWindow();
        uint hiloActual = GetWindowThreadProcessId(actual, IntPtr.Zero);
        uint yo = GetCurrentThreadId();
        bool unido = hiloActual != 0 && hiloActual != yo && AttachThreadInput(yo, hiloActual, true);
        BringWindowToTop(h);
        SetForegroundWindow(h);
        if (unido) AttachThreadInput(yo, hiloActual, false);
        if (GetForegroundWindow() == h) return true;

        // Último recurso: Windows deja cambiar la ventana activa a quien generó la última tecla (Alt suelto).
        keybd_event(0x12, 0, 0, UIntPtr.Zero);
        keybd_event(0x12, 0, 2, UIntPtr.Zero);
        SetForegroundWindow(h);
        return GetForegroundWindow() == h;
    }
}
"@

function Anotar([string]$texto) {
    $ms = [int]((Get-Date) - $inicio).TotalMilliseconds
    Add-Content -Path $Log -Value ("{0} +{1}ms {2}" -f (Get-Date -Format o), $ms, $texto)
}

$h = [IntPtr]::Zero
while (((Get-Date) - $inicio).TotalSeconds -lt $Segundos) {
    $p = Get-Process -Name $Proceso | Where-Object { $_.MainWindowHandle -ne [IntPtr]::Zero } | Select-Object -First 1
    if ($p) { $h = $p.MainWindowHandle; break }
    Start-Sleep -Milliseconds 100
}
if ($h -eq [IntPtr]::Zero) { Anotar "sin ventana de $Proceso"; exit 0 }

Anotar "ventana visible"
$ok = $false
for ($i = 0; $i -lt 10 -and -not $ok; $i++) {
    $ok = [GsVentana]::AlFrente($h)
    if (-not $ok) { Start-Sleep -Milliseconds 150 }
}
Anotar ("al frente: " + $ok)
