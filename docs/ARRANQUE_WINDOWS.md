# Arranque automático del ID-Printer en Windows

Plantillas: `docs/tareas/iniciar.xml` y `detener.xml`. Reemplazar `<APP>` (carpeta donde quedó instalado), `<PC>\<USUARIO>`; guardar como **UTF-16LE con BOM** e importar:
`schtasks /Create /TN "ID-Print - Iniciar servidor" /XML iniciar.xml /RU <USUARIO> /RP <contraseña> /F`

## Lo que importa (medido en el lab, Windows 8.1, 2026-10-15)
> **Versión medida:** el ID-Printer del lab estaba en `7b2300c` (PR #14). `origin/main` ya trae el PR #15 (**E-16: lista de impresoras sin wmic y con opcache**), que probablemente reduce la consulta en frío de `/nprint/printers`. **Hay que volver a medir con esa versión** antes de dar por buenos los 4.9-6.5 s.

- **`WorkingDirectory` = carpeta del ID-Printer es obligatorio.** El `Caddyfile` usa rutas relativas (`cert/…`, `logs/…`); sin él la tarea arranca en `System32`, php queda vivo y **caddy muere** (9443 sin respuesta) aunque el doble clic funcione.
- Sin `DisallowStartIfOnBatteries` / `StopIfGoingOnBatteries` (cortes de luz/UPS). `StartWhenAvailable` y `RestartOnFailure` activos.
- `iniciar_servidor.vbs` es idempotente (solo lanza lo que no corre; `tasklist`, **no WMI**: no está listo al arrancar) y deja en `servidor.log` una línea `[T] tiempos(s): …`.
- **Detener:** lo que lanzó la tarea no se mata con `taskkill`/doble clic de un usuario normal («Acceso denegado», ni con nivel normal). Se usó una tarea `Detener` a demanda (`schtasks /Run`); **su funcionamiento sin administrador no está confirmado**.
- Tiempos (desde el arranque del kernel): tarea+script → procesos ~+14 s, primer 200 ~+21 s. La primera consulta a `/nprint/printers` en frío tarda 4.9-6.5 s (caliente ~0.55 s); `/nprint/hello` 44 ms. La espera de la tarea (30→10→0 s) no cambió el resultado.
- `public/nprint/index.php` contesta `Access-Control-Allow-Private-Network: true` solo si el preflight trae `Access-Control-Request-Private-Network: true` (Chrome moderno, página pública → IP privada).
- Pendiente: `php -S 0.0.0.0:8080` expone HTTP plano a la LAN; Caddy solo necesita `127.0.0.1`. No cambiar sin confirmar que ninguna app (APK) lo use.
- Si la IP de la PC cambia, regenerar el certificado (`mkcert <ip>`) y reiniciar caddy.
