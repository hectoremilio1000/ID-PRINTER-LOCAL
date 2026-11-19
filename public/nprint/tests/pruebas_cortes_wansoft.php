<?php
/**
 * Cortes con formato Wansoft (se incluye desde pruebas_estilo.php; usa $ctrl, $casos, $ok, $cubiertos, correr(), snap(), sinFormato(), V2()).
 * Secciones nuevas de reporte de caja, cierre de caja y reporte de turno, todas APAGADAS por defecto:
 *   propina por forma de pago · control por forma de pago · información operativa · totales generales · ventas por producto.
 * Regla de oro: sin `style`, sin la clave o con la clave apagada, el ticket es el de hoy aunque el payload traiga los datos.
 * Seguridad: con la caja ABIERTA el control por forma de pago nunca imprime el fondo ni el efectivo esperado, y el reporte
 * «ciego» (sin métodos ni información operativa) no imprime ninguna sección nueva aunque el estilo las encienda.
 */
use App\Support\CatalogoTicket;

$txt = fn (string $b): string => sinFormato($b);

$productos = [
    'items' => [
        ['name' => 'Taco al pastor con pina y cilantro extra grande de la casa', 'qty' => 12, 'amount' => 420],
        ['name' => 'Agua de jamaica', 'qty' => 3, 'amount' => 75],
        ['name' => 'Pizza mitad y mitad', 'qty' => 0.5, 'amount' => 107.5],
    ],
    'totalQty' => 15.5, 'totalAmount' => 602.5,
];
$operativa = [
    'totals' => ['net' => 3555, 'tax' => 568.8, 'gross' => 4123.8], 'orders' => 20, 'dishes' => 140, 'persons' => 48,
    'avgDishesPerOrder' => 7, 'avgPerOrder' => 206.19, 'avgPerPerson' => 85.9,
    'byService' => [['name' => 'Comedor', 'orders' => 20, 'sales' => 4123.8]],
    'courtesies' => ['dishes' => 1, 'dishesAmount' => 30, 'accounts' => 0],
    'cancellations' => ['dishes' => 2, 'dishesAmount' => 60, 'accounts' => 1, 'accountsAmount' => 120],
    'discounts' => ['dishes' => 3, 'dishesAmount' => 45, 'accounts' => 2, 'accountsAmount' => 20],
    'productSales' => $productos,
];
$conDatos = function (array $caso, array $extraData) {
    $caso['jobs'] = array_map(function ($j) use ($extraData) {
        $j['data'] = array_replace_recursive($j['data'], $extraData);
        return $j;
    }, $caso['jobs']);
    return $caso;
};
$metodosConCaja = ['methods' => [['isCash' => true], ['isCash' => false]]];
$caja = $conDatos($casos['reporte_caja'], $metodosConCaja + ['operational' => $operativa]);
$cierre = $conDatos($casos['cierre_caja'], $metodosConCaja + ['operational' => $operativa]);
$turno = $conDatos($casos['corte_x'], [
    'operational' => array_diff_key($operativa, ['productSales' => 1]),
    'productSales' => $productos,
    'cajas' => [['expectedCash' => 2150, 'movements' => ['net' => -350], 'methods' => [
        ['name' => 'Efectivo', 'isCash' => true, 'sales' => 2000, 'tips' => 0],
    ]]],
]);
// la caja del turno también cobró con tarjeta
$turno['jobs'][0]['data']['cajas'][0]['methods'][] = ['name' => 'Tarjeta', 'isCash' => false, 'sales' => 2123.8, 'tips' => 300];

$marcas = [
    // [doc, caso, clave, texto que solo sale con la clave encendida]
    ['reporte_caja', $caja, 'totales_generales', 'TOTALES GENERALES'],
    ['reporte_caja', $caja, 'propinas_por_metodo', 'PROPINA POR FORMA DE PAGO'],
    ['reporte_caja', $caja, 'control_por_metodo', 'CONTROL POR FORMA DE PAGO'],
    ['reporte_caja', $caja, 'info_operativa', 'INFORMACION OPERATIVA'],
    ['reporte_caja', $caja, 'ventas_por_producto', 'REPORTE DE VENTAS POR PRODUCTO'],
    ['cierre_caja', $cierre, 'propinas_por_metodo', 'PROPINA POR FORMA DE PAGO'],
    ['cierre_caja', $cierre, 'control_por_metodo', 'CONTROL POR FORMA DE PAGO'],
    ['cierre_caja', $cierre, 'ventas_por_producto', 'REPORTE DE VENTAS POR PRODUCTO'],
    ['corte_x', $turno, 'control_por_metodo', 'CONTROL POR FORMA DE PAGO'],
    ['corte_x', $turno, 'info_operativa', 'INFORMACION OPERATIVA'],
    ['corte_x', $turno, 'ventas_por_producto', 'REPORTE DE VENTAS POR PRODUCTO'],
];
foreach ($marcas as [$doc, $caso, $key, $m]) {
    $cubiertos[$doc][$key] = true;
    $def = CatalogoTicket::CAMPOS[$doc][$key] ?? null;
    $ok($def !== null && $def['def'] === false && !$def['obl'], "catálogo: $doc.$key existe, apagado por defecto y no obligatorio");
    $ok(!str_contains($txt(correr($ctrl, $caso, null)), $m), "$doc.$key: sin estilo NO sale «{$m}» aunque vengan los datos");
    $ok(!str_contains($txt(correr($ctrl, $caso, V2([], []))), $m), "$doc.$key: estilo sin la clave NO sale «{$m}»");
    $ok(!str_contains($txt(correr($ctrl, $caso, V2([], [$key => false]))), $m), "$doc.$key=false NO sale «{$m}»");
    $ok(str_contains($txt(correr($ctrl, $caso, V2([], [$key => true]))), $m), "$doc.$key=true imprime «{$m}»");
}

