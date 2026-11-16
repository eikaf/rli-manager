<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';
require_once 'lib/RliImport/RliParser.php';
require_once 'lib/RliImport/RliImportService.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

if ($_SERVER['REQUEST_METHOD'] === 'POST') Security::requireCsrf();

$service = new RliImportService($pdo);

$phase      = 'upload';
$errori     = [];
$anteprime  = [];
$risultati  = [];

// ============================================================
// FASE 1: UPLOAD
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'upload') {
    try {
        if (empty($_FILES['rli_files']) || empty($_FILES['rli_files']['tmp_name'])) {
            throw new RuntimeException("Nessun file caricato.");
        }

        $files = $_FILES['rli_files'];
        $tmpDir = sys_get_temp_dir() . '/rli_import_' . session_id();
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        $uploaded = [];
        $count = is_array($files['tmp_name']) ? count($files['tmp_name']) : 1;

        for ($i = 0; $i < $count; $i++) {
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $origName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

            if ($error !== UPLOAD_ERR_OK) {
                $errori[] = "Upload fallito per '{$origName}' (codice {$error}).";
                continue;
            }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if ($ext === 'zip') {
                if (!class_exists('ZipArchive')) {
                    $errori[] = "Estensione ZipArchive non attiva: impossibile estrarre '{$origName}'.";
                    continue;
                }
                $zip = new ZipArchive();
                if ($zip->open($tmpName) !== true) {
                    $errori[] = "Impossibile aprire lo ZIP '{$origName}'.";
                    continue;
                }
                for ($j = 0; $j < $zip->numFiles; $j++) {
                    $entry = $zip->getNameIndex($j);
                    $entryExt = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    if (!in_array($entryExt, ['rli', 'xml', 'txt'], true)) continue;

                    $content = $zip->getFromIndex($j);
                    if ($content === false || trim($content) === '') continue;

                    $destName = $tmpDir . '/' . basename($entry);
                    file_put_contents($destName, $content);
                    $uploaded[] = ['path' => $destName, 'name' => basename($entry)];
                }
                $zip->close();
            } else {
                $destName = $tmpDir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
                if (!move_uploaded_file($tmpName, $destName)) {
                    $errori[] = "Impossibile spostare '{$origName}'.";
                    continue;
                }
                $uploaded[] = ['path' => $destName, 'name' => basename($destName)];
            }
        }

        if (empty($uploaded)) {
            throw new RuntimeException("Nessun file valido da importare.");
        }

        foreach ($uploaded as $u) {
            try {
                $result = $service->analizza($u['path']);
                $anteprime[] = [
                    'path'      => $u['path'],
                    'file'      => $u['name'],
                    'parsed'    => $result['parsed'],
                    'anteprima' => $result['anteprima'],
                ];
            } catch (Throwable $e) {
                $errori[] = "Errore parsing '{$u['name']}': " . $e->getMessage();
            }
        }

        if (!empty($anteprime)) {
            $phase = 'anteprima';
        }
    } catch (Throwable $e) {
        $errori[] = $e->getMessage();
    }
}

