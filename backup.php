<?php
/**
 * backup.php — Backup del database.
 *
 * Funzionalità:
 *   - Backup completo (struttura + dati)
 *   - Backup solo struttura
 *   - Selezione tabelle specifiche
 *   - Download come file .sql
 *
 * Sicurezza:
 *   - Solo admin
 *   - CSRF protection
 */
session_start();
include 'db.php';
require_once 'config.php';

$auth->requireAdmin();

// === GENERAZIONE BACKUP ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['genera_backup'])) {
    Security::requireCsrf();

    try {
        $modalita = $_POST['modalita'] ?? 'completo'; // completo | struttura | selezionate
        $tabelleSelezionate = $_POST['tabelle'] ?? [];
        $includiDrop = !empty($_POST['includi_drop']);

        // Determina quali tabelle includere
        $tutteTabelle = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        if ($modalita === 'selezionate') {
            $tabelle = array_intersect($tutteTabelle, $tabelleSelezionate);
            if (empty($tabelle)) {
                throw new Exception('Nessuna tabella selezionata.');
            }
        } else {
            $tabelle = $tutteTabelle;
        }

        $soloStruttura = ($modalita === 'struttura');

        // Genera dump SQL
        $sql = "-- ============================================================\n";
        $sql .= "-- RLI Manager — Backup Database\n";
        $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Modalità: " . $modalita . "\n";
        $sql .= "-- Tabelle: " . count($tabelle) . "\n";
        $sql .= "-- Utente: " . $auth->username() . "\n";
        $sql .= "-- ============================================================\n\n";

        $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sql .= "SET time_zone = \"+00:00\";\n";
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tabelle as $tabella) {
            // Struttura
            if ($includiDrop) {
                $sql .= "DROP TABLE IF EXISTS `$tabella`;\n";
            }

            $createStmt = $pdo->query("SHOW CREATE TABLE `$tabella`")->fetch();
            if ($createStmt && isset($createStmt['Create Table'])) {
                $sql .= $createStmt['Create Table'] . ";\n\n";
            }

            // Dati
            if (!$soloStruttura) {
                $rows = $pdo->query("SELECT * FROM `$tabella`");
                $rowCount = 0;
                
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $cols = array_keys($row);
                    $vals = array_values($row);
                    
                    $escapedVals = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote($v);
                    }, $vals);
                    
                    $sql .= "INSERT INTO `$tabella` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $escapedVals) . ");\n";
                    $rowCount++;
                }
                
                if ($rowCount > 0) {
                    $sql .= "\n";
                }
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        // Log
        Logger::azione('backup_download', 'database', null, [
            'modalita' => $modalita,
            'tabelle' => count($tabelle),
            'size_bytes' => strlen($sql),
        ]);

        // Output
        if (ob_get_length()) ob_end_clean();

        $suffix = $modalita === 'struttura' ? '_struttura' : ($modalita === 'selezionate' ? '_parziale' : '');
        $filename = 'backup_rli' . $suffix . '_' . date('Ymd_His') . '.sql';

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $sql;
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
        Logger::errore('backup_error', $e->getMessage());
    }
}

// === INFO TABELLE (per la UI) ===
$tabelleInfo = [];
$tutteTabelle = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tutteTabelle as $tabella) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `$tabella`")->fetchColumn();
        $size = $pdo->query("
            SELECT ROUND((data_length + index_length) / 1024, 2) AS size_kb
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE() AND table_name = '$tabella'
        ")->fetchColumn();
        
        $tabelleInfo[] = [
            'nome' => $tabella,
            'righe' => $count,
            'size_kb' => $size,
        ];
    } catch (Throwable $e) {
        $tabelleInfo[] = [
            'nome' => $tabella,
            'righe' => 0,
            'size_kb' => 0,
        ];
    }
}

// Ordina alfabeticamente
usort($tabelleInfo, function($a, $b) { return strcmp($a['nome'], $b['nome']); });

// Statistiche generali
$totRighe = array_sum(array_column($tabelleInfo, 'righe'));
$totSizeKb = array_sum(array_column($tabelleInfo, 'size_kb'));

