<?php
/**
 * Casos con `style` (se incluye desde golden_estilos.php; usa $ctrl, $casos, $ok, correr(), snap(), sinFormato()).
 * Verifica, para los 11 tipos: (a) que cada PARTE de estilo cambie el ticket, (b) que cada CAMPO del catálogo oculte
 * exactamente su renglón/sección (y que los obligatorios sigan saliendo), (c) pruebaEstilo, (d) compatibilidad antro.
 */
use App\Support\CatalogoTicket;
use App\Support\EstiloTicket;

function V2(array $partes = [], array $campos = [], array $extra = []): array
{
    return array_merge(['v' => 2, 'partes' => $partes, 'campos' => $campos], $extra);
}
function contiene(string $out, string $m): bool
{
    return $m !== '' && $m[0] === '/' ? preg_match($m, $out) === 1 : str_contains($out, $m);
}

// ───────────── (a) cada parte con contenido cambia el ticket ─────────────
$grande = ['ancho' => 3, 'alto' => 3, 'negrita' => true, 'alineacion' => 'der'];
$partesConContenido = [
    'cuenta' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'nota_con_pago' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'nota_qr_dias' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'factura' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'comanda_restaurante' => ['titulo', 'encabezado', 'detalle'],
    'comanda_antro' => ['titulo', 'encabezado', 'detalle'],
    'cancelacion' => ['titulo', 'encabezado', 'detalle'],
    'split_preview' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'split_final' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'propinas' => ['titulo', 'encabezado', 'detalle', 'totales'],
    'propinas_vacio' => ['titulo', 'encabezado'],
    'comisiones' => ['titulo', 'encabezado', 'detalle', 'totales'],
    'comisiones_vacio' => ['titulo', 'encabezado'],
    'movimientos' => ['titulo', 'encabezado', 'totales', 'pie'],
    'corte_x' => ['titulo', 'encabezado', 'detalle', 'totales'],
    'reporte_caja' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
    'cierre_caja' => ['titulo', 'encabezado', 'detalle', 'totales', 'pie'],
];
foreach ($partesConContenido as $nombre => $partes) {
    $base = snap($nombre);
    foreach ($partes as $parte) {
        $con = correr($ctrl, $casos[$nombre], V2([$parte => $grande]));
        $ok($con !== $base && str_contains($con, "\x1d\x21\x22"), "parte '$parte' (3x3 negrita der) cambia el ticket: $nombre");
    }
}

