<?php
/**
 * Pruebas golden de estilos de impresión (ID-Printer).
 *   php tests/golden_estilos.php snapshot   -> (re)genera tests/golden/*.bin con el código ACTUAL (hacerlo solo en main, antes de cambiar nada)
 *   php tests/golden_estilos.php            -> sin `style` los bytes deben ser IDÉNTICOS al snapshot; con `style` deben cambiar solo como se espera
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use App\Controllers\PrinterController;

date_default_timezone_set('America/Mexico_City');
$casos = require __DIR__ . '/golden/casos.php';
$ctrl = new PrinterController();

function renderizar(PrinterController $ctrl, array $caso, array $dataExtra = []): string
{
    $data = array_merge($caso['data'], $dataExtra);
    $conn = new DummyPrintConnector();
    $p = new Printer($conn);
    if ($caso['metodo'] === 'receipt') {
        $m = new ReflectionMethod($ctrl, 'printReceiptBody');
        $m->invoke($ctrl, $p, $data, 42);
    } else {
        $m = new ReflectionMethod($ctrl, 'renderComandaBody');
        $m->invoke($ctrl, $p, $data, (bool) $caso['esCancelacion']);
    }
    $p->feed(3);
    $p->cut();
    $bytes = $conn->getData();
    $p->close();
    // la fecha de la comanda cambia en cada corrida
    return preg_replace('#Fecha: \d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}#', 'Fecha: 01/01/2026 00:00:00', $bytes);
}

$modo = $argv[1] ?? 'check';
if ($modo === 'snapshot') {
    foreach ($casos as $nombre => $caso) {
        file_put_contents(__DIR__ . "/golden/$nombre.bin", renderizar($ctrl, $caso));
        echo "snapshot $nombre\n";
    }
    exit(0);
}

$fallas = 0;
$ok = function (bool $cond, string $msg) use (&$fallas) { echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n"; if (!$cond) $fallas++; };

// 1) sin style: idéntico al snapshot (bytes)
foreach ($casos as $nombre => $caso) {
    $esperado = @file_get_contents(__DIR__ . "/golden/$nombre.bin");
    $ok($esperado !== false && renderizar($ctrl, $caso) === $esperado, "sin style idéntico al snapshot: $nombre");
}
// 1b) style inválido => igual que sin style
foreach ([['v' => 2, 'partes' => ['titulo' => ['ancho' => 3]]], 'texto', ['v' => 1, 'partes' => ['titulo' => ['ancho' => 9, 'alto' => 0, 'alineacion' => 'x']]], ['v' => 1, 'partes' => []]] as $i => $malo) {
    $esperado = file_get_contents(__DIR__ . '/golden/cuenta.bin');
    $ok(renderizar($ctrl, $casos['cuenta'], ['style' => $malo]) === $esperado, "style inválido #$i se ignora (cuenta)");
}
// 2) con style: cambian solo las partes pedidas
$titulo3 = ['v' => 1, 'partes' => ['titulo' => ['ancho' => 3, 'alto' => 3, 'negrita' => false, 'alineacion' => 'izq']]];
$base = file_get_contents(__DIR__ . '/golden/cuenta.bin');
$con = renderizar($ctrl, $casos['cuenta'], ['style' => $titulo3]);
$ok($con !== $base, 'cuenta: style de título cambia la salida');
$ok(str_contains($con, "\x1d\x21\x22") && str_contains($con, "RESTAURANTE LAB"), 'cuenta: título a 3x3 (GS ! 0x22) y mismo texto');
$ok(str_contains($con, "TOTAL: \$95.00") && str_contains($con, "XAXX010101000"), 'cuenta: el resto del contenido sigue igual');
$ok(strlen($con) > 0 && substr_count($con, "\n") === substr_count($base, "\n"), 'cuenta: mismo número de renglones');

$tot = ['v' => 1, 'partes' => ['totales' => ['ancho' => 1, 'alto' => 1, 'negrita' => true, 'alineacion' => 'der']]];
$con2 = renderizar($ctrl, $casos['cuenta'], ['style' => $tot]);
$ok($con2 !== $base && str_contains($con2, 'TOTAL: $95.00'), 'cuenta: style de totales (1x1 negrita derecha)');

$det = ['v' => 1, 'columnas' => 42, 'partes' => ['detalle' => ['ancho' => 2, 'alto' => 2]]];
$con3 = renderizar($ctrl, $casos['nota_con_pago'], ['style' => $det]);
$ok($con3 !== file_get_contents(__DIR__ . '/golden/nota_con_pago.bin') && str_contains($con3, 'Taco'), 'nota: detalle a 2x2 con 42 columnas');

$com = ['v' => 1, 'partes' => ['detalle' => ['ancho' => 2, 'alto' => 2, 'negrita' => true]]];
$con4 = renderizar($ctrl, $casos['comanda_restaurante'], ['style' => $com]);
$ok($con4 !== file_get_contents(__DIR__ . '/golden/comanda_restaurante.bin') && str_contains($con4, 'Hamburguesa clasica'), 'comanda restaurante: detalle 2x2 negrita');
$con5 = renderizar($ctrl, $casos['comanda_antro'], ['style' => ['v' => 1, 'partes' => ['titulo' => ['ancho' => 1, 'alto' => 1]]]]);
$ok($con5 !== file_get_contents(__DIR__ . '/golden/comanda_antro.bin') && str_contains($con5, 'COMANDA TICKET'), 'comanda antro: título 1x1');
$con6 = renderizar($ctrl, $casos['cancelacion'], ['style' => ['v' => 1, 'partes' => ['encabezado' => ['alto' => 1]]]]);
$ok($con6 !== file_get_contents(__DIR__ . '/golden/cancelacion.bin') && str_contains($con6, 'CANCELACION'), 'cancelación: encabezado 1x1');

// 3) prueba
$pr = renderizar($ctrl, $casos['cuenta'], ['pruebaEstilo' => true]);
$ok(str_contains($pr, 'PRUEBA - NO VALIDO') && !str_contains($base, 'PRUEBA - NO VALIDO'), 'pruebaEstilo agrega PRUEBA - NO VALIDO');
$pr2 = renderizar($ctrl, $casos['comanda_restaurante'], ['pruebaEstilo' => true]);
$ok(str_contains($pr2, 'PRUEBA - NO VALIDO'), 'pruebaEstilo en comanda');

// 4) EstiloTicket::normalizar
$n = \App\Support\EstiloTicket::normalizar(['style' => ['v' => 1, 'columnas' => 42, 'partes' => ['titulo' => ['ancho' => '3', 'alto' => 2, 'negrita' => true, 'alineacion' => 'centro'], 'raro' => ['ancho' => 2]]]]);
$ok($n !== null && $n['partes']['titulo']['ancho'] === 3 && !isset($n['partes']['raro']) && $n['columnas'] === 42, 'normalizar: acepta válidos, ignora claves desconocidas');
$ok(\App\Support\EstiloTicket::normalizar(['style' => ['v' => 1, 'partes' => ['titulo' => ['ancho' => 9]]]]) === null, 'normalizar: sin nada válido => null');
$ok(\App\Support\EstiloTicket::normalizar([]) === null, 'normalizar: sin style => null');

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
