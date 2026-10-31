<?php
// Respuesta de ejemplo de GET /shifts/:id/session-report (datos ficticios) para reporte_caja / cierre_caja
return [
    'restaurante' => 'RESTAURANTE LAB',
    'station' => ['name' => 'Caja 1', 'code' => 'C1'],
    'session' => ['cashUserName' => 'Luis', 'openedAt' => '2026-10-23T12:00:00-06:00', 'closedAt' => '2026-10-23T23:30:00-06:00', 'closedByName' => 'Luis'],
    'textos' => ['apertura' => '23/10/2026 12:00', 'cierre' => '23/10/2026 23:30', 'impreso' => '23/10/2026 23:31'],
    'openingCash' => 500, 'expectedCash' => 2200,
    'methods' => [
        ['paymentMethodName' => 'Efectivo', 'sales' => 2000, 'refunds' => 50, 'tips' => 0, 'expected' => 2200, 'declared' => 2190, 'difference' => -10],
        ['paymentMethodName' => 'Tarjeta', 'sales' => 2123.8, 'tips' => 300, 'expected' => 2423.8, 'declared' => 2423.8, 'difference' => 0],
    ],
    'movements' => ['in' => 0, 'out' => 250, 'drops' => 0, 'adjusts' => 0, 'tipPayouts' => 100, 'commissionPayouts' => 0, 'net' => -350,
        'items' => [['hora' => '20:15', 'type' => 'OUT', 'reason' => 'Hielo', 'actorName' => 'Luis', 'amount' => 250]]],
    'operators' => [['name' => 'Luis', 'isResponsible' => true, 'paymentsCount' => 20, 'salesTotal' => 4123.8, 'tipsTotal' => 300], ['name' => 'Mara', 'paymentsCount' => 3, 'salesTotal' => 400]],
    'personalDeclarations' => [
        ['cashierName' => 'Luis', 'paymentMethodName' => 'Efectivo', 'declared' => true, 'isFinal' => true, 'salesDeclared' => 2000, 'tipsDeclared' => 0, 'difference' => -10],
        ['cashierName' => 'Mara', 'paymentMethodName' => 'Tarjeta', 'declared' => false],
    ],
    'summary' => ['ordersCount' => 20, 'salesGross' => 4123.8, 'avgTicket' => 206.19],
];