// ───────────── (b) cada campo oculta lo suyo ─────────────
$recibo = [
    'restaurante_rfc' => 'XAXX010101000', 'restaurante_cp' => 'CP 06600', 'restaurante_direccion' => 'Calle Prueba 123', 'restaurante_tel' => 'TEL: 5555555555',
    'mesa' => 'MESA:M01', 'mesero' => 'MESERO:Ana', 'personas_orden' => 'PERSONAS:2', 'folio' => 'FOLIO:F001', 'fecha_creacion' => 'Fecha Creacion',
    'fecha_impresion' => 'Fecha Impresi', 'cajero' => 'CAJERO:Luis', 'tabla_encabezado' => 'DESCRIPCION', 'descuento_item' => 'PROMO',
    'descuento_orden' => 'DCTO ORDEN', 'subtotal' => 'SUBTOTAL', 'iva' => '/IVA +\$/', 'total_letra' => 'NOVENTA Y CINCO',
];
$reciboPago = ['consumo' => 'TOTAL CONSUMO', 'propina' => '/PROPINA +\$20/', 'formas_pago' => 'FORMAS DE PAGO'];
$matriz = [
    // [doc, caso, marcadores campo => texto, obligatorios campo => texto]
    ['cuenta', 'cuenta', $recibo, ['restaurante_nombre' => 'RESTAURANTE LAB', 'total' => 'TOTAL: $95.00']],
    ['cuenta', 'nota_con_pago', $reciboPago, ['total' => 'TOTAL: $135.00']],
    ['nota_venta', 'nota_con_pago', $reciboPago + ['qr_factura' => 'ESCANEA EL SIGUIENTE', 'marca' => 'POS GROWTHSUITE'], ['leyenda_no_fiscal' => 'NO ES UN COMPROBANTE FISCAL', 'total' => 'TOTAL: $135.00']],
    ['nota_venta', 'nota_qr_dias', $recibo + ['dias_para_facturar' => 'TIENES 3 DIAS PARA FACTURAR'], ['leyenda_no_fiscal' => 'NO ES UN COMPROBANTE FISCAL']],
    ['factura', 'factura', $reciboPago + ['factura_qr' => 'ESCANEA PARA VER TU FACTURA', 'marca' => 'POS GROWTHSUITE'], ['factura_titulo' => 'FACTURA ELECTRONICA (CFDI)', 'factura_datos' => 'FACTURADO A: CLIENTE DE PRUEBA SA']],
    ['comanda', 'comanda_restaurante', ['area' => 'Area: Cocina', 'mesero' => 'Mesero: Ana', 'fecha' => 'Fecha: 01/01/2026', 'tiempo' => 'Tiempo:', 'notas_producto' => 'Nota: sin cebolla', 'modificadores' => 'Modificadores:'], ['titulo' => 'COMANDA TICKET', 'mesa' => 'Mesa: M01']],
    ['cancelacion', 'cancelacion', ['area' => 'Area: Cocina', 'mesero' => 'Mesero: Ana', 'fecha' => 'Fecha: 01/01/2026', 'cancelado_por' => 'Cancelo: Gerente', 'motivo' => 'Motivo: Cliente se arrepintio', 'tiempo' => 'Tiempo:'], ['titulo' => 'CANCELACION', 'mesa' => 'Mesa: M01']],
    ['cuenta', 'split_final', ['iva' => 'IVA:$13.10'] + $recibo, ['restaurante_nombre' => 'RESTAURANTE LAB', 'total' => 'TOTAL: $95.00']],
    ['propinas', 'propinas', ['impresion' => 'Impresi', 'fecha' => 'Fecha: 01/01/2026', 'detalle_pagos' => 'Detalle de pagos', 'total_venta' => 'Total de venta'], ['titulo' => 'PAGO DE PROPINAS', 'mesero' => 'Mesero: Ana Lopez', 'total_propina' => 'TOTAL PROPINA - ANA LOPEZ']],
    ['comisiones', 'comisiones', ['fecha_turno' => 'Turno: #12', 'pagado_por' => 'Luis', 'pagos' => 'Pago (Efectivo)', 'comision_ganada' => 'Comision ganada', 'pagada_pendiente' => 'Pendiente'], ['titulo' => 'PAGO DE COMISIONES', 'mesero' => 'Mesero: Ana Lopez', 'total_comision' => 'TOTAL COMISION - ANA']],
    ['movimientos', 'movimientos', ['tipo' => 'Tipo: Retiro', 'estacion' => 'Estacion: Caja 1', 'turno_ids' => 'Shift ID: 140', 'razon' => 'Razon: Compra de hielo', 'registrado' => 'Registrado:', 'firma_autoriza' => 'Firma de quien autoriza', 'firma_recibe' => 'Firma de quien recibe'], ['titulo' => 'MOVIMIENTO DE CAJA', 'monto' => 'MONTO']],
    ['corte_x', 'corte_x', [
        'empresa' => 'RESTAURANTE LAB', 'periodo' => 'APERTURA:', 'turno_estacion' => 'TURNO: 140', 'cajeros' => 'CAJERO: Luis, Mara', 'cajas_del_turno' => 'CAJAS DEL TURNO',
        'caja' => '+EFECTIVO INIC', 'ventas_por_metodo' => 'FORMA DE PAGO VENTAS', 'propinas_por_metodo' => 'FORMA DE PAGO PROPINA', 'por_categoria' => 'POR TIPO DE PRODUCTO',
        'por_servicio' => 'POR TIPO DE SERVICIO', 'totales' => 'VENTA NETA', 'resumen_cuentas' => 'RESUMEN CUENTAS', 'cortesias_descuentos' => 'CORTESIAS POR CATEGORIA', 'declaraciones' => 'DECLARACION DE CAJERO',
    ], ['titulo' => 'REPORTE DE TURNO']],
    ['reporte_caja', 'reporte_caja', [
        'restaurante' => 'RESTAURANTE LAB', 'datos_caja' => '/Responsable +Luis/', 'ventas_por_metodo' => 'VENTAS POR METODO', 'movimientos_efectivo' => 'MOVIMIENTOS DE EFECTIVO', 'operadores' => 'OPERADORES',
        'declaraciones_personales' => 'DECLARACIONES PERSONALES', 'resumen_final' => 'Venta bruta', 'pie_impreso' => 'Impreso:',
    ], ['titulo' => 'REPORTE DE CAJA']],
    ['cierre_caja', 'cierre_caja', [
        'restaurante' => 'RESTAURANTE LAB', 'datos_caja' => '/Responsable +Luis/', 'cierre_info' => 'Cerrada por', 'efectivo_esperado' => 'Efectivo esperado', 'contado_diferencia' => 'Efectivo contado',
        'totales_generales' => 'TOTALES GENERALES', 'ventas_por_metodo' => 'VENTAS POR METODO', 'conciliacion_por_metodo' => '  Declarado', 'movimientos_efectivo' => 'MOVIMIENTOS DE EFECTIVO',
        'info_operativa' => 'INFORMACION OPERATIVA', 'operadores' => 'OPERADORES', 'declaraciones_personales' => 'DECLARACIONES PERSONALES', 'resumen_final' => 'Venta bruta', 'pie_impreso' => 'Impreso:',
    ], ['titulo' => 'CIERRE DE CAJA']],
];
$cubiertos = []; // doc => [key => true], para comprobar que el catálogo entero está probado
foreach ($matriz as [$doc, $caso, $marcas, $obl]) {
    $base = snap($caso);
    foreach ($marcas as $key => $m) {
        $cubiertos[$doc][$key] = true;
        $def = CatalogoTicket::CAMPOS[$doc][$key] ?? null;
        $ok($def !== null && !$def['obl'], "catálogo: $doc.$key existe y no es obligatorio");
        $ok(contiene($base, $m), "base de $caso muestra «{$m}» ($doc.$key)");
        $sin = correr($ctrl, $casos[$caso], V2([], [$key => false]));
        $ok(!contiene($sin, $m), "campos.$key=false oculta «{$m}»: $caso");
        $ok(sinFormato(correr($ctrl, $casos[$caso], V2([], [$key => true]))) === sinFormato($base), "campos.$key=true deja el ticket como hoy: $caso");
    }
    foreach ($obl as $key => $m) {
        $cubiertos[$doc][$key] = true;
        $def = CatalogoTicket::CAMPOS[$doc][$key] ?? null;
        $ok($def !== null && $def['obl'], "catálogo: $doc.$key es obligatorio");
        $todo = array_fill_keys(array_keys(CatalogoTicket::CAMPOS[$doc]), false);
        $out = correr($ctrl, $casos[$caso], V2([], $todo));
        $ok(contiene($out, $m), "obligatorio $doc.$key=false se ignora y sigue saliendo «{$m}»: $caso");
    }
}
// Tickets de TACO CAPITAL: fecha/hora separadas, leyenda, divisores y comensales (marcan sus campos en $cubiertos)
require __DIR__ . '/pruebas_tickets_taco.php';

