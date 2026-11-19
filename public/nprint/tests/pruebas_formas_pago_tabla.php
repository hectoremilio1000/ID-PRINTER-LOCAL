<?php
/**
 * Formas de pago en tabla + Terminal (nota de venta / factura) y cobro dividido con solo tickets por persona (2026-11-19).
 *   php tests/pruebas_formas_pago_tabla.php
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
/* Solo se quitan los bytes de control (no el parámetro que sigue): los renglones se buscan con regex sin ancla */
$texto = fn(string $bytes) => preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $bytes);

$data = [
    'restaurante' => ['nombre' => 'RESTAURANTE LAB'],
    'orden' => ['mesa' => '5', 'mesero' => 'Ana', 'orden' => '10', 'terminal' => 'Caja 1'],
    'items' => [['cantidad' => 1, 'descripcion' => 'Tacos', 'importe' => 5700]],
    'totales' => ['subtotal' => 4913.79, 'iva' => 786.21, 'total' => 5745, 'consumo' => 5700, 'propina' => 45],
    'pagos' => [
        ['metodo' => 'Efectivo', 'monto' => 5000, 'tipo' => 'SALE', 'cambio' => 200],
        ['metodo' => 'Tarjeta', 'monto' => 300, 'tipo' => 'SALE', 'payerName' => 'Ana Lopez Garcia'],
        ['metodo' => 'Tarjeta', 'monto' => 400, 'tipo' => 'SALE'],
        ['metodo' => 'Tarjeta', 'monto' => 45, 'tipo' => 'TIP', 'payerName' => 'Ana Lopez Garcia'],
        ['metodo' => 'Vales', 'monto' => 10, 'tipo' => 'TIP'],
    ],
];
$job = fn(array $d) => [['printerName' => 'CAJA', 'data' => $d]];
$estilo = fn(array $campos) => ['v' => 2, 'partes' => [], 'campos' => $campos];

// Sin estilo: desglosado de siempre, sin terminal
$t = $texto(imprimirEndpoint($ctrl, 'printNotaVenta', $job($data)));
$ok(str_contains($t, '(PROPINA)') && str_contains($t, 'Ana Lopez'), 'sin estilo: desglosado con (PROPINA)');
$ok(!str_contains($t, 'Terminal:') && !str_contains($t, 'Cambio'), 'sin estilo: sin tabla ni terminal');

// Tabla + terminal
$t = $texto(imprimirEndpoint($ctrl, 'printNotaVenta', $job($data + ['style' => $estilo(['formas_pago_tabla' => true, 'terminal' => true])])));
$ok((bool) preg_match('/Nombre\s+Monto\s+Propina\s+Cambio/', $t), 'tabla: encabezado Nombre · Monto · Propina · Cambio');
$ok((bool) preg_match('/Efectivo\s+\$5,?000\.00\s+\$0\.00\s+\$200\.00/', $t), 'tabla: efectivo con su cambio');
$ok((bool) preg_match('/Tarjeta - Ana Lopez Garcia\n\s+\$300\.00\s+\$45\.00\s+\$0\.00/', $t), 'tabla: propina junto a la venta de la misma persona; nombre largo en su renglón');
$ok((bool) preg_match('/\n(?:\W[a-zA-Z!])*Tarjeta\s+\$400\.00\s+\$0\.00\s+\$0\.00/', $t), 'tabla: venta sin propina');
$ok((bool) preg_match('/Vales\s+\$0\.00\s+\$10\.00\s+\$0\.00/', $t), 'tabla: propina sin venta en su propio renglón');
$ok(!str_contains($t, '(PROPINA)'), 'tabla: sin renglones (PROPINA)');
$ok((bool) preg_match('/Terminal:\s+Caja 1/', $t), 'terminal: «Terminal: Caja 1»');
$seccion = substr($t, (int) strpos($t, 'FORMAS DE PAGO'));
$seccion = substr($seccion, 0, (int) strpos($seccion, '====='));
foreach (explode("\n", $seccion) as $l) {
    if (strlen(preg_replace('/^(?:[!Ea])+(?=[A-Z ])/', '', $l)) > 42) { $ok(false, "renglón de más de 42 columnas: " . $l); break; }
}

// Terminal encendida sin nombre: no sale
$sinTerm = $data; unset($sinTerm['orden']['terminal']);
$t = $texto(imprimirEndpoint($ctrl, 'printNotaVenta', $job($sinTerm + ['style' => $estilo(['terminal' => true])])));
$ok(!str_contains($t, 'Terminal:'), 'terminal sin nombre: no sale');

// Formas de pago apagadas + terminal: solo la terminal
$t = $texto(imprimirEndpoint($ctrl, 'printNotaVenta', $job($data + ['style' => $estilo(['formas_pago' => false, 'terminal' => true])])));
$ok(!str_contains($t, 'FORMAS DE PAGO') && str_contains($t, 'Terminal:'), 'formas de pago apagadas: sale solo la terminal');

// La cuenta (print-consumo) no tiene estos campos
$t = $texto(imprimirEndpoint($ctrl, 'printConsumo', $job($data + ['style' => ['v' => 2, 'partes' => [], 'campos' => ['formas_pago_tabla' => true, 'terminal' => true]]])));
$ok(!str_contains($t, 'Terminal:') && !str_contains($t, 'Cambio'), 'cuenta: ignora tabla y terminal');

// Split final con soloVouchers: sin consolidado, con tickets por persona
$split = [
    'restaurante' => ['nombre' => 'RESTAURANTE LAB'],
    'orden' => ['mesa' => '5', 'orden' => '10'],
    'items' => [['cantidad' => 1, 'descripcion' => 'Tacos', 'importe' => 100]],
    'totales' => ['subtotal' => 86.21, 'iva' => 13.79, 'total' => 100],
    'splits' => [['payerName' => 'Ana', 'amount' => 50, 'paymentMethod' => 'Efectivo'], ['payerName' => 'Luis', 'amount' => 50, 'paymentMethod' => 'Tarjeta']],
];
$t = $texto(imprimirEndpoint($ctrl, 'printSplitFinal', $job($split)));
$ok(str_contains($t, 'CUENTA DIVIDIDA - PAGADA'), 'split final sin bandera: consolidado como siempre');
$t = $texto(imprimirEndpoint($ctrl, 'printSplitFinal', $job($split + ['soloVouchers' => true])));
$ok(!str_contains($t, 'CUENTA DIVIDIDA - PAGADA') && str_contains($t, 'Ana') && str_contains($t, 'Luis'), 'soloVouchers: solo los tickets por persona');
$t = $texto(imprimirEndpoint($ctrl, 'printSplitPreview', $job($split + ['soloVouchers' => true])));
$ok(str_contains($t, 'CUENTA DIVIDIDA - PRELIMINAR'), 'preview ignora soloVouchers');

echo $fallas ? "\n$fallas FALLA(S)\n" : "\nTODO OK\n";
exit($fallas ? 1 : 0);
