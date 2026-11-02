<?php
/**
 * Arnés de las pruebas golden: carga una COPIA del controlador cambiando el conector de impresión por
 * \Tests\ConexionPrueba y llama a los endpoints reales con una petición falsa. Devuelve los bytes por trabajo.
 */
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

require_once __DIR__ . '/ConexionPrueba.php';

function cargarControlador(string $ruta): object
{
    $src = file_get_contents($ruta);
    if ($src === false) throw new RuntimeException("No pude leer $ruta");
    $src = str_replace('new TrackedWindowsPrintConnector(', 'new \\Tests\\ConexionPrueba(', $src);
    $tmp = sys_get_temp_dir() . '/idp_ctrl_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    require $tmp;
    @unlink($tmp);
    return new \App\Controllers\PrinterController();
}

/** Llama al endpoint con los jobs y devuelve los bytes de cada trabajo, unidos con un separador. */
function imprimirEndpoint(object $ctrl, string $endpoint, array $jobs): string
{
    \Tests\ConexionPrueba::$salidas = [];
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/printers/x')
        ->withBody((new StreamFactory())->createStream(json_encode($jobs)));
    $resp = (new ResponseFactory())->createResponse();
    $res = $ctrl->$endpoint($req, $resp);
    $json = json_decode((string) $res->getBody(), true);
    if (is_array($json)) {
        foreach ($json as $r) {
            if (is_array($r) && array_key_exists('success', $r) && $r['success'] !== 1) {
                throw new RuntimeException("$endpoint falló: " . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    $out = implode("\n#--trabajo--\n", \Tests\ConexionPrueba::$salidas);
    // la hora actual cambia en cada corrida (comanda, propinas, comisiones, "Impreso" del reporte)
    return preg_replace('#\d{2}/\d{2}/\d{4} \d{2}:\d{2}(:\d{2})?#', '01/01/2026 00:00', $out);
}
