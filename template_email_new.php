<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/ComunicazioneRepository.php';

$auth->requireAdmin();
$comRepo = new ComunicazioneRepository($pdo);
$error = '';

// Salvataggio
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
    try {
        $codice = strtolower(trim($_POST['codice'] ?? ''));
        $nome = trim($_POST['nome'] ?? '');
        $oggetto = trim($_POST['oggetto'] ?? '');
        $corpo = trim($_POST['corpo'] ?? '');
        
        if ($codice === '' || $nome === '' || $oggetto === '' || $corpo === '') {
            throw new Exception('Tutti i campi sono obbligatori.');
        }
        
        $comRepo->saveTemplate([
            'codice' => $codice,
            'nome' => $nome,
            'oggetto' => $oggetto,
            'corpo' => $corpo,
            'attivo' => 1
        ]);
        
        header('Location: template_email.php?msg=Template+creato+con+successo');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Nuovo Template — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; font-family: inherit; }
        textarea { resize: vertical; min-height: 200px; font-family: monospace; }
        input:focus, textarea:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <div style="margin-bottom: 15px;">
        <a href="<?= url('template_email.php') ?>" class="btn btn-gray">← Torna ai template</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error">⚠ <?= Security::h($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>Nuovo Template Email</h2>
        
        <div class="info-box">
            <strong>Variabili disponibili</strong> (non distinguono maiuscole/minuscole):<br>
            <code>{id_contratto}</code>, <code>{codice_ade}</code>,
            <code>{locatore_nome}</code> / <code>{nome_locatore}</code> / <code>{locatori}</code> (tutti i locatori),
            <code>{locatore_cognome}</code>, <code>{locatore_email}</code>,
            <code>{conduttore_nome}</code>, <code>{conduttori_list}</code>, <code>{conduttori_nomi}</code>,
            <code>{data_stipula}</code>, <code>{data_scadenza}</code>, <code>{canone}</code>,
            <code>{immobile_indirizzo}</code>, <code>{immobile_comune}</code>,
            <code>{studio_nome}</code>, <code>{data_oggi}</code>
        </div>

        <form method="POST" autocomplete="off">
            <?= Security::csrfField() ?>

            <div class="row">
                <div>
                    <label>Codice (univoco) *</label>
                    <input type="text" name="codice" class="no-uppercase" required placeholder="es. sollecito_scadenza">
                </div>
                <div>
                    <label>Nome visualizzato *</label>
                    <input type="text" name="nome" required placeholder="es. Sollecito scadenza contratto">
                </div>
            </div>

            <label>Oggetto *</label>
            <input type="text" name="oggetto" class="no-uppercase" required placeholder="es. Promemoria scadenza contratto {id_contratto}">

            <label>Corpo del messaggio *</label>
            <textarea name="corpo" class="no-uppercase" required placeholder="Gentile {locatore_nome},&#10;..."></textarea>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" class="btn btn-green">💾 Salva Template</button>
                <a href="<?= url('template_email.php') ?>" class="btn btn-gray">Annulla</a>
            </div>
        </form>
    </div>
</div>

<script src="assets/uppercase.js"></script>
</body>
</html>
