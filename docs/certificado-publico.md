# Certificado público por restaurante (Caddy)

El `Caddyfile` sirve HTTPS en el puerto 9443 con dos certificados, y Caddy escoge por el nombre que pide el navegador (SNI):

| El navegador entra por | Certificado |
| --- | --- |
| `https://<ip>:9443` | El de siempre (mkcert, `cert/certificado.pem`). Sigue exigiendo instalar la CA. |
| `https://r<id>.<zona>:9443` | El público, emitido por Let's Encrypt. No hay que instalar nada. |

## Cómo llega el certificado público
1. El Edge de la PC (con `EDGE_PUBLIC_CERT_DIR` apuntando a `<carpeta del ID-Printer>\cert\publico`) lo pide a la nube y lo valida.
2. Escribe en esa carpeta `certificado-publico.pem`, `llave-publica.pem` y, al final, `sitio.caddy` (el bloque del nombre del restaurante).
3. Recarga Caddy con `EDGE_PUBLIC_CERT_RELOAD_CMD`, por ejemplo:
   `["C:\\printerapp\\ID-PRINTER-LOCAL\\caddy.exe","reload","--config","C:\\printerapp\\ID-PRINTER-LOCAL\\Caddyfile","--address","127.0.0.1:2119"]`

Sin `cert/publico/sitio.caddy` el `import` no encuentra nada y el servidor arranca igual que antes. `cert/` está en `.gitignore`: las llaves nunca se versionan.

## Puesta en marcha en una PC que ya tiene el ID-Printer
- Copiar el `Caddyfile` nuevo y **reiniciar el ID-Printer una vez** (el anterior corría con `admin off`, así que no se puede recargar hasta que arranque con el nuevo).
- Crear la carpeta `cert\publico` y darle permiso de escritura a la cuenta del Edge.
- Si el firewall de la PC solo abre 9443 a la red local, no hace falta nada más: es el mismo puerto.

## Cosas a saber
- La administración de Caddy queda en `127.0.0.1:2119`, solo accesible desde la propia PC. Cualquier programa de esa PC podría cambiar la configuración de Caddy; es una PC de uso exclusivo del restaurante.
- Si un `sitio.caddy` queda mal escrito o la llave no corresponde, `caddy reload` falla y el servidor sigue con la configuración anterior. El reinicio completo del ID-Printer sí fallaría al arrancar si el archivo está roto: el Edge valida el nombre y el certificado antes de escribir.
- Probado con Caddy 2.10.2 (la versión de `caddy.exe` del repo): arranque sin el archivo, recarga con él, SNI por nombre y por IP, y un `sitio.caddy` roto.
