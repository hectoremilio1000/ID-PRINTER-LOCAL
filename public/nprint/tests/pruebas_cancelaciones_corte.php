<?php
/**
 * Corte X con «Cancelaciones y anulaciones» al final (opcional por restaurante, 2026-11-13).
 *   php tests/pruebas_cancelaciones_corte.php [--ver]
 * Sin `cancelaciones` el corte sale idéntico (eso lo cubre golden_estilos); con ellas sale la sección.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/arnes.php';
date_default_timezone_set('America/Mexico_City');
putenv('FACTURACION_BASE_URL=https://pos.example.com');

$ctrl = cargarControlador(__DIR__ . '/../controllers/PrinterController.php');
$casos = require __DIR__ . '/golden/casos.php';
$fallas = 0;
$ok = function (bool $c, string $msg) use (&$fallas) {
    echo ($c ? 'PASS ' : 'FAIL ') . $msg . "\n";
    if (!$c) $fallas++;
};

$jobs = $casos['corte_x']['jobs'];
$sin = imprimirEndpoint($ctrl, 'printCorteX', $jobs);
$jobs[0]['data']['cancelaciones'] = [
    'ordenes' => [['folio' => '32', 'usuario' => 'ANTONIO MARCOS', 'estatus' => 'Cancelación', 'total' => 0]],
    'platillos' => [
        ['folio' => '32', 'usuario' => 'ANTONIO MARCOS', 'estatus' => 'Anulación', 'total' => 250, 'producto' => 'Copa Bacardí Blanco', 'cantidad' => 1],
        ['folio' => '38', 'usuario' => 'José Núñez', 'estatus' => 'Anulación', 'total' => 400, 'producto' => 'Mezcalita', 'cantidad' => 2],
    ],
    'totalOrdenes' => 0,
    'totalPlatillos' => 650,
];
$con = imprimirEndpoint($ctrl, 'printCorteX', $jobs);
if (in_array('--ver', $argv, true)) echo preg_replace('/[\x00-\x09\x0B-\x1F]./', '', substr($con, strlen($sin) - 40)), "\n";

$ok(str_starts_with($con, substr($sin, 0, strlen($sin) - 20)), 'el corte de siempre queda igual y la sección va al final');
$ok(str_contains($con, 'Cancelaciones de ordenes del 23/10/2026'), 'título de cancelaciones con el día del turno');
$ok(str_contains($con, 'Anulaciones de platillos del 23/10/2026'), 'título de anulaciones');
$ok(str_contains($con, 'Total: $650.00'), 'total de anulaciones');
$ok(str_contains($con, '2 Mezcalita'), 'platillo con cantidad');
// En papel cada acento es UN byte (la impresora ya lo convirtió a su tabla): se mide en bytes.
$lineas = array_filter(explode("\n", $con), fn($l) => str_contains($l, '$400.00'));
$linea = reset($lineas) ?: '';
$ok($linea !== '' && strlen($linea) === 48, 'nombre con acentos no descuadra el importe (48 caracteres)');
$ok(str_contains($con, 'Cancelaci') && !preg_match('/Cancelaci\s/', $con), '«Cancelación» completo, sin cortar');

echo $fallas ? "\n$fallas FALLA(S)\n" : "\nTODO OK\n";
exit($fallas ? 1 : 0);
