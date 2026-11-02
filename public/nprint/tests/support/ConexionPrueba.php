<?php
namespace Tests;

use Mike42\Escpos\PrintConnectors\PrintConnector;

/**
 * Conector falso para las pruebas golden: en vez de TrackedWindowsPrintConnector junta los bytes en memoria.
 * El arnés (golden_estilos.php) reemplaza `new TrackedWindowsPrintConnector(` por `new \Tests\ConexionPrueba(`
 * en una COPIA del controlador, así se prueban los endpoints reales (print-consumo, print-propinas, ...).
 */
final class ConexionPrueba implements PrintConnector
{
    /** @var string[] un elemento por trabajo (conector) cerrado */
    public static array $salidas = [];
    private ?array $buffer = [];

    public function __construct($printerName = null, $jobUid = null)
    {
    }

    public function write($data)
    {
        $this->buffer[] = $data;
    }

    /** Igual que TrackedWindowsPrintConnector::repetirContenido: el contenido ya armado se repite `$veces` veces en el mismo trabajo. */
    public function repetirContenido(int $veces): void
    {
        if (!is_array($this->buffer) || !$this->buffer || $veces < 2) return;
        $una = $this->buffer;
        for ($i = 1; $i < $veces; $i++) {
            foreach ($una as $trozo) $this->buffer[] = $trozo;
        }
    }

    public function read($len)
    {
        return '';
    }

    public function finalize()
    {
        if ($this->buffer !== null) self::$salidas[] = implode($this->buffer);
        $this->buffer = null;
    }

    public function abort($mensaje = '')
    {
        $this->buffer = null;
    }

    public function __destruct()
    {
    }
}
