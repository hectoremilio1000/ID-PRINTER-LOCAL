<?php
// Payloads de ejemplo (datos ficticios, sin acentos en renglones de producto para que PHP 8.1 y 8.5 partan igual)
// para las pruebas golden de estilos de impresión. Cada caso: endpoint real del controlador + jobs.
$v1 = require __DIR__ . '/casos_v1.php';
$job = fn(array $data, array $extra = []) => array_merge(['printerName' => 'PRUEBA', 'data' => $data], $extra);

$restaurante = ['nombre' => 'RESTAURANTE LAB', 'rfc' => 'XAXX010101000', 'cp' => '06600', 'direccion' => 'Calle Prueba 123, CDMX', 'tel' => '5555555555'];
$cuentaBase = $v1['cuenta']['data'];
$notaBase = $v1['nota_con_pago']['data'];

return [
    // ── cuenta / nota / factura ──
    'cuenta' => ['ep' => 'printConsumo', 'doc' => 'cuenta', 'jobs' => [$job($cuentaBase)]],
    'nota_con_pago' => ['ep' => 'printNotaVenta', 'doc' => 'nota_venta', 'jobs' => [$job($notaBase, ['restaurantId' => '99'])]],
    'nota_qr_dias' => ['ep' => 'printNotaVenta', 'doc' => 'nota_venta', 'jobs' => [$job(array_merge($cuentaBase, ['diasParaFacturar' => 3]), ['restaurantId' => '99'])]],
    'factura' => ['ep' => 'printFactura', 'doc' => 'factura', 'jobs' => [$job(array_merge($notaBase, ['factura' => [
        'legalName' => 'CLIENTE DE PRUEBA SA', 'taxId' => 'XEXX010101000', 'series' => 'A', 'folioNumber' => 77,
        'uuid' => '11111111-2222-3333-4444-555555555555', 'pdfUrl' => 'https://example.com/factura.pdf',
    ]]))]],
    // ── comanda / cancelación ──
    'comanda_restaurante' => ['ep' => 'printComanda', 'doc' => 'comanda', 'jobs' => [$job($v1['comanda_restaurante']['data'])]],
    'comanda_antro' => ['ep' => 'printComanda', 'doc' => 'comanda', 'jobs' => [$job($v1['comanda_antro']['data'])]],
    'cancelacion' => ['ep' => 'printCancelacion', 'doc' => 'cancelacion', 'jobs' => [$job($v1['cancelacion']['data'])]],
    // ── cuenta dividida ──
    'split_preview' => ['ep' => 'printSplitPreview', 'doc' => 'cuenta', 'jobs' => [$job(array_merge($cuentaBase, ['splits' => [
        ['payerName' => 'Ana', 'amount' => 50, 'tipAmount' => 5], ['payerName' => 'Beto', 'amount' => 45],
    ]]))]],
    'split_final' => ['ep' => 'printSplitFinal', 'doc' => 'cuenta', 'jobs' => [$job(array_merge($cuentaBase, ['splits' => [
        ['payerName' => 'Ana', 'amount' => 50, 'tipAmount' => 5, 'paymentMethod' => 'Efectivo'], ['payerName' => 'Beto', 'amount' => 45, 'paymentMethod' => 'Tarjeta'],
    ]]))]],
    // ── propinas / comisiones / movimientos ──
    'propinas' => ['ep' => 'printPropinas', 'doc' => 'propinas', 'jobs' => [$job(['propinas' => [
        ['waiterId' => 1, 'waiterFullName' => 'Ana Lopez', 'orderId' => 101, 'tableName' => 'M01', 'amount' => 20, 'collected' => 20, 'paid' => 20, 'sale' => 200],
        ['waiterId' => 1, 'waiterFullName' => 'Ana Lopez', 'orderId' => 102, 'tableName' => 'M02', 'amount' => 15, 'collected' => 15, 'paid' => 0, 'sale' => 150],
        ['waiterId' => 2, 'waiterFullName' => 'Beto Ruiz', 'orderId' => 103, 'tableName' => 'M03', 'amount' => 30, 'collected' => 30, 'paid' => 30],
    ]], ['printName' => 'Cierre de turno'])]],
    'propinas_vacio' => ['ep' => 'printPropinas', 'doc' => 'propinas', 'jobs' => [$job(['propinas' => []])]],
    'comisiones' => ['ep' => 'printComisiones', 'doc' => 'comisiones', 'jobs' => [$job(['turno' => 12, 'pagadoPor' => 'Luis', 'comisiones' => [
        ['waiterId' => 1, 'waiterName' => 'Ana Lopez', 'amount' => 100, 'metodo' => 'Efectivo', 'totalEarned' => 300, 'totalPaid' => 200, 'pending' => 100],
        ['waiterId' => 2, 'waiterName' => 'Beto Ruiz', 'amount' => 50, 'metodo' => 'Efectivo'],
    ]])]],
    'comisiones_vacio' => ['ep' => 'printComisiones', 'doc' => 'comisiones', 'jobs' => [$job(['comisiones' => []])]],
    'movimientos' => ['ep' => 'printMovtos', 'doc' => 'movimientos', 'jobs' => [$job([
        'type' => 'OUT', 'amount' => 250.5, 'reason' => 'Compra de hielo', 'shiftId' => 140, 'stationId' => 3, 'printerStationName' => 'Caja 1',
        'authorizedBy' => 'Gerente', 'receivedBy' => 'Mozo', 'createdAt' => '2026-10-23T21:05:00-06:00',
    ])]],
    // ── corte X ──
    'corte_x' => ['ep' => 'printCorteX', 'doc' => 'corte_x', 'jobs' => [$job([
        'company' => ['name' => 'RESTAURANTE LAB', 'rfc' => 'XAXX010101000', 'address' => 'Calle Prueba 123'],
        'shift' => ['id' => 140, 'openedAt' => '2026-10-23T12:00:00-06:00', 'closedAt' => '2026-10-23T23:30:00-06:00', 'stationName' => 'Caja 1'],
        'summary' => ['cashierNames' => ['Luis', 'Mara'], 'closedCount' => 20, 'voidCount' => 1, 'discountCount' => 2, 'courtesyCount' => 1, 'avgTicket' => 210.5, 'avgConsumption' => 190, 'guests' => 48, 'tipsTotal' => 300, 'folioFrom' => 'F001-1', 'folioTo' => 'F001-20', 'totalCourtesy' => 30, 'totalDiscounts' => 45],
        'totals' => ['subtotal' => 3600, 'discounts' => 45, 'net' => 3555, 'taxes' => [['rateLabel' => '16%', 'base' => 3555, 'tax' => 568.8]], 'taxesTotal' => 568.8, 'gross' => 4123.8],
        'declarations' => ['byMethod' => [['name' => 'Efectivo', 'declared' => 2000, 'expected' => 2010, 'difference' => -10]], 'totalDeclared' => 2000, 'totalDifference' => -10],
        'salesByMethod' => [['name' => 'Efectivo', 'salesAmount' => 2000], ['name' => 'Tarjeta', 'salesAmount' => 2123.8]],
        'tipsByMethod' => [['name' => 'Tarjeta', 'tipAmount' => 300]],
        'byCategory' => [['name' => 'Alimentos', 'pct' => 0.6, 'salesAmount' => 2133, 'salesCount' => 80], ['name' => 'Bebidas', 'pct' => 0.4, 'salesAmount' => 1422, 'salesCount' => 60]],
        'byService' => [['name' => 'Comedor', 'pct' => 1, 'salesAmount' => 3555]],
        'courtesyByCategory' => [['name' => 'Alimentos', 'amount' => 30]],
        'discountByCategory' => [['name' => 'Bebidas', 'amount' => 45]],
        'openingCash' => 500, 'cashDeposits' => 0, 'tipsPaid' => 100, 'commissionsPaid' => 0, 'cashWithdrawals' => 250, 'saldoFinal' => 2150, 'cashFinal' => 2150,
        'cajas' => [[
            'station' => ['name' => 'Caja 1', 'mode' => 'MASTER'], 'responsible' => 'Luis', 'openedAt' => '2026-10-23T12:00:00-06:00', 'closedAt' => '2026-10-23T23:30:00-06:00', 'closedBy' => 'Luis',
            'openingCash' => 500, 'methods' => [['name' => 'Efectivo', 'sales' => 2000, 'tips' => 0, 'declared' => 2000, 'expected' => 2010, 'difference' => -10]],
            'summary' => ['ordersCount' => 20, 'salesGross' => 4123.8], 'operational' => ['persons' => 48], 'operators' => [['name' => 'Luis', 'isResponsible' => true, 'salesTotal' => 4123.8]],
        ]],
    ])]],
    // ── reporte / cierre de caja ──
    'reporte_caja' => ['ep' => 'printReporteCaja', 'doc' => 'reporte_caja', 'jobs' => [$job(require __DIR__ . '/sesion_caja.php')]],
    'cierre_caja' => ['ep' => 'printCierreCaja', 'doc' => 'cierre_caja', 'jobs' => [$job(array_merge(require __DIR__ . '/sesion_caja.php', [
        'openingCash' => 500, 'expectedCash' => 2200, 'closing' => ['closingCash' => 2190, 'difference' => -10],
        'operational' => [
            'totals' => ['net' => 3555, 'tax' => 568.8, 'gross' => 4123.8], 'orders' => 20, 'dishes' => 140, 'persons' => 48, 'avgDishesPerOrder' => 7, 'avgPerOrder' => 206.19, 'avgPerPerson' => 85.9,
            'byService' => [['name' => 'Comedor', 'orders' => 20, 'sales' => 4123.8]],
            'courtesies' => ['dishes' => 1, 'dishesAmount' => 30, 'accounts' => 0], 'cancellations' => ['dishes' => 2, 'dishesAmount' => 60, 'accounts' => 1, 'accountsAmount' => 120],
            'discounts' => ['dishes' => 3, 'dishesAmount' => 45, 'accounts' => 2, 'accountsAmount' => 20],
        ],
    ]))]],
];