// ============================================================
// FASE 2: IMPORT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'importa') {
    try {
        $filesDaImportare = $_POST['file_da_importare'] ?? [];
        $creaSoggetti = !empty($_POST['crea_soggetti']);
        $creaImmobili = !empty($_POST['crea_immobili']);

        if (empty($filesDaImportare)) {
            throw new RuntimeException("Nessun file selezionato per l'import.");
        }

        $parser = new RliParser();
        foreach ($filesDaImportare as $path) {
            if (!file_exists($path)) {
                $risultati[] = ['file' => basename($path), 'ok' => false, 'errore' => 'File non trovato'];
                continue;
            }
            try {
                $parsed = $parser->parseFile($path);
                $res = $service->importa($parsed, [
                    'crea_soggetti' => $creaSoggetti,
                    'crea_immobili' => $creaImmobili,
                ]);
                $risultati[] = [
                    'file' => basename($path),
                    'ok' => true,
                    'modo' => $res['modo'] ?? 'insert_completo',
                    'id_contratto' => $res['id_contratto'],
                    'tipo_evento' => $res['tipo_evento'] ?? null,
                    'data_evento' => $res['data_evento'] ?? null,
                    'creati' => $res['creati'],
                    'collegati' => $res['collegati'],
                ];
            } catch (Throwable $e) {
                $risultati[] = [
                    'file' => basename($path),
                    'ok' => false,
                    'errore' => $e->getMessage(),
                ];
            }
        }

        $phase = 'risultati';

        foreach ($filesDaImportare as $path) {
            if (strpos($path, sys_get_temp_dir()) === 0) @unlink($path);
        }
    } catch (Throwable $e) {
        $errori[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Import RLI — RLI Manager</title>
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
        h3 { color: #4a5568; margin-top: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input[type="file"] { width: 100%; padding: 12px; border: 2px dashed #cbd5e0; border-radius: 8px; background: #f7fafc; cursor: pointer; }
        input[type="file"]:hover { border-color: #4299e1; background: #ebf8ff; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.95em; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-orange { background: #ed8936; color: #fff; }
        .btn-red { background: #f56565; color: #fff; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .warn-box { background: #fff5f5; border-left: 4px solid #f56565; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .ok-box { background: #f0fff4; border-left: 4px solid #48bb78; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .event-box { background: #fffaf0; border-left: 4px solid #ed8936; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; margin: 10px 0; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #edf2f7; }
        th { color: #718096; font-size: 0.8em; text-transform: uppercase; background: #f7fafc; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .badge-ok { background: #c6f6d5; color: #22543d; }
        .badge-warn { background: #feebc8; color: #7b341e; }
        .badge-info { background: #bee3f8; color: #2c5282; }
        .badge-err { background: #fed7d7; color: #742a2a; }
        .badge-event { background: #fed7c8; color: #7b341e; }
        .file-block { border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 20px; background: #fff; }
        .file-block.skip { background: #fff5f5; border-color: #fed7d7; }
        .file-block.ok { background: #f0fff4; border-color: #c6f6d5; }
        .file-block.event { background: #fffaf0; border-color: #feebc8; }
        .check-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
        .check-row input[type="checkbox"] { width: auto; margin: 0; }
        .check-row label { margin: 0; font-weight: normal; }
    </style>
</head>
<body>

<div class="topbar">
    <h1>🏠 RLI Manager</h1>
    <nav style="display:flex; align-items:center;">
        <a href="index.php">Contratti</a>
        <a href="soggetti.php">Soggetti</a>
        <a href="immobili.php">Immobili</a>
        <a href="proprieta.php">Proprietà</a>
        <a href="calendario.php">📅 Calendario</a>
        <a href="dati.php">📊 Dati</a>
        <a href="rli_import.php" class="active">📥 Import</a>
        <?php if ($auth->isAdmin()): ?>
            <a href="utenti.php">👥 Utenti</a>
        <?php endif; ?>
        <a href="notifiche.php" style="position:relative;">
            🔔 Notifiche
            <?php if ($notifPendenti > 0): ?>
                <span style="position:absolute; top:-6px; right:-12px; background:#f56565; color:#fff; font-size:0.7em; font-weight:bold; padding:2px 7px; border-radius:10px;"><?= $notifPendenti ?></span>
            <?php endif; ?>
        </a>
        <a href="profilo.php">👤 Profilo</a>
        <a href="?logout=1" style="color:#fc8181;">Esci</a>
    </nav>
</div>

<div class="container">
    <h2>📥 Import file RLI</h2>

    <?php foreach ($errori as $err): ?>
        <div class="alert alert-error">⚠ <?= Security::h($err) ?></div>
    <?php endforeach; ?>

    <?php if ($phase === 'upload'): ?>
        <div class="card">
            <div class="info-box">
                <strong>Cosa puoi importare:</strong>
                <ul style="margin: 8px 0 0 20px;">
                    <li>File <code>.rli</code> / <code>.xml</code> (uno o più) generati da RLI Manager o altri software</li>
                    <li>Un archivio <code>.zip</code> contenente più file RLI</li>
                </ul>
                <strong>Comportamento:</strong>
                <ul style="margin: 8px 0 0 20px;">
                    <li><strong>Prima registrazione</strong> (tipologia 1): crea contratto completo</li>
                    <li><strong>Adempimento successivo</strong> (proroga, cessione, risoluzione, subentro, rinegoziazione): aggiunge l'evento al contratto esistente</li>
                    <li>Per <strong>cessione/subentro</strong>: il conduttore cedente viene sostituito dal cessionario</li>
                    <li>Soggetti e immobili mancanti vengono creati automaticamente</li>
                    <li>Ogni locatore viene collegato ai propri immobili in <strong>Proprietà</strong></li>
                </ul>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?= Security::csrfField() ?>
                <input type="hidden" name="azione" value="upload">

                <label>Seleziona file RLI / XML / ZIP</label>
                <input type="file" name="rli_files[]" multiple accept=".rli,.xml,.txt,.zip,application/xml,text/xml,application/zip">

                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">📤 Carica e analizza</button>
                </div>
            </form>
        </div>

    <?php elseif ($phase === 'anteprima'): ?>
        <div class="card">
            <div class="ok-box">
                <strong>✓ <?= count($anteprime) ?> file analizzati.</strong>
                Controlla l'anteprima qui sotto, poi conferma l'import.
            </div>

            <form method="POST">
                <?= Security::csrfField() ?>
                <input type="hidden" name="azione" value="importa">

                <?php foreach ($anteprime as $idx => $a):
                    $ap = $a['anteprima'];
                    $modo = $ap['modo_import'];

                    $blockClass = '';
                    $badge = '';
                    if ($modo === 'skip') { $blockClass = 'skip'; $badge = '<span class="badge badge-err">SKIP</span>'; }
                    elseif ($modo === 'insert_completo') { $blockClass = 'ok'; $badge = '<span class="badge badge-ok">NUOVO CONTRATTO</span>'; }
                    elseif ($modo === 'insert_evento') { $blockClass = 'event'; $badge = '<span class="badge badge-event">ADEMPIMENTO SUCCESSIVO</span>'; }
                    elseif ($modo === 'errore') { $blockClass = 'skip'; $badge = '<span class="badge badge-err">ERRORE</span>'; }
                ?>
                    <div class="file-block <?= $blockClass ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <strong>📄 <?= Security::h($a['file']) ?></strong>
                                <?= $badge ?>
                                <span style="color:#718096; font-size:0.85em; margin-left:10px;">
                                    (<?= Security::h($ap['tipo_adempimento_label']) ?>)
                                </span>
                            </div>
                            <?php if ($modo === 'insert_completo' || $modo === 'insert_evento'): ?>
                                <label class="check-row">
                                    <input type="checkbox" name="file_da_importare[]" value="<?= Security::h($a['path']) ?>" checked>
                                    <span>Importa questo file</span>
                                </label>
                            <?php endif; ?>
                        </div>

                        <?php if ($modo === 'skip' || $modo === 'errore'): ?>
                            <div class="warn-box" style="margin-top:10px;">
                                ⚠ <?= Security::h($ap['motivo_skip'] ?? 'Non importabile') ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($modo === 'insert_evento' && !empty($ap['evento_da_importare'])): ?>
                            <div class="event-box" style="margin-top:10px;">
                                <strong>📌 Evento da aggiungere al contratto #<?= (int)$ap['contratto_originale_id'] ?></strong>
                                (<?= Security::h($ap['contratto_originale_univoco'] ?? '') ?>)<br>
                                <strong>Tipo:</strong> <?= Security::h($ap['evento_da_importare']['tipo']) ?><br>
                                <strong>Data evento:</strong> <?= Security::h($ap['evento_da_importare']['data_evento']) ?>
                                <?php if (!empty($ap['evento_da_importare']['tipologia_proroga'])): ?>
                                    <br><strong>Tipologia proroga:</strong> <?= Security::h($ap['evento_da_importare']['tipologia_proroga']) ?>
                                <?php endif; ?>
                                <?php if (!empty($ap['evento_da_importare']['tipologia_subentro'])): ?>
                                    <br><strong>Tipologia subentro:</strong> <?= Security::h($ap['evento_da_importare']['tipologia_subentro']) ?>
                                <?php endif; ?>
                                <?php if (!empty($ap['evento_da_importare']['corrispettivo'])): ?>
                                    <br><strong>Corrispettivo:</strong> € <?= number_format((float)$ap['evento_da_importare']['corrispettivo'], 2, ',', '.') ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- DATI CONTRATTO (solo se prima registrazione) -->
                        <?php if ($modo === 'insert_completo'): ?>
                            <h3 style="font-size:1em; margin-top:15px;">Contratto</h3>
                            <table>
                                <tr><th style="width:200px;">ID interno</th><td><?= Security::h($ap['id_contratto_univoco'] ?: '—') ?></td></tr>
                                <tr><th>Tipologia</th><td><?= Security::h($ap['tipologia_contratto']) ?></td></tr>
                                <tr><th>Data stipula</th><td><?= Security::h($ap['data_stipula'] ?: '—') ?></td></tr>
                                <tr><th>Durata</th><td><?= Security::h(($ap['durata_dal'] ?: '—') . ' → ' . ($ap['durata_al'] ?: '—')) ?></td></tr>
                                <tr><th>Canone</th><td>€ <?= number_format((float)$ap['importo_canone'], 2, ',', '.') ?></td></tr>
                            </table>
                        <?php endif; ?>

                        <!-- SOGGETTI -->
                        <?php if (!empty($ap['locatori'])): ?>
                            <h3 style="font-size:1em;">Locatori (<?= count($ap['locatori']) ?>)</h3>
                            <table>
                                <thead><tr><th>CF</th><th>Cognome</th><th>Nome</th><th>Stato</th></tr></thead>
                                <tbody>
                                    <?php foreach ($ap['locatori'] as $l): ?>
                                        <tr>
                                            <td style="font-family:monospace;"><?= Security::h($l['codice_fiscale']) ?></td>
                                            <td><?= Security::h($l['cognome']) ?></td>
                                            <td><?= Security::h($l['nome']) ?></td>
                                            <td>
                                                <?php if ($l['esistente']): ?>
                                                    <span class="badge badge-info">Già presente (#<?= (int)$l['id_esistente'] ?>)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-ok">Da creare</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php if (!empty($ap['conduttori'])): ?>
                            <h3 style="font-size:1em;">Conduttori (<?= count($ap['conduttori']) ?>)</h3>
                            <table>
                                <thead><tr><th>CF</th><th>Cognome</th><th>Nome</th><th>Ruolo</th><th>Stato</th></tr></thead>
                                <tbody>
                                    <?php foreach ($ap['conduttori'] as $c): ?>
                                        <tr>
                                            <td style="font-family:monospace;"><?= Security::h($c['codice_fiscale']) ?></td>
                                            <td><?= Security::h($c['cognome']) ?></td>
                                            <td><?= Security::h($c['nome']) ?></td>
                                            <td>
                                                <?php if (($c['tipo_cessione'] ?? '') === '1'): ?>
                                                    <span class="badge badge-warn">Cedente</span>
                                                <?php elseif (($c['tipo_cessione'] ?? '') === '2'): ?>
                                                    <span class="badge badge-ok">Cessionario</span>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($c['esistente']): ?>
                                                    <span class="badge badge-info">Già presente (#<?= (int)$c['id_esistente'] ?>)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-ok">Da creare</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php if (!empty($ap['immobili'])): ?>
                            <h3 style="font-size:1em;">Immobili (<?= count($ap['immobili']) ?>)</h3>
                            <table>
                                <thead><tr><th>Comune</th><th>Fg</th><th>Part</th><th>Sub</th><th>Cat.</th><th>Stato</th></tr></thead>
                                <tbody>
                                    <?php foreach ($ap['immobili'] as $i): ?>
                                        <tr>
                                            <td><?= Security::h($i['comune']) ?> (<?= Security::h($i['provincia']) ?>)</td>
                                            <td><?= Security::h($i['foglio']) ?></td>
                                            <td><?= Security::h($i['particella']) ?></td>
                                            <td><?= Security::h($i['subalterno']) ?></td>
                                            <td><?= Security::h($i['categoria']) ?></td>
                                            <td>
                                                <?php if ($i['esistente']): ?>
                                                    <span class="badge badge-info">Già presente (#<?= (int)$i['id_esistente'] ?>)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-ok">Da creare</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php if (!empty($ap['proprieta'])): ?>
                            <h3 style="font-size:1em;">🏘 Proprietà da collegare (<?= count($ap['proprieta']) ?>)</h3>
                            <table>
                                <thead><tr><th>Locatore</th><th>Immobile</th><th>Quota</th><th>Stato</th></tr></thead>
                                <tbody>
                                    <?php foreach ($ap['proprieta'] as $p): ?>
                                        <tr>
                                            <td>
                                                <?= Security::h($p['locatore_nome']) ?>
                                                <br><small style="color:#718096; font-family:monospace;"><?= Security::h($p['locatore_cf']) ?></small>
                                            </td>
                                            <td style="font-family:monospace;"><?= Security::h($p['immobile_chiave']) ?></td>
                                            <td><?= number_format($p['quota'], 2, ',', '') ?>%</td>
                                            <td>
                                                <?php if (!empty($p['esiste'])): ?>
                                                    <span class="badge badge-info">Già presente</span>
                                                <?php else: ?>
                                                    <span class="badge badge-ok">Da creare</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="card" style="background:#f7fafc;">
                    <h3 style="margin-top:0;">Opzioni import</h3>
                    <label class="check-row">
                        <input type="checkbox" name="crea_soggetti" value="1" checked>
                        <span>Crea soggetti mancanti automaticamente</span>
                    </label>
                    <label class="check-row">
                        <input type="checkbox" name="crea_immobili" value="1" checked>
                        <span>Crea immobili mancanti automaticamente</span>
                    </label>
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-green">✅ Conferma import</button>
                    <a href="rli_import.php" class="btn btn-gray">Annulla</a>
                </div>
            </form>
        </div>

    <?php elseif ($phase === 'risultati'): ?>
        <div class="card">
            <h2>Risultati import</h2>

            <?php
            $okCount = 0; $failCount = 0;
            foreach ($risultati as $r) { if ($r['ok']) $okCount++; else $failCount++; }
            ?>

            <div class="alert <?= $failCount > 0 ? 'alert-error' : 'alert-success' ?>">
                <strong><?= $okCount ?> importati con successo</strong>
                <?php if ($failCount > 0): ?>, <strong><?= $failCount ?> falliti</strong><?php endif; ?>
            </div>

            <?php foreach ($risultati as $r): ?>
                <div class="file-block <?= $r['ok'] ? 'ok' : 'skip' ?>">
                    <strong><?= Security::h($r['file']) ?></strong>
                    <?php if ($r['ok']): ?>
                        <?php if (($r['modo'] ?? '') === 'insert_evento'): ?>
                            <span class="badge badge-event">EVENTO IMPORTATO</span>
                            <p style="margin:10px 0 5px 0;">
                                Evento <strong><?= Security::h($r['tipo_evento']) ?></strong>
                                del <strong><?= Security::h($r['data_evento']) ?></strong>
                                aggiunto al contratto <strong>#<?= (int)$r['id_contratto'] ?></strong>
                                — <a href="contract.php?id=<?= (int)$r['id_contratto'] ?>" class="btn btn-primary" style="padding:5px 12px; font-size:0.8em;">📂 Apri</a>
                            </p>
                        <?php else: ?>
                            <span class="badge badge-ok">CONTRATTO CREATO</span>
                            <p style="margin:10px 0 5px 0;">
                                Contratto creato: <strong>#<?= (int)$r['id_contratto'] ?></strong>
                                — <a href="contract.php?id=<?= (int)$r['id_contratto'] ?>" class="btn btn-primary" style="padding:5px 12px; font-size:0.8em;">📂 Apri</a>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($r['creati'])): ?>
                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer; color:#22543d; font-weight:bold;">
                                    ✅ <?= count($r['creati']) ?> record creati
                                </summary>
                                <ul style="margin:8px 0 0 20px; font-size:0.9em;">
                                    <?php foreach ($r['creati'] as $c): ?>
                                        <li>
                                            <?php if (($c['tipo'] ?? '') === 'contratto'): ?>
                                                Contratto #<?= (int)$c['id'] ?>
                                            <?php elseif (($c['tipo'] ?? '') === 'contratto_conduttore'): ?>
                                                Collegamento contratto #<?= (int)$c['id_contratto'] ?> ↔ conduttore #<?= (int)$c['id_conduttore'] ?>
                                            <?php elseif (($c['tipo'] ?? '') === 'evento'): ?>
                                                Evento <?= Security::h($c['sottotipo']) ?> del <?= Security::h($c['data']) ?>
                                            <?php elseif (($c['tipo'] ?? '') === 'immobile'): ?>
                                                Immobile <?= Security::h($c['chiave'] ?? '') ?> → #<?= (int)$c['id'] ?>
                                            <?php elseif (($c['tipo'] ?? '') === 'proprieta'): ?>
                                                Proprietà locatore #<?= (int)$c['id_locatore'] ?> ↔ immobile #<?= (int)$c['id_immobile'] ?> (<?= number_format($c['quota'] ?? 0, 2, ',', '') ?>%)
                                            <?php else: ?>
                                                <?= Security::h(ucfirst($c['tipo'])) ?> CF <?= Security::h($c['cf'] ?? '') ?> → #<?= (int)$c['id'] ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($r['collegati'])): ?>
                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer; color:#2c5282; font-weight:bold;">
                                    🔗 <?= count($r['collegati']) ?> record collegati (già esistenti)
                                </summary>
                                <ul style="margin:8px 0 0 20px; font-size:0.9em;">
                                    <?php foreach ($r['collegati'] as $c): ?>
                                        <li>
                                            <?php if (($c['tipo'] ?? '') === 'immobile'): ?>
                                                Immobile <?= Security::h($c['chiave'] ?? '') ?> → #<?= (int)$c['id'] ?>
                                            <?php elseif (($c['tipo'] ?? '') === 'proprieta'): ?>
                                                Proprietà locatore #<?= (int)$c['id_locatore'] ?> ↔ immobile #<?= (int)$c['id_immobile'] ?>
                                            <?php else: ?>
                                                <?= Security::h(ucfirst($c['tipo'])) ?> CF <?= Security::h($c['cf'] ?? '') ?> → #<?= (int)$c['id'] ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-err">FALLITO</span>
                        <p style="color:#742a2a; margin:10px 0 0 0;"><?= Security::h($r['errore']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div style="margin-top:20px;">
                <a href="index.php" class="btn btn-primary">← Torna ai contratti</a>
                <a href="rli_import.php" class="btn btn-gray">Importa altri file</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="assets/uppercase.js"></script>
</body>
</html>
