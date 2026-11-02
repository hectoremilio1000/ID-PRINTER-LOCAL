<?php
/**
 * Tickets de TACO CAPITAL (se incluye desde pruebas_estilo.php; usa $ctrl, $casos, $ok, $cubiertos, correr(), snap(), sinFormato(), V2()).
 *   1) fecha y hora: dos campos, cada uno se enciende o apaga aparte (juntos = como hoy)   2) leyenda al final de la cuenta / nota
 *   3) líneas divisorias apagables (renglón en blanco)   4) comensal en la comanda y subtotal por comensal en la cuenta
 * Regla de oro de todo el archivo: con el campo apagado, sin el campo o SIN `style`, el ticket es el de hoy aunque el payload traiga `seatNo`.
 */
use App\Support\EstiloTicket;

$txt = fn (string $b): string => sinFormato($b);
$con = fn (string $caso, array $campos, array $extra = []): string => correr($ctrl, $casos[$caso], V2([], $campos, $extra));

// ───────────── utilidades de fecha y hora ─────────────
$ok(EstiloTicket::partirFechaHora('23/10/2026 21:00') === ['23/10/2026', '21:00'], 'partirFechaHora: dd/mm/aaaa hh:mm');
$ok(EstiloTicket::partirFechaHora('23/10/2026 21:05:33') === ['23/10/2026', '21:05:33'], 'partirFechaHora: con segundos');
$ok(EstiloTicket::partirFechaHora('23/10/2026, 9:05:33 p. m.') === ['23/10/2026', '9:05:33 p. m.'], 'partirFechaHora: coma y «p. m.»');
$ok(EstiloTicket::partirFechaHora("23/10/2026,\u{00A0}9:05\u{202F}PM") === ['23/10/2026', "9:05\u{202F}PM"], 'partirFechaHora: espacios de no separación');
$ok(EstiloTicket::partirFechaHora('2026-10-23 21:00') === ['2026-10-23', '21:00'], 'partirFechaHora: aaaa-mm-dd');
$ok(EstiloTicket::partirFechaHora('mañana por la tarde') === null && EstiloTicket::partirFechaHora('23/10/2026') === null, 'partirFechaHora: lo que no reconoce da null (se imprime completo)');
$estilo = fn (array $campos) => EstiloTicket::normalizar(['style' => V2([], $campos)]);
$f = fn (?array $e) => EstiloTicket::fechaHora($e, 'cuenta', 'fecha_creacion', 'hora_creacion', '23/10/2026 21:00');
$ok($f(null) === ['junto' => '23/10/2026 21:00'], 'fechaHora: sin estilo, juntas como siempre');
$ok($f($estilo(['propina' => false])) === ['junto' => '23/10/2026 21:00'], 'fechaHora: estilo sin esos campos, juntas');
$ok($f($estilo(['hora_creacion' => false])) === ['fecha' => '23/10/2026'], 'fechaHora: hora apagada ⇒ solo fecha');
$ok($f($estilo(['fecha_creacion' => false, 'hora_creacion' => true])) === ['hora' => '21:00'], 'fechaHora: fecha apagada y hora encendida ⇒ solo hora');
$ok($f($estilo(['fecha_creacion' => false, 'hora_creacion' => false])) === [], 'fechaHora: las dos apagadas ⇒ nada');
$ok($f($estilo(['fecha_creacion' => false])) === [], 'fechaHora: fecha apagada y hora sin guardar ⇒ nada (la hora sigue a su fecha; no aparece sola)');
$ok(EstiloTicket::fechaHora($estilo(['hora_creacion' => false]), 'cuenta', 'fecha_creacion', 'hora_creacion', 'texto raro') === ['junto' => 'texto raro'], 'fechaHora: valor irreconocible ⇒ completo');

