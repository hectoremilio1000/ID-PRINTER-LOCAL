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
echo $fail ? "HAY FALLAS\n" : "TODO OK\n";
exit($fail ? 1 : 0);
