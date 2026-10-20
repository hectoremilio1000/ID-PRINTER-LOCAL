# Instalar el watchdog del ID-Printer en un restaurante

Tiempo: ~10 min. No corta el servicio: si el ID-Printer está funcionando, el watchdog lo
deja como está. Todo se hace en la PC del ID-Printer, en **PowerShell como administrador**.

## 0. Antes (desde soporte)

- [ ] Acceso a la PC (SSH por Tailscale o RustDesk) y saber dónde está instalado el
      ID-Printer (`C:\IDPRINT-impresiones-locales`, `C:\printerapp\ID-PRINTER-LOCAL`…).
- [ ] Windows 8.1 o superior con PowerShell ≥ 4 (`$PSVersionTable.PSVersion`).
- [ ] Horario sin servicio para el **reinicio de prueba** del paso 4 (el resto no interrumpe).
- [ ] Anotar el commit actual (para rollback): `git -C <carpeta> rev-parse --short HEAD`.

## 1. Actualizar el código

Si la carpeta es un clon de git (tiene `.git`):

```powershell
cd <carpeta>
git status --short              # debe salir vacío (o solo archivos ignorados)
git fetch origin
git checkout <rama-o-commit-aprobado>
```

`cert\`, `logs\`, `public\nprint\.env` y `public\nprint\data\print_jobs.sqlite*` están en
`.gitignore`: no se tocan. Si **no** es un clon de git, copiar encima los archivos del
release con `robocopy <release> <carpeta> /E /XD cert logs .git /XF print_jobs.sqlite* servidor.log`
después de respaldar la carpeta completa.

> Actualizar el código **no reinicia** php y no corta impresiones: `php -S` lee los `.php` en
> cada petición (opcache revalida cada 2 s), así que `/nprint/health` responde en cuanto se
> actualiza. Lo único que espera al reinicio del paso 4 es que php corra como SYSTEM con su
> `opcache.cache_id` propio.

## 2. Instalar

```powershell
powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1
```

Revisa el entorno, respalda las tareas de arranque previas en
`logs\watchdog\respaldos\<fecha>\`, **deshabilita** (no borra) las que lanzaban
`iniciar_servidor.vbs` de esta carpeta, registra `\GrowthSuite\ID-Printer Watchdog`
(SYSTEM, al encender y cada minuto), activa el registro de impresión de Windows y corre una
primera revisión. Sale con código 0 si quedó `disponible` (o `impresora_con_problema`).

## 3. Verificar

```powershell
powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1 -Accion Verificar
```

Debe decir `VERIFICADO`, con php, caddy, Spooler e impresoras. Revisar que las impresoras
listadas sean las del restaurante (solo aparecen las **compartidas**).

## 4. Reinicio de prueba (horario sin servicio)

1. `Restart-Computer`.
2. **No iniciar sesión** en Windows.
3. Desde otra máquina: `https://<ip-pc>:9443/nprint/health` → `"estadoGeneral":"disponible"`
   en ≤ 2 min después de que Windows arranque.
4. Mandar una comanda de prueba desde el POS a cada impresora y confirmar el papel.
5. `-Accion Estado`: en la bitácora debe verse `primera ejecucion`/`iniciar OK` como SYSTEM.

## 5. Dejar registro

En la ficha del equipo (Drive / `equipos.json`): commit instalado, fecha, resultado del
reinicio de prueba y quién confirmó el papel.

## Diagnóstico

| Síntoma | Qué ver |
|---|---|
| `requiere_intervencion` | `-Accion Estado` → la línea `requiere intervencion:` dice qué (puerto ocupado, faltan archivos, reintentos agotados) |
| `desconocido` en /health | El watchdog no corre: `Get-ScheduledTask -TaskPath \GrowthSuite\` y su `LastTaskResult` |
| `impresora_con_problema` | `/nprint/health` → `impresoras[].estado`; para red, `Test-NetConnection <ip> -Port 9100` |
| `spooler_falla` | `Get-Service Spooler`; el watchdog lo arranca solo; si se repite, revisar drivers |
| php no arranca | `logs\watchdog\watchdog.log` y ejecutar a mano `php\php.exe -S 127.0.0.1:8099 -t public` para ver el error |

Mantenimiento con el ID-Printer apagado a propósito: `-Accion Pausar` … `-Accion Reanudar`.

## Rollback

1. Quitar el watchdog y rehabilitar las tareas anteriores:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1 -Accion Desinstalar
   ```
   php/caddy siguen corriendo; las tareas que el instalador deshabilitó vuelven a estar
   habilitadas (según el último `manifiesto.json`).
2. Regresar el código (si hace falta): `git checkout <commit-anotado-en-el-paso-0>`.
3. Reiniciar php para que tome el código anterior: con el watchdog ya quitado, cerrar php
   (`Stop-Process -Name php -Force` como administrador) y lanzar `iniciar_servidor.vbs`.
4. Los respaldos (`logs\watchdog\respaldos\`) y la bitácora se conservan.