// ───────────── 1) fecha y hora en los tickets ─────────────
$sinHora = ['comanda' => '/Fecha: \d{2}\/\d{2}\/\d{4}\n/', 'cuenta' => '/Fecha Creacion: 23\/10\/2026\n/'];
$cubiertos['comanda']['hora'] = $cubiertos['cancelacion']['hora'] = $cubiertos['cuenta']['hora_creacion'] = $cubiertos['cuenta']['hora_impresion'] = true;
// comanda y cancelación (la fecha se arma en la PC del restaurante)
foreach (['comanda_restaurante', 'cancelacion'] as $caso) {
    $base = $txt(snap($caso));
    $ok(str_contains($base, 'Fecha: 01/01/2026 00:00'), "base de $caso: fecha y hora juntas");
    $s = $txt($con($caso, ['hora' => false]));
    $ok(preg_match('/Fecha: \d{2}\/\d{2}\/\d{4}\n/', $s) === 1 && !str_contains($s, 'Hora:'), "$caso: hora apagada ⇒ solo «Fecha: dd/mm/aaaa»");
    $s = $txt($con($caso, ['fecha' => false, 'hora' => true]));
    $ok(preg_match('/Hora: \d{2}:\d{2}:\d{2}\n/', $s) === 1 && !str_contains($s, 'Fecha:'), "$caso: fecha apagada y hora encendida ⇒ solo «Hora: hh:mm:ss»");
    $s = $txt($con($caso, ['fecha' => false, 'hora' => false]));
    $ok(!str_contains($s, 'Fecha:') && !str_contains($s, 'Hora:'), "$caso: las dos apagadas ⇒ ni fecha ni hora");
    $s = $txt($con($caso, ['fecha' => false]));
    $ok(!str_contains($s, 'Fecha:') && !str_contains($s, 'Hora:'), "$caso: fecha apagada y hora sin guardar ⇒ nada (compatible con quien ya la apagó)");
    $ok($txt($con($caso, ['fecha' => true, 'hora' => true])) === $base, "$caso: las dos encendidas = como hoy");
}
// cuenta / nota de venta / factura (la fecha llega armada en el payload)
foreach ([['cuenta', 'cuenta'], ['nota_venta', 'nota_qr_dias'], ['factura', 'factura']] as [$doc, $caso]) {
    $base = $txt(snap($caso));
    if (!str_contains($base, 'Fecha Creacion: 01/01/2026 00:00')) { $ok(true, "$caso: su payload de ejemplo no trae fechas (se prueba en cuenta y nota)"); continue; }
    $ord = $casos[$caso]['jobs'][0]['data']['orden'];
    foreach (['creacion' => ['Fecha Creacion', 'Hora Creacion', $ord['fechaCreacion']], 'impresion' => ['Fecha Impresi.n', 'Hora Impresi.n', $ord['fechaImpresion']]] as $cual => [$etFecha, $etHora, $valor]) {
        [$fecha, $hora] = EstiloTicket::partirFechaHora($valor); // la fecha y la hora que trae el propio payload de cada ticket
        $kf = "fecha_$cual"; $kh = "hora_$cual";
        $hay = fn (string $s, string $et) => preg_match('/' . $et . ': /s', $s) === 1;
        $s = $txt($con($caso, [$kh => false]));
        $ok(preg_match('/' . $etFecha . ': ' . preg_quote($fecha, '/') . '\n/s', $s) === 1 && !$hay($s, $etHora), "$doc: $kh=false ⇒ solo la fecha de $cual");
        $s = $txt($con($caso, [$kf => false, $kh => true]));
        $ok(preg_match('/' . $etHora . ': ' . preg_quote($hora, '/') . '\n/s', $s) === 1 && !$hay($s, $etFecha), "$doc: solo la hora de $cual");
        $s = $txt($con($caso, [$kf => false, $kh => false]));
        $ok(!$hay($s, $etFecha) && !$hay($s, $etHora), "$doc: sin fecha ni hora de $cual");
        $s = $txt($con($caso, [$kf => false]));
        $ok(!$hay($s, $etFecha) && !$hay($s, $etHora), "$doc: $kf=false y hora sin guardar ⇒ nada (como cuando solo existía la fecha)");
        $ok($txt($con($caso, [$kf => true, $kh => true])) === $base, "$doc: fecha y hora de $cual encendidas = como hoy");
    }
}