// Tabella per categorie
$categorie = [
    'Dati principali' => ['contratti', 'soggetti', 'immobili', 'proprieta_immobili'],
    'Collegamenti' => ['contratto_locatori', 'contratto_conduttori', 'contratto_immobili'],
    'Eventi e notifiche' => ['eventi_contratto', 'notifiche'],
    'Sistema' => ['utenti', 'log_attivita', 'contratti_condivisioni'],
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Backup Database — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1100px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        h3 { color: #4a5568; margin-top: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        .radio-group { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
        .radio-option { display: flex; align-items: flex-start; gap: 10px; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .radio-option:hover { border-color: #4299e1; background: #f0f9ff; }
        .radio-option.selected { border-color: #4299e1; background: #ebf8ff; }
        .radio-option input { width: auto; margin: 4px 0 0 0; }
        .radio-option .info { flex: 1; }
        .radio-option .title { font-weight: bold; color: #2d3748; margin-bottom: 4px; }
        .radio-option .desc { font-size: 0.85em; color: #718096; }
        .check-row { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid #edf2f7; font-size: 0.9em; font-weight: normal; }
        .check-row:last-child { border-bottom: none; }
        .check-row input { width: auto; margin: 0; }
        .check-row label { margin: 0; font-weight: normal; cursor: pointer; flex: 1; }
        .check-row .row-info { color: #a0aec0; font-size: 0.85em; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.95em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-lg { padding: 15px 30px; font-size: 1em; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .alert-info { background: #bee3f8; color: #2c5282; }
        .alert-warning { background: #feebc8; color: #7b341e; }
        .stats-bar { display: flex; gap: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; margin-bottom: 20px; font-size: 0.9em; flex-wrap: wrap; }
        .stat-item .lbl { color: #718096; font-size: 0.85em; }
        .stat-item .val { font-weight: bold; color: #2d3748; font-size: 1.1em; }
        .table-cat { margin-bottom: 20px; }
        .table-cat h4 { margin: 10px 0 5px 0; color: #4a5568; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.5px; }
        .table-list { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        #tabelle-sezione { display: none; margin-top: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; }
        #tabelle-sezione.visible { display: block; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; font-weight: normal; }
        .checkbox-row input { width: auto; margin: 0; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>💾 Backup Database</h2>

    <?php if (isset($error)) echo "<div class='alert alert-error'>⚠ " . Security::h($error) . "</div>"; ?>

    <div class="alert alert-warning">
        <strong>⚠ Importante:</strong> Il backup contiene dati sensibili (contratti, soggetti, CF, IBAN). Conservalo in luogo sicuro.
    </div>

    <div class="stats-bar">
        <div class="stat-item">
            <div class="lbl">Database</div>
            <div class="val">db_rli</div>
        </div>
        <div class="stat-item">
            <div class="lbl">Tabelle totali</div>
            <div class="val"><?= count($tabelleInfo) ?></div>
        </div>
        <div class="stat-item">
            <div class="lbl">Righe totali</div>
            <div class="val"><?= number_format($totRighe) ?></div>
        </div>
        <div class="stat-item">
            <div class="lbl">Dimensione</div>
            <div class="val"><?= number_format($totSizeKb / 1024, 2) ?> MB</div>
        </div>
    </div>

    <form method="POST" id="form-backup">
        <?= Security::csrfField() ?>

        <div class="card">
            <h3 style="margin-top:0;">Modalità di backup</h3>

            <div class="radio-group">
                <label class="radio-option selected" id="opt-completo">
                    <input type="radio" name="modalita" value="completo" checked onchange="toggleSelezione()">
                    <div class="info">
                        <div class="title">📦 Backup completo</div>
                        <div class="desc">Struttura + dati di tutte le tabelle. Dimensione stimata: <?= number_format($totSizeKb / 1024, 2) ?> MB</div>
                    </div>
                </label>

                <label class="radio-option" id="opt-struttura">
                    <input type="radio" name="modalita" value="struttura" onchange="toggleSelezione()">
                    <div class="info">
                        <div class="title">🏗️ Solo struttura</div>
                        <div class="desc">Solo CREATE TABLE, senza dati. Utile per migrazioni o reset. Dimensione: pochi KB.</div>
                    </div>
                </label>

                <label class="radio-option" id="opt-selezionate">
                    <input type="radio" name="modalita" value="selezionate" onchange="toggleSelezione()">
                    <div class="info">
                        <div class="title">🎯 Tabelle selezionate</div>
                        <div class="desc">Scegli quali tabelle includere nel backup.</div>
                    </div>
                </label>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" name="includi_drop" id="includi_drop" value="1" checked>
                <label for="includi_drop">Includi <code>DROP TABLE IF EXISTS</code> (sovrascrive tabelle esistenti al ripristino)</label>
            </div>
        </div>

        <!-- SEZIONE SELEZIONE TABELLE -->
        <div id="tabelle-sezione">
            <div class="card">
                <h3 style="margin-top:0;">Seleziona tabelle</h3>
                <p style="color: #718096; font-size: 0.9em;">
                    <a href="#" onclick="selectAll(true); return false;">Seleziona tutte</a> ·
                    <a href="#" onclick="selectAll(false); return false;">Deseleziona tutte</a>
                </p>

                <?php foreach ($categorie as $catNome => $catTabelle): 
                    $tabellePresenti = array_filter($tabelleInfo, function($t) use ($catTabelle) {
                        return in_array($t['nome'], $catTabelle);
                    });
                    if (empty($tabellePresenti)) continue;
                ?>
                    <div class="table-cat">
                        <h4><?= Security::h($catNome) ?></h4>
                        <div class="table-list">
                            <?php foreach ($tabellePresenti as $t): ?>
                                <div class="check-row">
                                    <input type="checkbox" name="tabelle[]" value="<?= Security::h($t['nome']) ?>" id="tbl_<?= Security::h($t['nome']) ?>" class="tbl-check" checked>
                                    <label for="tbl_<?= Security::h($t['nome']) ?>">
                                        <?= Security::h($t['nome']) ?>
                                    </label>
                                    <span class="row-info"><?= number_format($t['righe']) ?> righe · <?= number_format($t['size_kb'], 1) ?> KB</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php
                // Tabelle non categorizzate
                $categorizzate = array_merge(...array_values($categorie));
                $altre = array_filter($tabelleInfo, function($t) use ($categorizzate) {
                    return !in_array($t['nome'], $categorizzate);
                });
                if (!empty($altre)):
                ?>
                    <div class="table-cat">
                        <h4>Altre tabelle</h4>
                        <div class="table-list">
                            <?php foreach ($altre as $t): ?>
                                <div class="check-row">
                                    <input type="checkbox" name="tabelle[]" value="<?= Security::h($t['nome']) ?>" id="tbl_<?= Security::h($t['nome']) ?>" class="tbl-check" checked>
                                    <label for="tbl_<?= Security::h($t['nome']) ?>">
                                        <?= Security::h($t['nome']) ?>
                                    </label>
                                    <span class="row-info"><?= number_format($t['righe']) ?> righe · <?= number_format($t['size_kb'], 1) ?> KB</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" name="genera_backup" value="1" class="btn btn-green btn-lg">
                💾 Scarica Backup
            </button>
            <a href="<?= url('dati.php') ?>" class="btn btn-primary">← Torna a Dati</a>
        </div>
    </form>
</div>

<script>
function toggleSelezione() {
    const modalita = document.querySelector('input[name="modalita"]:checked').value;
    const sezione = document.getElementById('tabelle-sezione');
    
    if (modalita === 'selezionate') {
        sezione.classList.add('visible');
    } else {
        sezione.classList.remove('visible');
    }
    
    // Aggiorna classi
    document.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector('input[name="modalita"]:checked').closest('.radio-option').classList.add('selected');
}

function selectAll(valore) {
    document.querySelectorAll('.tbl-check').forEach(cb => cb.checked = valore);
}

document.addEventListener('DOMContentLoaded', toggleSelezione);
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
