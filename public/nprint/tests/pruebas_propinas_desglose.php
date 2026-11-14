<?php
/**
 * Pago de propinas con desglose efectivo / tarjeta (Symphonie, 2026-11-13).
 *   php tests/pruebas_propinas_desglose.php
 * Con los campos nuevos imprime venta y propina por forma de pago; sin ellos, el ticket de siempre.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/arnes.php';
date_default_timezone_set('America/Mexico_City');

$ctrl = cargarControlador(__DIR__ . '/../controllers/PrinterController.php');
$fallas = 0;
$ok = function (bool $c, string $msg) use (&$fallas) {
    echo ($c ? 'PASS ' : 'FAIL ') . $msg . "\n";
    if (!$c) $fallas++;
};
$texto = fn(string $bytes) => preg_replace('/[\x00-\x09\x0B-\x1F]./', '', $bytes);

$base = ['waiterId' => 7, 'waiterFullName' => 'Edwin Pedraza', 'tableName' => 'Orden 1', 'collected' => 138, 'paid' => 138];
$nuevo = [[
    'printerName' => 'CAJA',
    'data' => ['propinas' => [
        $base + ['orderId' => 1779661, 'amount' => 138, 'sale' => 2345, 'saleCash' => 845, 'saleCard' => 1500, 'tipCash' => 38, 'tipCard' => 100],
        $base + ['orderId' => 1779666, 'amount' => 138, 'sale' => 0, 'saleCash' => 0, 'saleCard' => 0, 'tipCash' => 0, 'tipCard' => 138],
    ]],
]];
$t = $texto(imprimirEndpoint($ctrl, 'printPropinas', $nuevo));
$ok(str_contains($t, 'Venta efectivo:') && str_contains($t, '$845.00'), 'imprime venta en efectivo');
$ok(str_contains($t, 'Venta tarjeta:') && (str_contains($t, '$1,500.00') || str_contains($t, '$1500.00')), 'imprime venta en tarjeta');
$ok(str_contains($t, 'Propina efectivo:') && str_contains($t, '$38.00'), 'imprime propina en efectivo');
$ok(str_contains($t, 'Propina tarjeta:') && str_contains($t, '$238.00'), 'suma la propina en tarjeta de todas las cuentas del mesero');
$ok(str_contains($t, '$276.00'), 'el TOTAL PROPINA no cambia');

$viejo = $nuevo;
foreach ($viejo[0]['data']['propinas'] as &$e) unset($e['saleCash'], $e['saleCard'], $e['tipCash'], $e['tipCard']);
unset($e);
$t2 = $texto(imprimirEndpoint($ctrl, 'printPropinas', $viejo));
$ok(!str_contains($t2, 'efectivo') && !str_contains($t2, 'tarjeta'), 'payload viejo: sin desglose, como siempre');

$ceros = $nuevo;
foreach ($ceros[0]['data']['propinas'] as &$e) { $e['saleCash'] = $e['saleCard'] = $e['tipCash'] = $e['tipCard'] = 0; }
unset($e);
$t3 = $texto(imprimirEndpoint($ctrl, 'printPropinas', $ceros));
$ok(!str_contains($t3, 'efectivo') && str_contains($t3, '$276.00'), 'desglose en ceros (sin registro de pago): no imprime "$0 / $0"');

echo $fallas ? "\n$fallas FALLA(S)\n" : "\nTODO OK\n";
exit($fallas ? 1 : 0);