// ───────────── 3) líneas divisorias ─────────────
$linea = '/[-=]{20,}/';
foreach ([['comanda', 'comanda_restaurante'], ['cancelacion', 'cancelacion'], ['cuenta', 'cuenta'], ['nota_venta', 'nota_con_pago'], ['nota_venta', 'nota_qr_dias'], ['factura', 'factura']] as [$doc, $caso]) {
    $cubiertos[$doc]['divisores'] = true;
    $base = $txt(snap($caso));
    $ok(preg_match($linea, $base) === 1, "base de $caso tiene líneas divisorias");
    $s = $txt($con($caso, ['divisores' => false]));
    $ok(preg_match($linea, $s) !== 1, "$caso: divisores=false no deja ninguna línea de - ni de =");
    $ok(substr_count($s, "\n") >= substr_count($base, "\n") - 2 && str_contains($s, "\n\n"), "$caso: cada línea se cambió por un renglón en blanco (el ticket conserva su largo)");
    $ok($txt($con($caso, ['divisores' => true])) === $base, "$caso: divisores=true = como hoy");
}

// ───────────── 2) leyenda al final de la cuenta y la nota ─────────────
$cubiertos['cuenta']['leyenda_pie'] = true;
$leyenda = "Gracias por su visita\nSiguenos en @tacocapital";
$conLeyenda = fn (string $caso, array $extraStyle = [], array $campos = ['leyenda_pie' => true], ?string $texto = null) =>
    correr($ctrl, $casos[$caso], array_merge(V2([], $campos, ['textos' => ['leyenda_pie' => $texto ?? $leyenda]]), $extraStyle));
$base = $txt(snap('cuenta'));
$s = $conLeyenda('cuenta');
$t = $txt($s);
$ok(str_contains($t, 'Gracias por su visita') && str_contains($t, 'Siguenos en @tacocapital'), 'cuenta: la leyenda sale con sus dos renglones');
$ok(strpos($t, 'Gracias por su visita') > strpos($t, 'TOTAL:'), 'cuenta: la leyenda va al final, después del total');
$antes = substr($s, 0, strpos($s, 'Gracias por su visita'));
$ok(strrpos($antes, "\x1ba") !== false && $antes[strrpos($antes, "\x1ba") + 2] === "\x01", 'cuenta: la leyenda sale centrada');
$ok($txt($con('cuenta', ['leyenda_pie' => true])) === $base, 'cuenta: campo encendido pero sin texto = como hoy');
$ok($txt($conLeyenda('cuenta', [], ['leyenda_pie' => false])) === $base, 'cuenta: texto guardado con el campo apagado = como hoy');
$ok($txt($conLeyenda('cuenta', [], ['propina' => true])) === $base, 'cuenta: texto guardado sin la casilla = como hoy (def apagado)');
$ok($txt($conLeyenda('cuenta', [], ['leyenda_pie' => true], str_repeat('x', 201))) === $base, 'cuenta: un texto de más de 200 caracteres se ignora');
$ok($txt($conLeyenda('cuenta', [], ['leyenda_pie' => true], "a\nb\nc\nd\ne")) === $base, 'cuenta: un texto de más de 4 renglones se ignora');
$ok($txt($conLeyenda('cuenta', [], ['leyenda_pie' => true], "a\x07b\x1b[0m\n")) !== $base && !str_contains($conLeyenda('cuenta', [], ['leyenda_pie' => true], "a\x07b\x1b[0m\n"), "\x07"), 'cuenta: los caracteres de control no pasan al ticket');
// partida al ancho del papel
$largo = 'Gracias por elegirnos, esperamos verte muy pronto de regreso en tu restaurante favorito';
$t32 = $txt($conLeyenda('cuenta', ['columnas' => 32], ['leyenda_pie' => true], $largo));
$renglones = array_filter(explode("\n", $t32), fn ($l) => preg_match('/Gracias|esperamos|restaurante|favorito|regreso/', $l));
$ok(count($renglones) >= 3 && max(array_map('strlen', $renglones)) <= 32, 'cuenta: la leyenda larga se parte por palabras a 32 columnas');
// nota de venta: al final de su pie, antes de la marca
$cubiertos['nota_venta']['leyenda_pie'] = true;
$tn = $txt($conLeyenda('nota_con_pago', [], ['leyenda_pie' => true]));
$ok(str_contains($tn, 'Gracias por su visita'), 'nota de venta: lleva la leyenda');
$ok(strpos($tn, 'Gracias por su visita') > strpos($tn, 'ESCANEA EL SIGUIENTE') && strpos($tn, 'Gracias por su visita') < strpos($tn, 'POS GROWTHSUITE'), 'nota de venta: la leyenda va después del QR y antes de la marca');
$ok($txt($con('nota_con_pago', ['leyenda_pie' => true])) === $txt(snap('nota_con_pago')), 'nota de venta: sin texto = como hoy');
// donde no existe el campo, el texto no hace nada
foreach (['comanda_restaurante', 'cancelacion', 'factura', 'corte_x'] as $caso) {
    $ok($txt($conLeyenda($caso, [], ['leyenda_pie' => true])) === $txt(snap($caso)), "$caso: no tiene leyenda; un texto guardado no cambia nada");
}

