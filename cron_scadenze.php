<?php
/**
 * cron_scadenze.php — Genera notifiche per scadenze contratti.
 *
 * Uso CLI:  php cron_scadenze.php
 * Uso web:  https://tuosito/RLI/cron_scadenze.php?token=XXX
 *
 * Configura cron (una volta al giorno, es. alle 06:00):
 *   0 6 * * * cd /var/www/html/RLI && php cron_scadenze.php >> logs/cron.log 2>&1
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/NotificaRepository.php';

// Se chiamato via web, richiedi token
if (php_sapi_name() !== 'cli') {
    $tokenAtteso = getenv('RLI_CRON_TOKEN') ?: 'cambiami';
    if (($_GET['token'] ?? '') !== $tokenAtteso) {
        http_response_code(403);
        die("Token non valido.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$inizio = microtime(true);
echo "=== Cron Scadenze RLI — " . date('Y-m-d H:i:s') . " ===\n";

try {
    $notifRepo = new NotificaRepository($pdo);

    // 1. Genera notifiche da scadenze contratti
    $create = $notifRepo->generaDaScadenze($pdo);
    echo "Notifiche create: " . count($create) . "\n";
    foreach ($create as $id) {
        echo "  - ID {$id}\n";
    }

    // 2. Pulizia notifiche vecchie (>90gg, già lette)
    $eliminate = $notifRepo->cleanOld(90);
    echo "Notifiche eliminate (lette >90gg): {$eliminate}\n";

    // 3. Log
    Logger::azione('cron_scadenze', 'sistema', null, [
        'create'    => count($create),
        'eliminate' => $eliminate,
        'durata_ms' => (int)((microtime(true) - $inizio) * 1000),
    ]);

    echo "=== Completato in " . round((microtime(true) - $inizio) * 1000) . " ms ===\n";
    exit(0);

} catch (Throwable $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
    Logger::errore('cron_scadenze', $e->getMessage());
    exit(1);
}
