<?php
session_start();
include 'db.php';
require_once 'config.php';
$auth->requireLogin();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Export CSV — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        .export-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px; }
        .export-card { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; transition: all 0.2s; }
        .export-card:hover { background: #edf2f7; border-color: #4299e1; }
        .export-card h3 { margin-top: 0; color: #2d3748; }
        .export-card p { color: #718096; font-size: 0.9em; margin-bottom: 15px; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>📤 Export CSV</h2>
    <div class="card">
        <p>Seleziona il tipo di dati da esportare. Verranno esportati solo i dati visibili al tuo profilo (per gli operator: propri + condivisi).</p>

        <div class="export-grid">
            <div class="export-card">
                <h3>📄 Contratti</h3>
                <p>Tutti i contratti visibili con stato, tipologia, canone, durata, locatori e conduttori.</p>
                <a href="<?= url('export_csv.php') ?>?tipo=contratti" class="btn btn-primary">⬇ Esporta Contratti</a>
            </div>

            <div class="export-card">
                <h3>👥 Soggetti</h3>
                <p>Anagrafica completa: CF, dati personali, indirizzo, email, telefono, ruolo.</p>
                <a href="<?= url('export_csv.php') ?>?tipo=soggetti" class="btn btn-primary">⬇ Esporta Soggetti</a>
            </div>

            <div class="export-card">
                <h3>🏠 Immobili</h3>
                <p>Dati catastali completi: comune, foglio, particella, subalterno, categoria, rendita, indirizzo.</p>
                <a href="<?= url('export_csv.php') ?>?tipo=immobili" class="btn btn-primary">⬇ Esporta Immobili</a>
            </div>

            <div class="export-card">
                <h3>🏘 Proprietà</h3>
                <p>Collegamenti locatore ↔ immobile con percentuale di possesso.</p>
                <a href="<?= url('export_csv.php') ?>?tipo=proprieta" class="btn btn-primary">⬇ Esporta Proprietà</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