// el catálogo completo de los 11 tipos tiene prueba (salvo los de comanda/antro, que se cubren abajo)
$pendientes = [];
foreach (CatalogoTicket::CAMPOS as $doc => $campos) {
    foreach ($campos as $key => $def) {
        if (!isset($cubiertos[$doc][$key]) && !($doc === 'comanda' && in_array($key, ['tiempo', 'modificadores_en_linea'], true))
            && !($doc === 'nota_venta' && isset(CatalogoTicket::CAMPOS['cuenta'][$key])) && !($doc === 'factura' && isset(CatalogoTicket::CAMPOS['cuenta'][$key]))
            && !($doc === 'cancelacion' && $key === 'modificadores' || $doc === 'cancelacion' && $key === 'notas_producto')) {
            $pendientes[] = "$doc.$key";
        }
    }
}
$ok($pendientes === [], 'todos los campos del catálogo tienen prueba' . ($pendientes ? ' (faltan: ' . implode(', ', $pendientes) . ')' : ''));

// apagar de golpe todo lo opcional de cada tipo: no truena y lo obligatorio sigue
foreach ($casos as $nombre => $c) {
    $doc = $c['doc'];
    $todo = [];
    foreach (CatalogoTicket::CAMPOS[$doc] as $k => $d) $todo[$k] = false;
    $out = correr($ctrl, $c, V2([], $todo));
    $ok($out !== '', "todos los campos apagados imprime algo sin fallar: $nombre");
}

