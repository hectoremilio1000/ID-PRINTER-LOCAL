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

        if ($columnas === null && !$partes && !$campos) return null;
        return ['columnas' => $columnas, 'partes' => $partes, 'campos' => $campos];
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
