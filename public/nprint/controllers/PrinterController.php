<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\TemplateModel;

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use App\Printing\TrackedWindowsPrintConnector;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\GdEscposImage;

use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Builder\Builder;

use Exception;
use DateTime;


class PrinterController
{
    /**
     * Imprime múltiples tickets según el JSON recibido
     * POST /print
     * Body: array de objetos { templateId, printerName, data }
     */
    public function print(Request $request, Response $response, $args = [])
    {
        $jobs = $request->getParsedBody();
        if (!is_array($jobs)) {
            $rawBody = (string) $request->getBody();
            $jobs = json_decode($rawBody, true);
        }
        if (!is_array($jobs)) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'El cuerpo debe ser un array JSON válido.'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $results = [];
        foreach ($jobs as $job) {
            $templateId = $job['templateId'] ?? null;
            $printerName = $job['printerName'] ?? null;
            $data = $job['data'] ?? [];

            if (!$templateId) {
                $results[] = [
                    'success' => 0,
                    'message' => 'ID de template es requerido',
                    'template_id' => $templateId,
                    'printer_name' => $printerName
                ];
                continue;
            }
            if (!$printerName) {
                $results[] = [
                    'success' => 0,
                    'message' => 'Nombre de impresora es requerido',
                    'template_id' => $templateId,
                    'printer_name' => $printerName
                ];
                continue;
            }

            try {
                $model = new TemplateModel();
                $template = $model->getTemplateById($templateId);

                if (!$template) {
                    $response->getBody()->write(json_encode(['success' => 0, 'message' => 'Template no encontrado']));
                    return $response->withHeader('Content-Type', 'application/json');
                }

                $templateJson = json_decode($template['template_json'], true);
                $exampleJson = json_decode($template['example_json'], true);
                $caracteres = $template['caracteres'] ?? 48;
                $paperWidth = $caracteres * 8; // Calcula el ancho real en píxeles

                $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                //$profile = CapabilityProfile::load("simple");
                $printer = new Printer($connector);

                // Inicializar impresora
                $this->iniciarTicket($printer);
                $printer->setJustification(Printer::JUSTIFY_LEFT);



                // Procesar cada elemento del template
                foreach ($templateJson as $item) {
                    $type = $item['type'] ?? 'text';
                    $align = $item['align'] ?? 'left';
                    $fontSize = $item['fontSize'] ?? '1x1';
                    $columns = $item['columns'] ?? [];

                    $textType = $item['textType'] ?? 'static';
                    $field = $item['field'] ?? '';
                    $barcodeFormat = $item['formatBarcode'] ?? 'CODE128';
                    $barcodeSizeMap = [
                        '1x' => ['width' => 1.0, 'height' => 30, 'fontSize' => 11],
                        '2x' => ['width' => 1.8, 'height' => 45, 'fontSize' => 14],
                        '3x' => ['width' => 2.6, 'height' => 60, 'fontSize' => 17],
                        '4x' => ['width' => 3.4, 'height' => 80, 'fontSize' => 20],
                        '5x' => ['width' => 4.2, 'height' => 100, 'fontSize' => 23]
                    ];
                    $barcodeSize = $barcodeSizeMap[$item['size'] ?? '1x'];

                    // Configurar alineación
                    switch ($align) {
                        case 'center':
                            $printer->setJustification(Printer::JUSTIFY_CENTER);
                            break;
                        case 'right':
                            $printer->setJustification(Printer::JUSTIFY_RIGHT);
                            break;
                        default:
                            $printer->setJustification(Printer::JUSTIFY_LEFT);
                            break;
                    }

                    // Configurar tamaño de fuente
                    $fontSizeMap = [
                        '10px'  => [1, 1],
                        '12px' => [2, 1],
                        '16px' => [1, 2],
                        '24px' => [2, 2],
                        '32px' => [4, 4]
                    ];
                    $fontSizeFrontend = $item['fontSize'] ?? '8px';
                    $size = $fontSizeMap[$fontSizeFrontend] ?? [1, 1];
                    $printer->setTextSize($size[0], $size[1]);

                    // Configurar alineación
                    switch ($align) {
                        case 'center':
                            $printer->setJustification(Printer::JUSTIFY_CENTER);
                            break;
                        case 'right':
                            $printer->setJustification(Printer::JUSTIFY_RIGHT);
                            break;
                        default:
                            $printer->setJustification(Printer::JUSTIFY_LEFT);
                            break;
                    }

                    // Configurar tamaño de fuente
                    $fontSizeMap = [
                        '11px' => [1, 1],
                        '12px' => [2, 1],
                        '16px' => [1, 2],
                        '24px' => [2, 2],
                        '32px' => [4, 4]
                    ];
                    $fontSizeFrontend = $item['fontSize'] ?? '8px';
                    $size = $fontSizeMap[$fontSizeFrontend] ?? [1, 1];
                    $printer->setTextSize($size[0], $size[1]);

                    // Procesar según tipo de elemento                
                    switch ($type) {
                        case 'text':
                            /* $text = $item['text'] ?? '';
                            if (is_array($text)) {
                                $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                            }
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            $printer->text($text . "\n");
                            // Restablecer estilos
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);*/
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            if (isset($item['leftText']) && isset($item['rightText'])) {
                                // Imprimir en dos columnas
                                $this->printTwoColumnLine($printer, $item['leftText'], $item['rightText'], $caracteres);
                            } else {
                                $text = $item['text'] ?? '';
                                if (is_array($text)) {
                                    $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                                }
                                $printer->text($text . "\n");
                            }
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);
                            break;

                        case 'field':
                            /*$field = $item['field'] ?? '';
                            $textBefore = $item['textBefore'] ?? '';
                            $textAfter = $item['textAfter'] ?? '';
                            $value = $this->getFieldValue($exampleJson, $field);
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            if (!empty($columns) && is_array($value)) {
                                $this->printTable($printer, $columns, $value);
                            } else {
                                if (is_array($value)) {
                                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                                }
                                $printer->text($textBefore . $value . $textAfter . "\n");
                            }
                            // Restablecer estilos
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);*/
                            $fontWeight = $item['fontWeight'] ?? 'normal';
                            $fontUnderline = $item['fontUnderline'] ?? 'none';
                            $printer->setEmphasis($fontWeight === 'bold');
                            $printer->setUnderline($fontUnderline === 'underline');
                            if (isset($item['leftField']) && isset($item['rightField'])) {
                                // Obtener valores de los campos
                                $leftValue = $this->getFieldValue($data, $item['leftField']);
                                $rightValue = $this->getFieldValue($data, $item['rightField']);
                                $this->printTwoColumnLine($printer, $leftValue, $rightValue, $caracteres);
                            } else {
                                $field = $item['field'] ?? '';
                                $textBefore = $item['textBefore'] ?? '';
                                $textAfter = $item['textAfter'] ?? '';
                                $value = $this->getFieldValue($data, $field);
                                if (!empty($columns) && is_array($value)) {
                                    $this->printTable($printer, $columns, $value, $caracteres);
                                } else {
                                    if (is_array($value)) {
                                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                                    }
                                    $printer->text($textBefore . $value . $textAfter . "\n");
                                }
                            }
                            $printer->setEmphasis(false);
                            $printer->setUnderline(false);
                            break;

                        case 'line':
                            $printer->text(str_repeat('-', $caracteres) . "\n");
                            break;

                        case 'doubleline':
                            $printer->text(str_repeat('=', $caracteres) . "\n");
                            break;


                        case 'feed':
                            $lines = $item['lines'] ?? 1;
                            $printer->feed($lines);
                            break;

                        case 'newline':
                            $printer->feed(1);
                            break;
                    }
                }

                // Finalizar impresión
                $printer->feed(3);
                // buscar en templateJson en la columna type cut, si existe habilitar el corte
                if (in_array('cut', array_column($templateJson, 'type'))) {
                    $printer->cut();
                }

                $printer->close();

                $results[] = [
                    'success' => 1,
                    'message' => 'Ticket impreso correctamente en ' . $printerName,
                    'template_id' => $templateId,
                    'printer_name' => $printerName,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            } catch (\Throwable $e) {
                $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                $results[] = [
                    'success' => 0,
                    'message' => 'Error al imprimir: ' . $e->getMessage(),
                    'template_id' => $templateId,
                    'printer_name' => $printerName,
                    'error_type' => 'general'
                ];
            }
        }

        $response->getBody()->write(json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Arranca un ticket de TEXTO: reinicia la impresora y FIJA la página de códigos 0 (CP437).
     *
     * Por qué no basta `initialize()`: la librería lo trata como "ya estoy en la página 0" (guarda
     * ese estado sin mandar nada), así que las letras que existen en CP437 (á é í ó ú ñ ü) se envían
     * SIN `ESC t` y la impresora las lee con su página de fábrica. Si esa no es CP437 (la barra de
     * Fogo: Latin-1) salía "Cl sica" y "Sangr¡a". Mandando `ESC t 0` la impresora queda en la misma
     * página que la librería cree que tiene, sea cual sea su configuración de fábrica.
     */
    private function iniciarTicket($printer): void
    {
        $printer->initialize();
        $printer->selectCharacterTable(0);
    }

    /**
     * Avisa a la bitácora (PrintJobLog) que este trabajo NO se imprimió.
     *
     * Sin esto, un error que no sea el típico "impresora apagada" (por
     * ejemplo un TypeError al armar el ticket) deja el renglón atorado en
     * estado "recibido": ni impreso ni marcado como fallido. Mientras dure
     * eso (hasta 2 min, ver PrintJobLog::MS_INTENTO_MUERTO), un reenvío de la
     * tablet por Wi-Fi intermitente con el MISMO jobUid se descarta como
     * "ya se había impreso" aunque nunca haya salido nada — cocina se queda
     * sin comanda y sin aviso. Marcando 'rechazado' aquí, el reenvío se
     * reconoce al toque como una segunda oportunidad y se imprime.
     */
    private function marcarTrabajoFallido($jobUid, string $mensaje): void
    {
        $uid = is_string($jobUid) ? strtolower(trim($jobUid)) : '';
        if (!preg_match('/^[0-9a-f-]{36}$/', $uid)) return;
        \App\Printing\PrintJobLog::actualizar($uid, [
            'status' => 'rechazado',
            'message' => \App\Printing\WindowsPrintStatus::recortar($mensaje, 500),
        ]);
    }

    /** 4 → "4", 0.5 → "0.5": sin ceros de más. */
    private function cantidadLimpia($q): string
    {
        $t = rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.');
        return $t === '' ? '0' : $t;
    }

    /**
     * Junta los modificadores repetidos de un producto: 4 filas "A. MINERAL" → una sola con qty 4.
     *
     * El front manda cada pieza como su propia fila (Botella + 4 aguas = 4 filas iguales), y el
     * ticket las repetía. Se agrupa por nombre + mitad + nota (una nota distinta NO se mezcla) y se
     * conserva el orden de la primera aparición. Las mitades ("1ERA MITAD - X") no se suman: cada una
     * es su propia línea.
     *
     * @return array<int, array{name:string, half:int, qty:float, notes:string}>
     */
    private function agruparModificadores(array $modifiers): array
    {
        $grupos = [];
        foreach ($modifiers as $modifier) {
            $name = trim((string) ($modifier['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $half = (int) ($modifier['half'] ?? 1);
            if ($half < 1 || $half > 3) {
                $half = 1;
            }
            $notes = trim((string) ($modifier['notes'] ?? ''));
            $qty = (float) ($modifier['qty'] ?? 1);
            if ($qty <= 0 || $half !== 1) {
                $qty = 1;
            }
            $key = $half === 1 ? $name . '|1|' . $notes : $name . '|' . $half . '|' . $notes . '|' . count($grupos);
            if (isset($grupos[$key])) {
                $grupos[$key]['qty'] += $qty;
            } else {
                $grupos[$key] = ['name' => $name, 'half' => $half, 'qty' => $qty, 'notes' => $notes];
            }
        }
        return array_values($grupos);
    }

    /** "4 x A. MINERAL" (siempre con cantidad, también 1) o "1ERA MITAD - PEPERONI". */
    private function etiquetaModificador(array $m): string
    {
        if ($m['half'] !== 1) {
            return $this->formatHalfLabel($m['half']) . ' - ' . $m['name'];
        }
        return $this->cantidadLimpia($m['qty']) . ' x ' . $m['name'];
    }

    /**
     * `mb_strlen`/`mb_substr` seguros: el PHP portátil de algunas PCs no trae
     * la extensión `mbstring` cargada (no está en `php.ini`, aunque el DLL sí
     * esté en `ext/`). Llamarlas directo tira "Call to undefined function" —
     * un `Error`, no una `Exception`, así que ni `printComanda` lo atrapa ni
     * el navegador recibe respuesta con CORS: sale como "Failed to fetch" y
     * parece un problema de red que no lo es. Mismo patrón que ya usa
     * `safeSubstr()` en este archivo; aquí hacía falta también para `strlen`.
     */
    private function mbLen(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private function mbSub(string $s, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }

    /**
     * Imprime un párrafo con partes en negrita y otras normales, cortando líneas SIN partir palabras.
     *
     * La impresora corta al llegar al borde aunque sea a media palabra ("J NARA / NJA"). Con letra grande
     * el renglón es corto y pasaba seguido. Aquí el corte se hace por palabras; una palabra más larga que
     * el renglón sí se parte, para no perder texto.
     *
     * Cada segmento puede ser ATÓMICO: si cabe en un renglón, se mantiene junto ("** 4 x A. MINERAL **"
     * no se corta entre "4" y "x"); si no cabe, se corta por palabras. Un segmento que empieza con coma
     * la pega a la palabra anterior (sin espacio antes).
     *
     * @param array<int, array{0:string, 1:bool, 2?:bool}> $segmentos [texto, negrita, atómico]
     * @param int $ancho caracteres por renglón AL TAMAÑO con que se imprime (48 / multiplicador de ancho)
     */
    private function imprimirParrafo($printer, array $segmentos, int $ancho): void
    {
        $union = "\x01"; // espacio que no se corta; se cambia por un espacio real al imprimir
        $palabras = [];
        foreach ($segmentos as $seg) {
            $texto = trim((string) $seg[0]);
            $negrita = (bool) $seg[1];
            $atomico = !empty($seg[2]);
            if ($texto !== '' && $texto[0] === ',' && !empty($palabras)) {
                $palabras[count($palabras) - 1][0] .= ',';
                $texto = ltrim(substr($texto, 1));
            }
            if ($texto === '') {
                continue;
            }
            if ($atomico && $this->mbLen($texto) <= $ancho) {
                $palabras[] = [preg_replace('/\s+/u', $union, $texto), $negrita];
                continue;
            }
            foreach (preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                while ($this->mbLen($w) > $ancho) {
                    $palabras[] = [$this->mbSub($w, 0, $ancho), $negrita];
                    $w = $this->mbSub($w, $ancho);
                }
                $palabras[] = [$w, $negrita];
            }
        }
        $lineas = [];
        $actual = [];
        $largo = 0;
        foreach ($palabras as [$w, $negrita]) {
            $l = $this->mbLen($w);
            if (!empty($actual) && $largo + 1 + $l > $ancho) {
                $lineas[] = $actual;
                $actual = [];
                $largo = 0;
            }
            $largo += (empty($actual) ? 0 : 1) + $l;
            $actual[] = [$w, $negrita];
        }
        if (!empty($actual)) {
            $lineas[] = $actual;
        }
        foreach ($lineas as $linea) {
            // Palabras seguidas con la misma negrita van juntas, para no encender/apagar la negrita a cada palabra.
            $corridas = [];
            foreach ($linea as [$w, $negrita]) {
                $n = count($corridas);
                if ($n > 0 && $corridas[$n - 1][1] === $negrita) {
                    $corridas[$n - 1][0] .= ' ' . $w;
                } else {
                    $corridas[] = [($n > 0 ? ' ' : '') . $w, $negrita];
                }
            }
            foreach ($corridas as [$texto, $negrita]) {
                $printer->setEmphasis($negrita);
                $printer->text(str_replace($union, ' ', $texto));
            }
            $printer->setEmphasis(false);
            $printer->text("\n");
        }
    }

    /* Cuerpo del ticket de cocina. Lo comparten la comanda y el ticket de
     * cancelación a propósito: cocina ya sabe leer este formato de un vistazo
     * — misma posición del área, la mesa y la orden — y un diseño distinto
     * para la cancelación obligaría a aprender otro. Lo único que cambia es
     * el encabezado, el motivo y que cada producto va marcado CANCELADO. */
    private function renderComandaBody($printer, array $data, bool $esCancelacion): void
    {
        /* Tipo 2 = antro (restaurants.tipo): cada producto sale con sus
         * modificadores seguidos en el mismo párrafo y sin "Tiempo". Lo demás
         * del ticket no cambia. Sin `tipo`, con tipo 1 o en una cancelación
         * sale el ticket de siempre. */
        $esAntro = !$esCancelacion && (int) ($data['tipo'] ?? 1) === 2;

        $this->iniciarTicket($printer);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->text($esCancelacion ? "** CANCELACION **\n" : "COMANDA TICKET\n");
        $printer->feed(1);
        $printer->setTextSize(1, 2); // un poco mas grande que el tamaño normal, sin duplicar el ancho
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text("Area: " . ($data['areaName'] ?? '') . "\n");
        $printer->text("Mesa: " . ($data['tableName'] ?? '') . "\n");
        /* Mesero de la cuenta: cocina sabe a quién llamar cuando el platillo
         * sale o hay una duda. Opcional — un front viejo que no lo manda (o una
         * cuenta sin mesero) imprime el ticket igual que antes, sin la línea. */
        $mesero = trim((string)($data['waiterName'] ?? ''));
        if ($mesero !== '') {
            $printer->text("Mesero: " . $mesero . "\n");
        }
        /* El número de orden ya no se imprime a propósito: a cocina lo
         * confundía con el número de mesa. El front lo sigue mandando (se
         * quitará ahí más adelante); aquí simplemente se ignora. */
        $printer->text("Fecha: " . date('d/m/Y H:i:s') . "\n");

        /* Quién y por qué. En una comanda normal no vienen estas llaves, así
         * que el ticket de cocina de siempre no cambia ni una línea. */
        if ($esCancelacion) {
            $cancelaPor = trim((string)($data['cancelledBy'] ?? ''));
            if ($cancelaPor !== '') {
                $printer->text("Cancelo: " . $cancelaPor . "\n");
            }
            $printer->text(str_repeat('-', 48) . "\n");
            $motivo = trim((string)($data['reason'] ?? ''));
            /* El motivo es lo primero que busca cocina cuando le llega esto:
             * sin él, el ticket solo genera una pregunta a gritos. */
            $printer->setEmphasis(true);
            $printer->text("Motivo: " . ($motivo !== '' ? $motivo : 'Sin motivo') . "\n");
            $printer->setEmphasis(false);
        }

        $printer->text(str_repeat('-', 48) . "\n");
        $printer->text($esCancelacion ? "NO PREPARAR / RETIRAR: \n" : "Pedidos: \n");

        $rawItems = $data['items'] ?? [];
        $items = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        $modifiersByCompositeId = [];
        $mainItems = [];
        foreach ($items as $item) {
            if (!empty($item['isModifier'])) {
                $compositeKey = $item['compositeProductId'] ?? '';
                if ($compositeKey !== '') {
                    $modifiersByCompositeId[$compositeKey][] = $item;
                }
                continue;
            }
            $mainItems[] = $item;
        }

        if (empty($mainItems)) {
            $printer->text("Sin items registrados\n");
        } else {
            usort($mainItems, function ($a, $b) {
                $courseA = $a['course'] ?? PHP_INT_MAX;
                $courseB = $b['course'] ?? PHP_INT_MAX;
                return $courseA <=> $courseB;
            });

            foreach ($mainItems as $item) {
                $printer->text(str_repeat('-', 48) . "\n");

                if (!$esAntro) {
                    $courseLabel = $this->formatCourseLabel($item['course'] ?? null);
                    if ($courseLabel !== '') {
                        $printer->text("Tiempo: " . $courseLabel . "\n");
                    }
                }

                $compositeKey = $item['compositeProductId'] ?? '';
                $modifiers = [];
                if (!empty($item['isCompositeProductMain']) && $compositeKey !== '') {
                    $modifiers = $modifiersByCompositeId[$compositeKey] ?? [];
                }

                $qty = trim((string) ($item['qty'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $itemLine = trim(($qty !== '' ? $qty . ($esAntro ? '-' : ' ') : '') . $name);
                if ($itemLine === '') {
                    $itemLine = 'Producto sin nombre';
                }
                /* Modificadores repetidos → una línea con cantidad ("4 x A. MINERAL"). Siempre se
                 * escribe la cantidad, también cuando es 1. */
                $modsAgrupados = $this->agruparModificadores($modifiers);

                if ($esAntro) {
                    /* "1-Nombre, ** 4 x MOD **, ** 1 x MOD **". Antro: letra MÁS GRANDE en los pedidos y
                     * el producto en NEGRITA solo cuando lleva modificadores; los modificadores, normales.
                     * La mitad solo se escribe cuando no es "TODO", y la nota del modificador, si trae,
                     * va entre paréntesis para que no se pierda. */
                    $segmentos = [[$itemLine, !empty($modsAgrupados), true]];
                    foreach ($modsAgrupados as $m) {
                        $etiqueta = $this->etiquetaModificador($m);
                        if ($m['notes'] !== '') {
                            $etiqueta .= ' (' . $m['notes'] . ')';
                        }
                        $segmentos[] = [', ** ' . $etiqueta . ' **', false, true];
                    }
                    $printer->setTextSize(2, 2);
                    $this->imprimirParrafo($printer, $segmentos, 24);
                    $printer->setTextSize(1, 2);
                } else {
                    $printer->text($itemLine . "\n");
                }

                $notes = $item['notes'] ?? null;
                if (!empty($notes)) {
                    $printer->text("Nota: " . $notes . "\n");
                }

                if (!$esAntro && !empty($modsAgrupados)) {
                    $printer->text("Modificadores:\n");
                    foreach ($modsAgrupados as $m) {
                        $printer->text('   ' . $this->etiquetaModificador($m) . "\n");
                        if ($m['notes'] !== '') {
                            $printer->text("      Nota: " . $m['notes'] . "\n");
                        }
                    }
                }

                $printer->text("\n");
            }
        }
    }

    /**
     * Imprime la plantilla fija COMANDA TICKET usando datos directos del front sin templateId.
     * POST /printers/print-comanda
     */
    public function printComanda(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);

                    $this->renderComandaBody($printer, $data, false);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket COMANDA impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'comanda_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                            // Intencionalmente silencioso para no interrumpir la respuesta
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    /**
     * Ticket de CANCELACIÓN de productos — se manda al área de impresión que
     * ya había recibido la comanda, para que cocina/barra sepa que eso ya no
     * se prepara (o que hay que retirarlo si ya salió).
     * POST /printers/print-cancelacion
     *
     * Mismo cuerpo que la comanda (renderComandaBody, que ya imprime
     * data.waiterName si viene), más:
     *   data.reason      → motivo de la cancelación
     *   data.cancelledBy → quién la hizo (opcional)
     */
    public function printCancelacion(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = ['success' => 0, 'message' => 'Nombre de impresora es requerido', 'printer_name' => $printerName];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = ['success' => 0, 'message' => 'El campo data debe ser un objeto', 'printer_name' => $printerName];
                    continue;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);

                    $this->renderComandaBody($printer, $data, true);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket de CANCELACION impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'cancelacion_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                            // Intencionalmente silencioso para no interrumpir la respuesta
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Abre el cajón de dinero (gaveta) conectado por cable RJ11 a la
     * impresora indicada, enviando el pulso ESC/POS estándar de apertura
     * (comando "kick-out drawer"). Se usa, por ejemplo, al querer cobrar.
     * POST /printers/open-drawer
     * Body: { printerName, pin?, onMs?, offMs? } o un array de esos objetos
     * para abrir varias gavetas en un mismo request.
     *   - pin: 0 o 1, según a qué pin del conector RJ11 esté cableada la
     *     gaveta (0 = pin 2, el más común; 1 = pin 5). Default 0.
     *   - onMs / offMs: duración del pulso en milisegundos. Default 120/240,
     *     los valores estándar que soportan la mayoría de gavetas.
     */
    public function openDrawer(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $pin = (int) ($job['pin'] ?? 0);
                $onMs = (int) ($job['onMs'] ?? 120);
                $offMs = (int) ($job['offMs'] ?? 240);

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new WindowsPrintConnector($printerName);
                    $printer = new Printer($connector);
                    $printer->pulse($pin, $onMs, $offMs);
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Cajón de dinero abierto correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al abrir el cajón: ' . $e->getMessage(),
                        'printer_name' => $printerName
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                            // Intencionalmente silencioso para no interrumpir la respuesta
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Imprime el resumen de pagos de propinas por mesero.
     * POST /printers/print-propinas
     */
    public function printPropinas(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $printName = $job['printName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $propinas = [];
                if (isset($data['propinas']) && is_array($data['propinas'])) {
                    foreach ($data['propinas'] as $entry) {
                        if (is_array($entry)) {
                            $propinas[] = $entry;
                        }
                    }
                }

                // Agrupa las propinas por mesero (usando waiterId si viene, o el nombre
                // como respaldo) para imprimir un ticket físico independiente por cada uno,
                // conservando el orden en el que aparecen en el payload.
                $groups = [];
                $groupOrder = [];
                foreach ($propinas as $entry) {
                    $waiterKey = $entry['waiterId'] ?? $entry['waiterFullName'] ?? '__sin_mesero__';
                    $waiterKey = (string) $waiterKey;
                    if (!isset($groups[$waiterKey])) {
                        $groups[$waiterKey] = [];
                        $groupOrder[] = $waiterKey;
                    }
                    $groups[$waiterKey][] = $entry;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $this->iniciarTicket($printer);

                    if (empty($groups)) {
                        // Sin registros de propinas: se imprime un único ticket informativo.
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        $printer->setTextSize(2, 2);
                        $printer->setEmphasis(true);
                        $printer->text("PAGO DE PROPINAS\n");
                        $printer->setEmphasis(false);
                        $printer->feed(1);
                        $printer->setTextSize(1, 1);
                        $printer->setJustification(Printer::JUSTIFY_LEFT);

                        if ($printName) {
                            $printer->text("Impresión: " . $printName . "\n");
                        }
                        $printer->text("Fecha: " . date('d/m/Y H:i:s') . "\n");
                        $printer->text(str_repeat('-', 48) . "\n");
                        $printer->text("Sin registros de propinas.\n");

                        $printer->feed(3);
                        $printer->cut();
                    } else {
                        // Un ticket completo (encabezado + detalle + total) por cada mesero,
                        // cortando el papel al final de cada uno antes de pasar al siguiente.
                        foreach ($groupOrder as $waiterKey) {
                            $entries = $groups[$waiterKey];
                            $waiterName = $entries[0]['waiterFullName'] ?? 'Sin nombre';
                            $waiterTotal = 0;
                            // Venta de las cuentas de ESTE mesero (cada entrada trae `sale`
                            // desde Admin/Caja). Versiones viejas no lo mandan → queda en 0
                            // y la línea no se imprime.
                            $waiterSale = 0;
                            foreach ($entries as $entry) {
                                $waiterTotal += (float) ($entry['amount'] ?? 0);
                                $waiterSale += (float) ($entry['sale'] ?? 0);
                            }

                            $printer->setJustification(Printer::JUSTIFY_CENTER);
                            $printer->setTextSize(2, 2);
                            $printer->setEmphasis(true);
                            $printer->text("PAGO DE PROPINAS\n");
                            $printer->setEmphasis(false);
                            $printer->feed(1);
                            $printer->setTextSize(1, 1);
                            $printer->setJustification(Printer::JUSTIFY_LEFT);

                            if ($printName) {
                                $printer->text("Impresión: " . $printName . "\n");
                            }
                            $printer->text("Fecha: " . date('d/m/Y H:i:s') . "\n");
                            $printer->text("Mesero: " . $waiterName . "\n");
                            $printer->text(str_repeat('-', 48) . "\n");
                            $printer->text("Detalle de pagos:\n");

                            foreach ($entries as $entry) {
                                $printer->text(str_repeat('-', 48) . "\n");
                                $printer->text("Orden: " . ($entry['orderId'] ?? '') . "   Mesa: " . ($entry['tableName'] ?? '') . "\n");
                                $printer->text(
                                    "Propina: " . $this->formatMoney($entry['amount'] ?? 0) .
                                        "  Cobrado: " . $this->formatMoney($entry['collected'] ?? 0) .
                                        "  Pagado: " . $this->formatMoney($entry['paid'] ?? 0) . "\n"
                                );
                            }
                            $printer->text(str_repeat('-', 48) . "\n");

                            if ($waiterSale > 0) {
                                $printer->setEmphasis(true);
                                $printer->text("Total de venta: " . $this->formatMoney($waiterSale) . "\n");
                                $printer->setEmphasis(false);
                            }

                            $printer->feed(1);
                            $printer->setJustification(Printer::JUSTIFY_CENTER);
                            $printer->setTextSize(2, 2);
                            $printer->setEmphasis(true);
                            $printer->text("TOTAL PROPINA - " . strtoupper($waiterName) . "\n");
                            $printer->text($this->formatMoney($waiterTotal) . "\n");
                            $printer->setEmphasis(false);
                            $printer->setTextSize(1, 1);
                            $printer->setJustification(Printer::JUSTIFY_LEFT);

                            $printer->feed(3);
                            $printer->cut();
                        }
                    }

                    $printer->close();
                    $printerClosed = true;

                    $ticketsPrinted = empty($groups) ? 1 : count($groups);
                    $results[] = [
                        'success' => 1,
                        'message' => 'Se imprimieron ' . $ticketsPrinted . ' ticket(s) de propinas en ' . $printerName . ' (uno por mesero)',
                        'printer_name' => $printerName,
                        'template' => 'propinas_ticket',
                        'tickets_printed' => $ticketsPrinted,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Comprobante de PAGO DE COMISIONES a meseros — el equivalente de `print-propinas` para comisiones.
     * POST /printers/print-comisiones
     *
     * Antes las propinas pagadas se imprimían y las comisiones NO: quien entregaba el dinero no tenía comprobante.
     *
     * Body: { printerName, data: { comisiones: [ { waiterId?, waiterName, amount, metodo?, totalEarned?, totalPaid?,
     *          pending? } ], total?, turno?, pagadoPor? } }  — o un arreglo de esos jobs.
     *   amount      → lo que se acaba de pagar
     *   totalPaid   → acumulado pagado al mesero, YA con este pago
     * Un ticket por mesero, cortando el papel entre uno y otro (mismo criterio que print-propinas).
     */
    public function printComisiones(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = ['success' => 0, 'message' => 'Nombre de impresora es requerido', 'printer_name' => $printerName];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = ['success' => 0, 'message' => 'El campo data debe ser un objeto', 'printer_name' => $printerName];
                    continue;
                }

                $entradas = [];
                foreach (($data['comisiones'] ?? []) as $entry) {
                    if (is_array($entry) && (float) ($entry['amount'] ?? 0) > 0) {
                        $entradas[] = $entry;
                    }
                }

                // Un ticket por mesero, en el orden en que vienen.
                $grupos = [];
                $orden = [];
                foreach ($entradas as $entry) {
                    $clave = (string) ($entry['waiterId'] ?? $entry['waiterName'] ?? '__sin_mesero__');
                    if (!isset($grupos[$clave])) {
                        $grupos[$clave] = [];
                        $orden[] = $clave;
                    }
                    $grupos[$clave][] = $entry;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $this->renderComisionesBody($printer, $data, $grupos, $orden);
                    $printer->close();
                    $printerClosed = true;

                    $n = empty($grupos) ? 1 : count($grupos);
                    $results[] = [
                        'success' => 1,
                        'message' => 'Se imprimieron ' . $n . ' ticket(s) de comisiones en ' . $printerName . ' (uno por mesero)',
                        'printer_name' => $printerName,
                        'template' => 'comisiones_ticket',
                        'tickets_printed' => $n,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /** Cuerpo del comprobante de comisiones (separado de la ruta para poder probarlo sin impresora). */
    private function renderComisionesBody($printer, array $data, array $grupos, array $orden): void
    {
        $W = 42;
        $this->iniciarTicket($printer);

        $encabezado = function () use ($printer, $data) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setTextSize(2, 2);
            $printer->setEmphasis(true);
            $printer->text("PAGO DE COMISIONES\n");
            $printer->setEmphasis(false);
            $printer->feed(1);
            $printer->setTextSize(1, 1);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text("Fecha: " . date('d/m/Y H:i:s') . "\n");
            if (!empty($data['turno'])) {
                $printer->text("Turno: #" . $data['turno'] . "\n");
            }
        };

        if (empty($grupos)) {
            $encabezado();
            $printer->text(str_repeat('-', $W) . "\n");
            $printer->text("Sin pagos de comisiones.\n");
            $printer->feed(3);
            $printer->cut();
            return;
        }

        foreach ($orden as $clave) {
            $entries = $grupos[$clave];
            $nombre = trim((string) ($entries[0]['waiterName'] ?? '')) ?: 'Sin nombre';
            $total = 0.0;
            foreach ($entries as $e) {
                $total += (float) ($e['amount'] ?? 0);
            }

            $encabezado();
            $printer->text("Mesero: " . $nombre . "\n");
            if (!empty($data['pagadoPor'])) {
                $printer->text("Pagó: " . $data['pagadoPor'] . "\n");
            }
            $printer->text(str_repeat('-', $W) . "\n");
            foreach ($entries as $e) {
                $metodo = trim((string) ($e['metodo'] ?? ''));
                $this->printTwoColumnLine($printer, 'Pago' . ($metodo !== '' ? ' (' . $metodo . ')' : ''), $this->formatMoney($e['amount'] ?? 0), $W);
            }
            // Cómo va el mesero: lo ganado, lo pagado hasta ahora (con este pago) y lo que queda.
            $ultimo = end($entries);
            if (isset($ultimo['totalEarned'])) {
                $printer->text(str_repeat('-', $W) . "\n");
                $this->printTwoColumnLine($printer, 'Comision ganada', $this->formatMoney($ultimo['totalEarned']), $W);
                if (isset($ultimo['totalPaid'])) {
                    $this->printTwoColumnLine($printer, 'Pagada (acumulado)', $this->formatMoney($ultimo['totalPaid']), $W);
                }
                if (isset($ultimo['pending'])) {
                    $this->printTwoColumnLine($printer, 'Pendiente', $this->formatMoney($ultimo['pending']), $W);
                }
            }
            $printer->text(str_repeat('-', $W) . "\n");
            $printer->feed(1);

            // Total grande. Con letra doble caben 24 caracteres: se corta por palabras, no a media palabra.
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setTextSize(2, 2);
            $this->imprimirParrafo($printer, [['TOTAL COMISION - ' . strtoupper($nombre), true]], 24);
            $printer->setEmphasis(true);
            $printer->text($this->formatMoney($total) . "\n");
            $printer->setEmphasis(false);
            $printer->setTextSize(1, 1);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->feed(3);
            $printer->cut();
        }
    }

    /**
     * Imprime un reporte de movimiento de caja con el monto destacado.
     * POST /printers/print-movtos
     */
    public function printMovtos(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $restaurantId = $job['restaurantId'] ?? $job['restaurant_id'] ?? '';
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                /* El ticket debe decir lo mismo que ya ve el cajero en pantalla
                 * (Retiro/Deposito), no el código crudo de la BD (IN/OUT/DROP/
                 * PAYOUT/ADJUST) — antes se imprimía "Tipo: OUT" tal cual.
                 * `typeLabel` lo manda el front ya traducido (MovementsModal.tsx,
                 * pos_cash_front); si no llega (front viejo, u otro caller), se
                 * traduce aquí mismo como respaldo — nunca debe quedar el código
                 * crudo en el papel. */
                $movementTypeRaw = strtoupper((string) ($data['type'] ?? ''));
                $movementTypeLabels = [
                    'IN' => 'Deposito',
                    'OUT' => 'Retiro',
                    'DROP' => 'Retiro de boveda',
                    'PAYOUT' => 'Pago',
                    'ADJUST' => 'Ajuste',
                ];
                $movementType = trim((string) ($data['typeLabel'] ?? ''));
                if ($movementType === '') {
                    $movementType = $movementTypeLabels[$movementTypeRaw] ?? $movementTypeRaw;
                }
                $amount = (float) ($data['amount'] ?? 0);
                $reason = (string) ($data['reason'] ?? '');
                $shiftId = $data['shiftId'] ?? '';
                $stationId = $data['stationId'] ?? '';
                $printerStationName = (string) ($data['printerStationName'] ?? '');
                /* Quién autorizó la salida/entrada de dinero y quién la recibió
                 * físicamente. Son dos personas distintas y dos firmas distintas
                 * — antes el vale solo dejaba un espacio libre sin etiquetar y
                 * quedaba a criterio de quien firmaba, sin quedar claro cuál
                 * firma es cuál. Opcional: si el front no manda el nombre, se
                 * imprime la línea igual para firmar a mano. */
                $authorizedBy = trim((string) ($data['authorizedBy'] ?? ''));
                $receivedBy = trim((string) ($data['receivedBy'] ?? ''));
                $createdAt = $data['createdAt'] ?? '';
                $createdAtFormatted = '';
                if (!empty($createdAt)) {
                    try {
                        $dt = new DateTime($createdAt);
                        $createdAtFormatted = $dt->format('d/m/Y H:i:s');
                    } catch (\Throwable $e) {
                        $createdAtFormatted = $createdAt;
                    }
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);

                    $this->iniciarTicket($printer);
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->setEmphasis(true);
                    $printer->text("MOVIMIENTO DE CAJA\n");
                    $printer->setEmphasis(false);
                    $printer->feed(1);

                    $printer->setTextSize(1, 1);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);
                    /* Sin acentos a propósito: escpos-php traduce UTF-8 al code
                     * page que reporte la impresora vía su CapabilityProfile,
                     * pero en las impresoras genéricas que usan los restaurantes
                     * esa tabla no coincide con la real del firmware — la "ó" salía
                     * como "¢" en el papel (ver foto 2026-09-20, Caja La Llorona).
                     * Mismo criterio que ya usa "Recibio" un poco más abajo. */
                    $printer->text("Tipo: " . $movementType . "\n");
                    $printer->text("Estacion: " . $printerStationName . "\n");
                    $printer->text("Shift ID: " . $shiftId . "   Station ID: " . $stationId . "\n");
                    $printer->text("Razon: " . $reason . "\n");
                    if ($createdAtFormatted !== '') {
                        $printer->text("Registrado: " . $createdAtFormatted . "\n");
                    }

                    $printer->feed(1);
                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->setTextSize(2, 2);
                    $printer->setEmphasis(true);
                    $printer->text("MONTO\n");
                    $printer->text($this->formatMoney($amount) . "\n");
                    $printer->setEmphasis(false);
                    $printer->setTextSize(1, 1);
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    /* ===== Firmas: quién autorizó y quién recibió =====
                     * Dos espacios separados y etiquetados, cada uno con su
                     * propia línea para firmar. Si viene el nombre se imprime
                     * arriba de la línea; si no, queda en blanco para llenarse
                     * a mano junto con la firma. */
                    $printer->feed(1);
                    $printer->text(str_repeat('-', 48) . "\n");

                    $printer->text("Autorizo: " . $authorizedBy . "\n");
                    $printer->feed(2);
                    $printer->text(str_repeat('_', 30) . "\n");
                    $printer->text("Firma de quien autoriza\n");

                    $printer->feed(2);
                    $printer->text("Recibio: " . $receivedBy . "\n");
                    $printer->feed(2);
                    $printer->text(str_repeat('_', 30) . "\n");
                    $printer->text("Firma de quien recibe\n");

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket de movimiento impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'movimiento_caja',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Imprime el CORTE DE CAJA X / CIERRE DE TURNO vía ESC/POS crudo (misma
     * fuente nítida de la impresora que usan consumo/propinas/comanda) en vez
     * del diálogo de impresión del navegador — ese camino rasterizaba el HTML
     * y salía con letra borrosa, además de depender de @page/driver para el
     * largo del papel (bug reportado 2026-09-02/03).
     * POST /printers/print-corte-x
     * Body: array de objetos { printerName, data } — `data` es casi 1:1 el
     * mismo shape que ya devuelve /shifts/:id/xcut en el front (ver
     * XCutReport en pos_admin_front/pos_cash_front, lib/xcutHtmlBuilders.ts).
     */
    public function printCorteX(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $this->iniciarTicket($printer);

                    $this->printCorteXBody($printer, $data);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Corte de caja X impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'corte_x',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Cuerpo del corte X — mismas secciones/orden que buildPrintHtml() en
     * xcutHtmlBuilders.ts (admin y cash), solo que en ESC/POS en vez de HTML.
     */
    private function printCorteXBody($printer, array $data, int $W = 42): void
    {
        $money = function ($v) {
            return $this->formatMoney($v ?? 0);
        };
        $div = function () use ($printer, $W) {
            $printer->text(str_repeat('-', $W) . "\n");
        };
        $sectionTitle = function (string $title) use ($printer) {
            $printer->setEmphasis(true);
            $printer->text($title . "\n");
            $printer->setEmphasis(false);
        };

        $company = $data['company'] ?? [];
        $shift = $data['shift'] ?? [];
        $summary = $data['summary'] ?? [];
        $totals = $data['totals'] ?? [];
        $declarations = $data['declarations'] ?? [];
        $salesByMethod = $data['salesByMethod'] ?? [];
        $tipsByMethod = $data['tipsByMethod'] ?? [];
        $byCategory = $data['byCategory'] ?? [];
        $byService = $data['byService'] ?? [];
        $courtesyByCategory = $data['courtesyByCategory'] ?? [];
        $discountByCategory = $data['discountByCategory'] ?? [];

        /* dd/mm/aaaa hh:mm en la hora de ESTA PC (la del restaurante). El backend manda ISO-8601 en UTC; antes
         * llegaba `String(Date)` ("Sat Sep 26 2026 23:38:01 GMT+0000 (Coordinated Universal Time)") y se imprimía
         * tal cual, en inglés y en UTC. */
        $fmtDT = function ($iso) {
            if (!$iso) return '';
            try {
                $dt = new DateTime($iso);
                $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                return $dt->format('d/m/Y H:i');
            } catch (\Throwable $e) {
                return (string) $iso;
            }
        };
        $cajas = is_array($data['cajas'] ?? null) ? $data['cajas'] : [];

        // ── Cabecera ──
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text(($company['name'] ?? '') . "\n");
        $printer->setEmphasis(false);
        if (!empty($company['rfc'])) $printer->text($company['rfc'] . "\n");
        if (!empty($company['address'])) $printer->text($company['address'] . "\n");
        $printer->setTextSize(1, 2);
        $printer->setEmphasis(true);
        $printer->text("REPORTE DE TURNO\n");
        $printer->setEmphasis(false);
        $printer->setTextSize(1, 1);
        $printer->text("APERTURA: " . $fmtDT($shift['openedAt'] ?? null) . "\n");
        $printer->text("CIERRE:   " . $fmtDT($shift['closedAt'] ?? null) . "\n");
        $turnoLine = "TURNO: " . ($shift['id'] ?? '');
        if (!empty($shift['stationName'])) $turnoLine .= " · ESTACIÓN: " . $shift['stationName'];
        $printer->text($turnoLine . "\n");
        if (!empty($summary['cashierNames'])) {
            $printer->text("CAJERO: " . implode(', ', $summary['cashierNames']) . "\n");
        }
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $div();

        // ── Cajas del turno: una por SESIÓN que de verdad se abrió, con quién la abrió y cómo cerró ──
        if (!empty($cajas)) {
            $sectionTitle('CAJAS DEL TURNO');
            $printer->text(count($cajas) . " sesion(es) de caja\n");
            $div();
            foreach ($cajas as $i => $c) {
                $st = $c['station'] ?? [];
                $nombre = (string) ($st['name'] ?? $st['code'] ?? 'Caja');
                $printer->setEmphasis(true);
                $printer->text(($i + 1) . '. ' . $nombre . (strtoupper((string) ($st['mode'] ?? '')) === 'MASTER' ? ' (principal)' : '') . "\n");
                $printer->setEmphasis(false);
                $this->printTwoColumnLine($printer, '  Responsable', (string) ($c['responsible'] ?? '-'), $W);
                $this->printTwoColumnLine($printer, '  Apertura', $fmtDT($c['openedAt'] ?? null), $W);
                if (!empty($c['closedAt'])) {
                    $this->printTwoColumnLine($printer, '  Cierre', $fmtDT($c['closedAt']), $W);
                    if (!empty($c['closedBy'])) $this->printTwoColumnLine($printer, '  Cerrada por', (string) $c['closedBy'], $W);
                } else {
                    $printer->text("  (sin cerrar)\n");
                }
                $this->printTwoColumnLine($printer, '  Fondo inicial', $money($c['openingCash'] ?? 0), $W);
                foreach (($c['methods'] ?? []) as $m) {
                    $printer->text('  ' . strtoupper((string) ($m['name'] ?? '')) . "\n");
                    $this->printTwoColumnLine($printer, '    Ventas', $money($m['sales'] ?? 0), $W);
                    if ((float) ($m['tips'] ?? 0) != 0.0) $this->printTwoColumnLine($printer, '    Propinas', $money($m['tips']), $W);
                    if (isset($m['declared']) && $m['declared'] !== null) {
                        $this->printTwoColumnLine($printer, '    Esperado', $money($m['expected'] ?? 0), $W);
                        $this->printTwoColumnLine($printer, '    Declarado', $money($m['declared']), $W);
                        $dif = (float) ($m['difference'] ?? 0);
                        $this->printTwoColumnLine($printer, '    Diferencia', ($dif > 0 ? '+' : '') . $money($dif), $W);
                    }
                }
                $sum = $c['summary'] ?? [];
                $ops = $c['operational'] ?? [];
                $this->printTwoColumnLine($printer, '  Cuentas', (string) ($sum['ordersCount'] ?? 0), $W);
                $this->printTwoColumnLine($printer, '  Personas', (string) ($ops['persons'] ?? 0), $W);
                $this->printTwoColumnLine($printer, '  Venta bruta', $money($sum['salesGross'] ?? 0), $W);
                $operadores = $c['operators'] ?? [];
                if (!empty($operadores)) {
                    $printer->text("  Cobraron:\n");
                    foreach ($operadores as $o) {
                        $this->printTwoColumnLine(
                            $printer,
                            '    ' . ($o['name'] ?? '') . (!empty($o['isResponsible']) ? ' (resp.)' : ''),
                            $money($o['salesTotal'] ?? 0),
                            $W
                        );
                    }
                }
                $div();
            }
        }

        // ── CAJA ──
        $sectionTitle(!empty($cajas) ? 'TODAS LAS CAJAS' : 'CAJA');
        $this->printTwoColumnLine($printer, '+EFECTIVO INIC', $money($data['openingCash'] ?? 0), $W);
        foreach ($salesByMethod as $m) {
            $this->printTwoColumnLine($printer, '+VENTA ' . strtoupper($m['name'] ?? ''), $money($m['salesAmount'] ?? 0), $W);
        }
        foreach ($tipsByMethod as $m) {
            $this->printTwoColumnLine($printer, '+PROP. ' . strtoupper($m['name'] ?? ''), $money($m['tipAmount'] ?? 0), $W);
        }
        $this->printTwoColumnLine($printer, '+DEPÓSITOS EFE', $money($data['cashDeposits'] ?? 0), $W);
        $this->printTwoColumnLine($printer, '-PROPINAS PAGA', $money($data['tipsPaid'] ?? 0), $W);
        $this->printTwoColumnLine($printer, '-COMISIONES PA', $money($data['commissionsPaid'] ?? 0), $W);
        $this->printTwoColumnLine($printer, '-RETIROS EFECT', $money($data['cashWithdrawals'] ?? 0), $W);
        $div();
        $saldoFinal = $data['saldoFinal'] ?? $data['finalBalance'] ?? 0;
        $cashFinal = $data['cashFinal'] ?? $data['finalBalance'] ?? 0;
        $printer->setEmphasis(true);
        $this->printTwoColumnLine($printer, '=SALDO FINAL', $money($saldoFinal), $W);
        $this->printTwoColumnLine($printer, 'EFECTIVO FINA', $money($cashFinal), $W);
        $printer->setEmphasis(false);

        // ── Formas de pago ──
        $sectionTitle('FORMA DE PAGO VENTAS');
        $totalSalesForms = 0;
        foreach ($salesByMethod as $m) {
            $this->printTwoColumnLine($printer, $m['name'] ?? '', $money($m['salesAmount'] ?? 0), $W);
            $totalSalesForms += (float) ($m['salesAmount'] ?? 0);
        }
        $printer->setEmphasis(true);
        $this->printTwoColumnLine($printer, 'TOTAL FORMAS', $money($totalSalesForms), $W);
        $printer->setEmphasis(false);
        $div();

        $sectionTitle('FORMA DE PAGO PROPINA');
        $totalTipsForms = 0;
        foreach ($tipsByMethod as $m) {
            $this->printTwoColumnLine($printer, $m['name'] ?? '', $money($m['tipAmount'] ?? 0), $W);
            $totalTipsForms += (float) ($m['tipAmount'] ?? 0);
        }
        $printer->setEmphasis(true);
        $this->printTwoColumnLine($printer, 'TOTAL FORMAS PROPINA', $money($totalTipsForms), $W);
        $printer->setEmphasis(false);
        $div();

        // ── Venta por tipo/servicio ──
        $sectionTitle('VENTA (NO INCLUYE IMPUESTOS)');
        $sectionTitle('POR TIPO DE PRODUCTO');
        foreach ($byCategory as $c) {
            $pct = round(($c['pct'] ?? 0) * 100);
            $this->printTwoColumnLine(
                $printer,
                ($c['name'] ?? ''),
                $money($c['salesAmount'] ?? 0) . " ({$pct}%) " . ($c['salesCount'] ?? 0),
                $W
            );
        }
        $sectionTitle('POR TIPO DE SERVICIO');
        foreach ($byService as $s) {
            $pct = round(($s['pct'] ?? 0) * 100);
            $this->printTwoColumnLine($printer, ($s['name'] ?? ''), $money($s['salesAmount'] ?? 0) . " ({$pct}%)", $W);
        }
        $div();

        // ── Totales fiscales ──
        if (isset($totals['subtotal'])) $this->printTwoColumnLine($printer, 'SUBTOTAL', $money($totals['subtotal']), $W);
        if (isset($totals['discounts'])) $this->printTwoColumnLine($printer, '-DESCUENTOS', $money($totals['discounts']), $W);
        if (isset($totals['net'])) $this->printTwoColumnLine($printer, 'VENTA NETA', $money($totals['net']), $W);
        $div();
        foreach (($totals['taxes'] ?? []) as $t) {
            $this->printTwoColumnLine($printer, 'VENTA GRAVADA AL ' . ($t['rateLabel'] ?? ''), $money($t['base'] ?? 0), $W);
            $this->printTwoColumnLine($printer, 'IVA ' . ($t['rateLabel'] ?? ''), $money($t['tax'] ?? 0), $W);
        }
        if (isset($totals['taxesTotal'])) $this->printTwoColumnLine($printer, 'TOTAL DE IMPUESTOS', $money($totals['taxesTotal']), $W);
        $div();
        if (isset($totals['gross'])) {
            $printer->setEmphasis(true);
            $this->printTwoColumnLine($printer, 'TOTAL CON IMP.', $money($totals['gross']), $W);
            $printer->setEmphasis(false);
        }
        $div();

        // ── Resumen de cuentas ──
        $sectionTitle('RESUMEN CUENTAS');
        $this->printTwoColumnLine($printer, 'CUENTAS NORMALES', (string) ($summary['closedCount'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'CUENTAS CANCELADAS', (string) ($summary['voidCount'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'CUENTAS CON DESCUENTO', (string) ($summary['discountCount'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'CUENTAS CON CORTESIA', (string) ($summary['courtesyCount'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'CUENTA PROMEDIO', $money($summary['avgTicket'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'CONSUMO PROMEDIO', $money($summary['avgConsumption'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'COMENSALES', (string) ($summary['guests'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'PROPINAS', $money($summary['tipsTotal'] ?? 0), $W);
        if (!empty($summary['folioFrom'])) $this->printTwoColumnLine($printer, 'FOLIO INICIAL', (string) $summary['folioFrom'], $W);
        if (!empty($summary['folioTo'])) $this->printTwoColumnLine($printer, 'FOLIO FINAL', (string) $summary['folioTo'], $W);
        $div();

        // ── Cortesías / descuentos ──
        $sectionTitle('CORTESIAS POR CATEGORIA');
        foreach ($courtesyByCategory as $c) {
            $this->printTwoColumnLine($printer, $c['name'] ?? '', $money($c['amount'] ?? 0), $W);
        }
        $printer->setEmphasis(true);
        $this->printTwoColumnLine($printer, 'TOTAL CORTESIAS', $money($summary['totalCourtesy'] ?? 0), $W);
        $printer->setEmphasis(false);
        $div();

        $sectionTitle('DESCUENTOS POR CATEGORIA');
        foreach ($discountByCategory as $c) {
            $this->printTwoColumnLine($printer, $c['name'] ?? '', $money($c['amount'] ?? 0), $W);
        }
        $printer->setEmphasis(true);
        $this->printTwoColumnLine($printer, 'TOTAL DESCUENTOS', $money($summary['totalDiscounts'] ?? 0), $W);
        $printer->setEmphasis(false);
        $div();

        // ── Declaración de cajero ──
        $sectionTitle('DECLARACION DE CAJERO');
        foreach (($declarations['byMethod'] ?? []) as $d) {
            $this->printTwoColumnLine(
                $printer,
                $d['name'] ?? '',
                $money($d['declared'] ?? 0) . " (Esp: " . $money($d['expected'] ?? 0) . " / Dif: " . $money($d['difference'] ?? 0) . ")",
                $W
            );
        }
        $printer->setEmphasis(true);
        $this->printTwoColumnLine($printer, 'TOTAL DECLARADO', $money($declarations['totalDeclared'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'SOBRANTE/FALTANTE', $money($declarations['totalDifference'] ?? 0), $W);
        $printer->setEmphasis(false);
    }

    /**
     * Reporte de caja por SESIÓN (una caja, no todo el turno). Dos tickets con el mismo cuerpo:
     *   POST /printers/print-reporte-caja   caja ABIERTA — corte en vivo ("Reporte de mi caja")
     *   POST /printers/print-cierre-caja    caja CERRADA — cierre de esa caja ("Reporte de cierre de caja")
     * El corte de TODO el turno sigue siendo POST /printers/print-corte-x (sin cambios).
     *
     * Body: { printerName, data } o una lista de esos. `data` es la respuesta de GET /shifts/:id/session-report
     * del POS (station, session, openingCash, expectedCash, closing, methods[], movements{...items[]},
     * operators[], personalDeclarations[], summary) más:
     *   restaurante            nombre del restaurante
     *   textos.apertura|cierre|impreso   fechas YA formateadas por el POS en la hora del restaurante
     *   movements.items[].hora           idem para cada movimiento
     * Las fechas vienen formateadas del front a propósito: aquí PHP puede tener otra zona horaria.
     */
    public function printReporteCaja(Request $request, Response $response, $args = [])
    {
        return $this->printReporteCajaJobs($request, $response, false);
    }

    public function printCierreCaja(Request $request, Response $response, $args = [])
    {
        return $this->printReporteCajaJobs($request, $response, true);
    }

    private function printReporteCajaJobs(Request $request, Response $response, bool $cerrada)
    {
        $etiqueta = $cerrada ? 'Cierre de caja' : 'Reporte de caja';
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (isset($jobs['printerName']) && isset($jobs['data'])) {
                $jobs = [$jobs];
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;
                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $this->iniciarTicket($printer);

                    $this->printReporteCajaBody($printer, $data, $cerrada);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => $etiqueta . ' impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => $cerrada ? 'cierre_caja' : 'reporte_caja',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /** Sin acentos: la térmica no siempre trae la página de códigos (mismo criterio que print-movtos). */
    private function ticketSinAcentos($s): string
    {
        return strtr((string) $s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }

    private function etiquetaMovimientoCaja($type): string
    {
        $labels = [
            'IN' => 'Entrada',
            'OUT' => 'Salida',
            'DROP' => 'Retiro a boveda',
            'ADJUST' => 'Ajuste',
            'PAYOUT' => 'Pago',
        ];
        $raw = strtoupper((string) $type);
        return $labels[$raw] ?? $raw;
    }

    private function printReporteCajaBody($printer, array $data, bool $cerrada, int $W = 42): void
    {
        $t = function ($s) {
            return $this->ticketSinAcentos($s);
        };
        $money = function ($v) {
            return $this->formatMoney($v ?? 0);
        };
        $div = function () use ($printer, $W) {
            $printer->text(str_repeat('-', $W) . "\n");
        };
        $sectionTitle = function (string $title) use ($printer) {
            $printer->setEmphasis(true);
            $printer->text($title . "\n");
            $printer->setEmphasis(false);
        };
        $line = function ($left, $right) use ($printer, $W, $t) {
            $this->printTwoColumnLine($printer, $t($left), $t($right), $W);
        };
        $signed = function ($v) use ($money) {
            $n = (float) ($v ?? 0);
            return ($n > 0 ? '+' : '') . $money($n);
        };

        $station = $data['station'] ?? [];
        $session = $data['session'] ?? [];
        $textos = $data['textos'] ?? [];
        $closing = $data['closing'] ?? null;
        $methods = $data['methods'] ?? [];
        $movements = $data['movements'] ?? [];
        $items = $movements['items'] ?? [];
        $operators = $data['operators'] ?? [];
        $personal = $data['personalDeclarations'] ?? [];
        $summary = $data['summary'] ?? [];
        /* Información operativa del cierre (solo la manda el backend nuevo). Sin ella el ticket sale como antes. */
        $op = is_array($data['operational'] ?? null) ? $data['operational'] : null;

        // ── Cabecera ──
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if (!empty($data['restaurante'])) {
            $printer->setEmphasis(true);
            $printer->text($t($data['restaurante']) . "\n");
            $printer->setEmphasis(false);
        }
        $printer->setTextSize(1, 2);
        $printer->setEmphasis(true);
        $printer->text(($cerrada ? "CIERRE DE CAJA" : "REPORTE DE CAJA") . "\n");
        $printer->setEmphasis(false);
        $printer->setTextSize(1, 1);
        if (!$cerrada) {
            $printer->text("(caja abierta - corte en vivo)\n");
        }
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $div();

        $line('Caja', (string) ($station['name'] ?? $station['code'] ?? '-'));
        $line('Responsable', (string) ($session['cashUserName'] ?? '-'));
        $line('Apertura', (string) ($textos['apertura'] ?? $session['openedAt'] ?? '-'));
        if ($cerrada) {
            $line('Cierre', (string) ($textos['cierre'] ?? $session['closedAt'] ?? '-'));
            $line('Cerrada por', (string) ($session['closedByName'] ?? '-'));
        }
        $div();

        /* Fondo inicial y efectivo esperado SOLO en el cierre. Con la caja abierta NO se imprimen: quien va a
         * declarar su arqueo vería contra qué número cuadrar y dejaría de contar a ciegas. */
        if ($cerrada) {
            $line('Fondo inicial', $money($data['openingCash'] ?? 0));
            $printer->setEmphasis(true);
            $line('Efectivo esperado', $money($data['expectedCash'] ?? 0));
            $printer->setEmphasis(false);
            if (is_array($closing)) {
                $line('Efectivo contado', $money($closing['closingCash'] ?? 0));
                $printer->setEmphasis(true);
                $line('Diferencia', $signed($closing['difference'] ?? 0));
                $printer->setEmphasis(false);
            }
            $div();
        }

        // ── Totales generales del consumo (cierre): sin impuestos, IVA y con impuestos ──
        if ($cerrada && $op !== null) {
            $tot = $op['totals'] ?? [];
            $sectionTitle('TOTALES GENERALES');
            $line('Venta sin impuestos', $money($tot['net'] ?? 0));
            $line('IVA', $money($tot['tax'] ?? 0));
            $printer->setEmphasis(true);
            $line('Venta con impuestos', $money($tot['gross'] ?? 0));
            $printer->setEmphasis(false);
            $div();
        }

        // ── Ventas por método ──
        $sectionTitle('VENTAS POR METODO');
        if (empty($methods)) {
            $printer->text("Sin cobros en esta caja.\n");
        }
        foreach ($methods as $m) {
            $printer->setEmphasis(true);
            $printer->text($t($m['paymentMethodName'] ?? '') . "\n");
            $printer->setEmphasis(false);
            $line('  Ventas', $money($m['sales'] ?? 0));
            if ((float) ($m['refunds'] ?? 0) != 0.0) $line('  Reembolsos', $money($m['refunds']));
            if ((float) ($m['tips'] ?? 0) != 0.0) $line('  Propinas', $money($m['tips']));
            if ($cerrada) $line('  Esperado', $money($m['expected'] ?? 0));
            if ($cerrada && isset($m['declared']) && $m['declared'] !== null) {
                $line('  Declarado', $money($m['declared']));
                $line('  Diferencia', $signed($m['difference'] ?? 0));
            }
        }
        $div();

        // ── Movimientos de efectivo ──
        $sectionTitle('MOVIMIENTOS DE EFECTIVO');
        $line('Entradas', $money($movements['in'] ?? 0));
        $line('Salidas', $money($movements['out'] ?? 0));
        $line('Retiros a boveda', $money($movements['drops'] ?? 0));
        $line('Ajustes', $money($movements['adjusts'] ?? 0));
        $line('Propinas pagadas', $money($movements['tipPayouts'] ?? 0));
        $line('Comisiones pagadas', $money($movements['commissionPayouts'] ?? 0));
        $printer->setEmphasis(true);
        $line('Neto', $money($movements['net'] ?? 0));
        $printer->setEmphasis(false);
        foreach ($items as $it) {
            $hora = (string) ($it['hora'] ?? $it['createdAt'] ?? '');
            if ($hora !== '') $printer->text($t($hora) . "\n");
            $etq = $this->etiquetaMovimientoCaja($it['type'] ?? '');
            if (!empty($it['reason'])) $etq .= ' (' . $it['reason'] . ')';
            if (!empty($it['actorName'])) $etq .= ' - ' . $it['actorName'];
            $line($etq, $money($it['amount'] ?? 0));
        }
        $div();

        // ── Información operativa (cierre) ──
        if ($cerrada && $op !== null) {
            $sectionTitle('INFORMACION OPERATIVA');
            $line('Cuentas cobradas', (string) ($op['orders'] ?? 0));
            $line('Platillos', $this->cantidadLimpia($op['dishes'] ?? 0));
            $line('Personas', (string) ($op['persons'] ?? 0));
            $line('Prom. platillos / cuenta', $this->cantidadLimpia($op['avgDishesPerOrder'] ?? 0));
            $line('Prom. $ / cuenta', $money($op['avgPerOrder'] ?? 0));
            $line('Prom. $ / persona', $money($op['avgPerPerson'] ?? 0));
            $servicios = $op['byService'] ?? [];
            if (!empty($servicios)) {
                $printer->setEmphasis(true);
                $printer->text("Ventas por tipo de servicio\n");
                $printer->setEmphasis(false);
                foreach ($servicios as $sv) {
                    $line('  ' . ($sv['name'] ?? '') . ' (' . (int) ($sv['orders'] ?? 0) . ')', $money($sv['sales'] ?? 0));
                }
            }
            $cor = $op['courtesies'] ?? [];
            $can = $op['cancellations'] ?? [];
            $des = $op['discounts'] ?? [];
            $printer->setEmphasis(true);
            $printer->text("Cortesias\n");
            $printer->setEmphasis(false);
            $line('  Platillos', $this->cantidadLimpia($cor['dishes'] ?? 0) . ' - ' . $money($cor['dishesAmount'] ?? 0));
            $line('  Cuentas', (string) ($cor['accounts'] ?? 0));
            $printer->setEmphasis(true);
            $printer->text("Cancelaciones\n");
            $printer->setEmphasis(false);
            $line('  Platillos', $this->cantidadLimpia($can['dishes'] ?? 0) . ' - ' . $money($can['dishesAmount'] ?? 0));
            $line('  Cuentas anuladas', (int) ($can['accounts'] ?? 0) . ' - ' . $money($can['accountsAmount'] ?? 0));
            $printer->setEmphasis(true);
            $printer->text("Descuentos\n");
            $printer->setEmphasis(false);
            $line('  Platillos', (int) ($des['dishes'] ?? 0) . ' - ' . $money($des['dishesAmount'] ?? 0));
            $line('  Cuentas', (int) ($des['accounts'] ?? 0) . ' - ' . $money($des['accountsAmount'] ?? 0));
            $div();
        }

        // ── Operadores ──
        $sectionTitle('OPERADORES');
        if (empty($operators)) {
            $printer->text("Nadie ha cobrado todavia.\n");
        }
        foreach ($operators as $o) {
            $printer->text($t($o['name'] ?? '') . (!empty($o['isResponsible']) ? ' (Responsable)' : '') . "\n");
            $line('  ' . (int) ($o['paymentsCount'] ?? 0) . ' cobro(s) - ventas', $money($o['salesTotal'] ?? 0));
            if ((float) ($o['tipsTotal'] ?? 0) != 0.0) $line('  Propinas', $money($o['tipsTotal']));
        }

        // ── Declaraciones personales ──
        if (!empty($personal)) {
            $div();
            $sectionTitle('DECLARACIONES PERSONALES');
            foreach ($personal as $d) {
                $printer->text($t(($d['cashierName'] ?? '') . ' - ' . ($d['paymentMethodName'] ?? '')) . "\n");
                if (!empty($d['declared'])) {
                    $line('  ' . (!empty($d['isFinal']) ? 'Firmada' : 'Registrada'),
                        $money((float) ($d['salesDeclared'] ?? 0) + (float) ($d['tipsDeclared'] ?? 0)));
                    $line('  Diferencia', $signed($d['difference'] ?? 0));
                } else {
                    $printer->text("  Pendiente de declarar\n");
                }
            }
        }
        $div();

        $line('Cuentas', (string) ($summary['ordersCount'] ?? 0));
        $line('Venta bruta', $money($summary['salesGross'] ?? 0));
        if (!($cerrada && $op !== null)) $line('Cuenta promedio', $money($summary['avgTicket'] ?? 0));
        $div();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text('Impreso: ' . $t($textos['impreso'] ?? date('d/m/Y H:i')) . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
    }

    /**
     * Imprime la CUENTA / NOTA DE CONSUMO (estilo SoftRestaurant) usando datos directos del front sin templateId.
     * POST /printers/print-consumo
     * Body: array de objetos { printerName, data }
     */
    /**
     * Cuerpo compartido por los 3 tickets de cuenta (consumo / nota de venta /
     * factura): cabecera del restaurante, bloque de orden, tabla de items,
     * descuento de orden, TOTAL grande, total en letra y subtotal/IVA. Cada
     * endpoint le agrega su propio footer (nada, QR de autofactura, o QR +
     * datos de timbrado).
     */
    private function printReceiptBody($printer, array $data, int $W): void
    {
        $rest = $data['restaurante'] ?? [];
        $ord  = $data['orden'] ?? [];
        $items = $data['items'] ?? [];
        $tot = $data['totales'] ?? [];
        $descuentoOrden = $data['descuentoOrden'] ?? null;

        /* Cómo se pagó la cuenta. Todo esto es OPCIONAL: el ticket de cuenta
         * (print-consumo) se imprime ANTES de cobrar, así que no hay pagos ni
         * propina que mostrar, y los payloads viejos que no mandan estas
         * llaves siguen imprimiendo exactamente igual que antes.
         *
         * pagos: [{ metodo, monto, tipo: 'SALE'|'TIP', payerName? }]
         * El desglose importa porque el TOTAL solo no explica nada: una cuenta
         * de $416 pagada $200 en efectivo y $266 con tarjeta, más $50 de
         * propina, hoy se imprime como un número suelto y el cliente no puede
         * cuadrarlo. Ese detalle ya existía en el ticket de cuenta dividida
         * (renderConsolidatedTicket); esto lo trae al ticket normal. */
        $pagos = is_array($data['pagos'] ?? null) ? $data['pagos'] : [];
        $propina = (float)($tot['propina'] ?? 0);
        $consumo = isset($tot['consumo']) ? (float)$tot['consumo'] : null;

        $this->iniciarTicket($printer);

        /* ===== Cabecera Restaurante ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->setEmphasis(true);
        $printer->text(($rest['nombre'] ?? '') . "\n");
        $printer->setEmphasis(false);

        $printer->setTextSize(1, 1);
        if (!empty($rest['rfc'])) $printer->text($rest['rfc'] . "\n");
        if (!empty($rest['cp']))  $printer->text("CP " . $rest['cp'] . "\n");
        if (!empty($rest['direccion'])) $printer->text($rest['direccion'] . "\n");
        if (!empty($rest['tel'])) $printer->text("TEL: " . $rest['tel'] . "\n");

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Bloque Orden ===== */
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text("MESA:" . ($ord['mesa'] ?? '') . "\n");
        $printer->text("MESERO:" . ($ord['mesero'] ?? '') . "\n");

        $this->printTwoColumnLine(
            $printer,
            "PERSONAS:" . (string)($ord['personas'] ?? ''),
            "ORDEN:" . (string)($ord['orden'] ?? ''),
            $W
        );

        $printer->text("FOLIO:" . ($ord['folioSerie'] ?? '') . ' N°:' . ($ord['folioNumber'] ?? '') . "\n");

        if (!empty($ord['fechaCreacion'])) {
            $printer->text('Fecha Creacion: ' . $ord['fechaCreacion'] . "\n");
        }
        if (!empty($ord['fechaImpresion'])) {
            $printer->text('Fecha Impresión: ' . $ord['fechaImpresion'] . "\n");
        }

        $printer->text("CAJERO:" . ($ord['cajero'] ?? '') . "\n");

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Encabezado Tabla ===== */
        $printer->setEmphasis(true);
        $header =
            str_pad("CANT.", 5) . " " .
            str_pad("DESCRIPCION", 31) . " " .
            str_pad("IMPORTE", 10, " ", STR_PAD_LEFT);
        $printer->text($header . "\n");
        $printer->setEmphasis(false);

        /* ===== Items ===== */
        if (!is_array($items) || empty($items)) {
            $printer->text("Sin items\n");
        } else {
            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $qty  = $it['cantidad'] ?? '';
                $desc = (string)($it['descripcion'] ?? '');
                $imp  = $it['importe'] ?? 0;

                // Si es cortesía, marcar en la descripción
                if (!empty($it['isCourtesy'])) {
                    $desc = $desc . ' [CORTESIA]';
                }

                $this->printItemRow($printer, $qty, $desc, $imp, $W);

                // Imprimir línea de descuento por item si aplica
                $descuento = isset($it['descuento']) ? (float)$it['descuento'] : 0;
                $descuentoLabel = (string)($it['descuentoLabel'] ?? '');
                if ($descuento > 0 && $descuentoLabel !== '') {
                    $discLine = str_pad('', 6) .
                        str_pad($descuentoLabel, 30) . ' ' .
                        str_pad('-' . $this->formatMoney($descuento), 10, ' ', STR_PAD_LEFT);
                    $printer->text($discLine . "\n");
                }
            }
        }

        $printer->text(str_repeat('-', $W) . "\n");

        /* ===== Descuento de orden ===== */
        if (is_array($descuentoOrden) && ($descuentoOrden['monto'] ?? 0) > 0) {
            $dTipo = $descuentoOrden['tipo'] ?? '';
            $dValor = $descuentoOrden['valor'] ?? 0;
            $dMonto = $descuentoOrden['monto'] ?? 0;

            $dLabel = 'DCTO ORDEN';
            if ($dTipo === 'percent') {
                $dLabel = 'DCTO ORDEN (' . $dValor . '%)';
            }

            $this->printTwoColumnLine(
                $printer,
                $dLabel,
                '-' . $this->formatMoney($dMonto),
                $W
            );
            $printer->text(str_repeat('-', $W) . "\n");
        }

        /* ===== Totales, en este orden =====
         *   SUBTOTAL · IVA · TOTAL CONSUMO · PROPINA · TOTAL (grande) · formas de pago
         *
         * Subtotal e IVA se imprimen tal como vienen: la propina no causa IVA, así que quien arma el
         * payload es el que debe sacar la base del CONSUMO, no del total cobrado. Aquí no se recalcula
         * nada. "TOTAL CONSUMO" y "PROPINA" solo salen cuando hubo propina: sin ella, TOTAL CONSUMO
         * sería el mismo número que el TOTAL grande de abajo. */
        $this->printTwoColumnLine($printer, 'SUBTOTAL', $this->formatMoney($tot['subtotal'] ?? 0), $W);
        $this->printTwoColumnLine($printer, 'IVA', $this->formatMoney($tot['iva'] ?? 0), $W);
        if ($propina > 0) {
            $this->printTwoColumnLine(
                $printer,
                'TOTAL CONSUMO',
                $this->formatMoney($consumo !== null ? $consumo : (($tot['total'] ?? 0) - $propina)),
                $W
            );
            $this->printTwoColumnLine($printer, 'PROPINA', $this->formatMoney($propina), $W);
        }
        $printer->text(str_repeat('-', $W) . "\n");

        /* ===== TOTAL grande ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->text("TOTAL: " . $this->formatMoney($tot['total'] ?? 0) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text(str_repeat('-', $W) . "\n");

        /* ===== Total en letra =====
         * Solo si de verdad trae LETRAS. Los tres fronts mandan hoy el importe como número ("253.00",
         * un placeholder que nunca se implementó) y salía repetido justo debajo del TOTAL grande. */
        $enLetra = trim((string)($tot['totalEnLetra'] ?? ''));
        if ($enLetra !== '' && preg_match('/\p{L}/u', $enLetra)) {
            $printer->text($enLetra . "\n\n");
        }

        /* ===== Formas de pago ===== */
        $this->printFormaDePago($printer, $pagos, $W);
    }

    /* Desglose de cómo se pagó: una línea por pago, separando propina.
     * No imprime nada si no vienen pagos — así el ticket de cuenta previo al
     * cobro no lleva este bloque. Ya NO imprime "TOTAL PAGADO": esa suma es el
     * TOTAL grande de arriba y repetirla solo estorbaba. */
    private function printFormaDePago($printer, array $pagos, int $W): void
    {
        if (empty($pagos)) return;

        $printer->setEmphasis(true);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("FORMAS DE PAGO\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->setEmphasis(false);

        foreach ($pagos as $pago) {
            if (!is_array($pago)) continue;

            $metodo = trim((string)($pago['metodo'] ?? ''));
            if ($metodo === '') $metodo = 'Otro';
            $monto = (float)($pago['monto'] ?? 0);
            $esPropina = strtoupper((string)($pago['tipo'] ?? 'SALE')) === 'TIP';

            /* El nombre solo aparece cuando la cuenta se dividió por persona:
             * es lo que permite reclamar "yo pagué mi parte con tarjeta". */
            $payerName = trim((string)($pago['payerName'] ?? ''));

            $etiqueta = $metodo;
            if ($payerName !== '') $etiqueta .= ' - ' . $payerName;
            if ($esPropina) $etiqueta .= ' (PROPINA)';

            $this->printTwoColumnLine($printer, $etiqueta, $this->formatMoney($monto), $W);
        }

        $printer->text(str_repeat('=', $W) . "\n");
    }

    /**
     * Ticket de cuenta simple — SIN QR de facturación. Se imprime al
     * comandar/imprimir cuenta, antes de cobrar. La invitación a facturar
     * vive ahora solo en la nota de venta (print-nota-venta), al cobrar.
     */
    public function printConsumo(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $W = 42; // ancho real: POSBANK A6e Font A = 42 col (self-test)

                    $this->printReceiptBody($printer, $data, $W);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Ticket CONSUMO impreso correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'template' => 'consumo_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Nota de venta — se imprime AL COBRAR cuando el cliente no pidió factura
     * en el momento. Mismo cuerpo que el ticket + QR de autofactura futura,
     * con el mensaje de cuántos días tiene para pedirla (data.diasParaFacturar).
     */
    public function printNotaVenta(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = ['success' => 0, 'message' => 'Nombre de impresora es requerido', 'printer_name' => $printerName];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = ['success' => 0, 'message' => 'El campo data debe ser un objeto', 'printer_name' => $printerName];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $W = 42;

                    $this->printReceiptBody($printer, $data, $W);

                    $facturarUrl = $this->buildFacturarUrl($job['restaurantId'] ?? null);
                    $diasParaFacturar = $data['diasParaFacturar'] ?? null;

                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->feed(1);

                    $printer->setEmphasis(true);
                    $printer->text("ESTO NO ES UN COMPROBANTE FISCAL\n");
                    $printer->feed(2);

                    $printer->text("ESCANEA EL SIGUIENTE CODIGO QR\n");
                    $printer->text("PARA PODER EMITIR TU FACTURA\n");
                    $printer->text("ELECTRONICA\n");
                    $printer->setEmphasis(false);

                    if (!empty($diasParaFacturar)) {
                        $printer->feed(1);
                        $printer->setEmphasis(true);
                        $printer->text("TIENES " . (int)$diasParaFacturar . " DIAS PARA FACTURAR\n");
                        $printer->setEmphasis(false);
                    }

                    $printer->feed(1);
                    if (!empty($facturarUrl)) {
                        $printer->qrCode($facturarUrl, Printer::QR_ECLEVEL_M, 6, Printer::QR_MODEL_2);
                        $printer->feed(1);
                    }

                    $printer->feed(1);
                    $printer->text("POS GROWTHSUITE\n");
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Nota de venta impresa correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'factura_url' => $facturarUrl,
                        'template' => 'nota_venta_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = ['success' => 0, 'message' => 'Error al imprimir: ' . $e->getMessage(), 'printer_name' => $printerName, 'error_type' => 'general'];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Comprobante de factura YA generada — se imprime al cobrar cuando el
     * cliente sí pidió factura y Facturapi la timbró con éxito. Mismo cuerpo
     * + nombre/RFC del cliente, datos de timbrado (serie/folio propio + UUID)
     * y un QR que apunta al PDF ya generado (no a crear una nueva).
     * Espera en data.factura: { legalName, taxId, series, folioNumber, uuid, pdfUrl }
     */
    public function printFactura(Request $request, Response $response, $args = [])
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = ['success' => 0, 'message' => 'Nombre de impresora es requerido', 'printer_name' => $printerName];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = ['success' => 0, 'message' => 'El campo data debe ser un objeto', 'printer_name' => $printerName];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);
                    $W = 42;

                    $this->printReceiptBody($printer, $data, $W);

                    $fac = $data['factura'] ?? [];
                    $pdfUrl = $fac['pdfUrl'] ?? null;

                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->feed(1);

                    $printer->setEmphasis(true);
                    $printer->text("FACTURA ELECTRONICA (CFDI)\n");
                    $printer->setEmphasis(false);
                    $printer->feed(1);

                    $printer->setJustification(Printer::JUSTIFY_LEFT);
                    if (!empty($fac['legalName'])) $printer->text("FACTURADO A: " . $fac['legalName'] . "\n");
                    if (!empty($fac['taxId'])) $printer->text("RFC: " . $fac['taxId'] . "\n");
                    if (!empty($fac['series']) || !empty($fac['folioNumber'])) {
                        $printer->text("SERIE-FOLIO: " . ($fac['series'] ?? '') . '-' . ($fac['folioNumber'] ?? '') . "\n");
                    }
                    if (!empty($fac['uuid'])) $printer->text("UUID: " . $fac['uuid'] . "\n");

                    $printer->setJustification(Printer::JUSTIFY_CENTER);
                    $printer->feed(1);

                    if (!empty($pdfUrl)) {
                        $printer->text("ESCANEA PARA VER TU FACTURA\n");
                        $printer->feed(1);
                        $printer->qrCode($pdfUrl, Printer::QR_ECLEVEL_M, 6, Printer::QR_MODEL_2);
                        $printer->feed(1);
                    }

                    $printer->feed(1);
                    $printer->text("POS GROWTHSUITE\n");
                    $printer->setJustification(Printer::JUSTIFY_LEFT);

                    $printer->feed(3);
                    $printer->cut();
                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => 'Factura impresa correctamente en ' . $printerName,
                        'printer_name' => $printerName,
                        'pdf_url' => $pdfUrl,
                        'template' => 'factura_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = ['success' => 0, 'message' => 'Error al imprimir: ' . $e->getMessage(), 'printer_name' => $printerName, 'error_type' => 'general'];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Imprime una fila de item tipo: [CANT] [DESCRIPCION] [IMPORTE]
     * Con wrap de descripción si se pasa del ancho.
     */
    private function printItemRow($printer, $qty, $desc, $importe, $totalWidth = 48)
    {
        $wQty = 5;
        $wDesc = 31;
        $wImp = 10;

        $qtyStr = (string)$qty;
        $impStr = $this->formatMoney($importe);

        // Recorta/parte descripción para wrap
        $desc = trim((string)$desc);
        if ($desc === '') $desc = 'Producto';

        $first = $this->safeSubstr($desc, 0, $wDesc);
        $line =
            str_pad($qtyStr, $wQty) . " " .
            str_pad($first, $wDesc) . " " .
            str_pad($impStr, $wImp, " ", STR_PAD_LEFT);

        $printer->text($line . "\n");

        // Si sobra descripción, imprimir líneas extra indentadas (sin importe)
        $rest = $this->safeSubstr($desc, $wDesc);
        while (!empty($rest)) {
            $chunk = $this->safeSubstr($rest, 0, $wDesc);
            $rest = $this->safeSubstr($rest, $wDesc);

            $printer->text(
                str_pad("", $wQty) . " " .
                    str_pad($chunk, $wDesc) . " " .
                    str_pad("", $wImp) . "\n"
            );
        }
    }

    /**
     * Formatea dinero como $130.00
     */
    private function formatMoney($value)
    {
        $n = is_numeric($value) ? (float)$value : 0;
        return '$' . number_format($n, 2, '.', '');
    }


    /**
     * Construye la URL completa para facturación usando la variable de entorno disponible.
     */
    private function buildFacturarUrl($restaurantId)
    {
        $restaurantId = trim((string) $restaurantId);
        if ($restaurantId === '') {
            return '';
        }
        $envKeys = ['FACTURACION_BASE_URL', 'POS_BASE_URL', 'APP_URL', 'BASE_URL'];
        foreach ($envKeys as $key) {
            $baseUrl = $this->getEnvValue($key);
            if ($baseUrl === '') {
                continue;
            }
            $base = rtrim($baseUrl, '/');
            return $base . '/' . ltrim($restaurantId, '/') . '/facturar';
        }
        return '';
    }

    private function getEnvValue($key)
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        static $cachedLocalEnv = null;
        if ($cachedLocalEnv === null) {
            $cachedLocalEnv = $this->parseLocalEnvFile();
        }
        return $cachedLocalEnv[$key] ?? '';
    }

    private function parseLocalEnvFile()
    {
        $path = realpath(__DIR__ . '/../.env');
        if ($path === false || !file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            list($rawKey, $rawValue) = explode('=', $line, 2);
            $key = trim($rawKey);
            $value = trim($rawValue);
            if ($value === '') {
                continue;
            }
            $length = strlen($value);
            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, $length - 2);
                }
            }
            $values[$key] = $value;
        }
        return $values;
    }

    public function list(Request $request, Response $response, $args = [])
    {
        $printers = [];
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Obtener detalles adicionales de las impresoras en Windows
            exec('wmic printer get Name,Shared,WorkOffline,Default,Status,Network,Availability /format:csv', $output);
            $headers = [];
            foreach ($output as $i => $line) {
                $line = trim($line);
                if ($line === '' || stripos($line, 'Node,Name') !== false) continue;
                if (empty($headers) && strpos($line, ',') !== false) {
                    $headers = array_map('trim', explode(',', $line));
                    continue;
                }
                if ($line && strpos($line, ',') !== false) {
                    $cols = array_map('trim', explode(',', $line));
                    // Si hay más columnas que headers, ajusta
                    if (count($cols) > count($headers)) {
                        $cols = array_slice($cols, -count($headers));
                    }
                    $printer = [];
                    foreach ($headers as $idx => $header) {
                        $printer[$header] = $cols[$idx] ?? null;
                    }
                    if (!empty($printer['Name'])) {
                        $printers[] = [
                            'name'        => $printer['Name'],
                            'shared'      => $printer['Shared'],
                            'work_offline' => $printer['WorkOffline'],
                            'default'     => $printer['Default'],
                            'status'      => $printer['Status'],
                            'network'     => $printer['Network'],
                            'availability' => $printer['Availability'],
                        ];
                    }
                }
            }
        } else {
            // Linux: obtener nombre y estado de impresoras
            exec('lpstat -p', $output);
            foreach ($output as $line) {
                if (preg_match('/^printer\s+(\S+)\s+(.*)$/', $line, $matches)) {
                    $printers[] = [
                        'name'   => $matches[1],
                        'status' => $matches[2],
                    ];
                }
            }
        }
        $payload = json_encode(['printers' => $printers], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function printTemplateTest(Request $request, Response $response, $args)
    {

        $id = $args['id'] ?? null;
        $printerName = $request->getParsedBody()['printerName'] ?? null;

        if (!$id) {
            throw new Exception('ID de template es requerido');
        }

        if (!$printerName) {
            throw new Exception('Nombre de impresora es requerido');
        }

        try {
            $model = new TemplateModel();
            $template = $model->getTemplateById($id);

            if (!$template) {
                $response->getBody()->write(json_encode(['success' => 0, 'message' => 'Template no encontrado']));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $templateJson = json_decode($template['template_json'], true);
            $exampleJson = json_decode($template['example_json'], true);
            $caracteres = $template['caracteres'] ?? 48;
            $paperWidth = $caracteres * 8; // Calcula el ancho real en píxeles

            $connector = new WindowsPrintConnector($printerName);
            //$profile = CapabilityProfile::load("simple");
            $printer = new Printer($connector);

            // Inicializar impresora
            $this->iniciarTicket($printer);
            $printer->setJustification(Printer::JUSTIFY_LEFT);

            // Procesar cada elemento del template
            foreach ($templateJson as $item) {
                $type = $item['type'] ?? 'text';
                $align = $item['align'] ?? 'left';
                $fontSize = $item['fontSize'] ?? '1x1';
                $columns = $item['columns'] ?? [];

                $textType = $item['textType'] ?? 'static';
                $field = $item['field'] ?? '';
                $barcodeFormat = $item['formatBarcode'] ?? 'CODE128';
                $barcodeSizeMap = [
                    '1x' => ['width' => 1.0, 'height' => 30, 'fontSize' => 11],
                    '2x' => ['width' => 1.8, 'height' => 45, 'fontSize' => 14],
                    '3x' => ['width' => 2.6, 'height' => 60, 'fontSize' => 17],
                    '4x' => ['width' => 3.4, 'height' => 80, 'fontSize' => 20],
                    '5x' => ['width' => 4.2, 'height' => 100, 'fontSize' => 23]
                ];
                $barcodeSize = $barcodeSizeMap[$item['size'] ?? '1x'];

                // Configurar alineación
                switch ($align) {
                    case 'center':
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        break;
                    case 'right':
                        $printer->setJustification(Printer::JUSTIFY_RIGHT);
                        break;
                    default:
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                        break;
                }

                // Configurar tamaño de fuente
                $fontSizeMap = [
                    '11px' => [1, 1],
                    '12px' => [2, 1],
                    '16px' => [1, 2],
                    '24px' => [2, 2],
                    '32px' => [4, 4]
                ];
                $fontSizeFrontend = $item['fontSize'] ?? '8px';
                $size = $fontSizeMap[$fontSizeFrontend] ?? [1, 1];
                $printer->setTextSize($size[0], $size[1]);

                // Procesar según tipo de elemento
                switch ($type) {
                    case 'text':
                        /* $text = $item['text'] ?? '';
                        if (is_array($text)) {
                            $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                        }
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        $printer->text($text . "\n");
                        // Restablecer estilos
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);*/
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        if (isset($item['leftText']) && isset($item['rightText'])) {
                            // Imprimir en dos columnas
                            $this->printTwoColumnLine($printer, $item['leftText'], $item['rightText'], $caracteres);
                        } else {
                            $text = $item['text'] ?? '';
                            if (is_array($text)) {
                                $text = json_encode($text, JSON_UNESCAPED_UNICODE);
                            }
                            $printer->text($text . "\n");
                        }
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);
                        break;

                    case 'field':
                        /*$field = $item['field'] ?? '';
                        $textBefore = $item['textBefore'] ?? '';
                        $textAfter = $item['textAfter'] ?? '';
                        $value = $this->getFieldValue($exampleJson, $field);
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        if (!empty($columns) && is_array($value)) {
                            $this->printTable($printer, $columns, $value);
                        } else {
                            if (is_array($value)) {
                                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                            }
                            $printer->text($textBefore . $value . $textAfter . "\n");
                        }
                        // Restablecer estilos
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);*/
                        $fontWeight = $item['fontWeight'] ?? 'normal';
                        $fontUnderline = $item['fontUnderline'] ?? 'none';
                        $printer->setEmphasis($fontWeight === 'bold');
                        $printer->setUnderline($fontUnderline === 'underline');
                        if (isset($item['leftField']) && isset($item['rightField'])) {
                            // Obtener valores de los campos
                            $leftValue = $this->getFieldValue($exampleJson, $item['leftField']);
                            $rightValue = $this->getFieldValue($exampleJson, $item['rightField']);
                            $this->printTwoColumnLine($printer, $leftValue, $rightValue, $caracteres);
                        } else {
                            $field = $item['field'] ?? '';
                            $textBefore = $item['textBefore'] ?? '';
                            $textAfter = $item['textAfter'] ?? '';
                            $value = $this->getFieldValue($exampleJson, $field);
                            if (!empty($columns) && is_array($value)) {
                                $this->printTable($printer, $columns, $value, $caracteres);
                            } else {
                                if (is_array($value)) {
                                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                                }
                                $printer->text($textBefore . $value . $textAfter . "\n");
                            }
                        }
                        $printer->setEmphasis(false);
                        $printer->setUnderline(false);
                        break;

                    case 'line':
                        $printer->text(str_repeat('-', $caracteres) . "\n");
                        break;

                    case 'doubleline':
                        $printer->text(str_repeat('=', $caracteres) . "\n");
                        break;

                    case 'feed':
                        $lines = $item['lines'] ?? 1;
                        $printer->feed($lines);
                        break;

                    case 'newline':
                        $printer->feed(1);
                        break;
                }
            }

            // Finalizar impresión
            $printer->feed(3);
            // buscar en templateJson en la columna type cut, si existe habilitar el corte
            if (in_array('cut', array_column($templateJson, 'type'))) {
                $printer->cut();
            }

            $printer->close();

            $response->getBody()->write(json_encode([
                'success' => 1,
                'message' => 'Ticket impreso correctamente en ' . $printerName,
                'template_id' => $id,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error de conexión con impresora: ' . $e->getMessage(),
                'error_type' => 'printer_connection'
            ]));
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al imprimir: ' . $e->getMessage(),
                'error_type' => 'general'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Imprime una línea con dos columnas (izquierda y derecha) ajustadas al ancho total
     */

    private function printTwoColumnLine($printer, $left, $right, $totalWidth = 48)
    {
        $maxLeft = $totalWidth - strlen($right) - 1;
        $left = $this->safeSubstr($left, 0, $maxLeft);
        $right = $this->safeSubstr($right, 0, $totalWidth - strlen($left) - 1);
        $spaces = $totalWidth - strlen($left) - strlen($right);
        $line = $left . str_repeat(' ', $spaces) . $right . "\n";
        $printer->text($line);
    }

    /**
     * Obtiene el valor de un campo, soportando notación punto para campos anidados
     */
    private function getFieldValue($data, $field)
    {
        if (strpos($field, '.') === false) {
            return $data[$field] ?? '';
        }

        $parts = explode('.', $field);
        $value = $data;
        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                return '';
            }
        }
        return $value;
    }

    /**
     * Fallback para substitución de subcadenas cuando faltan las funciones multibyte.
     */
    private function safeSubstr($text, $start, $length = null)
    {
        if (!is_string($text)) {
            $text = (string) $text;
        }
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($text, $start) : mb_substr($text, $start, $length);
        }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }

    /**
     * Imprime una tabla con columnas específicas
     */
    private function printTable($printer, $columns, $data, $totalWidth = 48)
    {
        $colCount = count($columns);
        $colWidth = floor(($totalWidth - $colCount + 1) / $colCount); // -1 por separadores

        // Imprimir encabezados
        $header = '';
        foreach ($columns as $i => $col) {
            $header .= str_pad(substr($col, 0, $colWidth), $colWidth);
            if ($i < $colCount - 1) $header .= ' ';
        }
        $printer->setEmphasis(true);
        $printer->text($header . "\n");
        $printer->setEmphasis(false);

        // Línea separadora
        $printer->text(str_repeat('-', $totalWidth) . "\n");

        // Imprimir filas de datos
        foreach ($data as $row) {
            $line = '';
            foreach ($columns as $i => $col) {
                $cellValue = isset($row[$col]) ? $row[$col] : '';
                $line .= str_pad(substr($cellValue, 0, $colWidth), $colWidth);
                if ($i < $colCount - 1) $line .= ' ';
            }
            $printer->text($line . "\n");
        }
    }
    /**
     * Traduce el formato de código de barras del frontend al formato de Mike42
     */
    public static function translateBarcodeFormat($frontendFormat)
    {
        $map = [
            'CODE128' => 'BARCODE_CODE128',
            'CODE39'  => 'BARCODE_CODE39',
            'EAN13'   => 'BARCODE_JAN13',
            'EAN8'    => 'BARCODE_JAN8',
            'UPC'     => 'BARCODE_UPCA',
            'ITF'     => 'BARCODE_ITF'
        ];
        return $map[$frontendFormat] ?? null;
    }

    /**
     * Devuelve la etiqueta de curso legible para la plantilla fija.
     */
    private function formatCourseLabel($course)
    {
        $map = [
            1 => '1er tiempo',
            2 => '2do tiempo',
            3 => '3er tiempo'
        ];
        if ($course !== null && isset($map[$course])) {
            return $map[$course];
        }
        if (is_numeric($course)) {
            return 'Tiempo ' . intval($course);
        }
        return '';
    }

    /**
     * Convierte el valor de half en una etiqueta legible.
     */
    private function formatHalfLabel($half)
    {
        $map = [
            1 => 'TODO',
            2 => '1ERA MITAD',
            3 => '2DA MITAD'
        ];
        if ($half !== null && isset($map[$half])) {
            return $map[$half];
        }
        return '';
    }

    /* ════════════════════════════════════════════════════════════════
     *  SPLIT-PAY: PRE-IMPRESIÓN (preview, antes de cobrar)
     *  POST /printers/print-split-preview
     *
     *  Body: array de jobs. Cada job imprime:
     *    1) Ticket consolidado de la cuenta + lista de DIVISIÓN propuesta
     *    2) N vouchers preliminares (uno por cada persona) marcados
     *       "PRELIMINAR — NO PAGADO"
     *
     *  data esperada por job:
     *    {
     *      restaurante: { nombre, rfc, cp, direccion, tel },
     *      orden: { mesa, mesero, personas, orden, folioSerie, folioNumber,
     *               fechaCreacion, fechaImpresion, cajero },
     *      items: [...],            // mismo formato que printConsumo
     *      totales: { subtotal, iva, total, totalEnLetra },
     *      descuentoOrden: ...,     // opcional
     *      splits: [ { payerName, amount, paymentMethod? }, ... ]
     *    }
     * ════════════════════════════════════════════════════════════════ */
    public function printSplitPreview(Request $request, Response $response, $args = [])
    {
        return $this->printSplitInternal($request, $response, /*final*/ false);
    }

    /* ════════════════════════════════════════════════════════════════
     *  SPLIT-PAY: IMPRESIÓN FINAL (después de cobrar)
     *  POST /printers/print-split-final
     *
     *  Body: array de jobs. Cada job imprime:
     *    1) Ticket consolidado de la cuenta con la lista de pagos (nombre,
     *       monto y método por persona)
     *    2) N vouchers finales (uno por persona) marcados "PAGADO" con su
     *       método de pago
     *
     *  data esperada por job: igual a printSplitPreview, pero cada split
     *  debe traer paymentMethod (texto). Se asume que todos están pagados.
     * ════════════════════════════════════════════════════════════════ */
    public function printSplitFinal(Request $request, Response $response, $args = [])
    {
        return $this->printSplitInternal($request, $response, /*final*/ true);
    }

    /* Implementación común para preview y final. */
    private function printSplitInternal(Request $request, Response $response, bool $isFinal)
    {
        try {
            $jobs = $request->getParsedBody();
            if (!is_array($jobs)) {
                $rawBody = (string) $request->getBody();
                $jobs = json_decode($rawBody, true);
            }
            if (!is_array($jobs)) {
                $response->getBody()->write(json_encode([
                    'success' => 0,
                    'message' => 'El cuerpo debe ser un array JSON válido.'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $results = [];
            foreach ($jobs as $job) {
                $printerName = $job['printerName'] ?? null;
                $data = $job['data'] ?? [];

                if (!$printerName) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'Nombre de impresora es requerido',
                        'printer_name' => $printerName
                    ];
                    continue;
                }
                if (!is_array($data)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'El campo data debe ser un objeto',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $splits = $data['splits'] ?? [];
                if (!is_array($splits) || empty($splits)) {
                    $results[] = [
                        'success' => 0,
                        'message' => 'splits[] requerido con al menos 1 división',
                        'printer_name' => $printerName
                    ];
                    continue;
                }

                $printer = null;
                $printerClosed = false;

                try {
                    $connector = new TrackedWindowsPrintConnector($printerName, $job['jobUid'] ?? null);
                    $printer = new Printer($connector);

                    /* (1) Ticket consolidado de la cuenta */
                    $this->renderConsolidatedTicket($printer, $data, $isFinal);

                    /* (2) N vouchers individuales — uno por persona */
                    $orderTotal = (float)($data['totales']['total'] ?? 0);
                    foreach ($splits as $idx => $sp) {
                        if (!is_array($sp)) continue;
                        $this->renderSplitVoucher(
                            $printer,
                            $data,
                            $sp,
                            $idx + 1,
                            count($splits),
                            $orderTotal,
                            $isFinal
                        );
                    }

                    $printer->close();
                    $printerClosed = true;

                    $results[] = [
                        'success' => 1,
                        'message' => ($isFinal ? 'Tickets de split-pay FINAL' : 'Tickets de split-pay PRELIMINAR')
                            . ' impresos en ' . $printerName,
                        'printer_name' => $printerName,
                        'splits' => count($splits),
                        'template' => $isFinal ? 'split_final_ticket' : 'split_preview_ticket',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                } catch (\Throwable $e) {
                    $this->marcarTrabajoFallido($job['jobUid'] ?? null, $e->getMessage());
                    $results[] = [
                        'success' => 0,
                        'message' => 'Error al imprimir: ' . $e->getMessage(),
                        'printer_name' => $printerName,
                        'error_type' => 'general'
                    ];
                } finally {
                    if ($printer && !$printerClosed) {
                        try {
                            $printer->close();
                        } catch (\Throwable $inner) {
                        }
                    }
                }
            }

            $response->getBody()->write(json_encode($results, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => 0,
                'message' => 'Error al procesar el request: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /* Renderiza el ticket consolidado de la cuenta con la sección de splits.
     * Mismo layout que printConsumo pero con un bloque "DIVIDIDO EN:" antes
     * del TOTAL grande. Si $isFinal, muestra el método de pago de cada split. */
    private function renderConsolidatedTicket($printer, array $data, bool $isFinal): void
    {
        $W = 42;

        $rest = $data['restaurante'] ?? [];
        $ord  = $data['orden'] ?? [];
        $items = $data['items'] ?? [];
        $tot = $data['totales'] ?? [];
        $descuentoOrden = $data['descuentoOrden'] ?? null;
        $splits = $data['splits'] ?? [];

        $this->iniciarTicket($printer);

        /* ===== Cabecera Restaurante ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->setEmphasis(true);
        $printer->text(($rest['nombre'] ?? '') . "\n");
        $printer->setEmphasis(false);

        $printer->setTextSize(1, 1);
        if (!empty($rest['rfc'])) $printer->text($rest['rfc'] . "\n");
        if (!empty($rest['cp']))  $printer->text("CP " . $rest['cp'] . "\n");
        if (!empty($rest['direccion'])) $printer->text($rest['direccion'] . "\n");
        if (!empty($rest['tel'])) $printer->text("TEL: " . $rest['tel'] . "\n");

        /* ===== Aviso de modo (preliminar / final) ===== */
        $printer->setEmphasis(true);
        $printer->text(str_repeat('*', $W) . "\n");
        $printer->text(($isFinal ? "CUENTA DIVIDIDA - PAGADA" : "CUENTA DIVIDIDA - PRELIMINAR") . "\n");
        $printer->text(str_repeat('*', $W) . "\n");
        $printer->setEmphasis(false);

        /* ===== Bloque Orden ===== */
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text("MESA:" . ($ord['mesa'] ?? '') . "\n");
        $printer->text("MESERO:" . ($ord['mesero'] ?? '') . "\n");

        $this->printTwoColumnLine(
            $printer,
            "PERSONAS:" . (string)($ord['personas'] ?? ''),
            "ORDEN:" . (string)($ord['orden'] ?? ''),
            $W
        );

        $printer->text("FOLIO:" . ($ord['folioSerie'] ?? '') . ' N°:' . ($ord['folioNumber'] ?? '') . "\n");

        if (!empty($ord['fechaCreacion'])) {
            $printer->text('Fecha Creacion: ' . $ord['fechaCreacion'] . "\n");
        }
        if (!empty($ord['fechaImpresion'])) {
            $printer->text('Fecha Impresión: ' . $ord['fechaImpresion'] . "\n");
        }
        $printer->text("CAJERO:" . ($ord['cajero'] ?? '') . "\n");

        $printer->text(str_repeat('=', $W) . "\n");

        /* ===== Encabezado Tabla ===== */
        $printer->setEmphasis(true);
        $header =
            str_pad("CANT.", 5) . " " .
            str_pad("DESCRIPCION", 31) . " " .
            str_pad("IMPORTE", 10, " ", STR_PAD_LEFT);
        $printer->text($header . "\n");
        $printer->setEmphasis(false);

        /* ===== Items ===== */
        if (!is_array($items) || empty($items)) {
            $printer->text("Sin items\n");
        } else {
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $qty  = $it['cantidad'] ?? '';
                $desc = (string)($it['descripcion'] ?? '');
                $imp  = $it['importe'] ?? 0;
                if (!empty($it['isCourtesy'])) {
                    $desc = $desc . ' [CORTESIA]';
                }
                $this->printItemRow($printer, $qty, $desc, $imp, $W);

                $descuento = isset($it['descuento']) ? (float)$it['descuento'] : 0;
                $descuentoLabel = (string)($it['descuentoLabel'] ?? '');
                if ($descuento > 0 && $descuentoLabel !== '') {
                    $discLine = str_pad('', 6) .
                        str_pad($descuentoLabel, 30) . ' ' .
                        str_pad('-' . $this->formatMoney($descuento), 10, ' ', STR_PAD_LEFT);
                    $printer->text($discLine . "\n");
                }
            }
        }

        $printer->text(str_repeat('-', $W) . "\n");

        /* ===== Descuento de orden ===== */
        if (is_array($descuentoOrden) && ($descuentoOrden['monto'] ?? 0) > 0) {
            $dTipo = $descuentoOrden['tipo'] ?? '';
            $dValor = $descuentoOrden['valor'] ?? 0;
            $dMonto = $descuentoOrden['monto'] ?? 0;
            $dLabel = 'DCTO ORDEN';
            if ($dTipo === 'percent') {
                $dLabel = 'DCTO ORDEN (' . $dValor . '%)';
            }
            $this->printTwoColumnLine(
                $printer,
                $dLabel,
                '-' . $this->formatMoney($dMonto),
                $W
            );
            $printer->text(str_repeat('-', $W) . "\n");
        }

        /* ===== TOTAL grande ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setTextSize(2, 2);
        $printer->text("TOTAL: " . $this->formatMoney($tot['total'] ?? 0) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text(str_repeat('=', $W) . "\n");

        if (!empty($tot['totalEnLetra'])) {
            $printer->text($tot['totalEnLetra'] . "\n\n");
        }

        $this->printTwoColumnLine(
            $printer,
            "SUBTOTAL:" . $this->formatMoney($tot['subtotal'] ?? 0),
            "IVA:" . $this->formatMoney($tot['iva'] ?? 0),
            $W
        );

        /* ===== Bloque DIVIDIDO EN ===== */
        $printer->text(str_repeat('=', $W) . "\n");
        $printer->setEmphasis(true);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text(($isFinal ? "DETALLE DE PAGOS" : "DIVISION PROPUESTA") . "\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->setEmphasis(false);
        $printer->text(str_repeat('-', $W) . "\n");

        $sumSplit = 0.0;
        $sumTip = 0.0;
        foreach ($splits as $idx => $sp) {
            if (!is_array($sp)) continue;
            $name = (string)($sp['payerName'] ?? ('Persona ' . ($idx + 1)));
            $amount = (float)($sp['amount'] ?? 0);
            $tipAmount = (float)($sp['tipAmount'] ?? 0);
            $personalTotal = $amount + $tipAmount;
            $method = (string)($sp['paymentMethod'] ?? '');
            $sumSplit += $amount;
            $sumTip += $tipAmount;

            $left = '#' . ($idx + 1) . ' ' . $name;
            if ($isFinal && $method !== '') {
                $left .= ' (' . $method . ')';
            }
            $this->printTwoColumnLine($printer, $left, $this->formatMoney($personalTotal), $W);
            if ($tipAmount > 0) {
                // Línea secundaria con breakdown: consumo + propina
                $breakdownRight = $this->formatMoney($amount) . ' + tip ' . $this->formatMoney($tipAmount);
                $this->printTwoColumnLine($printer, '   ', $breakdownRight, $W);
            }
        }
        $printer->text(str_repeat('-', $W) . "\n");
        $this->printTwoColumnLine(
            $printer,
            'CONSUMO DIVIDIDO',
            $this->formatMoney($sumSplit),
            $W
        );
        if ($sumTip > 0) {
            $this->printTwoColumnLine(
                $printer,
                'PROPINAS DIVIDIDAS',
                $this->formatMoney($sumTip),
                $W
            );
            $this->printTwoColumnLine(
                $printer,
                'GRAN TOTAL',
                $this->formatMoney($sumSplit + $sumTip),
                $W
            );
        }

        /* ===== Footer ===== */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->feed(1);
        $printer->setEmphasis(true);
        $printer->text("ESTO NO ES UN COMPROBANTE FISCAL\n");
        $printer->setEmphasis(false);
        $printer->feed(1);
        $printer->text("POS GROWTHSUITE\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->feed(3);
        $printer->cut();
    }

    /* Renderiza UN voucher individual de uno de los splits. */
    private function renderSplitVoucher(
        $printer,
        array $data,
        array $split,
        int $idx,
        int $totalSplits,
        float $orderTotal,
        bool $isFinal
    ): void {
        $W = 42;
        $rest = $data['restaurante'] ?? [];
        $ord  = $data['orden'] ?? [];
        $name = (string)($split['payerName'] ?? ('Persona ' . $idx));
        $amount = (float)($split['amount'] ?? 0);
        $tipAmount = (float)($split['tipAmount'] ?? 0);
        $personalTotal = $amount + $tipAmount;
        $method = (string)($split['paymentMethod'] ?? '');

        $this->iniciarTicket($printer);

        /* Header del voucher */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(1, 2);
        $printer->text("VOUCHER DE PAGO INDIVIDUAL\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        $printer->text(str_repeat('=', $W) . "\n");

        /* Estado del voucher */
        $printer->setEmphasis(true);
        $printer->text(($isFinal ? "*** PAGADO ***" : "*** PRELIMINAR — NO PAGADO ***") . "\n");
        $printer->setEmphasis(false);

        $printer->text(str_repeat('=', $W) . "\n");

        /* Restaurante (compacto) */
        $printer->setEmphasis(true);
        $printer->text(($rest['nombre'] ?? '') . "\n");
        $printer->setEmphasis(false);

        /* Datos de la orden (compactos) */
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("MESA: " . ($ord['mesa'] ?? '') . "\n");
        $printer->text("FOLIO: " . ($ord['folioSerie'] ?? '') . ' N°:' . ($ord['folioNumber'] ?? '') . "\n");
        if (!empty($ord['fechaImpresion'])) {
            $printer->text('Fecha: ' . $ord['fechaImpresion'] . "\n");
        }

        $printer->text(str_repeat('=', $W) . "\n");

        /* Datos del split */
        $printer->setEmphasis(true);
        $printer->setTextSize(1, 2);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($name . "\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text("Persona: " . $idx . " de " . $totalSplits . "\n");

        $printer->text(str_repeat('-', $W) . "\n");

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("$" . number_format($personalTotal, 2) . "\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);

        if ($isFinal && $method !== '') {
            $printer->text("Método: " . $method . "\n");
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text(str_repeat('-', $W) . "\n");

        /* Comparativa: consumo + propina */
        $this->printTwoColumnLine(
            $printer,
            "Tu consumo:",
            $this->formatMoney($amount),
            $W
        );
        if ($tipAmount > 0) {
            $this->printTwoColumnLine(
                $printer,
                "Tu propina:",
                $this->formatMoney($tipAmount),
                $W
            );
        }
        $this->printTwoColumnLine(
            $printer,
            "Tu total a pagar:",
            $this->formatMoney($personalTotal),
            $W
        );
        $printer->text(str_repeat('-', $W) . "\n");
        $this->printTwoColumnLine(
            $printer,
            "Total de la cuenta:",
            $this->formatMoney($orderTotal),
            $W
        );

        $printer->text(str_repeat('=', $W) . "\n");

        /* Footer */
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if (!$isFinal) {
            $printer->setEmphasis(true);
            $printer->text("Este voucher es solo informativo.\n");
            $printer->text("Acércalo al cajero para pagar.\n");
            $printer->setEmphasis(false);
        } else {
            $printer->setEmphasis(true);
            $printer->text("¡Gracias por tu visita!\n");
            $printer->setEmphasis(false);
        }
        $printer->feed(1);
        $printer->text("POS GROWTHSUITE\n");

        $printer->feed(3);
        $printer->cut();
    }
}

 // Descomentar la siguiente línea si tu impresora soporta impresión directa de QR
// $printer->qrCode($qrText, Printer::QR_ECLEVEL_L, $sizeQR);
//$printer->feed(1);
