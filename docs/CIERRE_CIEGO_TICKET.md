# Ticket de caja con cierre ciego

Rutas: `POST /nprint/printers/print-cierre-caja` (caja cerrada) y `POST /nprint/printers/print-reporte-caja` (caja abierta).
Cuerpo: `[{ printerName, jobUid?, data }]` (o un solo objeto `{ printerName, data }`). Respuesta: sin cambios.

El ticket se elige con `data.visibility` (`PrinterController::nivelVisibilidadCaja`):

| `data.visibility`              | Ticket                                                                  |
|--------------------------------|-------------------------------------------------------------------------|
| (no viene)                     | El de siempre, idéntico (fronts viejos)                                 |
| `"full"`                       | El de siempre                                                           |
| `"blind"`                      | CIERRE / REPORTE DE CAJA - CIEGO: solo lo que esa persona contó/declaró |
| `"none"` o cualquier otro valor | CAJA CERRADA + caja + hora (falla cerrado)                              |

El ticket ciego solo lee una lista blanca de campos. Nunca imprime fondo inicial, esperado, diferencia, ventas,
movimientos, operadores ni declaraciones de otros, aunque vinieran en el payload. Una cifra que no llegó se imprime
como `No registrado`, nunca como `$0.00`. El corte X (`print-corte-x`) no cambia.

## ¿Esta PC ya entiende el ticket ciego? `GET /nprint/capabilities`

Misma base que las rutas de impresión: si el front imprime en `${base}/nprint/printers/print-cierre-caja`
(`base` = `restaurants.local_base_url`), consulta `GET ${base}/nprint/capabilities`. Sin auth, mismo CORS que el resto.

Respuesta (200, `application/json`):

```json
{ "ok": true, "capabilities": ["cierre_caja_visibility"] }
```

Uso en el front, antes de imprimir un reporte con `visibility` distinto de `full`:

- Si la respuesta es 200, JSON válido y `capabilities` incluye `"cierre_caja_visibility"` → mandar el payload ciego.
- Cualquier otra cosa → **no soporta**: 404 (versión vieja), error de red o de CORS (en una versión vieja el 404 sale
  sin encabezados CORS y el navegador lo reporta como `TypeError: Failed to fetch`), timeout, o JSON sin la capacidad.
  En ese caso no imprimir el ciego: avisar "El servidor de impresión de este restaurante aún no está actualizado".
- Se puede guardar el resultado en memoria por `base` mientras dure la sesión de la página; no hace falta persistirlo.

## Ejemplo ciego, caja cerrada (`print-cierre-caja`)

```json
[{
  "printerName": "CAJA1",
  "jobUid": "0f8c2a4e-6d1b-4a57-9e3c-1b2d3e4f5a6b",
  "data": {
    "visibility": "blind",
    "scope": "session",
    "mode": "closed",
    "station": { "id": 3, "code": "C1", "name": "Caja 1", "printerName": "CAJA1" },
    "session": { "id": 812, "status": "closed", "cashUserName": "Jampier pruebas",
                 "openedAt": "2026-10-16T15:02:00.000Z", "closedAt": "2026-10-17T03:42:00.000Z",
                 "closedByName": "Jampier pruebas" },
    "counted": { "closingCash": 950,
                 "methods": [
                   { "paymentMethodId": 1, "paymentMethodName": "Efectivo", "isCash": true, "declared": 950 },
                   { "paymentMethodId": 2, "paymentMethodName": "Tarjeta", "isCash": false, "declared": null }
                 ] },
    "restaurante": "La Llorona",
    "textos": { "apertura": "16/10/2026, 9:02:00", "cierre": "16/10/2026, 21:42:00", "impreso": "16/10/2026, 21:45:10" }
  }
}]
```

Sale (42 columnas):

```
                La Llorona
          CIERRE DE CAJA - CIEGO
------------------------------------------
Caja                                Caja 1
Responsable                Jampier pruebas
Apertura                16/10/2026, 9:02:00
Cierre                 16/10/2026, 21:42:00
Cerrada por                Jampier pruebas
------------------------------------------
Efectivo contado                   $950.00
DECLARADO
 Efectivo                          $950.00
 Tarjeta                     No registrado
------------------------------------------

Firma: ________________________

         Impreso: 16/10/2026, 21:45:10
```

## Ejemplo ciego, caja abierta (`print-reporte-caja`)

```json
{ "printerName": "CAJA1", "data": {
  "visibility": "blind", "scope": "session", "mode": "open",
  "station": { "id": 3, "code": "C1", "name": "Caja 1", "printerName": "CAJA1" },
  "session": { "id": 812, "status": "open", "cashUserName": "Jampier pruebas", "openedAt": "2026-10-16T15:02:00.000Z",
               "closedAt": null, "closedByName": null },
  "counted": null,
  "myDeclarations": [
    { "paymentMethodName": "Efectivo", "declared": true, "isFinal": false },
    { "paymentMethodName": "Tarjeta", "declared": false, "isFinal": false }
  ]
} }
```

Imprime caja, responsable, apertura y "MIS DECLARACIONES" con `Registrada` / `Firmada` / `Pendiente` (sin montos).

## Ejemplo sin acceso (`print-cierre-caja`)

```json
{ "printerName": "CAJA1", "data": {
  "visibility": "none", "mode": "closed",
  "station": { "name": "Caja 1" },
  "session": { "closedAt": "2026-10-17T03:42:00.000Z" }
} }
```

Imprime `CAJA CERRADA`, `Caja  Caja 1`, `Cierre 16/10/2026 21:42` (hora de esta PC) e `Impreso: ...`.

## Ejemplo completo (`visibility: "full"` o sin el campo)

El payload de siempre (`ticketReporteCaja.ts → payloadImpresionReporteCaja`) más `"visibility": "full"`:

```json
{ "printerName": "CAJA1", "data": {
  "visibility": "full", "mode": "closed",
  "station": { "name": "Caja 1" },
  "session": { "cashUserName": "Jampier pruebas", "closedByName": "Jampier pruebas" },
  "openingCash": 500, "expectedCash": 950,
  "closing": { "closingCash": 950, "difference": 0 },
  "methods": [ { "paymentMethodName": "Efectivo", "sales": 450, "expected": 950, "declared": 950, "difference": 0 } ],
  "movements": { "in": 0, "out": 0, "drops": 0, "adjusts": 0, "tipPayouts": 0, "commissionPayouts": 0, "net": 0, "items": [] },
  "operators": [], "summary": { "ordersCount": 3, "salesGross": 450 },
  "restaurante": "La Llorona",
  "textos": { "apertura": "16/10/2026, 9:02:00", "cierre": "16/10/2026, 21:42:00", "impreso": "16/10/2026, 21:45:10" }
} }
```

## Prueba manual (no hay pruebas automáticas en este repo)

Con el ID-Printer de esta rama corriendo en una PC de pruebas, mandar cada ejemplo con
`curl -k -X POST https://<pc>/nprint/printers/print-cierre-caja -H "Content-Type: application/json" -d @ejemplo.json`
y revisar el papel: el ciego no debe mostrar `Fondo inicial`, `Efectivo esperado`, `Diferencia`, `VENTAS POR METODO`
ni `$0.00` en lugar de un dato faltante; sin `visibility` debe salir idéntico a `main`.
