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
$idEvento = (int)($_GET['id_evento'] ?? 0);
if ($id <= 0) { header("Location: index.php"); exit; }

// Se id_evento è presente, carica i dati per la modifica
$eventoEdit = null;
if ($idEvento > 0) {
    $stmtEv = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
    $stmtEv->execute([$idEvento, $id]);
    $eventoEdit = $stmtEv->fetch();
    if (!$eventoEdit) { header("Location: contract.php?id=$id&error=Evento+non+trovato"); exit; }
}

// Variabili per la precompilazione
$evDataEvento = date('Y-m-d');
$evDataInvio = date('Y-m-d');
$evDataImpegno = date('Y-m-d');
$evNote = '';
$evLato = 'conduttore';
$evCedente = '';
$evCessionari = [];
$evDataCessione = date('Y-m-d');
$evCorrispettivo = '';
$evDataSubentro = date('Y-m-d');
$evTipologiaSubentro = '1';
$evDurataAl = date('Y-m-d');

if ($eventoEdit) {
    $det = json_decode($eventoEdit['dettagli_json'] ?? '{}', true) ?: [];
    $evDataEvento = $eventoEdit['data_evento'] ?? date('Y-m-d');
    $evDataInvio = $eventoEdit['data_invio'] ?? date('Y-m-d');
    $evDataImpegno = $det['data_impegno_intermediario'] ?? $eventoEdit['data_invio'] ?? date('Y-m-d');
    $evNote = $det['note'] ?? '';
    $evLato = $det['lato_subentro'] ?? $det['lato_cessione'] ?? 'conduttore';
    $evCedente = $det['soggetto_cedente_id'] ?? '';
    $evCessionari = $det['cessionari_ids'] ?? [];
    $evDataCessione = $det['data_cessione'] ?? $eventoEdit['data_evento'] ?? date('Y-m-d');
    $evCorrispettivo = $det['corrispettivo'] ?? '';
    $evDataSubentro = $det['data_subentro'] ?? $eventoEdit['data_evento'] ?? date('Y-m-d');
    $evTipologiaSubentro = $det['tipologia_subentro'] ?? '1';
    $evDurataAl = $det['durata_al'] ?? $eventoEdit['data_evento'] ?? date('Y-m-d');

    // Fallback: se non ci sono cessionari salvati, usa i locatori/conduttori attuali (escluso il cedente)
    // (verrà eseguito dopo il caricamento di $repo e $locatori/$conduttori)
}

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$repo = new ContrattoRepository($pdo);
$contratto = $repo->find($id);
if (!$contratto) { header("Location: index.php"); exit; }

$locatori = $repo->locatori($id);
$conduttori = $repo->conduttori($id);

// Fallback: se non ci sono cessionari salvati, usa i locatori/conduttori attuali (escluso il cedente)
if ($eventoEdit && empty($evCessionari)) {
    $soggettiAttuali = array_merge($locatori, $conduttori);
    foreach ($soggettiAttuali as $sogg) {
        if ((int)$sogg['id'] !== (int)$evCedente) {
            $evCessionari[] = (int)$sogg['id'];
        }
    }
}

