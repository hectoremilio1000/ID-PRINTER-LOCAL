<?php
/**
 * REPORTE CORTE MESERO (Symphonie, 2026-11-13).
 *   php tests/pruebas_corte_mesero.php [--ver]
 * --ver imprime el texto del ticket para revisarlo a ojo.
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
$texto = fn(string $b) => preg_replace('/[\x00-\x09\x0B-\x1F][\x00-\xFF]?[\x00-\x03]?/', '', $b);

$c = fn($n, $m) => ['cantidad' => $n, 'monto' => $m];
$mesero = fn(string $nombre) => [
    'waiterId' => 6,
    'waiterName' => $nombre,
    'totales' => ['ventaSinImpuestos' => 19168.10, 'iva' => 3066.90, 'ventaConImpuestos' => 22235],
    'ventas' => ['efectivo' => 0, 'tarjeta' => 22235, 'total' => 22235],
    'propinas' => ['efectivo' => 0, 'tarjeta' => 3964.5, 'total' => 3964.5],
    'control' => [
        'efectivo' => ['ventas' => 0, 'propina' => 0, 'total' => 0],
        'tarjeta' => ['ventas' => 22235, 'propina' => 3964.5, 'total' => 26199.5],
    ],
    'operativa' => [
        'ordenes' => 7, 'platillos' => 103, 'personas' => 16, 'promPlatillosPorOrden' => 14.71,
        'promPorOrden' => 3176.43, 'promPorPersona' => 1389.69,
        'ventasEnMesa' => $c(7, 22235), 'ventasADomicilio' => $c(0, 0), 'ventasParaLlevar' => $c(0, 0),
        'cortesiasEnCuentas' => $c(0, 0), 'cortesiasEnPlatillos' => $c(0, 0),
        'cancelacionesDeCuentas' => $c(1, 650), 'anulacionesEnPlatillos' => $c(2, 650),
        'descuentosEnCuentas' => $c(0, 0), 'descuentosEnPlatillos' => $c(0, 0),
    ],
];
$jobs = [['printerName' => 'CAJA', 'data' => [
    'restaurante' => 'Symphonie', 'periodo' => '13/11/2026', 'turno' => '#140329',
    'meseros' => [$mesero('Israel Cruz'), $mesero('Edwin Pedraza')],
]]];
$bytes = imprimirEndpoint($ctrl, 'printCorteMesero', $jobs);
$t = $texto($bytes);
if (in_array('--ver', $argv, true)) echo $t, "\n";

$ok(substr_count($bytes, 'Reporte Corte Mesero') === 2, 'un ticket por mesero');
$ok(substr_count($bytes, "\x1dV") >= 2, 'corta el papel entre meseros');
$ok(str_contains($t, 'ISRAEL CRUZ') && str_contains($t, 'EDWIN PEDRAZA'), 'nombre del mesero en mayúsculas');
$ok((bool) preg_match('/Total de Venta c\/Impts\s+\$22235\.00/', $t), 'total de venta con impuestos');
$ok((bool) preg_match('/Total Propina:\s+\$3964\.50/', $t), 'total de propina');
$ok((bool) preg_match('/Total Tarjeta:\s+\$26199\.50/', $t), 'control tarjeta = ventas + propina');
$ok((bool) preg_match('/Anulaciones en Platillos:\s+2\s+\$650\.00/', $t), 'anulaciones con cantidad e importe');
$ok(!str_contains($t, 'IEPS'), 'sin IEPS (el POS no lo maneja)');
foreach (explode("\n", $t) as $l) {
    if (strlen($l) > 48) { $ok(false, "renglón de más de 48: «$l»"); break; }
}

$vacio = [['printerName' => 'CAJA', 'data' => ['meseros' => []]]];
try {
    imprimirEndpoint($ctrl, 'printCorteMesero', $vacio);
    $ok(false, 'sin meseros debe contestar error');
} catch (RuntimeException $e) {
    $ok(str_contains($e->getMessage(), 'No hay meseros'), 'sin meseros: error claro, no imprime nada');
}

echo $fallas ? "\n$fallas FALLA(S)\n" : "\nTODO OK\n";
exit($fallas ? 1 : 0);
