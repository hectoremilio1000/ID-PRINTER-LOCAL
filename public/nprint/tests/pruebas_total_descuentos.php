<?php
/**
 * Campo `total_descuentos` (se incluye desde pruebas_estilo.php; usa $ctrl, $casos, $ok, $cubiertos, correr(), snap(), sinFormato(), V2()).
 * Línea «TOTAL DESCUENTOS  -$x» antes de SUBTOTAL en cuenta, nota de venta, factura y cuenta dividida. Apagado por defecto.
 * El PHP no recalcula: imprime totales.descuentos tal como llega. Sin el campo, sin descuentos o SIN `style`, el papel es el de hoy.
 */
$cubiertos['cuenta']['total_descuentos'] = $cubiertos['nota_venta']['total_descuentos'] = $cubiertos['factura']['total_descuentos'] = true;
foreach (['cuenta', 'nota_venta', 'factura'] as $d) {
    $def = \App\Support\CatalogoTicket::CAMPOS[$d]['total_descuentos'] ?? null;
    $ok($def !== null && $def['def'] === false && $def['obl'] === false && $def['parte'] === 'totales', "catálogo: $d.total_descuentos existe, apagado por defecto y no obligatorio");
}
$conDesc = function (array $c, $descuentos): array {
    foreach ($c['jobs'] as &$j) {
        if ($descuentos === null) unset($j['data']['totales']['descuentos']); else $j['data']['totales']['descuentos'] = $descuentos;
    }
    unset($j);
    return $c;
};
$linDesc = '/TOTAL DESCUENTOS +-\$17\.50/';
foreach (['cuenta', 'nota_con_pago', 'nota_qr_dias', 'factura', 'split_final', 'split_preview'] as $caso) {
    $sinCampo = $conDesc($casos[$caso], null);
    $con17 = $conDesc($casos[$caso], 17.5);
    $ok(correr($ctrl, $con17, null) === correr($ctrl, $sinCampo, null) && correr($ctrl, $sinCampo, null) === snap($caso), "total_descuentos: sin style, totales.descuentos no cambia ni un byte: $caso");
    $ok(correr($ctrl, $con17, V2([], [])) === correr($ctrl, $sinCampo, V2([], [])), "total_descuentos: con style pero sin el campo, no sale nada: $caso");
    $ok(correr($ctrl, $con17, V2([], ['total_descuentos' => false])) === correr($ctrl, $sinCampo, V2([], ['total_descuentos' => false])), "total_descuentos: campo apagado, no sale nada: $caso");
    $on = ['total_descuentos' => true];
    $s = sinFormato(correr($ctrl, $con17, V2([], $on)));
    $ok(preg_match($linDesc, $s) === 1, "total_descuentos: encendido muestra «TOTAL DESCUENTOS -\$17.50»: $caso");
    $ok(strpos($s, 'TOTAL DESCUENTOS') < strpos($s, 'SUBTOTAL'), "total_descuentos: va antes de SUBTOTAL: $caso");
    $ok(sinFormato(correr($ctrl, $sinCampo, V2([], $on))) === sinFormato(correr($ctrl, $sinCampo, V2([], []))), "total_descuentos: encendido pero sin descuentos = el mismo texto de siempre: $caso");
    $ok(sinFormato(correr($ctrl, $conDesc($casos[$caso], 0), V2([], $on))) === sinFormato(correr($ctrl, $sinCampo, V2([], []))), "total_descuentos: descuentos en 0 no imprime nada: $caso");
    $s2 = sinFormato(correr($ctrl, $con17, V2([], $on + ['descuento_item' => false, 'descuento_orden' => false])));
    $ok(preg_match($linDesc, $s2) === 1 && !str_contains($s2, 'PROMO') && !str_contains($s2, 'DCTO ORDEN'), "total_descuentos: sale aunque se oculten el descuento por producto y el de la orden: $caso");
}
$s = sinFormato(correr($ctrl, $conDesc($casos['cuenta'], 17.5), V2([], ['total_descuentos' => true])));
$ok(strpos($s, 'DCTO ORDEN') < strpos($s, 'TOTAL DESCUENTOS'), 'total_descuentos: en la cuenta va después del descuento de la orden');
$ok(!str_contains(sinFormato(correr($ctrl, $conDesc($casos['cuenta'], 17.5), V2([], ['total_descuentos' => true, 'subtotal' => false]))), 'SUBTOTAL') && str_contains(sinFormato(correr($ctrl, $conDesc($casos['cuenta'], 17.5), V2([], ['total_descuentos' => true, 'subtotal' => false]))), 'TOTAL DESCUENTOS'), 'total_descuentos: independiente de SUBTOTAL');