// ───────────── 4) comensal en la comanda ─────────────
$cubiertos['comanda']['comensales'] = true;
$datosComanda = $casos['comanda_restaurante']['jobs'][0]['data'];
$itemsComanda = [
    ['qty' => 2, 'name' => 'Hamburguesa clasica', 'course' => 1, 'notes' => 'sin cebolla', 'isCompositeProductMain' => true, 'compositeProductId' => 'L1', 'seatNo' => 2],
    ['isModifier' => true, 'compositeProductId' => 'L1', 'name' => 'Queso extra', 'qty' => 2, 'seatNo' => 2],
    ['qty' => 1, 'name' => 'Ensalada', 'course' => 2],
    ['qty' => 1, 'name' => 'Papas', 'course' => 1, 'seatNo' => 1],
];
$sinAsientos = array_map(function ($i) { unset($i['seatNo']); return $i; }, $itemsComanda);
$caso = fn (array $items, string $ep = 'printComanda') => ['ep' => $ep, 'jobs' => [['printerName' => 'PRUEBA', 'data' => array_merge($datosComanda, ['items' => $items])]]];
$base = correr($ctrl, $caso($sinAsientos), null);
$ok(correr($ctrl, $caso($itemsComanda), null) === $base, 'comanda: sin style, un seatNo en los renglones NO cambia ni un byte');
$ok(correr($ctrl, $caso($itemsComanda), V2([], [])) === correr($ctrl, $caso($sinAsientos), V2([], [])), 'comanda: con style pero sin el campo, el seatNo no cambia nada');
$ok(correr($ctrl, $caso($itemsComanda), V2([], ['comensales' => false])) === correr($ctrl, $caso($sinAsientos), V2([], ['comensales' => false])), 'comanda: campo apagado, el seatNo no cambia nada');
$ok($txt(correr($ctrl, $caso($sinAsientos), V2([], ['comensales' => true]))) === $txt(correr($ctrl, $caso($sinAsientos), V2([], []))), 'comanda: campo encendido pero ningún renglón con comensal = el mismo texto de siempre');
$agrupada = $txt(correr($ctrl, $caso($itemsComanda), V2([], ['comensales' => true])));
$pos = fn (string $m) => strpos($agrupada, $m);
$ok($pos('COMENSAL 1') !== false && $pos('COMENSAL 2') !== false && $pos('MESA') !== false, 'comanda: salen los encabezados COMENSAL 1, COMENSAL 2 y MESA');
$ok($pos('COMENSAL 1') < $pos('Papas') && $pos('Papas') < $pos('COMENSAL 2') && $pos('COMENSAL 2') < $pos('Hamburguesa') && $pos('Hamburguesa') < $pos('MESA') && $pos('MESA') < $pos('Ensalada'), 'comanda: cada producto queda bajo su comensal y lo compartido al final');
$ok($pos('Queso extra') > $pos('Hamburguesa') && $pos('Queso extra') < $pos('MESA'), 'comanda: el modificador se queda con su platillo');
$ok(substr_count($agrupada, 'COMENSAL 2') === 1, 'comanda: un comensal con varios productos lleva un solo encabezado');
// la cancelación no se agrupa aunque traiga seatNo
$datosCancel = $casos['cancelacion']['jobs'][0]['data'];
$cancel = fn (array $items) => ['ep' => 'printCancelacion', 'jobs' => [['printerName' => 'PRUEBA', 'data' => array_merge($datosCancel, ['items' => $items])]]];
$ic = $datosCancel['items'];
$icSeat = array_map(function ($i) { $i['seatNo'] = 1; return $i; }, $ic);
$ok(correr($ctrl, $cancel($icSeat), V2([], ['comensales' => true])) === correr($ctrl, $cancel($ic), V2([], ['comensales' => true])), 'cancelación: no agrupa por comensal');

