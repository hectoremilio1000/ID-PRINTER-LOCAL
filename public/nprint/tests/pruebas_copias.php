<?php
/**
 * Copias del ticket (se incluye desde golden_estilos.php; usa $ctrl, $casos, $ok, correr(), snap()).
 * `style.copias` (1 a 3, solo v2) repite el ticket COMPLETO (con su corte) dentro del mismo trabajo, por impresora.
 *   - sin copias, copias=1, v1 o valores inválidos => bytes idénticos al snapshot de origin/main
 *   - copias=2 y 3 => cada trabajo (impresora) sale 2 y 3 veces seguidas, bytes idénticos entre copias
 */
use App\Support\EstiloTicket;

$separador = "\n#--trabajo--\n";
/** Cada trabajo (impresora) repetido $n veces, como debe salir con copias=$n. */
$repetido = function (string $salida, int $n) use ($separador): string {
    return implode($separador, array_map(fn ($t) => str_repeat($t, $n), explode($separador, $salida)));
};
$corte = "\x1d\x56"; // GS V: corte (cualquier variante empieza igual)

// EstiloTicket::copias: solo v2 y entero de 1 a 3
$c = fn ($style) => EstiloTicket::copias(['style' => $style]);
$ok($c(['v' => 2, 'copias' => 2]) === 2 && $c(['v' => 2, 'copias' => 3]) === 3 && $c(['v' => 2, 'copias' => 1]) === 1, 'EstiloTicket::copias: 1, 2 y 3 en v2');
$ok($c(['v' => 1, 'copias' => 2]) === 1, 'EstiloTicket::copias: v1 no admite copias');
foreach ([0, 4, -1, 2.5, '2', null, true, [2]] as $i => $malo) {
    $ok($c(['v' => 2, 'copias' => $malo]) === 1, "EstiloTicket::copias: valor inválido #$i => 1");
}
$ok(EstiloTicket::copias(null) === 1 && EstiloTicket::copias([]) === 1 && EstiloTicket::copias(['style' => 'x']) === 1, 'EstiloTicket::copias: sin estilo => 1');
$ok(EstiloTicket::normalizar(['style' => ['v' => 2, 'partes' => [], 'copias' => 3]]) === null, 'copias sola no activa el camino con estilo (el ticket conserva sus bytes)');

foreach ($casos as $nombre => $caso) {
    $base = snap($nombre);
    // 1 copia, sin copias o inválidas: igual que hoy
    foreach ([['v' => 2, 'partes' => [], 'copias' => 1], ['v' => 1, 'partes' => [], 'copias' => 3], ['v' => 2, 'partes' => [], 'copias' => 4], ['v' => 2, 'partes' => [], 'copias' => '2'], ['v' => 2, 'partes' => [], 'copias' => 0]] as $i => $st) {
        $ok(correr($ctrl, $caso, $st) === $base, "copias inválida o 1 (#$i) deja el papel idéntico a hoy: $nombre");
    }
    // La cuenta dividida (split) usa el estilo de «cuenta» pero NO repite: son un consolidado + un voucher por persona, y repetirlos
    // duplicaría todo el paquete. Con copias queda idéntica a hoy.
    if (str_starts_with($nombre, 'split_')) {
        $ok(correr($ctrl, $caso, ['v' => 2, 'partes' => [], 'copias' => 3]) === $base, "copias no repite la cuenta dividida (sale como hoy): $nombre");
        continue;
    }
    // 2 y 3 copias: cada trabajo repetido, sin cambiar un solo byte de cada copia
    foreach ([2, 3] as $n) {
        $con = correr($ctrl, $caso, ['v' => 2, 'partes' => [], 'copias' => $n]);
        $ok($con === $repetido($base, $n), "copias=$n repite cada trabajo $n veces, idéntico: $nombre");
        $ok(substr_count($con, $corte) === $n * substr_count($base, $corte), "copias=$n: $n cortes por cada corte de hoy: $nombre");
    }
}

// Con un estilo real (campos y partes) las copias son N veces ese mismo ticket
foreach (['cuenta', 'comanda_restaurante', 'nota_con_pago', 'factura', 'propinas', 'movimientos'] as $nombre) {
    $sin = correr($ctrl, $casos[$nombre], V2(['titulo' => ['ancho' => 2, 'alto' => 2, 'negrita' => true, 'alineacion' => 'centro']], ['propina' => false]));
    $con = correr($ctrl, $casos[$nombre], V2(['titulo' => ['ancho' => 2, 'alto' => 2, 'negrita' => true, 'alineacion' => 'centro']], ['propina' => false], ['copias' => 2]));
    $ok($sin !== snap($nombre) && $con === $repetido($sin, 2), "estilo real + copias=2 = el mismo ticket dos veces: $nombre");
}
