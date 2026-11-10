<?php

namespace App\Controllers;

use App\Support\BiometricProtocol;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class FingerprintController
{
    // Ajusta estas rutas a tu instalación
    private string $exePath = __DIR__ . '/../bin/finger/FingerPrint.exe';
    // Mismo programa con los textos de la ventana en "autorizacion" (tools/huella/textos_autorizacion.py).
    private string $exeAuthPath = __DIR__ . '/../bin/finger/FingerPrintAuth.exe';
    // Lectores ZKTeco (ZK9500): mismas órdenes y salida que FingerPrint.exe (tools/huella/zk/HuellaZK.cs).
    // Sus lectores se listan como "zk:<serie>" y sus plantillas empiezan con "zk1:".
    private string $exeZkPath = __DIR__ . '/../bin/finger/HuellaZK.exe';

    // ✅ Cloud API (Adonis)
    private string $cloudBaseUrl;
    private int $cloudTimeoutSec = 15;

    public function __construct()
    {
        $this->cloudBaseUrl = $this->resolveCloudBaseUrl();
    }

    private function resolveCloudBaseUrl(): string
    {
        $value = $this->loadEnvValue('CLOUD_BASE_URL');
        if ($value !== null && $value !== '') {
            return rtrim($value, '/');
        }

        return 'http://localhost:3333/api';
    }

    private function loadEnvValue(string $key): ?string
    {
        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        $envPath = __DIR__ . '/../.env';
        if (!is_file($envPath) || !is_readable($envPath)) {
            return null;
        }

        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }

            [$name, $raw] = explode('=', $line, 2);
            if (trim($name) !== $key) {
                continue;
            }

            $value = trim($raw);
            if ($value === '') {
                return '';
            }

            $first = $value[0];
            if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                $value = substr($value, 1, -1);
            }

            return str_replace('\n', "\n", $value);
        }

        return null;
    }

    private function httpGetJson(string $url, ?string $authHeader = null): array
    {
        $headers = "Accept: application/json\r\n";
        if ($authHeader) $headers .= "Authorization: " . $authHeader . "\r\n";

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $headers,
                'timeout' => $this->cloudTimeoutSec,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            // 👇 log extra: headers HTTP si existen
            $hdrs = isset($http_response_header) ? $http_response_header : [];

            return [
                'ok' => false,
                'error' => 'cloud_request_failed',
                'detail' => $err['message'] ?? 'unknown',
                'http_response_header' => $hdrs,
            ];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'cloud_invalid_json', 'raw' => $raw];
        }

        return $json;
    }





    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function runExe(array $args, int $timeoutSec = 120, ?string $exe = null): array
    {
        $exe = $exe ?? $this->exePath;
        // php -S corta cada peticion a los 30 s (max_execution_time) y deja el exe abierto sin nadie que lo lea.
        @set_time_limit($timeoutSec + 30);

        if (!file_exists($exe)) {
            return ['ok' => false, 'error' => 'finger_exe_not_found', 'exePath' => $exe];
        }

        $cmd = '"' . $exe . '" ' . implode(' ', array_map('escapeshellarg', $args));
        file_put_contents('C:\temp\nprint_cmd.log', $cmd . PHP_EOL, FILE_APPEND);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'proc_open_failed'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = time();
        $out = '';
        $err = '';

        while (true) {
            $status = proc_get_status($proc);
            $out .= stream_get_contents($pipes[1]) ?: '';
            $err .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) break;

            if ((time() - $start) > $timeoutSec) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['ok' => false, 'error' => 'exe_timeout', 'timeoutSec' => $timeoutSec, 'stderr' => trim($err)];
            }

            usleep(200000); // 200ms
        }

        $exitCode = $status['exitcode'];
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $lines = preg_split("/\r\n|\n|\r/", trim($out));
        $lastJson = null;

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') continue;
            $try = json_decode($line, true);
            if (is_array($try)) {
                $lastJson = $try;
                break;
            }
        }

        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => 'finger_exe_failed', 'exitCode' => $exitCode, 'stderr' => trim($err), 'raw' => trim($out)];
        }

        if (!$lastJson) {
            return ['ok' => false, 'error' => 'finger_exe_invalid_json', 'stderr' => trim($err), 'raw' => trim($out)];
        }

        return $lastJson;
    }


    private function runExeLines(array $args, ?string $exe = null): array
    {
        $exe = $exe ?? $this->exePath;
        if (!file_exists($exe)) {
            return ['ok' => false, 'error' => 'finger_exe_not_found', 'exePath' => $exe, 'lines' => []];
        }

        $cmd = '"' . $exe . '" ' . implode(' ', array_map('escapeshellarg', $args));
        $output = [];
        $exitCode = 0;

        exec($cmd, $output, $exitCode);
        $raw = trim(implode("\n", $output));

        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => 'finger_exe_failed', 'exitCode' => $exitCode, 'raw' => $raw, 'lines' => $output];
        }

        return ['ok' => true, 'lines' => $output, 'raw' => $raw];
    }

    public function enrollDebug(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $reader = (string)($q['readerSerial'] ?? '');
        $fingerIndex = (int)($q['fingerIndex'] ?? 0);

        if (!$reader || $fingerIndex < 1 || $fingerIndex > 4) {
            $response->getBody()->write("missing readerSerial or fingerIndex");
            return $response->withStatus(400);
        }

        // Streaming plain text (no JSON)
        $res = $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache');

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        $cmd = '"' . $this->exePath . '" ' . implode(' ', array_map('escapeshellarg', [
            'enroll',
            '--reader',
            $reader,
            '--fingerIndex',
            (string)$fingerIndex,
            '--samples',
            '4',
            '--timeoutMs',
            '90000'
        ]));

        // Esto manda stdout del exe directo al HTTP response (debug)
        passthru($cmd);

        return $res;
    }



    // POST /fingerprint/enroll  body: { "userId": 123, "fingerIndex": 1 }
    public function enroll(Request $request, Response $response): Response
    {
        file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] enroll start\n", FILE_APPEND);

        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) $body = [];

            // Fallback robusto: leer body crudo correctamente (PSR-7 stream)
            if (empty($body)) {
                $stream = $request->getBody();
                if ($stream->isSeekable()) $stream->rewind();
                $raw = $stream->getContents();

                // Log útil para ver qué llegó
                file_put_contents(
                    'C:\temp\nprint_http.log',
                    "[" . date('c') . "] content-type=" . $request->getHeaderLine('Content-Type') .
                        " rawLen=" . strlen($raw) .
                        " raw=" . $raw . "\n",
                    FILE_APPEND
                );

                $try = json_decode($raw, true);
                if (is_array($try)) $body = $try;
            }


            $fingerIndex = (int)($body['fingerIndex'] ?? 0);
            if ($fingerIndex < 1 || $fingerIndex > 4) {
                return $this->json($response, ['ok' => false, 'error' => 'finger_index_invalid', 'hint' => 'use 1..4'], 400);
            }
            $reader = (string)($body['readerSerial'] ?? '');

            if (!$reader) return $this->json($response, ['ok' => false, 'error' => 'readerSerial_required'], 400);
            file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] running exe\n", FILE_APPEND);

            $exeEnroll = $this->exeParaLector($reader);
            $this->traerAlFrente(pathinfo($exeEnroll, PATHINFO_FILENAME));
            $result = $this->runExe([
                'enroll',
                '--reader',
                $reader,
                '--fingerIndex',
                (string)$fingerIndex,
                '--samples',
                '4',
                '--timeoutMs',
                '90000'
            ], 180, $exeEnroll);
            file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] exe done\n", FILE_APPEND);



            return $this->json($response, $result, $result['ok'] ? 200 : 500);
        } catch (\Exception $e) {
            $code = 500;

            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $code);
        }
    }

    // POST /fingerprint/verify body: { "templateBase64": "...", "userId": 123 }
    public function verify(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody() ?? [];
            $tpl = (string)($body['templateBase64'] ?? '');
            if (!$tpl) return $this->json($response, ['ok' => false, 'error' => 'template_required'], 400);

            $reader = (string)($body['readerSerial'] ?? '');
            if (!$reader) return $this->json($response, ['ok' => false, 'error' => 'readerSerial_required'], 400);

            $result = $this->runExe([
                'verify',
                '--reader',
                $reader,
                '--templateBase64',
                $tpl,
                '--timeoutMs',
                '60000'
            ]);


            return $this->json($response, $result, $result['ok'] ? 200 : 500);
        } catch (\Exception $e) {
            $code = 500;

            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $code);
        }
    }

    // POST /fingerprint/identify body:
    // { "restaurantId": 123, "readerSerial": "SERIAL" }
    // El local server trae candidates desde la nube y ejecuta Identify 1:N con el EXE.
    public function identify(Request $request, Response $response): Response
    {
        try {
            $stream = $request->getBody();
            if ($stream->isSeekable()) $stream->rewind();
            $raw = $stream->getContents();

            file_put_contents(
                'C:\temp\nprint_http.log',
                "[" . date('c') . "] identify DEBUG ct=" . $request->getHeaderLine('Content-Type') .
                    " len=" . strlen($raw) .
                    " raw=" . $raw . "\n",
                FILE_APPEND
            );

            $body = $request->getParsedBody();
            if (!is_array($body)) $body = [];

            // Fallback robusto: leer body crudo (igual que enroll)
            if (empty($body)) {
                $stream = $request->getBody();
                if ($stream->isSeekable()) $stream->rewind();
                $raw = $stream->getContents();

                file_put_contents(
                    'C:\temp\nprint_http.log',
                    "[" . date('c') . "] identify content-type=" . $request->getHeaderLine('Content-Type') .
                        " rawLen=" . strlen($raw) .
                        " raw=" . $raw . "\n",
                    FILE_APPEND
                );

                $try = json_decode($raw, true);
                if (is_array($try)) $body = $try;
            }

            $restaurantId = (int)($body['restaurantId'] ?? 0);
            $reader = (string)($body['readerSerial'] ?? '');

            if ($restaurantId <= 0) {
                return $this->json($response, ['ok' => false, 'error' => 'restaurantId_required'], 400);
            }
            if (!$reader) {
                return $this->json($response, ['ok' => false, 'error' => 'readerSerial_required'], 400);
            }

            // ✅ Passthrough Authorization del front hacia la nube (si aplica)
            $authHeader = $request->getHeaderLine('Authorization');
            $cloudUrl = rtrim($this->cloudBaseUrl, '/') . "/restaurants/{$restaurantId}/fingerprint-candidates";

            // (Opcional) log para debug
            file_put_contents('C:\temp\nprint_http.log', "[" . date('c') . "] identify fetch cloud: {$cloudUrl}\n", FILE_APPEND);

            $cloud = $this->httpGetJson($cloudUrl, $authHeader ?: null);

            if (!isset($cloud['ok']) || $cloud['ok'] !== true) {
                return $this->json($response, [
                    'ok' => false,
                    'error' => 'cloud_candidates_failed',
                    'cloud' => $cloud
                ], 502);
            }

            $candidates = $cloud['candidates'] ?? [];
            if (!is_array($candidates) || count($candidates) === 0) {
                return $this->json($response, [
                    'ok' => false,
                    'error' => 'no_fingerprints_for_restaurant',
                    'restaurantId' => $restaurantId
                ], 404);
            }

            $candidates = $this->plantillasDeLaMarca($candidates, $reader);
            if (count($candidates) === 0) {
                return $this->json($response, [
                    'ok' => false,
                    'error' => 'no_fingerprints_for_restaurant',
                    'restaurantId' => $restaurantId
                ], 404);
            }

            // ✅ Guardamos candidates en archivo temporal para el EXE
            $tmp = tempnam(sys_get_temp_dir(), 'fp_');
            file_put_contents($tmp, json_encode(['candidates' => $candidates], JSON_UNESCAPED_UNICODE));

            // ✅ Ejecutar EXE Identify (el de la marca del lector)
            $exeIdent = $this->exeParaLector($reader);
            $this->traerAlFrente(pathinfo($exeIdent, PATHINFO_FILENAME));
            $result = $this->runExe([
                'identify',
                '--reader',
                $reader,
                '--candidatesFile',
                $tmp,
                '--timeoutMs',
                '60000'
            ], 120, $exeIdent);

            @unlink($tmp);

            // ✅ Respuesta enriquecida para el front
            // El EXE ya retorna: { ok:true, mode:'identify', matched:true/false, userId? }
            $result['restaurantId'] = $restaurantId;
            $result['candidatesUsers'] = count($candidates);

            return $this->json($response, $result, $result['ok'] ? 200 : 500);
        } catch (\Exception $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }


    private function firstReaderSerial(): string
    {
        $l = $this->lectores();
        return $l[0]['serial'] ?? '';
    }

    /** Lectores conectados de las dos marcas: [{serial, name, marca: 'dp'|'zk'}]. DigitalPersona primero. */
    private function lectores(): array
    {
        $out = [];
        foreach ([['dp', $this->exePath], ['zk', $this->exeZkPath]] as [$marca, $exe]) {
            if (!is_file($exe)) continue;
            $r = $this->runExeLines(['list-readers'], $exe);
            if (!$r['ok']) continue;
            foreach ($r['lines'] as $line) {
                $j = json_decode(trim($line), true);
                if (is_array($j) && isset($j['serial']) && (string)$j['serial'] !== '') {
                    $out[] = ['serial' => (string)$j['serial'], 'name' => (string)($j['name'] ?? ''), 'marca' => $marca];
                }
            }
        }
        return $out;
    }

    private function esZk(string $serial): bool
    {
        return stripos($serial, 'zk:') === 0;
    }

    /** Programa que lee ese lector. Para autorizar con DigitalPersona, la copia con textos de autorización. */
    private function exeParaLector(string $serial, bool $autorizar = false): string
    {
        if ($this->esZk($serial)) return $this->exeZkPath;
        return ($autorizar && is_file($this->exeAuthPath)) ? $this->exeAuthPath : $this->exePath;
    }

    /** Solo las plantillas de la marca del lector: ZKTeco empiezan con "zk1:", DigitalPersona no. */
    private function plantillasDeLaMarca(array $candidates, string $serial): array
    {
        $zk = $this->esZk($serial);
        $out = [];
        foreach ($candidates as $c) {
            $tpls = array_values(array_filter((array)($c['templates'] ?? []), function ($t) use ($zk) {
                return is_string($t) && $t !== '' && ((stripos($t, 'zk1:') === 0) === $zk);
            }));
            if (count($tpls) > 0) $out[] = ['userId' => (int)($c['userId'] ?? 0), 'templates' => $tpls];
        }
        return $out;
    }

    /**
     * GET /fingerprint/v1/identity — llaves PÚBLICAS de este agente, para registrarlo en la nube.
     * Las secretas se crean aquí la primera vez y nunca salen de esta PC.
     */
    public function v1Identity(Request $request, Response $response): Response
    {
        if (!BiometricProtocol::available()) {
            return $this->json($response, ['ok' => false, 'error' => 'sodium_no_disponible'], 503);
        }
        try {
            $id = (new BiometricProtocol())->identity();
        } catch (\Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => 'llaves_no_disponibles'], 500);
        }
        $readers = array_map(fn($l) => ['serial' => $l['serial'], 'name' => $l['name']], $this->lectores());
        return $this->json($response, ['ok' => true, 'v' => BiometricProtocol::VERSION] + $id + ['readers' => $readers]);
    }

    /**
     * POST /fingerprint/v1/authorize — contrato GSBIO1 (lo usan el Edge y, sin Edge, la app como simple transporte).
     * body: { "v": 1, "challenge": {...}, "sealed": { "epk", "iv", "ct", "tag" } }
     *
     * No pide llave compartida: las plantillas solo se pueden descifrar aquí y la respuesta va firmada con la
     * llave de este agente, ligada al reto. Las plantillas viven solo en un archivo temporal y nunca se registran.
     */
    public function v1Authorize(Request $request, Response $response): Response
    {
        if (!BiometricProtocol::available()) {
            return $this->json($response, ['ok' => false, 'error' => 'sodium_no_disponible'], 503);
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) $stream->rewind();
        $body = json_decode($stream->getContents(), true);
        if (!is_array($body) || ($body['v'] ?? null) !== BiometricProtocol::VERSION) {
            return $this->json($response, ['ok' => false, 'error' => 'cuerpo_invalido'], 400);
        }

        $challenge = BiometricProtocol::normalizeChallenge($body['challenge'] ?? null);
        if ($challenge === null) {
            return $this->json($response, ['ok' => false, 'error' => 'reto_invalido'], 400);
        }

        // Tiempos para diagnosticar la demora (ms desde que llegó la petición). Sin plantillas.
        $t0 = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $ms = static fn(): int => (int)round((microtime(true) - $t0) * 1000);
        $tiempos = [];

        $proto = new BiometricProtocol();
        try {
            $payload = $proto->open($challenge, $body['sealed'] ?? null);
        } catch (\Throwable $e) {
            return $this->json($response, ['ok' => false, 'error' => 'llaves_no_disponibles'], 500);
        }
        if ($payload === null) {
            return $this->json($response, ['ok' => false, 'error' => 'sobre_invalido'], 400);
        }

        $candidates = [];
        $allowedUserIds = [];
        foreach (($payload['candidates'] ?? []) as $c) {
            $userId = (int)($c['userId'] ?? 0);
            $templates = is_array($c['templates'] ?? null) ? array_values(array_filter($c['templates'], 'is_string')) : [];
            if ($userId <= 0 || count($templates) === 0) continue;
            $candidates[] = ['userId' => $userId, 'templates' => $templates];
            $allowedUserIds[$userId] = true;
        }
        if (count($candidates) === 0 || count($candidates) > 25) {
            return $this->json($response, ['ok' => false, 'error' => 'candidatos_invalidos'], 400);
        }

        $tiempos['abierto'] = $ms();
        $reader = $this->firstReaderSerial();
        $tiempos['lector'] = $ms();
        if ($reader === '') {
            return $this->json($response, ['ok' => false, 'error' => 'lector_no_detectado'], 503);
        }
        $candidates = $this->plantillasDeLaMarca($candidates, $reader);
        if (count($candidates) === 0) {
            return $this->json($response, ['ok' => false, 'error' => 'sin_huellas_de_este_lector'], 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fpa_');
        try {
            file_put_contents($tmp, json_encode(['candidates' => $candidates], JSON_UNESCAPED_UNICODE));
            $exeAuth = $this->exeParaLector($reader, true);
            $this->traerAlFrente(pathinfo($exeAuth, PATHINFO_FILENAME));
            $tiempos['exe'] = $ms();
            $result = $this->runExe(array_merge([
                'identify',
                '--reader',
                $reader,
                '--candidatesFile',
                $tmp,
                '--timeoutMs',
                '60000',
            ], $this->esZk($reader) ? ['--modo', 'autorizar'] : []), 120, $exeAuth);
        } finally {
            @unlink($tmp);
        }
        $tiempos['fin'] = $ms();

        // Sin plantillas: solo lo necesario para diagnosticar.
        @file_put_contents('C:\temp\nprint_authorize.log', date('c') . ' ' . json_encode(array_intersect_key($result, array_flip(['ok', 'error', 'matched', 'userId', 'exitCode', 'timeoutSec']))) . ' ms=' . json_encode($tiempos) . ' stderr=' . substr((string)($result['stderr'] ?? ''), 0, 300) . PHP_EOL, FILE_APPEND);

        if (($result['ok'] ?? false) !== true) {
            $raw = (string)($result['raw'] ?? '');
            $timedOut = ($result['error'] ?? '') === 'timeout'
                || ($result['error'] ?? '') === 'exe_timeout'
                || strpos($raw, '"error":"timeout"') !== false;

            return $this->json(
                $response,
                ['ok' => false, 'error' => $timedOut ? 'tiempo_agotado' : 'lector_error'],
                $timedOut ? 408 : 500
            );
        }

        $userId = (int)($result['userId'] ?? 0);
        $matched = ($result['matched'] ?? false) === true && isset($allowedUserIds[$userId]);
        if (!$matched) $userId = 0;

        return $this->json($response, [
            'ok' => true,
            'result' => $challenge + ['matched' => $matched, 'userId' => $userId],
            'sig' => $proto->sign($challenge, $matched, $userId),
        ]);
    }

    /**
     * Lanza, sin esperarlo, el ayudante que pone al frente la ventana del lector apenas aparece.
     * El lector DigitalPersona solo lee con su ventana ACTIVA, y Windows no deja que un programa abierto por PHP
     * se active solo: sin esto había que darle clic a la ventana antes de poner el dedo.
     */
    private function traerAlFrente(string $proceso): void
    {
        $ps1 = __DIR__ . '/../bin/finger/traer_al_frente.ps1';
        if (!is_file($ps1) || !preg_match('/^[A-Za-z0-9_.-]+$/', $proceso)) return;
        $cmd = 'start "" /B powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File '
            . escapeshellarg(realpath($ps1) ?: $ps1) . ' -Proceso ' . $proceso . ' -Segundos 20';
        $h = @popen($cmd, 'r');
        if ($h !== false) @pclose($h);
    }

    // GET /fingerprint/list-readers
    public function listReaders(Request $request, Response $response): Response
    {
        try {

            if (!is_file($this->exePath) && !is_file($this->exeZkPath)) {
                return $this->json($response, ['ok' => false, 'error' => 'finger_exe_not_found'], 500);
            }
            // DigitalPersona y ZKTeco: Admin muestra la lista tal cual (serial + name).
            $readers = array_map(fn($l) => ['serial' => $l['serial'], 'name' => $l['name']], $this->lectores());

            return $this->json($response, ['ok' => true, 'count' => count($readers), 'readers' => $readers], 200);
        } catch (\Exception $e) {
            $code = 500;

            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], $code);
        }
    }
}
