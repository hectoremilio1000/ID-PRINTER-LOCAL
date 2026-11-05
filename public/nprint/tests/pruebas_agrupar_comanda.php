<?php
/**
 * «Juntar productos idénticos» de la comanda (campo comanda.agrupar_productos; se incluye desde pruebas_estilo.php: usa $ctrl, $ok,
 * V2(), correr(), sinFormato() y $cubiertos). Sin el campo (o apagado) la comanda sale como siempre.
 */
$cubiertos['comanda']['agrupar_productos'] = true;

$comanda = function (array $items, array $extra = []): array {
    return ['ep' => 'printComanda', 'jobs' => [[
        'printerName' => 'COCINA',
        'data' => array_merge(['areaName' => 'Cocina', 'tableName' => 'M01', 'waiterName' => 'Ana', 'tipo' => 1, 'items' => $items], $extra),
    ]]];
};
$salida = fn (array $c, ?array $estilo): string => sinFormato(correr($ctrl, $c, $estilo));
$activo = V2([], ['agrupar_productos' => true]);
$lineas = fn (string $s): array => array_values(array_filter(array_map('trim', explode("\n", $s)), fn ($l) => $l !== ''));
$tiene = fn (string $s, string $linea): bool => in_array($linea, $lineas($s), true);

$taco = ['name' => 'Taco', 'course' => 1];
$items = [
    $taco + ['qty' => 3],
    ['qty' => 1, 'name' => 'Cafe', 'course' => 1],
    $taco + ['qty' => 2],
];
$c = $comanda($items);

// apagado, ausente o sin estilo: sale igual que hoy (3 renglones)
$base = $salida($c, null);
$ok($tiene($base, '3 Taco') && $tiene($base, '2 Taco') && $tiene($base, '1 Cafe'), 'agrupar_productos: sin estilo no junta nada');
$ok($salida($c, V2([], ['agrupar_productos' => false])) === $salida($c, V2()), 'agrupar_productos=false = campo sin guardar (como hoy)');
$ok($salida($c, V2()) === $salida($c, V2([], ['agrupar_productos' => false])) && !$tiene($salida($c, V2()), '5 Taco'), 'agrupar_productos: apagado por defecto');

// encendido: 3 + 2 = 5, en la posición de la primera aparición
$s = $salida($c, $activo);
$ok($tiene($s, '5 Taco') && $tiene($s, '1 Cafe') && !$tiene($s, '3 Taco') && !$tiene($s, '2 Taco'), 'agrupar_productos: 3 + 2 = 5 Taco, el café queda aparte');
$ok(strpos($s, '5 Taco') < strpos($s, '1 Cafe'), 'agrupar_productos: conserva el orden de la primera aparición');

// distinto en algo = no se junta (nota, tiempo, comensal, mitad)
$distintos = [
    'nota distinta' => [$taco + ['qty' => 1, 'notes' => 'sin cebolla'], $taco + ['qty' => 1]],
    'tiempo distinto' => [$taco + ['qty' => 1], ['qty' => 1, 'name' => 'Taco', 'course' => 2]],
    'comensal distinto' => [$taco + ['qty' => 1, 'seatNo' => 1], $taco + ['qty' => 1, 'seatNo' => 2]],
    'mitad distinta' => [$taco + ['qty' => 1, 'half' => 1], $taco + ['qty' => 1, 'half' => 2]],
];
foreach ($distintos as $motivo => $its) {
    $s = $salida($comanda($its), $activo);
    $ok(substr_count($s, 'Taco') === 2 && !$tiene($s, '2 Taco'), "agrupar_productos: $motivo no se junta");
}
// misma nota y mayúsculas/espacios distintos sí se juntan, y la nota se imprime una vez
$s = $salida($comanda([$taco + ['qty' => 1, 'notes' => 'Sin  cebolla'], ['name' => ' TACO ', 'course' => 1, 'qty' => 2, 'notes' => 'sin cebolla']]), $activo);
$ok($tiene($s, '3 Taco') && substr_count($s, 'sin cebolla') + substr_count($s, 'Sin  cebolla') === 1, 'agrupar_productos: misma nota (sin importar mayúsculas ni espacios) se junta y se imprime una vez');