// campo desconocido o con tipo raro: se ignora
foreach (['cuenta', 'comanda_restaurante', 'corte_x', 'movimientos'] as $nombre) {
    $ok(sinFormato(correr($ctrl, $casos[$nombre], V2([], ['no_existe' => false, 'mesa' => 'no']))) === sinFormato(snap($nombre)), "campos desconocidos o no booleanos se ignoran: $nombre");
}

// ───────────── antro / compatibilidad ─────────────
$antro = $casos['comanda_antro'];
$normal = $casos['comanda_restaurante'];
$sin = correr($ctrl, $antro, null);
$ok(contiene($sin, '1-Whisky') && !contiene($sin, 'Tiempo:') && !contiene($sin, 'Modificadores:'), 'antro sin style: tipo 2 sigue en línea y sin Tiempo (como hoy)');
$soloCampos = correr($ctrl, $antro, V2([], ['area' => true]));
$ok(contiene($soloCampos, '1-Whisky') && !contiene($soloCampos, 'Tiempo:'), 'antro con style sin las claves tiempo/modificadores_en_linea: sigue mandando data.tipo = 2');
$ok(contiene(correr($ctrl, $antro, V2([], ['tiempo' => true])), 'Tiempo:'), 'antro: campos.tiempo=true muestra el Tiempo aunque tipo = 2');
$fuera = correr($ctrl, $antro, V2([], ['modificadores_en_linea' => false]));
$ok(contiene($fuera, 'Modificadores:') && !contiene($fuera, '1-Whisky'), 'antro: campos.modificadores_en_linea=false manda sobre tipo = 2 (formato de restaurante)');
$enLinea = correr($ctrl, $normal, V2([], ['modificadores_en_linea' => true, 'tiempo' => false]));
$ok(contiene($enLinea, '2-Hamburguesa') && !contiene($enLinea, 'Modificadores:') && !contiene($enLinea, 'Tiempo:') && contiene($enLinea, "\x1d\x21\x11"), 'restaurante tipo 1 con campos antro (en línea, sin tiempo): párrafo 2x2');
$migrado = correr($ctrl, $normal, V2(['detalle' => ['ancho' => 2, 'alto' => 2, 'negrita' => false, 'alineacion' => 'izq']], ['tiempo' => false, 'modificadores_en_linea' => true]));
$ok(contiene($migrado, '2-Hamburguesa') && !contiene($migrado, 'Tiempo:'), 'estilo migrado de antro (campos + detalle 2x2): en línea y sin tiempo');
$ok(!contiene(correr($ctrl, $casos['cancelacion'], V2([], ['modificadores_en_linea' => true])), '1-Ensalada'), 'cancelación ignora modificadores_en_linea (no es campo suyo)');

