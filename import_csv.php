<?php
/**
 * import_csv.php — Importa soggetti o immobili da CSV.
 *
 * Tipo di import (parametro POST tipo):
 *   - soggetti
 *   - immobili
 *
 * Flusso:
 *   1. Upload file CSV
 *   2. Anteprima (prime 10 righe)
 *   3. Conferma import (transazione)
 *
 * Note:
 *   - CSV con separatore `;` e BOM UTF-8
 *   - Le colonne devono corrispondere all'export
 *   - Duplicati: skip (soggetti per CF, immobili per cod.comune + foglio + particella + subalterno)
 */
session_start();
include 'db.php';
require_once 'config.php';

$auth->requireLogin();
$auth->requireOperator(); // viewer non può importare

$tipo = $_POST['tipo'] ?? $_GET['tipo'] ?? 'soggetti';
$anteprima = null;
$risultati = null;
$errori = [];

// === UPLOAD E ANTEPRIMA ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    Security::requireCsrf();
    
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errori[] = 'Nessun file caricato o errore upload.';
    } else {
        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $csvContent = file_get_contents($tmpFile);
        
        // Rimuovi BOM
        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }
        
        // Salva temporaneamente
        $tmpPath = sys_get_temp_dir() . '/import_' . session_id() . '_' . time() . '.csv';
        file_put_contents($tmpPath, $csvContent);
        
        // Leggi prime 10 righe per anteprima
        $fh = fopen($tmpPath, 'r');
        $headers = fgetcsv($fh, 0, ';', '"');
        $righe = [];
        $rowCount = 0;
        while (($row = fgetcsv($fh, 0, ';', '"')) !== false && $rowCount < 10) {
            $righe[] = $row;
            $rowCount++;
        }
        fclose($fh);
        
        $anteprima = [
            'headers' => $headers,
            'righe' => $righe,
            'tmp_path' => $tmpPath,
            'total' => count(file($tmpPath)) - 1, // stima
        ];
    }
}

