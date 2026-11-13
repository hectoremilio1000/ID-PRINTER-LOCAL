#!/usr/bin/env python3
"""
Genera bin/finger/FingerPrintAuth.exe: el MISMO FingerPrint.exe con los textos de la ventana de identificar
cambiados de «asistencia» a «autorización». No cambia ninguna instrucción del programa, solo textos.

Por qué así: no tenemos el código fuente de FingerPrint.exe (proyecto AppHuella). Los textos de un programa .NET
viven en el montón #US de los metadatos: cada uno es [largo comprimido][UTF-16][1 byte de bandera], y el código
los busca por su posición. Un texto más corto cabe en el mismo lugar ajustando su largo; uno más largo no.

FingerPrint.exe (asistencia) queda intacto. ID-Printer usa FingerPrintAuth.exe solo en /fingerprint/v1/authorize.

Uso:  python3 -I tools/huella/textos_autorizacion.py
"""
import hashlib
import os
import struct
import sys

AQUI = os.path.dirname(os.path.abspath(__file__))
CARPETA = os.path.join(AQUI, '..', '..', 'public', 'nprint', 'bin', 'finger')
ORIGEN = os.path.join(CARPETA, 'FingerPrint.exe')
DESTINO = os.path.join(CARPETA, 'FingerPrintAuth.exe')

TEXTOS = {
    'Asistencia por huella': 'Autorizar con huella',
    'Marca tu asistencia': 'Autorizar acción',
    'Escanea tu huella para registrar tu asistencia.': 'Gerente: pon tu dedo para autorizar la acción.',
    'Este restaurante aún no tiene huellas activas.': 'Ningún gerente tiene huella para autorizar.',
    '🕒 Registro de asistencia': '🔐 Autorizar acción',
    'Coloca tu dedo en el lector.\nSe registrará tu asistencia automáticamente.':
        'Coloca tu dedo en el lector.\nSe autorizará la acción al reconocerte.',
    '✅ Listo para asistencia: {0} usuarios / {1} huellas.': '✅ Listo para autorizar: {0} gerentes / {1} huellas.',
    'Registrando asistencia…': 'Autorizando…',
    '❌ No match. Intento {0}/{1}. Vuelve a colocar tu dedo…': '❌ No coincide. Intento {0}/{1}. Pon el dedo otra vez…',
    'Lo intentaste 3 veces. Solicita tu identidad de nuevo.': 'Lo intentaste 3 veces. Usa la contraseña o reintenta.',
    '✅ Asistencia registrada': '✅ Autorizado',
}


def heap_us(d: bytes):
    pe = struct.unpack_from('<I', d, 0x3C)[0]
    nsec = struct.unpack_from('<H', d, pe + 6)[0]
    opt = pe + 24
    dd = opt + (96 if struct.unpack_from('<H', d, opt)[0] == 0x10B else 112)
    tam_opt = struct.unpack_from('<H', d, pe + 20)[0]
    secs = [struct.unpack_from('<8sIIII', d, opt + tam_opt + i * 40) for i in range(nsec)]

    def off(rva):
        for _, vs, va, rs, rp in secs:
            if va <= rva < va + max(vs, rs):
                return rva - va + rp
        raise ValueError('rva fuera de secciones')

    if struct.unpack_from('<II', d, dd + 4 * 8) != (0, 0):
        raise SystemExit('El exe está firmado (Authenticode): cambiarlo rompería la firma.')
    cli = off(struct.unpack_from('<I', d, dd + 14 * 8)[0])
    if struct.unpack_from('<II', d, cli + 32) != (0, 0):
        raise SystemExit('El exe tiene nombre seguro: cambiarlo rompería la firma.')
    md = off(struct.unpack_from('<I', d, cli + 8)[0])
    p = md + 16 + struct.unpack_from('<I', d, md + 12)[0]
    ns = struct.unpack_from('<H', d, p + 2)[0]
    p += 4
    for _ in range(ns):
        o, s = struct.unpack_from('<II', d, p)
        name = d[p + 8:d.index(b'\0', p + 8)]
        if name == b'#US':
            return md + o, s
        p += 8 + ((len(name) + 4) // 4) * 4
    raise SystemExit('Sin montón #US')


def entradas(d: bytes, ini: int, tam: int):
    """(posición, texto) de cada entrada del montón #US."""
    p, fin = ini + 1, ini + tam  # la entrada 0 es vacía
    while p < fin:
        b = d[p]
        if b & 0x80 == 0:
            n, h = b, 1
        elif b & 0xC0 == 0x80:
            n, h = ((b & 0x3F) << 8) | d[p + 1], 2
        else:
            n, h = ((b & 0x1F) << 24) | (d[p + 1] << 16) | (d[p + 2] << 8) | d[p + 3], 4
        if n == 0:
            p += 1
            continue
        yield p, h, n, d[p + h:p + h + n - 1].decode('utf-16le', 'replace')
        p += h + n


def bandera(s: str) -> int:
    # ECMA-335 II.24.2.4: 1 si algún carácter necesita manejo especial (no ASCII "simple").
    for ch in s:
        c = ord(ch)
        if c > 0x7E or 0x01 <= c <= 0x08 or 0x0E <= c <= 0x1F or c in (0x27, 0x2D):
            return 1
    return 0


def main() -> int:
    d = bytearray(open(ORIGEN, 'rb').read())
    print('origen sha256', hashlib.sha256(d).hexdigest())
    ini, tam = heap_us(bytes(d))
    pendientes = dict(TEXTOS)
    for p, h, n, s in list(entradas(bytes(d), ini, tam)):
        if s not in pendientes:
            continue
        nuevo = pendientes.pop(s)
        cuerpo = nuevo.encode('utf-16le') + bytes([bandera(nuevo)])
        L = len(cuerpo)
        if L > n or h not in (1, 2) or (h == 1 and L >= 0x80):
            raise SystemExit(f'No cabe: {s!r} -> {nuevo!r}')
        # Se conserva el tamaño del encabezado de largo (1 o 2 bytes) para no mover nada.
        d[p:p + h] = bytes([L]) if h == 1 else bytes([0x80 | (L >> 8), L & 0xFF])
        d[p + h:p + h + L] = cuerpo
        # El resto del espacio viejo queda sin uso (nadie apunta ahí); se limpia con ceros.
        for i in range(p + h + L, p + h + n):
            d[i] = 0
        print(f'  {s!r} -> {nuevo!r}')
    if pendientes:
        raise SystemExit(f'No se encontraron: {list(pendientes)}')
    open(DESTINO, 'wb').write(d)
    print('destino', DESTINO, hashlib.sha256(d).hexdigest())
    return 0


if __name__ == '__main__':
    sys.exit(main())
