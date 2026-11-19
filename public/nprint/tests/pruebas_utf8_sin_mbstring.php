<?php
/* Acentos sin mbstring (8 de Raíz: «La entrada debe ser UTF-8» al reimprimir «Agua Mineral Félix»).
   Correr: php -d disable_functions=mb_substr,mb_strlen tests/pruebas_utf8_sin_mbstring.php */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/controllers/PrinterController.php';
use Mike42\Escpos\Printer; use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
echo 'mb_substr disponible: ' . (function_exists('mb_substr') ? 'si' : 'NO') . "\n";
$rc = new ReflectionClass('App\Controllers\PrinterController');
$c = $rc->newInstanceWithoutConstructor();
$row = $rc->getMethod('printItemRow');
$two = $rc->getMethod('printTwoColumnLine');
$fail = 0;
foreach ([null, 32, 30, 42] as $cols) {
  foreach (['Agua Mineral Félix', 'Café de olla con piloncillo y canela', 'Ñoquis al pesto añejo'] as $d) {
    $p = new Printer(new DummyPrintConnector());
    try { $row->invoke($c, $p, 2, $d, 70, 48, $cols); $two->invoke($c, $p, $d, '$70.00', $cols ?? 48); echo "OK   cols=" . var_export($cols, true) . " $d\n"; }
    catch (Throwable $e) { $fail++; echo "FALLA cols=" . var_export($cols, true) . " $d: " . $e->getMessage() . "\n"; }
  }
}
/* Reporte de turno con cancelaciones (lab DEV 2026-11-19: «Call to undefined function App\Controllers\mb_strlen()»). */
$canc = $rc->getMethod('printCancelacionesTurno');
$p = new Printer(new DummyPrintConnector());
try {
  $canc->invoke($c, $p, ['ordenes' => [['folio' => 'F001-9', 'usuario' => 'José Núñez', 'estatus' => 'Cancelada', 'total' => 120]],
    'platillos' => [['folio' => 'F001-9', 'usuario' => 'Ana', 'estatus' => 'Anulado', 'total' => 35, 'producto' => 'Café', 'cantidad' => 1]],
    'totalOrdenes' => 120, 'totalPlatillos' => 35], '2026-11-19');
  echo "OK   cancelaciones del turno con acentos\n";
} catch (Throwable $e) { $fail++; echo "FALLA cancelaciones del turno: " . $e->getMessage() . "\n"; }
/* Ninguna llamada mb_* sin respaldo en el controlador: cada una debe estar justo debajo de su function_exists. */
$lineas = file($root . '/controllers/PrinterController.php');
foreach ($lineas as $i => $l) {
  if (!preg_match('/(?<![\w>$:])mb_[a-z_]+\(/', $l) || str_contains($l, "function_exists('mb_")) continue;
  $previas = implode('', array_slice($lineas, max(0, $i - 3), 3));
  if (!str_contains($previas, "function_exists('mb_")) { $fail++; echo "FALLA mb_* sin respaldo en la línea " . ($i + 1) . ": " . trim($l) . "\n"; }
}
echo $fail ? "HAY FALLAS\n" : "TODO OK\n";
exit($fail ? 1 : 0);