// === CONFERMA IMPORT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conferma'])) {
    Security::requireCsrf();
    
    $tmpPath = $_POST['tmp_path'] ?? '';
    $tipo = $_POST['tipo'] ?? 'soggetti';
    
    if (!file_exists($tmpPath)) {
        $errori[] = 'File temporaneo scaduto. Ricarica il CSV.';
    } else {
        try {
            $pdo->beginTransaction();
            
            $fh = fopen($tmpPath, 'r');
            $headers = fgetcsv($fh, 0, ';', '"');
            
            $importati = 0;
            $skipped = 0;
            $errors = [];
            $rowNum = 1;
            
            while (($row = fgetcsv($fh, 0, ';', '"')) !== false) {
                $rowNum++;
                
                if ($tipo === 'soggetti') {
                    // Colonne: 0=ID, 1=CF, 2=Cognome, 3=Nome, 4=Sesso, 5=DataNascita, 6=ComNascita, 7=ProvNascita, 8=Tipologia, 9=TipoCond, 10=Email, 11=Telefono, 12=IVA, 13=CFRappr, 14=CognRappr, 15=NomeRappr, 16=Carica, 17=Note
                    $cf = strtoupper(trim($row[1] ?? ''));
                    $cognome = strtoupper(trim($row[2] ?? ''));
                    $nome = strtoupper(trim($row[3] ?? ''));
                    
                    if ($cf === '' || $cognome === '') {
                        $errors[] = "Riga $rowNum: CF o Cognome mancanti";
                        continue;
                    }
                    
                    // Verifica duplicato
                    $stmt = $pdo->prepare("SELECT id FROM soggetti WHERE codice_fiscale = ? LIMIT 1");
                    $stmt->execute([$cf]);
                    if ($stmt->fetchColumn()) {
                        $skipped++;
                        continue;
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO soggetti (
                            codice_fiscale, cognome, nome, sesso, data_nascita,
                            comune_nascita, prov_nascita, tipologia_default, tipologia_conduttore,
                            email, telefono, soggettivita_iva,
                            cf_rappresentante, cognome_rappresentante, nome_rappresentante, codice_carica,
                            note
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $cf, $cognome, $nome,
                        !empty($row[4]) ? strtoupper($row[4]) : null,
                        !empty($row[5]) ? $row[5] : null,
                        strtoupper(trim($row[6] ?? '')),
                        strtoupper(trim($row[7] ?? '')),
                        $row[8] ?? 'ENTRAMBI',
                        !empty($row[9]) ? $row[9] : null,
                        trim($row[10] ?? ''),
                        trim($row[11] ?? ''),
                        ($row[12] ?? '') === 'Sì' ? 1 : 0,
                        !empty($row[13]) ? strtoupper(trim($row[13])) : null,
                        !empty($row[14]) ? strtoupper(trim($row[14])) : null,
                        !empty($row[15]) ? strtoupper(trim($row[15])) : null,
                        !empty($row[16]) ? $row[16] : null,
                        trim($row[17] ?? ''),
                    ]);
                    $importati++;
                    
                } elseif ($tipo === 'immobili') {
                    // Colonne: 0=ID, 1=IdentInterno, 2=CodComune, 3=Comune, 4=Provincia, ...
                    $ident = trim($row[1] ?? '');
                    $codComune = strtoupper(trim($row[2] ?? ''));
                    $foglio = $row[8] ?? '';
                    $particella = $row[9] ?? '';
                    $subalterno = $row[10] ?? '';
                    
                    if ($ident === '' || $codComune === '') {
                        $errors[] = "Riga $rowNum: Identificativo o Cod. Comune mancanti";
                        continue;
                    }
                    
                    // Verifica duplicato
                    $stmt = $pdo->prepare("
                        SELECT id FROM immobili
                        WHERE codice_comune = ? AND foglio = ? AND particella = ? AND COALESCE(subalterno,'') = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$codComune, $foglio, $particella, $subalterno]);
                    if ($stmt->fetchColumn()) {
                        $skipped++;
                        continue;
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO immobili (
                            identificativo_interno, codice_comune, comune, provincia, sezione,
                            tipo_catasto, categoria, foglio, particella, subalterno, rendita,
                            toponimo, indirizzo, civico, in_via_di_accatastamento,
                            partita_tavolare, corpo_tavolare, porzione_materiale,
                            superficie_ettari, superficie_are, superficie_centiare,
                            zona_censuaria, classe, consistenza, natura, reddito_dominicale, note
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $ident, $codComune,
                        strtoupper(trim($row[3] ?? '')),
                        strtoupper(trim($row[4] ?? '')),
                        strtoupper(trim($row[5] ?? '')),
                        strtoupper(trim($row[6] ?? 'U')),
                        strtoupper(trim($row[7] ?? '')),
                        $foglio, $particella, $subalterno,
                        !empty($row[11]) ? str_replace(',', '.', $row[11]) : null,
                        strtoupper(trim($row[12] ?? '')),
                        strtoupper(trim($row[13] ?? '')),
                        strtoupper(trim($row[14] ?? '')),
                        ($row[15] ?? '') === 'Sì' ? 1 : 0,
                        $row[16] ?? null, $row[17] ?? null, $row[18] ?? null,
                        !empty($row[19]) ? (int)$row[19] : null,
                        !empty($row[20]) ? (int)$row[20] : null,
                        !empty($row[21]) ? (int)$row[21] : null,
                        $row[22] ?? null, $row[23] ?? null, $row[24] ?? null,
                        strtoupper(trim($row[25] ?? '')),
                        !empty($row[26]) ? str_replace(',', '.', $row[26]) : null,
                        trim($row[27] ?? ''),
                    ]);
                    $importati++;
                }
            }
            
            fclose($fh);
            $pdo->commit();
            
            Logger::azione('csv_import', $tipo, null, [
                'importati' => $importati,
                'skipped' => $skipped,
                'errori' => count($errors),
            ]);
            
            $risultati = [
                'importati' => $importati,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 20),
                'total_errors' => count($errors),
            ];
            
            // Cancella file temporaneo
            @unlink($tmpPath);
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errori[] = 'Errore import: ' . $e->getMessage();
            Logger::errore('csv_import', $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Import CSV — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1200px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .alert-info { background: #bee3f8; color: #2c5282; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8em; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #edf2f7; }
        th { color: #718096; font-size: 0.75em; text-transform: uppercase; background: #f7fafc; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9em; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>📤 Import CSV</h2>

    <?php foreach ($errori as $err): ?>
        <div class="alert alert-error">⚠ <?= Security::h($err) ?></div>
    <?php endforeach; ?>

    <?php if ($risultati): ?>
        <div class="alert alert-success">
            <strong>✅ Import completato</strong><br>
            Importati: <strong><?= $risultati['importati'] ?></strong><br>
            Skippati (già presenti): <strong><?= $risultati['skipped'] ?></strong><br>
            <?php if ($risultati['total_errors'] > 0): ?>
                Errori: <strong><?= $risultati['total_errors'] ?></strong>
                <?php if (!empty($risultati['errors'])): ?>
                    <ul style="margin: 8px 0 0 20px; font-size: 0.9em;">
                        <?php foreach ($risultati['errors'] as $e): ?><li><?= Security::h($e) ?></li><?php endforeach; ?>
                        <?php if ($risultati['total_errors'] > count($risultati['errors'])): ?>
                            <li>...e altri <?= $risultati['total_errors'] - count($risultati['errors']) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div style="margin-bottom:20px;">
            <a href="<?= url('import_csv.php') ?>" class="btn btn-gray">← Importa altro</a>
            <a href="<?= $tipo === 'soggetti' ? 'soggetti.php' : 'immobili.php' ?>" class="btn btn-primary">Vai a <?= $tipo === 'soggetti' ? 'Soggetti' : 'Immobili' ?></a>
        </div>
    <?php endif; ?>

    <?php if ($anteprima): ?>
        <div class="card">
            <div class="alert alert-info">
                <strong>📋 Anteprima</strong> (prime 10 righe di <?= $anteprima['total'] ?> totali)
            </div>

            <table>
                <thead>
                    <tr>
                        <?php foreach ($anteprima['headers'] as $h): ?><th><?= Security::h($h) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($anteprima['righe'] as $r): ?>
                        <tr>
                            <?php foreach ($r as $cell): ?><td><?= Security::h($cell) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST" style="margin-top:20px;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="tmp_path" value="<?= Security::h($anteprima['tmp_path']) ?>">
                <input type="hidden" name="tipo" value="<?= Security::h($tipo) ?>">
                <button type="submit" name="conferma" value="1" class="btn btn-green">✅ Conferma Import</button>
                <a href="<?= url('import_csv.php') ?>" class="btn btn-gray">Annulla</a>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="info-box">
                <strong>Come funziona:</strong>
                <ol style="margin: 8px 0 0 20px;">
                    <li>Seleziona il tipo di dati da importare</li>
                    <li>Carica il file CSV (separatore <code>;</code>, UTF-8 con BOM)</li>
                    <li>Verifica l'anteprima</li>
                    <li>Conferma l'import</li>
                </ol>
                <strong>Suggerimento:</strong> usa prima l'export per capire il formato corretto.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?= Security::csrfField() ?>

                <label>Tipo di dati</label>
                <select name="tipo" required>
                    <option value="soggetti">👥 Soggetti</option>
                    <option value="immobili">🏠 Immobili</option>
                </select>

                <label>File CSV</label>
                <input type="file" name="csv_file" accept=".csv,text/csv" required>

                <button type="submit" name="upload" value="1" class="btn btn-primary">📤 Carica e Anteprima</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script src="assets/uppercase.js"></script>
</body>
</html>
