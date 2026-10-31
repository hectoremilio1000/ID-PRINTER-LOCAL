<?php

namespace App\Support;

use Mike42\Escpos\Printer;

/**
 * Estilo de ticket por restaurante (contrato v1 y v2, ramas estilos-impresion / estilos-impresion-v2).
 *
 * v2 agrega `campos` (mostrar sí/no por campo del catálogo CatalogoTicket, p. ej. {"propina": false}); v1 sigue válido.
 *
 * El front manda `data.style` ya resuelto (el diseño publicado en la nube) y el controlador lo
 * aplica por PARTE (titulo, encabezado, detalle, totales, pie). Regla de oro: un `style` ausente,
 * roto o sin ningún valor válido ⇒ normalizar() devuelve null y el controlador imprime EXACTAMENTE
 * como antes (camino legacy, sin tocar un solo byte). Nunca lanza excepciones.
 *
 * Forma esperada:
 *   { "v": 1, "columnas": 32|42|48,
 *     "partes": { "<parte>": { "ancho": 1-4, "alto": 1-4, "negrita": bool, "alineacion": "izq|centro|der" } } }
 * Cada campo de cada parte es opcional; el que falte o venga inválido usa el valor de hoy.
 *
 * Valores de hoy (defaults) por parte que reemplazan a los fijos del controlador:
 *   Cuenta / nota de venta / factura (printReceiptBody):
 *     titulo      2x2 negrita centro  ← setTextSize(2,2)+setEmphasis(true) del nombre del restaurante
 *     encabezado  1x1 normal  centro  ← setTextSize(1,1) de RFC / CP / dirección / TEL (heredan el centrado)
 *     detalle     1x1 normal  izq     ← bloque de orden (MESA, MESERO...), tabla de items, descuentos y
 *                                       SUBTOTAL / IVA / TOTAL CONSUMO / PROPINA (el renglón de encabezado
 *                                       de la tabla sigue siempre en negrita, igual que hoy)
 *     totales     2x2 normal  centro  ← la línea grande «TOTAL: $x» (setTextSize(2,2), sin negrita)
 *     pie         1x1 normal  izq     ← total en letra y renglones de formas de pago (el título
 *                                       «FORMAS DE PAGO» sigue en negrita y centrado)
 *   Comanda / cancelación (renderComandaBody):
 *     titulo      2x2 normal  centro  ← «COMANDA TICKET» / «** CANCELACION **»
 *     encabezado  1x2 normal  izq     ← Area, Mesa, Mesero, Fecha, Cancelo, Motivo (setTextSize(1,2)); el
 *                                       «Motivo:» sigue en negrita
 *     detalle     1x2 normal  izq     ← producto, Tiempo, Nota y Modificadores (restaurante)
 *                 2x2 normal  izq     ← producto + modificadores en párrafo (antro, tipo 2)
 *     pie         1x1 negrita centro  ← solo la línea «PRUEBA - NO VALIDO» (la comanda no tiene pie hoy)
 */
final class EstiloTicket
{
    public const PARTES = ['titulo', 'encabezado', 'detalle', 'totales', 'pie'];
    private const COLUMNAS = [32, 42, 48];
    private const ALINEACIONES = ['izq' => Printer::JUSTIFY_LEFT, 'centro' => Printer::JUSTIFY_CENTER, 'der' => Printer::JUSTIFY_RIGHT];

