<?php
/**
 * db.example.php — Template di connessione al database per RLI Manager.
 *
 * ISTRUZIONI:
 *   1. Copia questo file in `db.php`.
 *   2. Sostituisci i valori segnaposto con le credenziali reali del tuo MySQL/MariaDB.
 *   3. NON versionare mai `db.php` con credenziali reali.
 */

$host = 'localhost';          // Host del database (es. 'localhost' o '127.0.0.1')
$db   = 'db_rli';             // Nome del database
$user = 'db_user';            // Utente del database
$pass = 'cambiame';           // Password del database

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);

    // Doppia sicurezza: forza la selezione del DB
    $pdo->exec("USE `$db`");

    // Verifica che sia effettivamente selezionato
    $current = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($current !== $db) {
        throw new RuntimeException(
            "Database non selezionato. Atteso: '$db', attuale: '" . ($current ?: 'NULL') . "'"
        );
    }

} catch (PDOException $e) {
    http_response_code(500);
    die("Errore connessione DB: " . htmlspecialchars($e->getMessage()));
} catch (RuntimeException $e) {
    http_response_code(500);
    die("Errore DB: " . htmlspecialchars($e->getMessage()));
}
