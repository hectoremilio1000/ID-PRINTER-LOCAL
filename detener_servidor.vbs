Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

baseDir = fso.GetParentFolderName(WScript.ScriptFullName)
logFile = baseDir & "\servidor.log"
logsDir = baseDir & "\logs"

' Mismo modo silencioso que iniciar_servidor.vbs: para el Programador de
' tareas, donde no hay nadie que pueda cerrar un MsgBox.
esSilencioso = False
For i = 0 To WScript.Arguments.Count - 1
    If LCase(WScript.Arguments(i)) = "/silent" Then esSilencioso = True
Next

' Primero la bandera: el vigilante deja de levantar procesos y se cierra solo
' (a mas tardar en 30 s). Sin esto volveria a encender lo que se apaga abajo.
On Error Resume Next
If Not fso.FolderExists(logsDir) Then fso.CreateFolder logsDir
Set bandera = fso.OpenTextFile(logsDir & "\detener.flag", 2, True)
bandera.WriteLine Now
bandera.Close
On Error GoTo 0

' Detener PHP (tickets 8080, huella 8081 y consultas 8082)
shell.Run "taskkill /IM php.exe /F", 0, True

' Detener Caddy
shell.Run "taskkill /IM caddy.exe /F", 0, True

If esSilencioso Then
    Set logStream = fso.OpenTextFile(logFile, 8, True) ' 8 = ForAppending, True = crear si no existe
    logStream.WriteLine Now & " [OK] Servidor detenido (o ya no estaba corriendo)."
    logStream.Close
Else
    MsgBox "Servidor detenido correctamente.", vbInformation, "ID-Server"
End If