// ───────────── columnas ─────────────
$c32 = correr($ctrl, $casos['cuenta'], V2([], [], ['columnas' => 32]));
$ok(contiene($c32, str_repeat('=', 32) . "\n") && !contiene($c32, str_repeat('=', 33)), 'columnas 32: las rayas miden 32 (cuenta)');
$k32 = correr($ctrl, $casos['movimientos'], V2([], [], ['columnas' => 32]));
$ok(contiene($k32, str_repeat('-', 32) . "\n") && !contiene($k32, str_repeat('-', 33)), 'columnas 32: las rayas miden 32 (movimientos)');

// ───────────── prueba ─────────────
foreach ($casos as $nombre => $c) {
    $sinE = correr($ctrl, $c, null, ['pruebaEstilo' => true]);
    $conE = correr($ctrl, $c, V2([], []), ['pruebaEstilo' => true]);
    $ok(str_contains($sinE, 'PRUEBA - NO VALIDO') && str_contains($conE, 'PRUEBA - NO VALIDO') && !str_contains(snap($nombre), 'PRUEBA - NO VALIDO'), "pruebaEstilo agrega «PRUEBA - NO VALIDO»: $nombre");
}
$nv = correr($ctrl, $casos['nota_con_pago'], V2(), ['pruebaEstilo' => true]);
$ok(strrpos($nv, 'PRUEBA - NO VALIDO') > strrpos($nv, 'POS GROWTHSUITE'), 'nota de venta: la línea de prueba sale al FINAL, después de la marca');

// ───────────── seguridad del reporte «ciego» ─────────────
$verTodo = array_fill_keys(array_keys(CatalogoTicket::CAMPOS['cierre_caja']), true);
$ab = correr($ctrl, $casos['reporte_caja'], V2([], $verTodo));
$ok(!contiene($ab, 'Efectivo esperado') && !contiene($ab, 'Fondo inicial') && !contiene($ab, '  Esperado'), 'reporte de caja abierta: campos=true NO revela el esperado (sigue ciego)');

// ───────────── EstiloTicket ─────────────
$n = EstiloTicket::normalizar(['style' => ['v' => 2, 'campos' => ['propina' => false, 'x' => 'no', 3 => true]]]);
$ok($n !== null && $n['campos'] === ['propina' => false] && $n['partes'] === [], 'normalizar v2: solo campos booleanos válidos (sin partes)');
$ok(EstiloTicket::normalizar(['style' => ['v' => 1, 'partes' => ['titulo' => ['ancho' => 2]]]]) !== null, 'normalizar: v1 sigue válido');
$ok(EstiloTicket::normalizar(['style' => ['v' => 3, 'campos' => ['propina' => false]]]) === null, 'normalizar: v3 se ignora');
$ok(EstiloTicket::normalizar(['style' => ['v' => 2, 'campos' => []]]) === null, 'normalizar: v2 vacío => null');
$e = EstiloTicket::normalizar(['style' => ['v' => 2, 'campos' => ['propina' => false, 'total' => false, 'modificadores_en_linea' => true]]]);
$ok(EstiloTicket::visible($e, 'cuenta', 'propina') === false, 'visible: campo apagado');
$ok(EstiloTicket::visible($e, 'cuenta', 'iva') === true, 'visible: ausente = def del catálogo');
$ok(EstiloTicket::visible($e, 'cuenta', 'total') === true, 'visible: obligatorio ignora el false');
$ok(EstiloTicket::visible($e, 'cuenta', 'no_existe') === true, 'visible: clave desconocida => true');
$ok(EstiloTicket::visible(null, 'comanda', 'modificadores_en_linea') === false && EstiloTicket::campo($e, 'comanda', 'modificadores_en_linea') === true, 'visible/campo: def false sin estilo; explícito con estilo');
$ok(EstiloTicket::campo($e, 'cancelacion', 'modificadores_en_linea') === null, 'campo: clave que no es del tipo => null');
