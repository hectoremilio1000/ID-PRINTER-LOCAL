<?php
// Payloads de ejemplo (datos ficticios) para las pruebas golden de estilos de impresión.
return [
    'cuenta' => [
        'metodo' => 'receipt',
        'data' => [
            'restaurante' => ['nombre' => 'RESTAURANTE LAB', 'rfc' => 'XAXX010101000', 'cp' => '06600', 'direccion' => 'Calle Prueba 123, CDMX', 'tel' => '5555555555'],
            'orden' => ['mesa' => 'M01', 'mesero' => 'Ana', 'personas' => 2, 'orden' => 18218, 'folioSerie' => 'F001', 'folioNumber' => 40, 'fechaCreacion' => '23/10/2026 21:00', 'fechaImpresion' => '23/10/2026 21:20', 'cajero' => 'Luis'],
            'items' => [
                ['cantidad' => 2, 'descripcion' => 'Taco al pastor con piña y cilantro extra grande', 'importe' => 70],
                ['cantidad' => 1, 'descripcion' => 'Agua de jamaica', 'importe' => 25, 'descuento' => 5, 'descuentoLabel' => 'PROMO'],
                ['cantidad' => 1, 'descripcion' => 'Postre', 'importe' => 30, 'isCourtesy' => true],
            ],
            'descuentoOrden' => ['tipo' => 'percent', 'valor' => 10, 'monto' => 12.5],
            'totales' => ['subtotal' => 81.9, 'iva' => 13.1, 'total' => 95, 'totalEnLetra' => 'NOVENTA Y CINCO PESOS 00/100 M.N.'],
        ],
    ],
    'nota_con_pago' => [
        'metodo' => 'receipt',
        'data' => [
            'restaurante' => ['nombre' => 'RESTAURANTE LAB', 'rfc' => 'XAXX010101000', 'direccion' => 'Calle Prueba 123, CDMX'],
            'orden' => ['mesa' => 'M02', 'mesero' => 'Beto', 'personas' => 3, 'orden' => 18219, 'folioSerie' => 'F001', 'folioNumber' => 41, 'fechaCreacion' => '23/10/2026 21:05', 'fechaImpresion' => '23/10/2026 21:40', 'cajero' => 'Luis'],
            'items' => [
                ['cantidad' => 2, 'descripcion' => 'Cafe americano', 'importe' => 80],
                ['cantidad' => 1, 'descripcion' => 'Taco', 'importe' => 35],
            ],
            'totales' => ['subtotal' => 99.14, 'iva' => 15.86, 'propina' => 20, 'consumo' => 115, 'total' => 135, 'totalEnLetra' => 'x'],
            'pagos' => [
                ['metodo' => 'Efectivo', 'monto' => 50, 'tipo' => 'SALE'],
                ['metodo' => 'Tarjeta', 'monto' => 65, 'tipo' => 'SALE', 'payerName' => 'Carlos'],
                ['metodo' => 'Tarjeta', 'monto' => 20, 'tipo' => 'TIP'],
            ],
        ],
    ],
    'comanda_restaurante' => [
        'metodo' => 'comanda', 'esCancelacion' => false,
        'data' => [
            'areaName' => 'Cocina', 'tableName' => 'M01', 'waiterName' => 'Ana', 'tipo' => 1,
            'items' => [
                ['qty' => 2, 'name' => 'Hamburguesa clasica', 'course' => 1, 'notes' => 'sin cebolla', 'isCompositeProductMain' => true, 'compositeProductId' => 'L1'],
                ['isModifier' => true, 'compositeProductId' => 'L1', 'name' => 'Queso extra', 'qty' => 2],
                ['qty' => 1, 'name' => 'Ensalada', 'course' => 2],
            ],
        ],
    ],
    'comanda_antro' => [
        'metodo' => 'comanda', 'esCancelacion' => false,
        'data' => [
            'areaName' => 'Barra', 'tableName' => 'B03', 'waiterName' => 'Zoe', 'tipo' => 2,
            'items' => [
                ['qty' => 1, 'name' => 'Whisky', 'course' => 1, 'isCompositeProductMain' => true, 'compositeProductId' => 'L1'],
                ['isModifier' => true, 'compositeProductId' => 'L1', 'name' => 'Mineral', 'qty' => 4],
                ['qty' => 3, 'name' => 'Cerveza'],
            ],
        ],
    ],
    'cancelacion' => [
        'metodo' => 'comanda', 'esCancelacion' => true,
        'data' => [
            'areaName' => 'Cocina', 'tableName' => 'M01', 'waiterName' => 'Ana', 'cancelledBy' => 'Gerente', 'reason' => 'Cliente se arrepintio',
            'items' => [['qty' => 1, 'name' => 'Ensalada', 'course' => 2]],
        ],
    ],
];
