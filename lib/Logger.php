<?php
/**
 * Logger — Registra le attività nel DB per audit e debug.
 */
class Logger
{
    private static ?PDO $pdo = null;

    public static function setPdo(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Log generico.
     */
    public static function log(string $azione, array $opts = []): void
    {
        if (self::$pdo === null) return;
        try {
            $stmt = self::$pdo->prepare("
                INSERT INTO log_attivita (id_utente, data_ora, ip, user_agent, azione, entita, id_entita, dettagli, esito)
                VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['utente_id'] ?? null,
                Security::ip(),
                Security::userAgent(),
                substr($azione, 0, 50),
                $opts['entita'] ?? null,
                isset($opts['id_entita']) ? (int)$opts['id_entita'] : null,
                isset($opts['dettagli']) ? (is_array($opts['dettagli']) ? json_encode($opts['dettagli']) : (string)$opts['dettagli']) : null,
                $opts['esito'] ?? 'ok',
            ]);
        } catch (Throwable $e) {
            // Il log non deve mai rompere l'app
            error_log("Logger error: " . $e->getMessage());
        }
    }

    /**
     * Log azione utente.
     */
    public static function azione(string $azione, ?string $entita = null, ?int $idEntita = null, $dettagli = null): void
    {
        self::log($azione, [
            'entita' => $entita,
            'id_entita' => $idEntita,
            'dettagli' => $dettagli,
        ]);
    }

    /**
     * Log errore.
     */
    public static function errore(string $azione, string $messaggio, ?int $idEntita = null): void
    {
        self::log($azione, [
            'id_entita' => $idEntita,
            'dettagli' => ['errore' => $messaggio],
            'esito' => 'errore',
        ]);
    }
}
