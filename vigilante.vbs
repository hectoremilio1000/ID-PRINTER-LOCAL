' Vigilante del ID-Printer: cada 30 s corre iniciar_servidor.vbs /vigilante, que solo
' levanta lo que NO este corriendo (php de tickets 8080, huella 8081, consultas 8082 y
' caddy). Asi, si un proceso se cae a media operacion, vuelve solo en menos de un minuto
' sin reiniciar la PC.
'
' Lo lanza iniciar_servidor.vbs (al arrancar Windows o con doble clic). Se detiene solo
' cuando detener_servidor.vbs deja logs\detener.flag. Deja un latido en
' logs\vigilante.latido para que no se lance dos veces.
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

baseDir = fso.GetParentFolderName(WScript.ScriptFullName)
logsDir = baseDir & "\logs"
banderaDetener = logsDir & "\detener.flag"
latido = logsDir & "\vigilante.latido"
iniciarVbs = baseDir & "\iniciar_servidor.vbs"

Const ESPERA_MS = 30000

Do
    If fso.FileExists(banderaDetener) Then Exit Do

    On Error Resume Next
    If Not fso.FolderExists(logsDir) Then fso.CreateFolder logsDir
    Set f = fso.OpenTextFile(latido, 2, True) ' 2 = ForWriting: actualiza la fecha del archivo
    f.WriteLine Now
    f.Close
    ' Espera a que termine (True) para no encimar dos revisiones.
    shell.Run "wscript.exe """ & iniciarVbs & """ /vigilante", 0, True
    On Error GoTo 0

    WScript.Sleep ESPERA_MS
Loop

On Error Resume Next
If fso.FileExists(latido) Then fso.DeleteFile latido
