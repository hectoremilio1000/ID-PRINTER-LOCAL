<?php
/**
 * ¿Hay trabajos de impresión a medias? Lo usa el watchdog antes de reiniciar un
 * php colgado: si hay un ticket que llegó hace poco y todavía no se entregó a
 * Windows, se aplaza el reinicio un ciclo para no cortarlo.
 *
 * Uso (CLI): php trabajos_activos.php <segundos>
 * Imprime un número: trabajos en estado "recibido" de los últimos <segundos>.
 * Si la bitácora no existe o no se puede leer, imprime 0 (no bloquear la
 * recuperación por no poder medir).
 */
$gracia = max(1, (int) ($argv[1] ?? 60));
$archivo = __DIR__ . '/../public/nprint/data/print_jobs.sqlite';
if (!is_file($archivo)) { echo 0; exit(0); }
try {
    $pdo = new PDO('sqlite:' . $archivo, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 2000');
    $desde = (int) round((microtime(true) - $gracia) * 1000);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM print_jobs WHERE status = 'recibido' AND received_ms >= ?");
    $stmt->execute([$desde]);
    echo (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    echo 0;
}
