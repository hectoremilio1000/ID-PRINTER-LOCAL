# Arranque automático del ID-Printer en Windows

Plantillas: `docs/tareas/iniciar.xml` y `detener.xml`. Reemplazar `<APP>` (carpeta donde quedó instalado), `<PC>\<USUARIO>`; guardar como **UTF-16LE con BOM** e importar:
`schtasks /Create /TN "ID-Print - Iniciar servidor" /XML iniciar.xml /RU <USUARIO> /RP <contraseña> /F`

## Procesos (desde 2026-11, rama arreglosJampier494)

| Proceso | Escucha | Atiende |
|---|---|---|
| `php -S` tickets | `0.0.0.0:8080` | todo lo demás (imprimir, abrir cajón, plantillas…) |
| `php -S` huella | `127.0.0.1:8081` | `/nprint/fingerprint/*` (espera el dedo hasta 60 s) |
| `php -S` consultas | `127.0.0.1:8082` | `GET`/`OPTIONS` de `/nprint/jobs`, `/nprint/jobs/*` y `/nprint/printers` (corren PowerShell, 2–12 s) |
| `caddy` | `:9443` HTTPS | reparte a los tres; si el 8082 no contesta, manda las consultas al 8080 y reintenta el 8082 a los 30 s |
| `vigilante.vbs` | — | cada 30 s corre `iniciar_servidor.vbs /vigilante`, que levanta solo lo que se haya caído y lo anota en `servidor.log` |

**Por qué:** el servidor integrado de PHP atiende una petición a la vez. Antes, la consulta de estado que las apps hacen 2.5 s después de cada ticket (`GET /nprint/jobs`, ~5 s con PowerShell en una Celeron) formaba en fila al siguiente ticket: en Symphonie (2026-11-13) la cuenta tardó 5–7 s cuando se imprimía entre ~2.5 y ~8 s después del ticket anterior.

- `detener_servidor.vbs` deja `logs\detener.flag` antes de matar procesos: el vigilante deja de levantar y se cierra solo (≤30 s). `iniciar_servidor.vbs` (tarea o doble clic) borra la bandera.
- Latido del vigilante: `logs\vigilante.latido` (si tiene menos de 90 s, no se lanza otro).
- Las dos instancias que escriben en `public/nprint/data/print_jobs.sqlite` (8080 y 8082) comparten la base con WAL y `busy_timeout = 3000`. La consulta de estado solo actualiza un trabajo si sigue en el estado que leyó (`PrintJobLog::actualizarSiSigue`), para no pisar un reintento.
- Revisar que el 8082 está arriba: `netstat -an -p tcp | findstr 8082`.

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
