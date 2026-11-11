# Piloto: Orquideario (DESKTOP-2KNTPD0)

**Estado: NO ejecutado. Requiere autorización expresa de Héctor.** Todo lo de abajo se
verificó en solo lectura el 2026-10-20.

## Cómo está hoy (verificado)

| Dato | Valor |
|---|---|
| Acceso | `ssh orquideario` (Tailscale 100.95.135.122, usuario `admin`, huella ed25519 verificada en la PC) |
| Windows | 10 Pro 19045, PowerShell 5.1, Dell Vostro 3250 (i3-6100, 8 GB) |
| ID-Printer | `C:\IDPRINT-impresiones-locales`, clon git en `7b2300c` (PR #14), árbol limpio |
| Arranque | **Ninguno**: sin tarea, servicio, Run ni Inicio. php/caddy los abrió a mano el usuario `Admin` (sesión de consola 1) el 19-oct 07:46, 1 h 48 min después del arranque de Windows (05:58) |
| Prioridad de php | Normal (lanzado a mano) |
| Registro de impresión (307) | Activo; imprimiendo en producción (POS CAJA, 20-oct 10:14–10:15, usuario Admin) |
| Impresoras compartidas | `POS CAJA` → 192.168.3.62:9100 · `POS COCINA WIFI` → 192.168.3.65:9100 · `POS BARRA` → **192.168.1.53** · `POS80 Printer` → **192.168.1.65** |
| Red de la PC | 192.168.3.64 (Ethernet 2) |

`POS BARRA` y `POS80 Printer` apuntan a la red 192.168.1.x y la PC está en 192.168.3.x:
probablemente son de una configuración anterior. Hay que confirmarlo con el restaurante
antes del piloto (si se usan, hoy no estarían imprimiendo).

## Plan (cuando se autorice)

Horario: **sin servicio** (antes de abrir o después de cerrar). Duración ~20 min + reinicio.

1. **Respaldo** (solo lectura del estado + copia):
   `git -C C:\IDPRINT-impresiones-locales rev-parse HEAD` → anotar (`7b2300c…`).
   `robocopy C:\IDPRINT-impresiones-locales C:\IDPRINT-respaldo-<fecha> /E /XD .git /XF "SDK para Digital Persona.zip"`.
2. **Actualizar código:** `git fetch origin` + `git checkout <commit del PR aprobado>`.
   No corta: php sigue sirviendo (lee los `.php` en cada petición).
3. **Config local** (`watchdog\config.json`), si el restaurante confirma que POS BARRA y
   POS80 Printer no se usan: `{"ImpresorasIgnoradas":["POS BARRA","POS80 Printer"]}`.
4. **Instalar:** `powershell -ExecutionPolicy Bypass -File .\watchdog\instalar.ps1`
   → debe terminar en `disponible`. El php que abrió `Admin` se deja como está.
5. **Verificar sin reinicio:** `-Accion Verificar` y `https://192.168.3.64:9443/nprint/health`.
6. **Reinicio de prueba:** `Restart-Computer`, **sin iniciar sesión**. Desde la Mac:
   `ssh orquideario "curl -s http://127.0.0.1:8080/nprint/health"` hasta `disponible`, y
   `watchdog\tests\medir-arranque.ps1 -App C:\IDPRINT-impresiones-locales` (tiempos y que
   php corre como SYSTEM sin sesiones).
7. **Prueba de papel** (persona en sitio): una comanda desde el POS a POS CAJA y a POS
   COCINA WIFI. Confirmar que salen **una sola vez**.
8. Registrar en la ficha de Drive y en `equipos.json` (commit, fecha, resultado).

## Riesgos y mitigación

| Riesgo | Mitigación |
|---|---|
| php como SYSTEM no ve una impresora | Las 4 son locales y compartidas (puerto TCP/IP de máquina): SYSTEM sí las ve (probado en lab con el mismo tipo). Se confirma en el paso 7 antes de abrir |
| El reinicio no vuelve (red/Tailscale) | Tailscale se instaló con `--unattended` el 20-oct; sshd es servicio Automático. RustDesk también arranca solo |
| Falsa alarma permanente por impresoras viejas | `ImpresorasIgnoradas` (paso 3) |
| El cajero cierra php con `Id-server.exe` (Detener) | El watchdog lo vuelve a levantar en ≤ 1 min. Para mantenimiento: `-Accion Pausar` |
| Algo sale mal | Rollback: `instalar.ps1 -Accion Desinstalar` + `git checkout 7b2300c` + volver a abrir php como hoy |

## Criterios para dar el piloto por bueno

- [ ] Tras el reinicio sin sesión, `/nprint/health` = `disponible` en ≤ 2 min.
- [ ] Comandas reales a POS CAJA y POS COCINA WIFI salen una vez (confirmado en sitio).
- [ ] Una semana de bitácora sin `requiere_intervencion` inesperados ni reinicios en bucle.
- [ ] Ninguna queja de tickets duplicados o faltantes en ese periodo.
