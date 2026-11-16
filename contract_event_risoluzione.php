<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/ContrattoRepository.php';
require_once 'lib/FiscalCalculator.php';
require_once 'lib/NotificaRepository.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

$id = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'risoluzione'; // risoluzione o risoluzione_corrispettivo

if ($id <= 0) { header("Location: index.php"); exit; }

// Badge notifiche topbar
$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$repo = new ContrattoRepository($pdo);
$idEvento = (int)($_GET['id_evento'] ?? 0);
$eventoEdit = null;
if ($idEvento > 0) {
    $stmtEv = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
    $stmtEv->execute([$idEvento, $id]);
    $eventoEdit = $stmtEv->fetch();
}
$contratto = $repo->find($id);
if (!$contratto) { header("Location: index.php"); exit; }

$locatori = $repo->locatori($id);
$conduttori = $repo->conduttori($id);

$tipoLabel = rli_evento_label($tipo);

// Inizializza le variabili per la precompilazione
$evDataInvio = date('Y-m-d');
$evDataImpegno = date('Y-m-d');
$evDataEvento = date('Y-m-d');
$evDurataAl = date('Y-m-d');
$evNote = '';

if ($eventoEdit) {
    $det = json_decode($eventoEdit['dettagli_json'] ?? '{}', true) ?: [];
    $evDataInvio = $eventoEdit['data_invio'] ?? date('Y-m-d');
    $evDataImpegno = $det['data_impegno_intermediario'] ?? $eventoEdit['data_invio'] ?? date('Y-m-d');
    $evDataEvento = $eventoEdit['data_evento'] ?? date('Y-m-d');
    $evDurataAl = $det['durata_al'] ?? $eventoEdit['data_evento'] ?? date('Y-m-d');
    $evNote = $det['note'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($tipoLabel) ?> — Contratto #<?= $id ?></title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        h4 { color: #4a5568; margin: 20px 0 10px 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 15px; font-size: 0.95em; }
        input:focus, select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 15px; }
        .row > div { flex: 1; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.95em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-red { background: #f56565; color: #fff; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .warn-box { background: #fff5f5; border-left: 4px solid #f56565; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .hint { color: #718096; font-size: 0.85em; margin-top: -10px; margin-bottom: 12px; display: block; }
        .data-invio-box { background: #f0f9ff; border-left: 4px solid #4299e1; padding: 15px 18px; border-radius: 8px; margin-bottom: 20px; }
        .data-invio-box h4 { margin: 0 0 10px 0; border: none; padding: 0; color: #2b6cb0; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <a href="<?= url('contract.php') ?>?id=<?= $id ?>" style="color:#4299e1; text-decoration:none; font-size:0.9em;">← Torna al contratto</a>

    <h2 style="margin-top:20px;"><?= htmlspecialchars($tipoLabel) ?></h2>
    <p style="color:#718096;">Contratto #<?= $id ?> — <?= htmlspecialchars($contratto['id_contratto_univoco'] ?: 'ID non assegnato') ?></p>

    <div class="info-box">
        <strong>Contratto attuale:</strong><br>
        Locatori: <?= htmlspecialchars(implode(', ', array_map(fn($l) => $l['cognome'] . ' ' . $l['nome'], $locatori))) ?><br>
        Conduttori: <?= htmlspecialchars(implode(', ', array_map(fn($c) => $c['cognome'] . ' ' . $c['nome'], $conduttori))) ?><br>
        Durata: <?= $contratto['durata_dal'] ? date('d/m/Y', strtotime($contratto['durata_dal'])) : '—' ?>
                → <?= $contratto['durata_al'] ? date('d/m/Y', strtotime($contratto['durata_al'])) : '—' ?>
    </div>

    <form method="POST" action="save_pratica.php" enctype="multipart/form-data" id="form-risoluzione">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="<?= $eventoEdit ? 'update_evento' : 'save_evento' ?>">
        <input type="hidden" name="id_evento" value="<?= $idEvento ?>">
        <input type="hidden" name="id_contratto" value="<?= $id ?>">
        <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($tipo) ?>">

        <!-- DATA INVIO TELEMATICO -->
        <div class="card">
            <div class="data-invio-box">
                <h4>📅 Data invio telematico (prevista)</h4>
                <p style="font-size:0.85em; color:#2c5282; margin:0 0 10px 0;">
                    Data in cui prevedi di trasmettere questo adempimento all'Agenzia delle Entrate.
                    <strong>Determina i giorni di ritardo e quindi sanzioni e interessi del ravvedimento operoso.</strong>
                </p>
                <div class="row">
                    <div>
                        <label for="data_invio_input">Data invio telematico *</label>
                        <input type="date" name="data_invio" id="data_invio_input" value="<?= htmlspecialchars($evDataInvio) ?>" required>
                    </div>
                    <div>
                        <label for="data_impegno_input">Data impegno intermediario</label>
                        <input type="date" name="data_impegno_intermediario" id="data_impegno_input" value="<?= htmlspecialchars($evDataImpegno) ?>">
                    </div>
                    <div style="display:flex; align-items:flex-end;">
                        <button type="button" class="btn btn-gray" onclick="resetDataInvio()" style="margin-bottom:15px;">↺ Oggi</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h4><?= htmlspecialchars($tipoLabel) ?></h4>
            
            <label>Data risoluzione *</label>
            <input type="date" name="durata_al" id="durata_al" required value="<?= htmlspecialchars($evDurataAl) ?>">

            <?php if ($tipo === 'risoluzione_corrispettivo'): ?>
                <label>Corrispettivo *</label>
                <input type="number" step="0.01" name="corrispettivo" required placeholder="€">
            <?php endif; ?>

            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= htmlspecialchars($evDataEvento) ?>">

            <h4>Note interne</h4>
            <textarea name="note_evento" rows="2" placeholder="Note sulla risoluzione"><?= htmlspecialchars($evNote) ?></textarea>

            <h4>Allegato PDF/A (opzionale)</h4>
            <input type="file" name="allegato_pdf" accept="application/pdf,.pdf">
            <p style="font-size:0.8em; color:#718096;">Allega il verbale di riconsegna o la scrittura di risoluzione (se disponibile).</p>
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-red">❌ Registra Risoluzione</button>
            <a href="<?= url('contract.php') ?>?id=<?= $id ?>" class="btn btn-gray">Annulla</a>
        </div>
    </form>
</div>

<script>
function resetDataInvio() {
    const el = document.getElementById('data_invio_input');
    if (!el) return;
    el.value = new Date().toISOString().slice(0, 10);
    el.dispatchEvent(new Event('change'));
}
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
