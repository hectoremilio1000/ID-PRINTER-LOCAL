# Avance — Watchdog del ID-Printer (misión autónoma 2026-10-20)

Archivo vivo para retomar si la sesión se corta. Lo más nuevo arriba de cada sección.

- **Rama:** `hector_dev/idprinter-watchdog` (desde `main` = `b8e9424`, PR #17).
- **Frontera:** producción (Orquideario y demás restaurantes) **sin cambios**. Orquideario solo lectura.
- **Laboratorio:** `lab-dev` (DESKTOP-IHPELVF, Win10 19045, PS 5.1) para pruebas destructivas.
  `lab-lv1` (DESKTOP-QQVAB0U) solo lectura mientras corran las tareas `GS-Batch-L1/L2` de otra sesión.

## Hallazgos (verificados, con evidencia)

| # | Hallazgo | Evidencia |
|---|---|---|
| H1 | Orquideario (DESKTOP-2KNTPD0) corre el ID-Printer en `7b2300c` (PR #14), 6 commits detrás de `main`; sin tarea, servicio, Run ni Inicio que lo arranque. php/caddy los lanzó una persona 1 h 48 min después del boot (19-oct 05:58 → 07:46). | SSH solo lectura 2026-10-20 |
| H2 | El PR #17 (en `main`) trae `iniciar_servidor.vbs` idempotente (tasklist) con `CurrentDirectory`, y plantillas de tarea al arranque. **No supervisa**: corre una vez al boot; si php/caddy mueren o se cuelgan después, nadie los levanta. Los reintentos de la tarea no ayudan porque el `.vbs` termina con éxito tras lanzar. | Lectura del código |
| H3 | La idempotencia de impresión **ya existe**: `PrintJobLog::reenviados()` corta reenvíos del mismo `jobUid` (INSERT OR IGNORE atómico); reimprime solo si el intento anterior fue `rechazado` o quedó a medias > 120 s. E-15 descarta buffers parciales. | `index.php`, `PrintJobLog.php`, `TrackedWindowsPrintConnector.php` |
| H4 | El estado por trabajo **ya existe**: `GET /nprint/jobs?uids=` distingue recibido / enviado / en_cola / impreso / sin_confirmar / rechazado consultando la cola de Windows y el evento 307. El POS (rastreoImpresion.ts) ya lo consulta. | `PrintJobsController.php`, `WindowsPrintStatus.php`, pos-front |
| H5 | **Correr como SYSTEM imprime.** En lab-lv1 (php lanzado por la tarea `ID-Printer` como SYSTEM al boot) 7 comandas a Barra/Cocina (impresoras de red, puerto TCP 9100, driver Generic/Text Only) aparecen en el evento 307 con `user=SYSTEM`. | Evento 307, 2026-10-20 02:01–02:07 |
| H6 | La librería imprime con `copy <tmp> \\<hostname>\<recurso compartido>`: solo funcionan impresoras **locales y compartidas** (para cualquier cuenta). Las conexiones por usuario (`HKCU\Printers\Connections`) no las ve SYSTEM, pero el ID-Printer tampoco las usa. | `WindowsPrintConnector::finalizeWin` |
| H7 | Lo que falta: (a) supervisión periódica de salud real (HTTP, no solo proceso); (b) recuperación con límites/backoff; (c) vigilar el Spooler; (d) estado de impresoras/servidor consultable por el POS; (e) instalador y rollback reproducibles; (f) log con rotación. | — |
| H8 | `lab-dev` ya tenía una tarea `ID-Printer` (SYSTEM, AtStartup, `WorkingDirectory` correcta): php/caddy en sesión 0 a +24 s del boot sin autologon. | Inspección 2026-10-20 |

| H9 | **Prioridad baja heredada:** la plantilla del PR #17 (`<Priority>7</Priority>`) hace que php/caddy corran en `BelowNormal` (medido en lab-lv1). | `Get-Process` 2026-10-20 |
| H10 | **Opcache + SYSTEM:** un segundo php como SYSTEM aborta con *"Opcode handlers are unusable due to ASLR"* (opcache compartido por usuario+SAPI en Windows, activado en PR #15). | `php_err.txt` en lab-lv1 |
| H11 | Procesos lanzados desde una sesión SSH mueren al cerrarla (job de OpenSSH). Las pruebas lanzan todo vía tareas, igual que en producción. | lab-lv1 |

## Decisiones

(ver `docs/WATCHDOG.md` § Decisiones para el detalle y las razones)

- Programador de tareas + PowerShell (sin binarios nuevos); SYSTEM; cada 1 min + al arrancar.
- Salud por HTTP real con 3 reintentos; solo procesos propios (por ruta); backoff 3/15 min.
- `opcache.cache_id` por carpeta; prioridad normal; el watchdog nunca imprime.
- Laboratorio: instancia aislada en `lab-lv1:C:\gs-wd-test\app` (puertos 18080/18443, tarea
  `\GrowthSuite\ID-Printer Watchdog PRUEBA`) para no tocar el ID-Printer del lab ni su Edge.

## Tareas

- [x] Investigación: repo, PR #17, labs, Orquideario (solo lectura)
- [x] Watchdog (`watchdog/idprinter-watchdog.ps1`)
- [x] Endpoint `GET /nprint/health`
- [x] Instalador / desinstalador (`watchdog/instalar.ps1`)
- [x] Pruebas unitarias de la decisión (42/42 en lab-lv1)
- [x] Pruebas de integración en lab: 15/15 escenarios + reinicios + rollback
- [x] Reinicios reales consecutivos (3) — [ ] sin sesión: pendiente (lab-lv1 tiene autologon; lab-dev fuera de línea)
- [x] Integración POS: `saludIdPrinter.ts` en pos-front (rama `hector_dev/idprinter-salud`, worktree `idprinter-watchdog/pos-front`); Vitest 13/13 (Caja), node:test 4/4 (Comandero); sin cablear a la UI
- [ ] Documentación final + PR Draft

## Pruebas

Laboratorio: **lab-lv1** (DESKTOP-QQVAB0U, Win10 19045, PS 5.1), instancia aislada
`C:\gs-wd-test\app` (puertos 18080/18443) instalada con `instalar.ps1` como SYSTEM.
Impresora falsa `PRUEBA-WD` (Generic/Text Only → TCP 127.0.0.1:9101) para contar tickets
sin papel. Resultados crudos (JSON) en `C:\gs-wd-test\app\logs\watchdog\pruebas\`.
Detección = de la falla a la línea "falla detectada"; recuperación = de ahí a "OK";
la detección depende del intervalo de 1 min de la tarea.

**Automatizadas sin hardware:** `pruebas-decision.ps1` **50/50** (decisión, backoff,
estados, patrones de proceso) · pos-front `saludIdPrinter` Vitest **14/14**, node:test **4/4**.

| # | Prueba | Resultado | Evidencia | Tiempos | Límite |
|---|---|---|---|---|---|
| 1 | Arranque de Windows con inicio automático | **PASA** (3 reinicios) | php+caddy como `NT AUTHORITY\SYSTEM`, sesión 0, lanzados por la tarea | php responde a +52 / +57 / +46 s del arranque del kernel; ~100 s desde ordenar el reinicio | El piso lo pone Windows (la tarea vieja del lab arrancó php a +39 s en el mismo boot) |
| 2 | Arranque **sin** sesión iniciada | **PENDIENTE** | lab-lv1 tiene inicio de sesión automático (sesión `administrator` a la hora del arranque) | — | Evidencia indirecta: lab-dev (AutoAdminLogon=0) levantó php como SYSTEM a +24 s con una tarea del mismo tipo (BootTrigger+SYSTEM). Procedimiento abajo |
| 3 | Cierre forzado de PHP | **PASA** (S03) | pid 11900→9156, un solo php | det 41 s · rec 0.9 s · responde 41.8 s | — |
| 4 | Cierre forzado de Caddy | **PASA** (S04) | pid 2904→3068 | det 57 s · rec 0.5 s · responde 58.2 s | — |
| 5 | PHP vivo pero bloqueado | **PASA** (S05) | `NtSuspendProcess`; el congelado se elimina y se lanza otro | det 87 s (incluye 3 sondas ≈23 s) · rec 1.3 s · responde 84.5 s | — |
| 6 | Endpoint sin respuesta | **PASA** (S05/S06b) | La sonda es HTTP real; puerto ocupado por otro programa → `requiere_intervencion`, el intruso no se toca | reporte 35 s; al liberar el puerto php vuelve en 6.8 s | — |
| 7 | Caída del Spooler | **PASA** (S07-08) | `Stop-Service`; ticket con Spooler abajo → `success:0` (no queda como impreso) | det 28–36 s · rec 0.4 s | — |
| 8 | Recuperación del Spooler (colgado) | **PASA** (S07b) | `NtSuspendProcess(spoolsv)`; 2 avisos y reinicio a la 3.a revisión; spoolsv congelado eliminado | det 42 s · confirmaciones 120 s (a propósito) · reinicio 0.8 s · total 164 s | Antes del ajuste el reinicio tardaba 120 s más (`sc stop` colgado) |
| 9 | Impresora desconectada | **PASA** (S09) | La impresora falsa deja de contestar → `no_disponible`, estado `impresora_con_problema`; el ticket queda `en_cola` | detección 8.5 s (con revisión forzada) | **Simulado** (red). Desconectar físicamente USB/red: en sitio |
| 10 | Reconexión de impresora | **PASA** (S10) | El ticket en cola sale **una** vez al volver; estado `disponible` | entrega 2 s tras reconectar | El reintento lo hace el Spooler; el watchdog no reenvía |
| 11 | Pérdida temporal de conectividad | **PASA** (S16, S09-10) | POS↔ID-Printer: reenvío del mismo `jobUid` = `duplicate:true`, sin papel extra. ID-Printer↔impresora: cola de Windows | — | Corte real de WiFi de la tablet: en sitio |
| 12 | Múltiples ejecuciones del watchdog | **PASA** (S12) | 3 manuales + la tarea a la vez: 2–3 ceden por el candado; 1 php, 1 caddy | — | — |
| 13 | Reinicios consecutivos | **PASA** | Reinicios 2 y 3 seguidos (2 min entre ellos): ambos `disponible` sin intervención | +57 s / +46 s | — |
| 14 | Procesos previamente activos | **PASA** (S14) | Lanzar `iniciar_servidor.vbs` con el servidor arriba: mismo pid, sin duplicado. Instalar con php abierto a mano: no lo reinicia | — | — |
| 15 | Trabajo pendiente durante una falla | **PASA** (S15, S07-08, S10) | php colgado con ticket "recibido" reciente: aplaza 2 ciclos y luego reinicia. Spooler abajo: rechazado y el reintento sale 1 vez. Impresora caída: sale 1 vez al volver | reinicio tras aplazar: 169 s | El ticket a medias se simula en la bitácora |
| 16 | Reintento de solicitud ya procesada | **PASA** (S16) | mismo `jobUid` → `duplicate:true`, tickets +1 (no +2) | — | — |
| 17 | Compatibilidad LEGACY | **PASA** (S17) | Comanda sin `jobUid` imprime; `/printers`, `/hello` por https, preflight CORS 204; `iniciar_servidor.vbs` sigue funcionando | — | `Id-server.exe` (GUI) no se probó: necesita escritorio |
| 18 | Impresión física en hardware del lab | **PARCIAL** (S18) | 1 ticket a `Caja` (192.168.3.5, Generic/Text Only) desde la instancia SYSTEM: `success:1`, evento 307 `impreso` | Windows lo confirma en 1.8 s | **Que salió el papel no lo vio nadie**: falta confirmación visual |

Además: salud vieja tras reinicio (corregida y probada en vivo), `ImpresorasIgnoradas` en
vivo, rollback (`-Accion Desinstalar` → reinstalar → `Verificar` = VERIFICADO) y
aislamiento del instalador (la tarea real `\ID-Printer` del lab nunca se tocó).

### Procedimiento pendiente: arranque sin sesión (prueba 2)

En una PC **sin** inicio de sesión automático (lab-dev, o el propio piloto):
`Restart-Computer`; no tocar teclado ni RustDesk; desde la Mac
`ssh <pc> "quser"` (debe decir que no hay usuarios) y
`powershell -File watchdog\tests\medir-arranque.ps1 -App <carpeta>` → `procesos` con
usuario SYSTEM, sesión 0, `sesionesIniciadasAhora` vacío.

## Bitácora

- 10:59 Rollback y reinstalación probados en la copia de prueba; tarea real del lab intacta.
- 10:47–10:59 Tres reinicios reales de lab-lv1 (uno suelto + dos consecutivos).
- 09:23–09:45 Batería completa 15/15 con el código final; Spooler colgado: reinicio 0.8 s.
- Bugs encontrados por las pruebas y corregidos: backoff que contaba fallas aisladas;
  php de consola tratado como servidor duplicado; salud vieja tras reinicio; `sc stop`
  colgado con Spooler congelado; opcache ASLR con dos php como SYSTEM; prioridad baja
  heredada; tareas viejas de otras copias detectadas por el instalador.
- 03:05–09:10 La Mac se durmió (sin trabajo en ese lapso). Desde entonces `caffeinate`.

- 03:00 lab-dev se cayó de Tailscale a las ~03:05 sin acción nuestra (solo lecturas). Pendiente que vuelva.