// Sin estilo, aunque el payload ya traiga los datos nuevos, cada ticket sale idéntico al de hoy (bytes).
$turnoSoloNuevos = $conDatos($casos['corte_x'], ['operational' => $operativa, 'productSales' => $productos]);
foreach (['reporte_caja' => $caja, 'cierre_caja' => $cierre, 'corte_x' => $turnoSoloNuevos] as $nombre => $caso) {
    $ok(correr($ctrl, $caso, null) === snap($nombre), "sin estilo, el payload con los datos nuevos sale idéntico a hoy: $nombre");
}

// ── Control por forma de pago: el contenido ──
$c = $txt(correr($ctrl, $cierre, V2([], ['control_por_metodo' => true])));
$ok(preg_match('/Fondo de caja +\$500\.00/', $c) === 1, 'cierre: control trae el fondo de caja');
$ok(preg_match('/\(\+\/-\) Movimientos +\$-350\.00/', $c) === 1, 'cierre: control trae los movimientos netos');
$ok(preg_match('/Efectivo esperado +\$2200\.00/', $c) === 1, 'cierre: control trae el efectivo esperado');
$ok(preg_match('/Esperado - propinas tarjeta +\$1900\.00/', $c) === 1, 'cierre: esperado menos propinas con tarjeta (2200 - 300)');
$ok(preg_match('/Total Tarjeta +\$2423\.80/', $c) === 1, 'cierre: tarjeta = ventas + propina');

$c = $txt(correr($ctrl, $caja, V2([], ['control_por_metodo' => true])));
$ok(str_contains($c, 'CONTROL POR FORMA DE PAGO') && !str_contains($c, 'Fondo de caja') && !str_contains($c, 'Efectivo esperado') && !str_contains($c, 'Esperado - propinas'),
    'caja ABIERTA: el control no imprime fondo ni esperado (arqueo a ciegas)');
$ok(preg_match('/Total efectivo +\$2000\.00/', $c) === 1, 'caja abierta: total de efectivo = ventas + propina en efectivo');

$c = $txt(correr($ctrl, $turno, V2([], ['control_por_metodo' => true])));
$ok(preg_match('/Fondo de caja +\$500\.00/', $c) === 1 && preg_match('/Efectivo esperado +\$2150\.00/', $c) === 1, 'turno: control suma las cajas (fondo y esperado)');
$ok(preg_match('/Esperado - propinas tarjeta +\$1850\.00/', $c) === 1, 'turno: esperado menos propinas con tarjeta');

// ── Propina por forma de pago ──
$c = $txt(correr($ctrl, $cierre, V2([], ['propinas_por_metodo' => true])));
$ok(preg_match('/Total propina +\$300\.00/', $c) === 1, 'propina por forma de pago: total');

// ── Ventas por producto ──
$c = $txt(correr($ctrl, $cierre, V2([], ['ventas_por_producto' => true])));
$ok(preg_match('/^12   Taco al pastor .* +\$420\.00$/m', $c) === 1, 'productos: cantidad, nombre e importe en un renglón');
$ok(preg_match('/^     \S/m', $c) === 1, 'productos: un nombre largo sigue en el renglón de abajo, alineado al nombre');
$ok(preg_match('/^0\.5  Pizza mitad y mitad +\$107\.50$/m', $c) === 1, 'productos: media pieza sale 0.5');
$ok(preg_match('/Total de articulos +15\.5/', $c) === 1 && preg_match('/Importe total +\$602\.50/', $c) === 1, 'productos: total de artículos e importe');
foreach (explode("\n", $c) as $renglon) {
    if (strlen($renglon) > 42) { $ok(false, "productos: renglón de más de 42 columnas «{$renglon}»"); break; }
}
$c = $txt(correr($ctrl, $conDatos($casos['cierre_caja'], ['operational' => ['productSales' => ['items' => [], 'totalQty' => 0, 'totalAmount' => 0]]]), V2([], ['ventas_por_producto' => true])));
$ok(str_contains($c, 'Sin ventas.'), 'productos: sin ventas lo dice');

// ── Información operativa del turno ──
$c = $txt(correr($ctrl, $turno, V2([], ['info_operativa' => true])));
$ok(preg_match('/Cuentas cobradas +20/', $c) === 1 && preg_match('/Comedor \(20\) +\$4123\.80/', $c) === 1, 'turno: información operativa con cuentas y servicios');

// ── Reporte ciego: aunque el estilo encienda todo, no sale ninguna sección nueva ──
$ciego = $casos['reporte_caja'];
$ciego['jobs'][0]['data'] = ['visibility' => 'blind', 'restaurante' => 'RESTAURANTE LAB', 'station' => ['name' => 'Caja 1'], 'session' => ['cashUserName' => 'Luis'], 'textos' => ['impreso' => '23/10/2026 23:31']];
$todo = array_fill_keys(['totales_generales', 'propinas_por_metodo', 'control_por_metodo', 'info_operativa', 'ventas_por_producto'], true);
$c = $txt(correr($ctrl, $ciego, V2([], $todo)));
foreach (['TOTALES GENERALES', 'PROPINA POR FORMA', 'CONTROL POR FORMA', 'INFORMACION OPERATIVA', 'VENTAS POR PRODUCTO', 'Fondo de caja', 'esperado'] as $m) {
    $ok(!str_contains($c, $m), "reporte ciego con todo encendido no imprime «{$m}»");
}
