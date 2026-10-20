# Watchdog del ID-Printer

Que ningún restaurante dependa de que alguien abra el ID-Printer, y que si algo
falla durante el servicio se recupere solo o diga claramente qué requiere una persona.

## Qué es

| Pieza | Archivo | Qué hace |
|---|---|---|
| Watchdog | `watchdog/idprinter-watchdog.ps1` | Cada minuto (y al encender Windows): observa php, caddy, Spooler e impresoras; decide; recupera lo que es seguro recuperar; escribe `logs/watchdog/salud.json` y la bitácora. |
| Tarea | `\GrowthSuite\ID-Printer Watchdog` | Programador de tareas de Windows, cuenta **SYSTEM**, disparadores **al arrancar** + **cada 1 min**, `IgnoreNew`, prioridad normal, sin condiciones de batería. La crea `instalar.ps1`. |
| Salud para el POS | `GET /nprint/health` (`HealthController.php`) | Devuelve el último `salud.json` y si está vigente. No ejecuta PowerShell: responde en milisegundos. |
| Instalador | `watchdog/instalar.ps1` | `Instalar` / `Estado` / `Verificar` / `Pausar` / `Reanudar` / `Desinstalar` con respaldo y rollback. |
| Consulta auxiliar | `watchdog/trabajos_activos.php` | Cuenta los tickets "recibido" recientes en la bitácora SQLite (para no reiniciar php a media impresión). |
| Pruebas | `watchdog/tests/` | Unitarias de la decisión + integración en laboratorio con una impresora falsa. |

