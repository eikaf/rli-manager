<?php
session_start();
include 'db.php';
require_once 'config.php';

$auth->requireLogin();

// Statistiche DB
$stats = [
    'contratti' => (int)$pdo->query("SELECT COUNT(*) FROM contratti")->fetchColumn(),
    'soggetti' => (int)$pdo->query("SELECT COUNT(*) FROM soggetti")->fetchColumn(),
    'immobili' => (int)$pdo->query("SELECT COUNT(*) FROM immobili")->fetchColumn(),
    'utenti' => (int)$pdo->query("SELECT COUNT(*) FROM utenti WHERE attivo = 1")->fetchColumn(),
    'notifiche' => (int)$pdo->query("SELECT COUNT(*) FROM notifiche WHERE letta = 0")->fetchColumn(),
    'eventi' => (int)$pdo->query("SELECT COUNT(*) FROM eventi_contratto")->fetchColumn(),
];

// Info server
$serverInfo = [
    'php_version' => PHP_VERSION,
    'mysql_version' => $pdo->query("SELECT VERSION()")->fetchColumn(),
    'db_size' => $pdo->query("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
    ")->fetchColumn(),
    'db_name' => 'db_rli',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Dati — RLI Manager</title>
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
        .actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 20px; }
        .action-card { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; transition: all 0.2s; text-decoration: none; color: inherit; display: block; }
        .action-card:hover { background: #edf2f7; border-color: #4299e1; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .action-card h3 { margin-top: 0; color: #2d3748; font-size: 1.05em; }
        .action-card p { color: #718096; font-size: 0.88em; margin: 0 0 10px 0; }
        .action-card .icon { font-size: 2em; margin-bottom: 8px; display: block; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-top: 15px; }
        .stat-box { background: #f7fafc; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-box .val { font-size: 1.8em; font-weight: bold; color: #2b6cb0; }
        .stat-box .lbl { font-size: 0.8em; color: #718096; text-transform: uppercase; margin-top: 5px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #edf2f7; font-size: 0.9em; }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: #718096; }
        .info-row .val { color: #2d3748; font-weight: 600; font-family: monospace; }
        .badge-admin { background: #fef3c7; color: #744210; padding: 2px 8px; border-radius: 8px; font-size: 0.75em; font-weight: bold; }
        .info-box { background: #fffaf0; border-left: 4px solid #ed8936; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9em; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>📊 Gestione Dati</h2>

    <div class="card">
        <h3 style="margin-top:0; color:#4a5568;">Operazioni</h3>
        <div class="actions-grid">
            <a href="<?= url('export_csv_index.php') ?>" class="action-card">
                <span class="icon">📤</span>
                <h3>Export CSV</h3>
                <p>Esporta contratti, soggetti, immobili, proprietà in formato CSV</p>
            </a>

            <a href="<?= url('import_csv.php') ?>" class="action-card">
                <span class="icon">📥</span>
                <h3>Import CSV</h3>
                <p>Importa soggetti o immobili da file CSV con anteprima</p>
            </a>

            <a href="<?= url('backup.php') ?>" class="action-card">
                <span class="icon">💾</span>
                <h3>Backup Database</h3>
                <p>Scarica un dump completo del database in formato SQL</p>
            </a>

            <a href="<?= url('rli_import.php') ?>" class="action-card">
                <span class="icon">📄</span>
                <h3>Import RLI</h3>
                <p>Importa file .rli (XML RLI12) generati da altri software</p>
            </a>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0; color:#4a5568;">📊 Statistiche database</h3>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="val"><?= $stats['contratti'] ?></div>
                <div class="lbl">Contratti</div>
            </div>
            <div class="stat-box">
                <div class="val"><?= $stats['soggetti'] ?></div>
                <div class="lbl">Soggetti</div>
            </div>
            <div class="stat-box">
                <div class="val"><?= $stats['immobili'] ?></div>
                <div class="lbl">Immobili</div>
            </div>
            <div class="stat-box">
                <div class="val"><?= $stats['eventi'] ?></div>
                <div class="lbl">Eventi</div>
            </div>
            <div class="stat-box">
                <div class="val"><?= $stats['utenti'] ?></div>
                <div class="lbl">Utenti attivi</div>
            </div>
            <div class="stat-box">
                <div class="val"><?= $stats['notifiche'] ?></div>
                <div class="lbl">Notifiche da leggere</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0; color:#4a5568;">⚙️ Info sistema</h3>
        <div class="info-row">
            <span class="lbl">Versione PHP</span>
            <span class="val"><?= Security::h($serverInfo['php_version']) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Versione MySQL/MariaDB</span>
            <span class="val"><?= Security::h($serverInfo['mysql_version']) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Nome database</span>
            <span class="val"><?= Security::h($serverInfo['db_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Dimensione database</span>
            <span class="val"><?= Security::h($serverInfo['db_size']) ?> MB</span>
        </div>
    </div>
</div>

<script src="assets/uppercase.js"></script>
</body>
</html>
