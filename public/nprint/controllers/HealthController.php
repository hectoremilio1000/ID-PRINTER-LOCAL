<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Salud del ID-Printer para el POS (Caja, Comandero) y soporte.
 *
 *   GET /nprint/health                  estado general + servidor + Spooler + impresoras
 *   GET /nprint/health?impresora=Cocina solo esa impresora (por nombre o recurso compartido)
 *
 * Que esta respuesta llegue ya prueba que php y (si se pidió por :9443) Caddy
 * contestan. Lo demás lo mide el watchdog cada minuto y lo deja en
 * logs/watchdog/salud.json: aquí solo se lee ese archivo, para que la consulta
 * sea instantánea y nunca dependa de PowerShell ni del Spooler.
 *
 * Si el watchdog no está instalado o dejó de correr, se dice (estadoGeneral
 * "desconocido") en vez de inventar un "todo bien".
 *
 * El "servidor caído" no lo puede contestar el servidor: lo deduce el POS
 * cuando esta petición no llega.
 */
class HealthController
{
    public function index(Request $request, Response $response): Response
    {
        $app = realpath(__DIR__ . '/../../..');
        $archivo = $app . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'watchdog' . DIRECTORY_SEPARATOR . 'salud.json';
        $ahoraMs = (int) round(microtime(true) * 1000);

        $salud = null;
        if (is_file($archivo)) {
            $crudo = @file_get_contents($archivo);
            $salud = is_string($crudo) ? json_decode($crudo, true) : null;
        }

        $out = [
            'esquema' => 'gs.idprinter.health/v1',
            'servidor' => 'disponible',
            'version' => self::version($app),
            'generadoEn' => date('c'),
        ];

        if (!is_array($salud)) {
            $out['estadoGeneral'] = 'desconocido';
            $out['watchdog'] = ['instalado' => is_file($app . '/watchdog/idprinter-watchdog.ps1'), 'activo' => false,
                'motivo' => 'No hay datos del watchdog (no está instalado o no ha corrido).'];
            return $this->json($response, $out);
        }

        $edadSeg = isset($salud['generadoEnMs']) ? max(0, (int) round(($ahoraMs - (int) $salud['generadoEnMs']) / 1000)) : null;
        $vigencia = (int) ($salud['vigenciaSeg'] ?? 180);
        $vigente = $edadSeg !== null && $edadSeg <= $vigencia;
        // Si el php que contesta no es el que vio el watchdog, la revisión es de
        // antes de un reinicio (de php o de Windows) y ya no describe lo que hay.
        $pidsVistos = $salud['servidor']['php']['pids'] ?? null;
        $otroPhp = is_array($pidsVistos) && $pidsVistos && !in_array(getmypid(), array_map('intval', $pidsVistos), true);
        if ($otroPhp) $vigente = false;

        $out['watchdog'] = [
            'instalado' => true,
            'activo' => $vigente,
            'version' => $salud['versionWatchdog'] ?? null,
            'ultimaRevision' => $salud['generadoEn'] ?? null,
            'edadSeg' => $edadSeg,
        ];
        // Datos viejos: se muestran, pero el estado general no se afirma.
        $out['estadoGeneral'] = $vigente ? ($salud['estadoGeneral'] ?? 'desconocido') : 'desconocido';
        if ($otroPhp) {
            $out['watchdog']['motivo'] = 'El servidor se reinició después de la última revisión; el watchdog lo revisará en menos de un minuto.';
        } elseif (!$vigente) {
            $out['watchdog']['motivo'] = "La última revisión tiene {$edadSeg} s: el watchdog no está corriendo.";
        }
        $out['requiereIntervencion'] = $salud['requiereIntervencion'] ?? [];
        $out['spooler'] = $salud['spooler'] ?? null;

        $impresoras = is_array($salud['impresoras'] ?? null) ? $salud['impresoras'] : [];
        $filtro = trim((string) ($request->getQueryParams()['impresora'] ?? ''));
        if ($filtro !== '') {
            $impresoras = array_values(array_filter($impresoras, function ($i) use ($filtro) {
                return strcasecmp((string) ($i['nombre'] ?? ''), $filtro) === 0
                    || strcasecmp((string) ($i['recurso'] ?? ''), $filtro) === 0;
            }));
        }
        $out['impresoras'] = array_map(function ($i) {
            return [
                'nombre' => $i['nombre'] ?? null,
                'recurso' => $i['recurso'] ?? null,
                'tipo' => $i['tipo'] ?? null,
                'estado' => $i['estado'] ?? 'desconocido',
                'estadoReal' => $i['estadoReal'] ?? null,
                'alcanzable' => $i['alcanzable'] ?? null,
                'fueraDeLinea' => $i['fueraDeLinea'] ?? null,
                'errorDetectado' => $i['errorDetectado'] ?? null,
                'trabajosEnCola' => $i['trabajosEnCola'] ?? null,
                'trabajosConError' => $i['trabajosConError'] ?? null,
            ];
        }, $impresoras);

        return $this->json($response, $out);
    }

    /** Commit desplegado, si la carpeta es un clon de git (sin ejecutar git). */
    private static function version(string $app): ?string
    {
        $head = @file_get_contents($app . '/.git/HEAD');
        if (!is_string($head)) return null;
        $head = trim($head);
        if (strpos($head, 'ref: ') === 0) {
            $ref = substr($head, 5);
            $sha = @file_get_contents($app . '/.git/' . $ref);
            if (!is_string($sha)) {
                $packed = @file_get_contents($app . '/.git/packed-refs');
                if (is_string($packed) && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m)) $sha = $m[1];
            }
            return is_string($sha) ? substr(trim($sha), 0, 7) : null;
        }
        return substr($head, 0, 7);
    }

    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