    /**
     * Lee y valida `$data['style']`. Devuelve ['columnas' => ?int, 'partes' => [parte => [campo => valor]]]
     * con SOLO lo válido, o null si no hay nada aplicable (⇒ camino legacy).
     */
    public static function normalizar($data): ?array
    {
        if (!is_array($data)) return null;
        $style = $data['style'] ?? null;
        if (!is_array($style) || !in_array($style['v'] ?? null, [1, 2], true)) return null;

        $columnas = self::entero($style['columnas'] ?? null);
        if ($columnas !== null && !in_array($columnas, self::COLUMNAS, true)) $columnas = null;

        $partes = [];
        $crudas = $style['partes'] ?? null;
        if (is_array($crudas)) {
            foreach (self::PARTES as $nombre) {
                $p = $crudas[$nombre] ?? null;
                if (!is_array($p)) continue;
                $ok = [];
                $ancho = self::entero($p['ancho'] ?? null);
                if ($ancho !== null && $ancho >= 1 && $ancho <= 4) $ok['ancho'] = $ancho;
                $alto = self::entero($p['alto'] ?? null);
                if ($alto !== null && $alto >= 1 && $alto <= 4) $ok['alto'] = $alto;
                if (isset($p['negrita']) && is_bool($p['negrita'])) $ok['negrita'] = $p['negrita'];
                $al = $p['alineacion'] ?? null;
                if (is_string($al) && isset(self::ALINEACIONES[$al])) $ok['alineacion'] = $al;
                if ($ok) $partes[$nombre] = $ok;
            }
        }

        /* `campos` (v2): mapa clave => bool. Aquí solo se conservan las entradas booleanas; la validez de cada clave
         * se resuelve contra el catálogo del tipo de ticket en campo()/visible() (una clave que no es del tipo no hace nada). */
        $campos = [];
        $crudos = $style['campos'] ?? null;
        if (is_array($crudos)) {
            foreach ($crudos as $clave => $valor) {
                if (is_string($clave) && is_bool($valor)) $campos[$clave] = $valor;
            }
        }

        /* `textos` (v2): texto del restaurante por campo de texto del catálogo (p. ej. `leyenda_pie`). Se limpia aquí (sin caracteres de
         * control, máx. 200 caracteres y 4 renglones); si no cabe se ignora ese texto. Cuál campo es de texto lo decide texto(). */
        $textos = [];
        $crudosTextos = $style['textos'] ?? null;
        if (is_array($crudosTextos)) {
            foreach ($crudosTextos as $clave => $valor) {
                if (!is_string($clave) || !is_string($valor)) continue;
                $limpio = self::limpiarTexto($valor);
                if ($limpio !== null) $textos[$clave] = $limpio;
            }
        }

        if ($columnas === null && !$partes && !$campos && !$textos) return null;
        return ['columnas' => $columnas, 'partes' => $partes, 'campos' => $campos, 'textos' => $textos];
    }

    /**
     * Valor EXPLÍCITO del campo en el estilo, o null si el estilo no lo trae (o la clave no es del catálogo del tipo).
     * Un campo obligatorio (`obl`) siempre responde true: el `false` se ignora y se imprime siempre.
     */
    public static function campo(?array $estilo, string $docType, string $key): ?bool
    {
        if ($estilo === null) return null;
        $def = CatalogoTicket::CAMPOS[$docType][$key] ?? null;
        if ($def === null) return null;
        if ($def['obl']) return true;
        return array_key_exists($key, $estilo['campos'] ?? []) ? $estilo['campos'][$key] : null;
    }

    /**
     * ¿Se imprime este campo? Con estilo: su valor si lo trae, y si no, el `def` del catálogo (= lo que se imprime hoy).
     * Sin estilo, o con una clave que no es del catálogo del tipo: true (se imprime como siempre).
     */
    public static function visible(?array $estilo, string $docType, string $key): bool
    {
        $def = CatalogoTicket::CAMPOS[$docType][$key] ?? null;
        if ($def === null) return true;
        $v = self::campo($estilo, $docType, $key);
        return $v ?? (bool) $def['def'];
    }

    public const TEXTO_MAX_CARACTERES = 200;
    public const TEXTO_MAX_RENGLONES = 4;

