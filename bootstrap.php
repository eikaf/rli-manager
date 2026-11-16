<?php
/**
 * bootstrap.php — Inizializzazione centralizzata dell'applicazione.
 *
 * Ordine:
 *   1. avvio sessione
 *   2. autoload Composer (se presente)
 *   3. connessione $pdo (db.php)
 *   4. configurazione + $auth (config.php)
 *   5. fallback esplicito delle classi lib/ (se il classmap non è rigenerato)
 *   6. exception handler globale (JSON per XHR/Accept JSON, HTML per il resto)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Autoload Composer (vendor/) se presente
$__rli_autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($__rli_autoload)) {
    require_once $__rli_autoload;
}
unset($__rli_autoload);

// Connessione PDO ($pdo)
require_once __DIR__ . '/db.php';

// Configurazione: costanti, Security, Logger, Auth ($auth)
require_once __DIR__ . '/config.php';

// Fallback esplicito: garantisce il funzionamento anche se il classmap
// di Composer non è stato rigenerato con "composer dump-autoload".
require_once __DIR__ . '/lib/ContrattoRepository.php';
require_once __DIR__ . '/lib/XmlBuilder.php';
require_once __DIR__ . '/lib/RliPreFlightValidator.php';
require_once __DIR__ . '/lib/XsdValidator.php';
require_once __DIR__ . '/lib/AttachmentStorage.php';
require_once __DIR__ . '/lib/RliTaxCalculator.php';

// Exception handler globale
set_exception_handler(function (Throwable $e): void {
    // Log dettagliato lato server (mai esporre dettagli all'utente in produzione)
    error_log(
        '[RLI] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
    );

    $accept     = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xRequested = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $wantsJson  = (strpos($accept, 'application/json') !== false) || ($xRequested === 'xmlhttprequest');

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . ($wantsJson ? 'application/json' : 'text/html') . '; charset=utf-8');
    }

    if ($wantsJson) {
        echo json_encode(['ok' => false, 'error' => 'Errore interno del server.']);
        return;
    }

    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Errore — RLI Manager</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#f7fafc;padding:40px;color:#2d3748;}';
    echo '.box{background:#fff;border-left:4px solid #e53e3e;padding:25px 30px;border-radius:10px;max-width:700px;margin:0 auto;box-shadow:0 4px 12px rgba(0,0,0,0.08);}';
    echo 'h2{color:#742a2a;margin-top:0;}.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#4299e1;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;}</style>';
    echo '</head><body><div class="box">';
    echo '<h2>⚠ Si è verificato un errore</h2>';
    echo '<p>L\'operazione non è andata a buon fine. Riprova o contatta l\'assistenza.</p>';
    echo '<a class="btn" href="index.php">← Home</a>';
    echo '</div></body></html>';
});