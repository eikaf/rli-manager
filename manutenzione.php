<?php
session_start();
include 'db.php';
require_once 'config.php';

$auth->requireAdmin();

$messaggio = '';

// Gestione POST: attiva/disattiva manutenzione
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
    if (isset($_POST['attiva'])) {
        setManutenzione($pdo, true);
        $messaggio = '✅ Manutenzione ATTIVATA. Solo gli admin possono accedere.';
        Logger::azione('manutenzione_attiva', 'sistema');
    } elseif (isset($_POST['disattiva'])) {
        setManutenzione($pdo, false);
        $messaggio = '✅ Manutenzione DISATTIVATA. Tutti gli utenti possono accedere.';
        Logger::azione('manutenzione_disattiva', 'sistema');
    }
}

$manutenzioneAttiva = isManutenzioneAttiva($pdo);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Manutenzione — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-info { background: #bee3f8; color: #2c5282; }
        .alert-warning { background: #feebc8; color: #7b341e; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.95em; text-decoration: none; display: inline-block; }
        .btn-red { background: #f56565; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .status { display: flex; align-items: center; gap: 10px; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .status.active { background: #fff5f5; border: 2px solid #f56565; }
        .status.inactive { background: #f0fff4; border: 2px solid #48bb78; }
        .status .icon { font-size: 2em; }
        .status .text h3 { margin: 0; font-size: 1.1em; }
        .status .text p { margin: 5px 0 0 0; color: #4a5568; font-size: 0.9em; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>🔧 Manutenzione del Portale</h2>

    <?php if ($messaggio): ?>
        <div class="alert alert-success"><?= $messaggio ?></div>
    <?php endif; ?>

    <div class="card">
        <?php if ($manutenzioneAttiva): ?>
            <div class="status active">
                <div class="icon">🔴</div>
                <div class="text">
                    <h3>Manutenzione ATTIVA</h3>
                    <p>Solo gli amministratori possono accedere al portale. Gli altri utenti vedono una pagina di manutenzione.</p>
                </div>
            </div>
            <form method="POST">
                <?= Security::csrfField() ?>
                <button type="submit" name="disattiva" value="1" class="btn btn-green">✅ Disattiva Manutenzione</button>
            </form>
        <?php else: ?>
            <div class="status inactive">
                <div class="icon">🟢</div>
                <div class="text">
                    <h3>Portale ATTIVO</h3>
                    <p>Tutti gli utenti possono accedere normalmente.</p>
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('Attivare la modalità manutenzione? Tutti gli utenti non-admin verranno bloccati.')">
                <?= Security::csrfField() ?>
                <button type="submit" name="attiva" value="1" class="btn btn-red">🚫 Attiva Manutenzione</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>ℹ️ Cosa succede in manutenzione</h3>
        <ul>
            <li>Gli utenti con ruolo <strong>admin</strong> possono continuare a lavorare normalmente.</li>
            <li>Gli utenti con ruolo <strong>operator</strong> e <strong>viewer</strong> vengono reindirizzati alla pagina <code>manutenzione_attiva.php</code>.</li>
            <li>La modalità manutenzione è persistente (rimane attiva anche dopo il logout).</li>
            <li>Per disattivarla, torna su questa pagina e clicca "Disattiva Manutenzione".</li>
        </ul>
    </div>
</div>

<script src="assets/uppercase.js"></script>
</body>
</html>
