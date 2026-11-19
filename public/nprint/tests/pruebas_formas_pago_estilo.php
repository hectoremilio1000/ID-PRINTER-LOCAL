<?php
/**
 * Formas de pago en tabla y Terminal dentro de la suite de estilos (se incluye desde pruebas_estilo.php; usa $ctrl, $casos,
 * $ok, $cubiertos, correr(), snap(), contiene(), V2()). Detalle completo en tests/pruebas_formas_pago_tabla.php.
 */
foreach (['nota_venta' => 'nota_con_pago', 'factura' => 'factura'] as $doc => $caso) {
    $c = $casos[$caso];
    foreach ($c['jobs'] as &$j) $j['data']['orden']['terminal'] = 'Caja 9';
    unset($j);
    $base = correr($ctrl, $c, null);
    $ok(!contiene($base, '/Propina +Cambio/') && !contiene($base, 'Terminal:'), "$doc: tabla y terminal apagadas por defecto");
    $ok(contiene(correr($ctrl, $c, V2([], ['formas_pago_tabla' => true])), '/Propina +Cambio/'), "$doc: formas_pago_tabla=true imprime la tabla");
    $ok(contiene(correr($ctrl, $c, V2([], ['terminal' => true])), '/Terminal: +Caja 9/'), "$doc: terminal=true imprime «Terminal: Caja 9»");
    $cubiertos[$doc]['formas_pago_tabla'] = true;
    $cubiertos[$doc]['terminal'] = true;
}