// producto con modificadores: se junta solo si lleva EXACTAMENTE los mismos (y en la misma cantidad por pieza)
$mod = fn (string $id, string $nombre, $qty = 1, array $extra = []): array => ['isModifier' => true, 'compositeProductId' => $id, 'name' => $nombre, 'qty' => $qty] + $extra;
$principal = fn (string $id, $qty = 1, string $nombre = 'Taco carnitas', array $extra = []): array => ['qty' => $qty, 'name' => $nombre, 'course' => 1, 'isCompositeProductMain' => true, 'compositeProductId' => $id] + $extra;

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1'), $mod('A', 'Mod2'), $principal('B'), $mod('B', 'Mod1'), $mod('B', 'Mod2')]), $activo);
$ok($tiene($s, '2 Taco carnitas') && substr_count($s, 'Taco carnitas') === 1 && $tiene($s, '1 x Mod1') && $tiene($s, '1 x Mod2') && substr_count($s, 'Mod1') === 1, 'agrupar_productos: mismos modificadores y cantidad = suma el producto, los modificadores siguen por pieza');

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1'), $mod('A', 'Mod2'), $principal('B'), $mod('B', 'Mod2'), $mod('B', 'Mod1')]), $activo);
$ok($tiene($s, '2 Taco carnitas'), 'agrupar_productos: el orden en que se eligieron los modificadores no importa');

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1'), $principal('B'), $mod('B', 'Mod2')]), $activo);
$ok(substr_count($s, 'Taco carnitas') === 2 && !$tiene($s, '2 Taco carnitas'), 'agrupar_productos: modificadores distintos salen aparte');

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1'), $principal('B'), $mod('B', 'Mod1', 2)]), $activo);
$ok(substr_count($s, 'Taco carnitas') === 2 && $tiene($s, '1 x Mod1') && $tiene($s, '2 x Mod1'), 'agrupar_productos: misma opción en otra cantidad sale aparte');

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1'), $principal('B')]), $activo);
$ok(substr_count($s, 'Taco carnitas') === 2, 'agrupar_productos: con modificador y sin modificador salen aparte');

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1', 1, ['half' => 2]), $principal('B'), $mod('B', 'Mod1', 1, ['half' => 3])]), $activo);
$ok(substr_count($s, 'Taco carnitas') === 2, 'agrupar_productos: la misma opción en otra mitad sale aparte');

$s = $salida($comanda([$principal('A'), $mod('A', 'Mod1', 1, ['notes' => 'poco']), $principal('B'), $mod('B', 'Mod1', 1, ['notes' => 'mucho'])]), $activo);
$ok(substr_count($s, 'Taco carnitas') === 2, 'agrupar_productos: el mismo modificador con otra nota sale aparte');

$s = $salida($comanda([$principal('A', 2), $mod('A', 'Mod1', 2), $principal('B', 1), $mod('B', 'Mod1', 1)]), $activo);
$ok($tiene($s, '3 Taco carnitas') && $tiene($s, '1 x Mod1') && substr_count($s, 'Mod1') === 1, 'agrupar_productos: la comparación es por pieza (2 con 2 = 1 con 1)');

$sola = $comanda([$principal('A', 2), $mod('A', 'Mod1', 2)]);
$ok($tiene($salida($sola, $activo), '2 x Mod1') && $salida($sola, $activo) === $salida($sola, V2()), 'agrupar_productos: un renglón que no se junta con nadie sale igual que hoy');

// apagado: dos renglones con claves distintas no cambian
$dos = $comanda([$principal('A'), $mod('A', 'Mod1'), $principal('B'), $mod('B', 'Mod1')]);
$ok(substr_count($salida($dos, V2()), 'Taco carnitas') === 2, 'agrupar_productos apagado: productos con modificadores salen separados');

// modificadores «sueltos» (Caja, restaurante normal): renglones con sangría, sin clave. El producto no puede juntarse sin perder de quién son
$suelto = fn (string $nombre): array => ['qty' => 1, 'name' => '  ' . $nombre, 'course' => 1, 'half' => 1];
$s = $salida($comanda([['qty' => 1, 'name' => 'Taco', 'course' => 1], $suelto('Mod1'), ['qty' => 1, 'name' => 'Taco', 'course' => 1], $suelto('Mod2')]), $activo);
$ok(substr_count($s, 'Taco') === 2 && !$tiene($s, '2 Taco') && strpos($s, 'Mod1') < strpos($s, 'Mod2'), 'agrupar_productos: modificadores sueltos no se juntan ni se separan de su producto');

// cantidad no numérica o fraccionaria
$s = $salida($comanda([['qty' => 'x', 'name' => 'Raro', 'course' => 1], ['qty' => 'x', 'name' => 'Raro', 'course' => 1]]), $activo);
$ok(substr_count($s, 'Raro') === 2, 'agrupar_productos: cantidad no numérica se imprime tal cual, sin juntar');
$s = $salida($comanda([['qty' => 0.5, 'name' => 'Alitas', 'course' => 1], ['qty' => 1.5, 'name' => 'Alitas', 'course' => 1]]), $activo);
$ok($tiene($s, '2 Alitas'), 'agrupar_productos: 0.5 + 1.5 = 2 (sin decimales sobrantes)');

// la cancelación no usa este campo (otro tipo de ticket): no junta
if (isset($casos['cancelacion'])) {
    $cc = $casos['cancelacion'];
    $ok($salida($cc, V2([], ['agrupar_productos' => true])) === $salida($cc, V2()), 'agrupar_productos: no afecta al ticket de cancelación (mismo texto)');
}
