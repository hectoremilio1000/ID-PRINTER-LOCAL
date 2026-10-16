Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

baseDir = fso.GetParentFolderName(WScript.ScriptFullName)
phpExe = baseDir & "\php\php.exe"
docRoot = baseDir & "\public"
caddyExe = baseDir & "\caddy.exe"
caddyfile = baseDir & "\Caddyfile"
certFile = baseDir & "\cert\certificado.pem"
keyFile = baseDir & "\cert\llave.pem"
caBundle = baseDir & "\php\cert\cacert.pem"
logFile = baseDir & "\servidor.log"

' Cronometro de diagnostico: en modo /silent el log dice cuanto tardo cada etapa
' (archivos / deteccion de procesos / lanzamiento). Ayuda a ver si el arranque
' lento de Windows esta en este script o fuera de el.
t0 = Timer

' Modo silencioso: para cuando lo dispara el Programador de tareas al
' arrancar Windows, donde no hay nadie que pueda cerrar un MsgBox. En ese
' modo los avisos se escriben en servidor.log en vez de mostrarse en
' pantalla. Doble clic manual sigue mostrando los mensajes de siempre.
esSilencioso = False
For i = 0 To WScript.Arguments.Count - 1
    If LCase(WScript.Arguments(i)) = "/silent" Then esSilencioso = True
Next

Sub Avisar(mensaje, esError)
    If esSilencioso Then
        etiqueta = "OK"
        If esError Then etiqueta = "ERROR"
        Set logStream = fso.OpenTextFile(logFile, 8, True) ' 8 = ForAppending, True = crear si no existe
        logStream.WriteLine Now & " [" & etiqueta & "] " & mensaje
        logStream.Close
    ElseIf esError Then
        MsgBox mensaje, vbCritical, "Error"
    Else
        MsgBox mensaje, vbInformation, "Servidor iniciado"
    End If
End Sub

' Verificar archivos requeridos
If Not fso.FileExists(phpExe) Then
    Avisar "No se encontró PHP en: " & phpExe, True
    WScript.Quit
End If
If Not fso.FileExists(certFile) Then
    Avisar "No se encontró el certificado: " & certFile, True
    WScript.Quit
End If
If Not fso.FileExists(keyFile) Then
    Avisar "No se encontró la llave privada: " & keyFile, True
    WScript.Quit
End If
If Not fso.FileExists(caddyExe) Then
    Avisar "No se encontró caddy.exe en: " & caddyExe, True
    WScript.Quit
End If
If Not fso.FileExists(caddyfile) Then
    Avisar "No se encontró el archivo Caddyfile en: " & caddyfile, True
    WScript.Quit
End If
If Not fso.FileExists(caBundle) Then
    Avisar "No se encontró el CA bundle en: " & caBundle, True
    WScript.Quit
End If

' El Caddyfile usa rutas RELATIVAS (cert/..., logs/...): Caddy las resuelve desde
' el directorio de trabajo del proceso. Cuando esto lo lanza el Programador de
' tareas sin carpeta de trabajo, arranca en System32 y caddy muere. Se fija aqui
' para que no dependa de como se haya creado la tarea.
shell.CurrentDirectory = baseDir
tArchivos = Timer

' Idempotente: solo se lanza lo que NO esta corriendo ya. Si php.exe o caddy.exe
' estan vivos (de esta misma carpeta) se dejan como estan; asi un doble clic o una
' tarea que se dispare dos veces no duplica procesos ni corta el servicio.
' Se usa tasklist y NO WMI: medido en el arranque de Windows 8.1, WMI aun no esta
' listo a los ~3 s y la consulta retrasaba ~10 s el inicio de php/caddy. tasklist
' ve tambien los procesos elevados. Cuenta cualquier php.exe/caddy.exe del equipo,
' igual que detener_servidor.vbs (taskkill /IM).
Function EstaCorriendo(nombreExe)
    EstaCorriendo = False
    tmp = shell.ExpandEnvironmentStrings("%TEMP%") & "\idprinter_chk_" & nombreExe & ".txt"
    On Error Resume Next
    shell.Run "cmd /c tasklist /FI ""IMAGENAME eq " & nombreExe & """ /NH > """ & tmp & """ 2>&1", 0, True
    If fso.FileExists(tmp) Then
        Set f = fso.OpenTextFile(tmp, 1)
        contenido = f.ReadAll
        f.Close
        fso.DeleteFile tmp
        If InStr(1, contenido, nombreExe, vbTextCompare) > 0 Then EstaCorriendo = True
    End If
    On Error GoTo 0
End Function

lanzoPhp = False
lanzoCaddy = False
phpCorriendo = EstaCorriendo("php.exe")
caddyCorriendo = EstaCorriendo("caddy.exe")
tDeteccion = Timer

' Iniciar PHP en background (puerto 8080)
' Las rutas de CA para OpenSSL y cURL se pasan con -d para que se resuelvan
' segun la ubicacion real del proyecto (no dependen de C:\printerapp\...)
If Not phpCorriendo Then
    phpCmd = """" & phpExe & """" & _
             " -d openssl.cafile=""" & caBundle & """" & _
             " -d curl.cainfo=""" & caBundle & """" & _
             " -S 0.0.0.0:8080 -t """ & docRoot & """"
    shell.Run phpCmd, 0, False
    lanzoPhp = True
End If

' Iniciar Caddy en background (usando Caddyfile)
If Not caddyCorriendo Then
    caddyCmd = """" & caddyExe & """" & " run --config """ & caddyfile & """ --adapter caddyfile"
    shell.Run caddyCmd, 0, False
    lanzoCaddy = True
End If

tLanzamiento = Timer
If esSilencioso Then
    Set logT = fso.OpenTextFile(logFile, 8, True)
    logT.WriteLine Now & " [T] tiempos(s): archivos " & FormatNumber((tArchivos - t0), 1) & _
        ", deteccion " & FormatNumber((tDeteccion - tArchivos), 1) & _
        ", lanzamiento " & FormatNumber((tLanzamiento - tDeteccion), 1) & _
        ", total " & FormatNumber((tLanzamiento - t0), 1)
    logT.Close
End If

If lanzoPhp Or lanzoCaddy Then
    detalle = ""
    If lanzoPhp Then detalle = detalle & "php iniciado. "
    If lanzoCaddy Then detalle = detalle & "caddy iniciado. "
    If Not lanzoPhp Then detalle = detalle & "php ya estaba corriendo. "
    If Not lanzoCaddy Then detalle = detalle & "caddy ya estaba corriendo. "
    Avisar "Servidor iniciado en segundo plano. (" & Trim(detalle) & ")" & vbCrLf & _
           "Acceso LAN: https://[tu-ip]:9443" & vbCrLf & _
           "Acceso local: https://localhost:9443", False
Else
    Avisar "El servidor ya estaba corriendo; no se hizo nada." & vbCrLf & _
           "Acceso LAN: https://[tu-ip]:9443" & vbCrLf & _
           "Acceso local: https://localhost:9443", False
End If
