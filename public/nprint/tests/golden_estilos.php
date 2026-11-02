<?php
/**
 * Pruebas golden de estilos de impresión (ID-Printer), contrato v2 — los 11 tipos.
 *   php tests/golden_estilos.php snapshot <PrinterController.php de origin/main>
 *        -> (re)genera tests/golden/*.bin con el controlador de ESA ruta (hacerlo SOLO con el código de origin/main)
 *   php tests/golden_estilos.php
 *        -> sin `style` los bytes deben ser IDÉNTICOS al snapshot; con `style` deben cambiar solo como se espera
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/arnes.php';

date_default_timezone_set('America/Mexico_City');
putenv('FACTURACION_BASE_URL=https://pos.example.com');
$casos = require __DIR__ . '/golden/casos.php';

$modo = $argv[1] ?? 'check';
if ($modo === 'snapshot') {
    $ruta = $argv[2] ?? null;
    if (!$ruta || !is_file($ruta)) { fwrite(STDERR, "Uso: php tests/golden_estilos.php snapshot <ruta/PrinterController.php de origin/main>\n"); exit(2); }
    $ctrl = cargarControlador($ruta);
    foreach ($casos as $nombre => $c) {
        file_put_contents(__DIR__ . "/golden/$nombre.bin", imprimirEndpoint($ctrl, $c['ep'], $c['jobs']));
        echo "snapshot $nombre\n";
    }
    exit(0);
}

$ctrl = cargarControlador(__DIR__ . '/../controllers/PrinterController.php');
$fallas = 0;
$ok = function (bool $cond, string $msg) use (&$fallas) { echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n"; if (!$cond) $fallas++; };

/** Corre el caso con `style` (y/o extras) puesto en el data de cada job. */
function correr(object $ctrl, array $c, ?array $style, array $extra = []): string
{
    $jobs = $c['jobs'];
    foreach ($jobs as &$j) {
        if ($style !== null) $j['data']['style'] = $style;
        $j['data'] = array_merge($j['data'], $extra);
    }
    unset($j);
    return imprimirEndpoint($ctrl, $c['ep'], $jobs);
}
function snap(string $n): string { return (string) @file_get_contents(__DIR__ . "/golden/$n.bin"); }

// 1) sin style: idéntico al snapshot de origin/main (bytes) — los 11 tipos y sus variantes
foreach ($casos as $nombre => $c) {
    $esp = snap($nombre);
    $ok($esp !== '' && correr($ctrl, $c, null) === $esp, "sin style idéntico a origin/main: $nombre");
}

// 1b) style inválido o sin nada aplicable => igual que sin style
$malos = [['v' => 3, 'partes' => ['titulo' => ['ancho' => 3]]], 'texto', ['v' => 1, 'partes' => ['titulo' => ['ancho' => 9, 'alto' => 0, 'alineacion' => 'x']]], ['v' => 2, 'partes' => []], ['v' => 2, 'campos' => 'x']];
foreach ($casos as $nombre => $c) {
    foreach ($malos as $i => $malo) {
        $ok(correr($ctrl, $c, is_array($malo) ? $malo : null, is_array($malo) ? [] : ['style' => $malo]) === snap($nombre), "style inválido #$i se ignora: $nombre");
    }
}

// 1c) style válido pero con partes VACÍAS y campos en su valor por defecto => mismos renglones que hoy (solo cambian comandos de formato)
function sinFormato(string $b): string
{
    // quita ESC E n, GS ! n, ESC a n (negrita, tamaño, alineación) para comparar solo texto
    $b = preg_replace('/\x1bE./s', '', $b);
    $b = preg_replace('/\x1d!./s', '', $b);
    return preg_replace('/\x1ba./s', '', $b);
}
foreach ($casos as $nombre => $c) {
    $con = correr($ctrl, $c, ['v' => 2, 'partes' => ['detalle' => ['negrita' => false]], 'campos' => []]);
    $ok(sinFormato($con) === sinFormato(snap($nombre)), "style v2 con defaults conserva el texto de hoy: $nombre");
}

require __DIR__ . '/pruebas_estilo.php';

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