// ───────────── 4b) subtotal por comensal en la cuenta ─────────────
$cubiertos['cuenta']['desglose_por_comensal'] = true;
$datosCuenta = $casos['cuenta']['jobs'][0]['data'];
$itemsCuenta = $datosCuenta['items'];
$conAsiento = $itemsCuenta; $conAsiento[0]['seatNo'] = 1; $conAsiento[1]['seatNo'] = 2; // el tercero queda sin comensal
$cuentaCon = fn (array $items) => ['ep' => 'printConsumo', 'jobs' => [['printerName' => 'PRUEBA', 'data' => array_merge($datosCuenta, ['items' => $items])]]];
$base = correr($ctrl, $cuentaCon($itemsCuenta), null);
$ok(correr($ctrl, $cuentaCon($conAsiento), null) === $base, 'cuenta: sin style, un seatNo en los renglones NO cambia ni un byte');
$ok(correr($ctrl, $cuentaCon($conAsiento), V2([], [])) === correr($ctrl, $cuentaCon($itemsCuenta), V2([], [])), 'cuenta: con style pero sin el campo, el seatNo no cambia nada');
$ok(correr($ctrl, $cuentaCon($conAsiento), V2([], ['desglose_por_comensal' => false])) === correr($ctrl, $cuentaCon($itemsCuenta), V2([], ['desglose_por_comensal' => false])), 'cuenta: campo apagado, el seatNo no cambia nada');
$ok($txt(correr($ctrl, $cuentaCon($itemsCuenta), V2([], ['desglose_por_comensal' => true]))) === $txt(correr($ctrl, $cuentaCon($itemsCuenta), V2([], []))), 'cuenta: campo encendido pero ningún renglón con comensal = el mismo texto de siempre');
$d = $txt(correr($ctrl, $cuentaCon($conAsiento), V2([], ['desglose_por_comensal' => true])));
$ok(preg_match('/Comensal 1 +\$70\.00/', $d) === 1 && preg_match('/Comensal 2 +\$25\.00/', $d) === 1 && preg_match('/Mesa \(compartido\) +\$30\.00/', $d) === 1, 'cuenta: una línea por comensal con su importe y «Mesa (compartido)»');
$ok(strpos($d, 'Comensal 1') < strpos($d, 'Comensal 2') && strpos($d, 'Comensal 2') < strpos($d, 'Mesa (compartido)') && strpos($d, 'Mesa (compartido)') < strpos($d, 'SUBTOTAL'), 'cuenta: el desglose va en orden y antes del subtotal');
$ok(strpos($d, 'Postre') < strpos($d, 'Comensal 1'), 'cuenta: el desglose va después de los productos');
$dn = $txt(correr($ctrl, ['ep' => 'printNotaVenta', 'jobs' => [['printerName' => 'PRUEBA', 'restaurantId' => '99', 'data' => array_merge($datosCuenta, ['items' => $conAsiento])]]], V2([], ['desglose_por_comensal' => true])));
$ok(str_contains($dn, 'Comensal 2'), 'nota de venta: también lleva el desglose por comensal');
$df = $txt(correr($ctrl, $casos['factura'], V2([], ['desglose_por_comensal' => true])));
$ok(!str_contains($df, 'Comensal'), 'factura: no lleva desglose por comensal');