Reutiliza lo que ya existía: el arranque idempotente (`iniciar_servidor.vbs`, PR #17) sigue
funcionando con doble clic; la idempotencia por `jobUid` (`PrintJobLog::reenviados`); el
rastreo por trabajo (`GET /nprint/jobs`, evento 307) y el descarte de buffers parciales (E-15).

## Decisiones (y por qué)

1. **Programador de tareas + PowerShell, no un servicio de Windows.** `php -S` y `caddy run`
   no son servicios; convertirlos exigiría un envoltorio (NSSM/WinSW): un binario más que
   instalar, firmar y mantener. El Programador de tareas viene en todo Windows, corre sin
   sesión iniciada, y ya estaba probado con el ID-Printer (lab-dev/lab-lv1). La periodicidad
   de 1 min se paga con ~1 s de CPU por minuto.
2. **SYSTEM como cuenta de ejecución.** Medido: el php lanzado como SYSTEM imprime (evento
   307 con `user=SYSTEM`, impresoras de red Generic/Text Only, lab-lv1 2026-10-20). No
   necesita contraseña (una cuenta de usuario con contraseña se rompe cuando la cambian), no
   depende de que alguien inicie sesión. Límite: SYSTEM no ve las **conexiones por usuario**
   (`\\otro-servidor\impresora` agregadas en el perfil de alguien). El ID-Printer tampoco las
   usa: imprime con `copy` a `\\<esta PC>\<recurso compartido>`, o sea solo impresoras
   **instaladas en esta PC y compartidas** (USB, red TCP/IP o LPT: todas son de máquina).
3. **Salud = respuesta HTTP real, no "el proceso existe".** php se sondea en
   `http://127.0.0.1:8080/nprint/hello`; caddy en `https://127.0.0.1:9443/nprint/hello`.
   Una sonda fallida se repite 3 veces (4 s entre intentos, 5 s de timeout) antes de declarar
   "colgado": la primera lista de impresoras en frío tarda ~6 s y no debe confundirse con un
   cuelgue.
4. **Solo procesos propios.** El servidor se reconoce por ruta del ejecutable **y** línea
   de comando (`php … -S …:<puerto>`, `caddy run --config <este Caddyfile>`): un php de
   consola que use el mismo `php.exe` no es el servidor y no se toca (bug encontrado en
   pruebas: la primera versión lo cerraba como "sobrante"). Nunca se mata un proceso ajeno;
   si otro programa ocupa el puerto se reporta `requiere_intervencion`.
5. **Límites y backoff.** Máximo 3 recuperaciones por componente en 15 min; después espera
   15 → 30 → 60 min y lo marca `requiere_intervencion`. Evita bucles de reinicio (p. ej. un
   certificado borrado o un php.ini roto). Las recuperaciones se olvidan tras **10 min
   estable**: tres fallas aisladas en el día no son "inestable"; un php que cae cada 2 min sí.
6. **No cortar tickets.** Antes de reiniciar un php colgado se consulta la bitácora: si hay un
   ticket "recibido" de los últimos 60 s se aplaza hasta 2 ciclos. Si aun así se reinicia, el
   ticket queda "rechazado" y el reenvío del POS con el mismo `jobUid` se imprime una sola vez.
7. **Spooler.** Detenido → se arranca. Colgado (WMI no contesta en 20 s) → se reinicia solo
   tras 3 revisiones seguidas, porque reiniciar el Spooler puede repetir desde el principio un
   trabajo que se estaba imprimiendo. El reinicio cierra `spoolsv.exe` directo (un Spooler
   congelado no atiende `sc stop`: medido, se quedaba ~2 min) y lo vuelve a arrancar (0.8 s).
   Las consultas WMI de impresoras corren en un runspace con límite duro: una consulta que
   nunca vuelve no deja al watchdog colgado (con `IgnoreNew` eso detendría la supervisión).
8. **Un solo watchdog a la vez.** Mutex `Global\GrowthSuite-IDPrinter-Watchdog` + `IgnoreNew`.
9. **`opcache.cache_id` por carpeta.** En Windows el opcache se comparte entre procesos del
   mismo usuario y SAPI; un segundo php como SYSTEM aborta con *"Opcode handlers are unusable
   due to ASLR"* (reproducido). El watchdog lanza php con un `cache_id` derivado de la ruta y
   la consulta por consola corre sin opcache.
10. **Prioridad normal.** La plantilla del PR #17 usa `<Priority>7</Priority>` y los hijos la
    heredan: el php/caddy del laboratorio corrían en `BelowNormal` (medido). La tarea del
    watchdog usa prioridad 4 (normal).
11. **El watchdog nunca imprime ni reenvía.** No toca la cola ni la bitácora de impresión.
12. **Salud vigente = del php que contesta.** `/nprint/health` invalida el `salud.json` si
    el pid del php que responde no es el que vio el watchdog (reinicio de php o de Windows
    después de la última revisión): sin esto, tras un reinicio se mostraba un "disponible"
    de antes de apagar (encontrado en la prueba de reinicio).
13. **Impresoras fuera de uso.** `ImpresorasIgnoradas` en `watchdog\config.json`: se listan
    como `ignorada` y no afectan el estado general (Orquideario tiene dos que apuntan a una
    red vieja).
14. **Arranque en paralelo.** Al encender, php y caddy se lanzan a la vez y luego se espera
    a cada uno.

## Estados que ve el POS (`GET /nprint/health` → `estadoGeneral`)

| Estado | Significado | Quién lo determina |
|---|---|---|
| (sin respuesta) | **Servidor caído** o inaccesible desde la red | El POS, porque la petición no llega |
| `disponible` | php, caddy, Spooler e impresoras compartidas bien | watchdog |
| `impresora_con_problema` | Servidor bien; alguna impresora `no_disponible` o `con_error` | watchdog |
| `spooler_falla` | Servidor bien; la Cola de impresión de Windows detenida o colgada | watchdog |
| `https_caido` | php bien, caddy (9443) no | watchdog |
| `recuperando` | El watchdog está arrancando/reiniciando algo en esta revisión | watchdog |
| `requiere_intervencion` | Puerto ocupado por otro programa, faltan archivos, o se agotaron los reintentos | watchdog |
| `pausado` | Alguien puso el watchdog en pausa (mantenimiento) | watchdog |
| `desconocido` | El watchdog no está instalado o su última revisión tiene > 3 min | `HealthController` |

Por impresora (`impresoras[].estado`):

| Estado | Señal real de Windows |
|---|---|
| `disponible` | Impresora de red cuyo puerto TCP (9100) acepta conexión, sin trabajos en error |
| `no_disponible` | Puerto TCP sin respuesta, o Windows la marca fuera de línea (`WorkOffline`, `PrinterStatus=7`) |
| `con_error` | Trabajos en error en la cola, o el driver reporta sin papel/atasco/tapa abierta |
| `sin_senal` | USB/LPT sin errores reportados: Windows **no da prueba de vida** con drivers sin bidireccional |

Por trabajo (ya existía, `GET /nprint/jobs`): `enviado` = Windows lo aceptó en cola ·
`en_cola` = sigue atorado · `impreso` = Windows terminó de mandarlo (evento 307) ·
`sin_confirmar` · `rechazado`. **Ninguno prueba que salió papel**: con Generic/Text Only
Windows da por impreso aunque no haya papel. El HTTP 200 de una impresión significa "Windows
lo aceptó", no "salió".

## Operación

```
logs\watchdog\watchdog.log      bitácora (rota a 1 MB, 5 archivos). Solo cambios de estado,
                                acciones, errores y un latido cada 30 min.
logs\watchdog\salud.json        última revisión (lo lee /nprint/health)
logs\watchdog\estado.json       memoria entre ejecuciones (fallas, backoff)
logs\watchdog\PAUSA             si existe, el watchdog no arranca ni reinicia nada
logs\watchdog\respaldos\<fecha> respaldo de tareas previas + manifiesto de cada instalación
watchdog\config.json            (opcional) sobreescribe valores por omisión
```

Diagnóstico rápido: `powershell -ExecutionPolicy Bypass -File watchdog\instalar.ps1 -Accion Estado`

Mantenimiento (parar el ID-Printer a propósito): `-Accion Pausar`, hacer lo necesario,
`-Accion Reanudar`. Sin pausa, el watchdog lo vuelve a levantar en ≤ 1 min.

## Pruebas

Ver `docs/AVANCE_WATCHDOG.md` § Pruebas (tabla con resultado, evidencia y tiempos).