    /** Texto del restaurante listo para imprimir: sin caracteres de control, renglones recortados, sin renglones vacíos en las puntas. null si queda vacío o no cabe. */
    public static function limpiarTexto(string $texto): ?string
    {
        $texto = preg_replace('/\r\n?/', "\n", $texto) ?? '';
        $texto = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $texto) ?? '';
        $renglones = array_map('trim', explode("\n", $texto));
        while ($renglones && end($renglones) === '') array_pop($renglones);
        while ($renglones && $renglones[0] === '') array_shift($renglones);
        if (!$renglones || count($renglones) > self::TEXTO_MAX_RENGLONES) return null;
        $limpio = implode("\n", $renglones);
        if (mb_strlen($limpio, 'UTF-8') > self::TEXTO_MAX_CARACTERES) return null;
        return $limpio;
    }

    /**
     * Texto de un campo de texto del catálogo (p. ej. la leyenda del pie), o null si no se imprime. Solo sale si el campo es de texto en el catálogo
     * del tipo, el estilo lo ENCENDIÓ de forma explícita (def false: sin estilo o sin la clave no sale) y hay texto.
     */
    public static function texto(?array $estilo, string $docType, string $key): ?string
    {
        $def = CatalogoTicket::CAMPOS[$docType][$key] ?? null;
        if ($def === null || empty($def['texto'])) return null;
        if (self::campo($estilo, $docType, $key) !== true) return null;
        $t = $estilo['textos'][$key] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    /**
     * Separa «dd/mm/aaaa hh:mm[:ss]» en [fecha, hora]. Acepta coma o espacio entre las dos partes y «a. m. / p. m.» (con o sin espacios
     * de no separación). null si no se reconoce el formato: entonces se imprime completa, nunca se inventa.
     */
    public static function partirFechaHora(string $valor): ?array
    {
        $esp = '[\s\x{00A0}\x{202F}]';
        if (preg_match('~^' . $esp . '*(\d{1,4}[/\-.]\d{1,2}[/\-.]\d{1,4})' . $esp . '*,?' . $esp . '*(\d{1,2}:\d{2}(?::\d{2})?(?:' . $esp . '*[aApP]\.?' . $esp . '?[mM]\.?)?)' . $esp . '*$~u', $valor, $m) !== 1) {
            return null;
        }
        return [trim($m[1]), trim($m[2])];
    }

    /**
     * Qué se imprime de una fecha-con-hora según sus DOS campos (fecha y hora, cada uno se enciende o apaga aparte):
     *   fecha sí + hora sí → la fecha y la hora juntas, tal como llegan (= lo de hoy)
     *   solo fecha → ['fecha' => 'dd/mm/aaaa']      solo hora → ['hora' => 'hh:mm:ss']      ninguna → []
     * La hora, si el estilo no la trae guardada, hace lo mismo que su fecha: un restaurante que ya apagó la fecha no ve aparecer la hora.
     * Si el valor no se puede separar, se imprime completo mientras alguno de los dos esté encendido.
     * Devuelve ['junto' => valor] | ['fecha' => ..] | ['hora' => ..] | [].
     */
    public static function fechaHora(?array $estilo, string $docType, string $claveFecha, string $claveHora, string $valor): array
    {
        $f = self::visible($estilo, $docType, $claveFecha);
        $h = self::campo($estilo, $docType, $claveHora) ?? $f;
        if (!$f && !$h) return [];
        if ($f && $h) return ['junto' => $valor];
        $partes = self::partirFechaHora($valor);
        if ($partes === null) return ['junto' => $valor];
        return $f ? ['fecha' => $partes[0]] : ['hora' => $partes[1]];
    }

    /** Valores efectivos de una parte: el default de hoy con lo válido del estilo encima. */
    public static function parte(?array $estilo, string $parte, array $porDefecto): array
    {
        $p = $porDefecto;
        if ($estilo !== null && isset($estilo['partes'][$parte])) {
            $p = array_merge($p, $estilo['partes'][$parte]);
        }
        return $p;
    }

    /** Manda a la impresora la alineación, el tamaño y la negrita de la parte. */
    public static function fijar($printer, array $p): void
    {
        $printer->setJustification(self::ALINEACIONES[$p['alineacion']] ?? Printer::JUSTIFY_LEFT);
        $printer->setTextSize((int) $p['ancho'], (int) $p['alto']);
        $printer->setEmphasis((bool) $p['negrita']);
    }

    /** Caracteres por renglón a ese ancho de letra: base / multiplicador (mínimo 1). */
    public static function columnasEfectivas(int $base, int $ancho): int
    {
        return max(1, intdiv($base, max(1, $ancho)));
    }

    /** Línea de impresión de prueba; el controlador la agrega cuando `data.pruebaEstilo` es true. */
    public static function esPrueba($data): bool
    {
        return is_array($data) && ($data['pruebaEstilo'] ?? false) === true;
    }

    public static function imprimirPrueba($printer, ?array $estilo): void
    {
        $p = self::parte($estilo, 'pie', ['ancho' => 1, 'alto' => 1, 'negrita' => true, 'alineacion' => 'centro']);
        $printer->feed(1);
        self::fijar($printer, $p);
        $printer->text("PRUEBA - NO VALIDO\n");
        $printer->setEmphasis(false);
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
    }

    private static function entero($v): ?int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_float($v) && floor($v) === $v) return (int) $v;
        return null;
    }
}
