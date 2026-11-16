<?php
/**
 * migrate_blobs_to_disk.php — Migrazione CLI dei BLOB allegato_pdf verso il file system.
 *
 * Esporta in RLI_STORAGE_PATH/allegati/YYYY/MM/ i PDF presenti nelle colonne
 * `contratti.allegato_pdf` e `eventi_contratto.allegato_pdf`, calcola lo SHA-256,
 * verifica l'integrità e infine imposta `allegato_pdf = NULL`.
 *
 * Prerequisiti:
 *   - aver eseguito migrations/002_allegati_su_disco.sql (colonne allegato_path/sha256)
 *   - RLI_STORAGE_PATH configurato (config.local.php o variabile d'ambiente)
 *
 * Uso:
 *   php bin/migrate_blobs_to_disk.php [--dry-run]
 *
 * Exit code 0 = OK, 1 = uno o più errori.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Questo script può essere eseguito solo da CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';

// Configurazione: carica config.local.php (se presente) per RLI_STORAGE_PATH
$localConfig = __DIR__ . '/../config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}
if (!defined('RLI_STORAGE_PATH')) {
    define('RLI_STORAGE_PATH', getenv('RLI_STORAGE_PATH') ?: '/var/lib/rli_storage');
}
require_once __DIR__ . '/../lib/AttachmentStorage.php';

$dryRun = in_array('--dry-run', $argv, true);

/**
 * Verifica che una colonna esista nella tabella.
 */
function colonnaEsiste(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function migraTabella(PDO $pdo, string $table, bool $dryRun): array
{
    $stats = ['esportati' => 0, 'saltati' => 0, 'errori' => 0];

    if (!colonnaEsiste($pdo, $table, 'allegato_path') || !colonnaEsiste($pdo, $table, 'allegato_sha256')) {
        fwrite(STDERR, "[SKIP] {$table}: eseguire prima migrations/002_allegati_su_disco.sql\n");
        return $stats;
    }

    $stmt = $pdo->query("SELECT id, allegato_pdf, allegato_path, nome_allegato FROM `{$table}` WHERE allegato_pdf IS NOT NULL AND LENGTH(allegato_pdf) > 0");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id   = (int)$row['id'];
        $blob = $row['allegato_pdf'];
        $sha  = hash('sha256', $blob);

        try {
            // Se già presente su disco con lo stesso hash, svuota solo il BLOB.
            if (!empty($row['allegato_path']) && AttachmentStorage::exists($row['allegato_path'])) {
                $existing = AttachmentStorage::read($row['allegato_path'], null);
                if ($existing !== null && hash('sha256', $existing) === $sha) {
                    if (!$dryRun) {
                        $pdo->prepare("UPDATE `{$table}` SET allegato_sha256 = ?, allegato_pdf = NULL WHERE id = ?")
                            ->execute([$sha, $id]);
                    }
                    $stats['saltati']++;
                    echo "[{$table}#{$id}] già su disco, BLOB svuotato\n";
                    continue;
                }
            }

            if ($dryRun) {
                echo "[dry-run][{$table}#{$id}] esporterei " . strlen($blob) . " byte (sha256={$sha})\n";
                $stats['esportati']++;
                continue;
            }

            $saved = AttachmentStorage::save($blob, $row['nome_allegato'] ?: 'allegato.pdf');
            if (!hash_equals($sha, $saved['sha256'])) {
                throw new RuntimeException("Hash mismatch dopo il salvataggio");
            }

            $pdo->prepare("UPDATE `{$table}` SET allegato_path = ?, allegato_sha256 = ?, allegato_pdf = NULL WHERE id = ?")
                ->execute([$saved['path'], $saved['sha256'], $id]);

            $stats['esportati']++;
            echo "[{$table}#{$id}] esportato in {$saved['path']}\n";
        } catch (Throwable $e) {
            $stats['errori']++;
            fwrite(STDERR, "[{$table}#{$id}] ERRORE: " . $e->getMessage() . "\n");
        }
    }

    return $stats;
}

$totale = ['esportati' => 0, 'saltati' => 0, 'errori' => 0];

echo "Storage: " . RLI_STORAGE_PATH . ($dryRun ? " (DRY-RUN)" : "") . "\n";

foreach (['contratti', 'eventi_contratto'] as $table) {
    $s = migraTabella($pdo, $table, $dryRun);
    foreach ($s as $k => $v) {
        $totale[$k] += $v;
    }
}

echo "Totale: esportati={$totale['esportati']}, saltati={$totale['saltati']}, errori={$totale['errori']}\n";
exit($totale['errori'] > 0 ? 1 : 0);