$tipo = 'subentro';
$tipoLabel = rli_evento_label($tipo);
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
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .data-invio-box { background: #f0f9ff; border-left: 4px solid #4299e1; padding: 15px 18px; border-radius: 8px; margin-bottom: 20px; }
        .data-invio-box h4 { margin: 0 0 10px 0; border: none; padding: 0; color: #2b6cb0; }
        .hint { color: #718096; font-size: 0.85em; margin-top: -10px; margin-bottom: 12px; display: block; }
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

    <form method="POST" action="save_pratica.php" enctype="multipart/form-data" id="form-subentro">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="<?= $eventoEdit ? 'update_evento' : 'save_evento' ?>">
        <input type="hidden" name="id_evento" value="<?= $idEvento ?>">
        <input type="hidden" name="id_contratto" value="<?= $id ?>">
        <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($tipo) ?>">

        <!-- DATA INVIO -->
        <div class="card">
            <div class="data-invio-box">
                <h4>📅 Data invio telematico (prevista)</h4>
                <p style="font-size:0.85em; color:#2c5282; margin:0 0 10px 0;">
                    Data in cui prevedi di trasmettere questo adempimento all'Agenzia delle Entrate.
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

        <!-- DATI SUBENTRO -->
        <div class="card">
            <h4>Subentro nel contratto</h4>
            
            <div class="row">
                <div>
                    <label>Lato del Subentro *</label>
                    <select name="lato_subentro" required>
                        <option value="conduttore" <?= $evLato === 'conduttore' ? 'selected' : '' ?>>Conduttore (es. muore l'inquilino)</option>
                        <option value="locatore" <?= $evLato === 'locatore' ? 'selected' : '' ?>>Locatore (es. muore il proprietario)</option>
                        <option value="entrambi" <?= $evLato === 'entrambi' ? 'selected' : '' ?>>Entrambi</option>
                    </select>
                </div>
                <div>
                    <label>Data subentro *</label>
                    <input type="date" name="data_subentro" required value="<?= htmlspecialchars($evDataSubentro) ?>">
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Tipologia subentro *</label>
                    <select name="tipologia_subentro" required>
                        <option value="1" <?= $evTipologiaSubentro === '1' ? 'selected' : '' ?>>1 - Decesso</option>
                        <option value="2" <?= $evTipologiaSubentro === '2' ? 'selected' : '' ?>>2 - Trasferimento immobiliare</option>
                        <option value="3" <?= $evTipologiaSubentro === '3' ? 'selected' : '' ?>>3 - Trasformazione</option>
                        <option value="4" <?= $evTipologiaSubentro === '4' ? 'selected' : '' ?>>4 - Fusione</option>
                        <option value="5" <?= $evTipologiaSubentro === '5' ? 'selected' : '' ?>>5 - Scissione</option>
                        <option value="6" <?= $evTipologiaSubentro === '6' ? 'selected' : '' ?>>6 - Altro</option>
                    </select>
                </div>
                <div>
                    <label>Data evento *</label>
                    <input type="date" name="data_evento" required value="<?= htmlspecialchars($evDataEvento) ?>">
                </div>
            </div>
        </div>

        <!-- SOGGETTI -->
        <div class="card">
            <h4>Modifiche soggetti</h4>
            
            <label>Soggetto Cedente (chi esce) *</label>
            <select name="soggetto_cedente" id="soggetto_cedente" required>
                <option value="">— Seleziona chi esce —</option>
                <?php
                $stmtAllCed = $pdo->query("SELECT id, cognome, nome FROM soggetti ORDER BY cognome, nome");
                foreach ($stmtAllCed->fetchAll() as $sogg) {
                    $sel = ($sogg['id'] == $evCedente) ? 'selected' : '';
                    echo '<option value="' . $sogg['id'] . '" ' . $sel . '>' . htmlspecialchars($sogg['cognome'] . ' ' . $sogg['nome']) . '</option>';
                }
                ?>
            </select>

            <label>Seleziona i Cessionari (chi entra) *</label>
            <div style="max-height: 250px; overflow-y: auto; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                <?php
                $stmtAllSogg = $pdo->query("SELECT id, cognome, nome, codice_fiscale FROM soggetti ORDER BY cognome, nome");
                foreach ($stmtAllSogg->fetchAll() as $s) {
                    $checked = in_array($s['id'], $evCessionari) ? 'checked' : '';
                    echo '<div style="padding: 4px 0; display: flex; align-items: center; gap: 8px;">';
                    echo '<input type="checkbox" name="cessionari[]" value="' . $s['id'] . '" id="cess_' . $s['id'] . '" ' . $checked . ' style="width: auto; margin: 0;">';
                    echo '<label for="cess_' . $s['id'] . '" style="margin: 0; font-weight: normal; cursor: pointer;">' . htmlspecialchars($s['cognome'] . ' ' . $s['nome']) . ' (' . htmlspecialchars($s['codice_fiscale']) . ')</label>';
                    echo '</div>';
                }
                ?>
            </div>

            <h4>Note interne</h4>
            <textarea name="note_evento" rows="2" placeholder="Note sul subentro"></textarea>

            <h4>Allegato PDF/A (opzionale)</h4>
            <input type="file" name="allegato_pdf" accept="application/pdf,.pdf">
        </div>

        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-green">👤 Registra Subentro</button>
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
