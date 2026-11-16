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

$idEvento = (int)($_GET['id_evento'] ?? 0);
$id = (int)($_GET['id'] ?? 0);

if ($idEvento <= 0 || $id <= 0) { header("Location: index.php"); exit; }

// Badge notifiche topbar
$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$repo = new ContrattoRepository($pdo);
$contratto = $repo->find($id);
if (!$contratto) { header("Location: index.php"); exit; }

// Carica l'evento
$stmtEv = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
$stmtEv->execute([$idEvento, $id]);
$evento = $stmtEv->fetch();
if (!$evento) { header("Location: contract.php?id=$id&error=Evento+non+trovato"); exit; }

// Blocco sulla registrazione
if ($evento['tipo'] === 'registrazione') {
    header("Location: contract.php?id=$id&error=La+registrazione+si+modifica+da+'Modifica+contratto'");
    exit;
}

$tipo = $evento['tipo'];
$dettagli = json_decode($evento['dettagli_json'] ?? '{}', true) ?: [];

$locatori = $repo->locatori($id);
$conduttori = $repo->conduttori($id);

$tipoLabel = rli_evento_label($tipo);

// Valori precompilati
$v = function($key, $default = '') use ($dettagli, $evento) {
    if (array_key_exists($key, $dettagli) && $dettagli[$key] !== null) return $dettagli[$key];
    if (array_key_exists($key, $evento) && $evento[$key] !== null) return $evento[$key];
    return $default;
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Modifica <?= htmlspecialchars($tipoLabel) ?> — Contratto #<?= $id ?></title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
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
        .btn-orange { background: #ed8936; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-red { background: #f56565; color: #fff; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .edit-banner { background: #fff5f5; border-left: 4px solid #ed8936; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; }
        .edit-banner h3 { margin: 0 0 5px 0; color: #7b341e; }
        .edit-banner p { margin: 0; color: #744210; font-size: 0.9em; }
        .hint { color: #718096; font-size: 0.85em; margin-top: -10px; margin-bottom: 12px; display: block; }
        .data-invio-box { background: #f0f9ff; border-left: 4px solid #4299e1; padding: 15px 18px; border-radius: 8px; margin-bottom: 20px; }
        .data-invio-box h4 { margin: 0 0 10px 0; border: none; padding: 0; color: #2b6cb0; }
        .ravv-box { background: #fffbea; border: 2px solid #d69e2e; border-radius: 10px; padding: 20px; margin: 20px 0; }
        .ravv-box.ok { background: #f0fff4; border-color: #48bb78; }
        .ravv-box.error { background: #fff5f5; border-color: #e53e3e; }
        .ravv-box h3 { margin: 0 0 6px 0; color: #744210; font-size: 1em; }
        .ravv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 12px; }
        .ravv-field { background: #fff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .ravv-field .lbl { font-size: 0.72em; color: #718096; text-transform: uppercase; }
        .ravv-field .val { font-size: 1em; font-weight: bold; margin-top: 3px; font-family: monospace; }
        .ravv-field .val.warn { color: #742a2a; }
        .ravv-field .val.ok { color: #22543d; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <a href="<?= url('contract.php') ?>?id=<?= $id ?>" style="color:#4299e1; text-decoration:none; font-size:0.9em;">← Torna al contratto</a>

    <div class="edit-banner" style="margin-top:20px;">
        <h3>✏️ Modalità modifica</h3>
        <p>
            Stai modificando l'adempimento <strong><?= htmlspecialchars($tipoLabel) ?></strong>
            del <?= $evento['data_evento'] ? date('d/m/Y', strtotime($evento['data_evento'])) : '—' ?>.
            L'ID dell'evento è <code>#<?= $idEvento ?></code>.
        </p>
    </div>

<form method="POST" action="save_pratica.php" enctype="multipart/form-data">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="update_evento">
    <input type="hidden" name="id_evento" value="<?= $idEvento ?>">
    <input type="hidden" name="id_contratto" value="<?= $id ?>">
    <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($tipo) ?>">

    <div class="card">
        <div class="info-box">
            <strong>Contratto attuale:</strong><br>
            Locatori: <?= htmlspecialchars(implode(', ', array_map(fn($l) => $l['cognome'] . ' ' . $l['nome'], $locatori))) ?><br>
            Conduttori: <?= htmlspecialchars(implode(', ', array_map(fn($c) => $c['cognome'] . ' ' . $c['nome'], $conduttori))) ?>
        </div>

        <div class="data-invio-box">
            <h4>📅 Data invio telematico (prevista)</h4>
            <p style="font-size:0.85em; color:#2c5282; margin:0 0 10px 0;">
                Data in cui prevedi di trasmettere questo adempimento all'Agenzia delle Entrate.
                <strong>Determina i giorni di ritardo e quindi sanzioni e interessi del ravvedimento operoso.</strong>
            </p>
            <div class="row">
                <div>
                    <label for="data_invio_input">Data invio telematico *</label>
                    <input type="date" name="data_invio" id="data_invio_input"
                           value="<?= htmlspecialchars($evento['data_invio'] ?: ($contratto['data_invio'] ?: date('Y-m-d'))) ?>" required>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="button" class="btn btn-gray" onclick="resetDataInvio()" style="margin-bottom:15px;">↺ Oggi</button>
                </div>
            </div>
            <span class="hint" id="data_invio_hint"></span>
        </div>

        <?php if ($tipo === 'annualita'): ?>
            <h4>Annualità successiva</h4>
            <label>Annualità (anno di riferimento) *</label>
            <input type="text" name="annualita" maxlength="4" value="<?= Security::h($v('annualita')) ?>" required>
            <label>Tardività</label>
            <select name="tardivita_annualita">
                <option value="">Nessuna tardività</option>
                <option value="1" <?= $v('tardivita_annualita') == '1' ? 'selected' : '' ?>>1 - Tardiva con modifica regime</option>
                <option value="2" <?= $v('tardivita_annualita') == '2' ? 'selected' : '' ?>>2 - Tardiva senza modifica regime</option>
            </select>
            <label>Data evento *</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= Security::h($evento['data_evento']) ?>" required>
        <?php elseif ($tipo === 'proroga'): ?>
            <h4>Proroga del contratto</h4>
            <label>Nuova data fine *</label>
            <input type="date" name="data_fine_proroga" id="data_fine_proroga" value="<?= Security::h($v('data_fine_proroga')) ?>" required>
            <label>Tipologia proroga *</label>
            <select name="tipologia_proroga" required>
                <option value="1" <?= $v('tipologia_proroga') == '1' ? 'selected' : '' ?>>1 - Contratto che era in registro</option>
                <option value="2" <?= $v('tipologia_proroga') == '2' ? 'selected' : '' ?>>2 - Contratto che era in cedolare secca</option>
                <option value="3" <?= $v('tipologia_proroga') == '3' ? 'selected' : '' ?>>3 - Contratto misto</option>
            </select>
            <label>Data evento *</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= Security::h($evento['data_evento']) ?>" required>
        <?php elseif ($tipo === 'cessione'): ?>
            <h4>Cessione del contratto</h4>
            <label>Data effettiva cessione *</label>
            <input type="date" name="data_cessione" id="data_cessione" value="<?= Security::h($v('data_cessione')) ?>" required>
            <label>Corrispettivo (opzionale)</label>
            <input type="number" step="0.01" name="corrispettivo" value="<?= Security::h($v('corrispettivo')) ?>">
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= Security::h($evento['data_evento']) ?>">
        <?php elseif ($tipo === 'subentro'): ?>
            <h4>Subentro nel contratto</h4>
            <label>Data subentro *</label>
            <input type="date" name="data_subentro" id="data_subentro" value="<?= Security::h($v('data_subentro')) ?>" required>
            <label>Tipologia subentro *</label>
            <select name="tipologia_subentro" required>
                <?php foreach ([1=>'Decesso',2=>'Trasferimento immobiliare',3=>'Trasformazione',4=>'Fusione',5=>'Scissione',6=>'Altro'] as $k=>$lab): ?>
                    <option value="<?= $k ?>" <?= $v('tipologia_subentro') == $k ? 'selected' : '' ?>><?= $k ?> - <?= $lab ?></option>
                <?php endforeach; ?>
            </select>
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= Security::h($evento['data_evento']) ?>">
        <?php elseif ($tipo === 'risoluzione' || $tipo === 'risoluzione_corrispettivo'): ?>
            <h4>Risoluzione del contratto</h4>
            <label>Data risoluzione *</label>
            <input type="date" name="durata_al" id="durata_al" value="<?= Security::h($v('durata_al')) ?>" required>
            <?php if ($tipo === 'risoluzione_corrispettivo'): ?>
                <label>Corrispettivo *</label>
                <input type="number" step="0.01" name="corrispettivo" value="<?= Security::h($v('corrispettivo')) ?>" required>
            <?php endif; ?>
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= Security::h($evento['data_evento']) ?>">
        <?php elseif ($tipo === 'rinegoziazione'): ?>
            <h4>Rinegoziazione canone</h4>
            <label>Nuovo canone *</label>
            <input type="number" step="0.01" name="corrispettivo" value="<?= Security::h($v('corrispettivo')) ?>" required>
            <label>Tipo</label>
            <select name="rinegoziazione_tipo">
                <option value="aumento" <?= $v('rinegoziazione_tipo') === 'aumento' ? 'selected' : '' ?>>Aumento</option>
                <option value="diminuzione" <?= $v('rinegoziazione_tipo') === 'diminuzione' ? 'selected' : '' ?>>Diminuzione</option>
            </select>
            <label>Data inizio rinegoziazione</label>
            <input type="date" name="data_inizio_rinegoziazione" value="<?= Security::h($v('data_inizio_rinegoziazione')) ?>">
            <label>Data stipula rinegoziazione *</label>
            <input type="date" name="data_stipula" value="<?= Security::h($v('data_stipula')) ?>" required>
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= Security::h($evento['data_evento']) ?>">
        <?php endif; ?>

        <!-- RAVVEDIMENTO OPEROSO -->
        <div class="ravv-box" id="ravv-box">
            <h3>⏱️ Ravvedimento Operoso — Anteprima</h3>
            <p style="font-size:0.85em; color:#744210; margin:0 0 10px 0;">
                Il calcolo effettivo verrà eseguito al salvataggio, in base ai dati inseriti.
            </p>
            <div id="ravv-content">
                <div style="color:#a0aec0; text-align:center; padding:15px;">Modifica i campi per aggiornare l'anteprima.</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h4>Modifiche soggetti (opzionale)</h4>
        <p style="color:#718096; font-size:0.9em; margin-bottom: 15px;">Seleziona TUTTI i soggetti che devono rimanere o entrare nel contratto. Chi non è spuntato verrà rimosso.</p>

        <div style="background: #f0f9ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #4299e1;">
            <h4 style="margin-top: 0; color: #2b6cb0; margin-bottom: 15px;">👥 Soggetti del Contratto (Locatori)</h4>
            <div style="max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                <?php
                $stmtAllL = $pdo->query("SELECT id, cognome, nome, codice_fiscale FROM soggetti ORDER BY cognome, nome");
                $tuttiL = $stmtAllL->fetchAll();
                $idsAttualiL = array_column($locatori, 'id');
                foreach ($tuttiL as $tl) {
                    $checked = in_array($tl['id'], $idsAttualiL) ? 'checked' : '';
                    echo '<div style="padding: 4px 0; display: flex; align-items: center; gap: 8px;">';
                    echo '<input type="checkbox" name="locatori[]" value="' . $tl['id'] . '" id="loc_' . $tl['id'] . '" ' . $checked . ' style="width: auto; margin: 0;">';
                    echo '<label for="loc_' . $tl['id'] . '" style="margin: 0; font-weight: normal; cursor: pointer; flex-grow: 1;">' . htmlspecialchars($tl['cognome'] . ' ' . $tl['nome']) . ' (' . htmlspecialchars($tl['codice_fiscale']) . ')</label>';
                    $quotaAttuale = '50';
                    $cedolareAttuale = 1;
                    foreach ($locatori as $loc) { 
                        if ($loc['id'] == $tl['id']) { 
                            $quotaAttuale = $loc['percentuale_possesso']; 
                            $cedolareAttuale = $loc['opzione_cedolare']; 
                            break; 
                        } 
                    }
                    echo '<div style="display: flex; align-items: center; gap: 4px; margin-right: 10px;"><input type="checkbox" name="cedolare_locatori[' . $tl['id'] . ']" value="1" ' . ($cedolareAttuale ? 'checked' : '') . ' style="width: auto; margin: 0;"><span style="font-size: 0.8em; color: #4a5568;">Ced.</span></div>';
                    echo '<input type="number" name="quote_locatori[' . $tl['id'] . ']" value="' . ($quotaAttuale ?: '50') . '" step="0.01" min="0" max="100" style="width: 70px; padding: 4px; margin: 0;" placeholder="%">';
                    echo '</div>';
                }
                ?>
            </div>
        </div>

        <div style="background: #f0fff4; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #48bb78;">
            <h4 style="margin-top: 0; color: #22543d; margin-bottom: 15px;">👥 Soggetti del Contratto (Conduttori)</h4>
            <div style="max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                <?php
                $stmtAllC = $pdo->query("SELECT id, cognome, nome, codice_fiscale FROM soggetti ORDER BY cognome, nome");
                $tuttiC = $stmtAllC->fetchAll();
                $idsAttualiC = array_column($conduttori, 'id');
                foreach ($tuttiC as $tc) {
                    $checked = in_array($tc['id'], $idsAttualiC) ? 'checked' : '';
                    echo '<div style="padding: 4px 0; display: flex; align-items: center; gap: 8px;">';
                    echo '<input type="checkbox" name="conduttori[]" value="' . $tc['id'] . '" id="cond_' . $tc['id'] . '" ' . $checked . ' style="width: auto; margin: 0;">';
                    echo '<label for="cond_' . $tc['id'] . '" style="margin: 0; font-weight: normal; cursor: pointer;">' . htmlspecialchars($tc['cognome'] . ' ' . $tc['nome']) . ' (' . htmlspecialchars($tc['codice_fiscale']) . ')</label>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>

        <div style="background: #fffaf0; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ed8936;">
            <h4 style="margin-top: 0; color: #7b341e; margin-bottom: 15px;">⚙️ Dati Fiscali del Subentro</h4>
            <div class="row">
                <div>
                    <label>Nuovo Richiedente *</label>
                    <select name="nuovo_richiedente" id="nuovo_richiedente_select" required>
                        <option value="">— Seleziona —</option>
                    </select>
                </div>

            </div>
        </div>

        <h4>Allegato PDF/A <?= (!empty($evento['allegato_pdf']) || !empty($evento['allegato_path'])) ? '(già presente: <code>' . Security::h($evento['nome_allegato']) . '</code>)' : '(opzionale)' ?></h4>
        <input type="file" name="allegato_pdf" accept="application/pdf,.pdf">
        <p style="font-size:0.8em; color:#718096; margin:-10px 0 10px 0;">
            Se non carichi un nuovo file, verrà mantenuto l'allegato esistente.
        </p>
        <div style="display:flex; align-items:center; gap:8px; background:#fff; padding:8px 12px; border-radius:6px; border:1px dashed #4299e1;">
            <input type="checkbox" name="converti_pdfa_auto" value="1" checked style="width:auto; margin:0;">
            <label style="font-weight:600; color:#2c5282; cursor:pointer; margin:0;">🔄 Converti automaticamente in PDF/A se necessario</label>
        </div>
    </div>

    <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-orange">💾 Salva Modifiche</button>
        <a href="<?= url('contract.php') ?>?id=<?= $id ?>" class="btn btn-gray">Annulla</a>
    </div>
</form>
</div>

<script>
const TIPO = <?= json_encode($tipo) ?>;
const CONTRATTO = <?= json_encode([
    'durata_al' => $contratto['durata_al'],
    'data_stipula' => $contratto['data_stipula'],
    'importo_canone' => $contratto['importo_canone'],
    'durata_dal' => $contratto['durata_dal'],
]) ?>;

function calcolaRavvPreview() {
    let termineRif = null;
    let escluso = false;
    let motivoEsc = '';

    const dataInvioEl = document.querySelector('[name="data_invio"]');
    const dataInvio = (dataInvioEl && dataInvioEl.value) ? dataInvioEl.value : new Date().toISOString().slice(0, 10);

    if (TIPO === 'annualita') {
        const anno = parseInt(document.querySelector('[name="annualita"]').value);
        if (!anno || anno < 1900 || anno > 2100) return null;
        const baseStr = CONTRATTO.durata_dal || CONTRATTO.data_stipula;
        if (!baseStr) return null;
        const base = new Date(baseStr);
        termineRif = new Date(anno, base.getMonth(), base.getDate()).toISOString().slice(0, 10);
    } else if (TIPO === 'proroga') {
        termineRif = document.querySelector('[name="data_fine_proroga"]').value;
    } else if (TIPO === 'cessione') {
        termineRif = document.querySelector('[name="data_cessione"]').value;
    } else if (TIPO === 'subentro') {
        escluso = true;
        motivoEsc = 'Subentro: sanzioni e interessi non dovuti';
        termineRif = document.querySelector('[name="data_subentro"]').value;
    } else if (TIPO === 'risoluzione' || TIPO === 'risoluzione_corrispettivo') {
        termineRif = document.querySelector('[name="durata_al"]').value;
    } else if (TIPO === 'rinegoziazione') {
        const tipoR = document.querySelector('[name="rinegoziazione_tipo"]').value;
        if (tipoR === 'diminuzione') { escluso = true; motivoEsc = 'Rinegoziazione in diminuzione: sanzioni non dovute'; }
        const ds = document.querySelector('[name="data_stipula"]').value;
        const dr = document.querySelector('[name="data_inizio_rinegoziazione"]').value;
        termineRif = (dr && ds && new Date(dr) < new Date(ds)) ? dr : (ds || dr);
    }

    if (!termineRif) return null;

    const gg = Math.floor((new Date(dataInvio) - new Date(termineRif)) / 86400000);
    const giorni = Math.max(0, gg);

    const hint = document.getElementById('data_invio_hint');
    if (hint) {
        if (giorni <= 30) {
            hint.innerHTML = `In termine (${giorni} giorni dal termine di riferimento ${new Date(termineRif).toLocaleDateString('it-IT')}).`;
            hint.style.color = '#22543d';
        } else {
            hint.innerHTML = `⚠ ${giorni} giorni di ritardo rispetto al termine ${new Date(termineRif).toLocaleDateString('it-IT')}.`;
            hint.style.color = '#744210';
        }
    }

    let impReg = 67;
    if (TIPO === 'annualita' || TIPO === 'proroga') impReg = Math.max(67, Math.round(CONTRATTO.importo_canone * 0.02));

    let aliquota = 0, desc = '';
    if (giorni <= 30) desc = 'Nei termini';
    else if (giorni <= 90) { aliquota = 0.0139; desc = 'Ravv. entro 90gg'; }
    else if (giorni <= 365) { aliquota = 0.0312; desc = 'Ravv. entro 1 anno'; }
    else { aliquota = 0.0357; desc = 'Ravv. oltre 1 anno'; }

    let sanzReg = 0, intReg = 0;
    if (!escluso && aliquota > 0 && impReg > 0) {
        sanzReg = Math.round(impReg * aliquota * 100) / 100;
        intReg = Math.round(impReg * 0.02 / 365 * giorni * 100) / 100;
    }

    return {
        termine_riferimento: termineRif, giorni_ritardo: giorni,
        in_termine: giorni <= 30, escluso, motivo_esclusione: motivoEsc,
        descrizione: escluso ? motivoEsc : desc,
        imposta_registro: impReg, imposta_bollo: 0,
        sanzione_registro: sanzReg, sanzione_bollo: 0,
        interessi_registro: intReg, interessi_bollo: 0,
        totale: sanzReg + intReg,
    };
}

function renderRavv() {
    const content = document.getElementById('ravv-content');
    const box = document.getElementById('ravv-box');
    const r = calcolaRavvPreview();
    if (!r) {
        box.className = 'ravv-box';
        content.innerHTML = '<div style="color:#a0aec0; text-align:center; padding:15px;">Compila i campi.</div>';
        return;
    }
    const fmt = (n) => '€ ' + parseFloat(n || 0).toFixed(2).replace('.', ',');
    const fmtDate = (d) => d ? new Date(d).toLocaleDateString('it-IT') : '—';

    box.className = r.escluso || r.in_termine ? 'ravv-box ok' : (r.totale > 0 ? 'ravv-box error' : 'ravv-box');
    let html = '<h3>' + (r.escluso ? '✅ Escluso' : (r.in_termine ? '✅ Nei termini' : '⚠️ Sanzioni dovute')) + '</h3>';
    html += '<p style="font-size:0.85em; margin:0 0 8px 0;">Termine: <strong>' + fmtDate(r.termine_riferimento) + '</strong> · Giorni ritardo: <strong>' + r.giorni_ritardo + '</strong></p>';
    html += '<div class="ravv-grid">';
    html += '<div class="ravv-field"><div class="lbl">Imposta Registro</div><div class="val">' + fmt(r.imposta_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Sanzione Registro</div><div class="val' + (r.sanzione_registro > 0 ? ' warn' : '') + '">' + fmt(r.sanzione_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Interessi Registro</div><div class="val' + (r.interessi_registro > 0 ? ' warn' : '') + '">' + fmt(r.interessi_registro) + '</div></div>';
    html += '<div class="ravv-field" style="background:#fffbea;"><div class="lbl">Totale Ravvedimento</div><div class="val' + (r.totale > 0 ? ' warn' : ' ok') + '">' + fmt(r.totale) + '</div></div>';
    html += '</div>';
    content.innerHTML = html;
}

function resetDataInvio() {
    const el = document.getElementById('data_invio_input');
    if (el) { el.value = new Date().toISOString().slice(0, 10); el.dispatchEvent(new Event('change')); }
}

document.querySelectorAll('input, select').forEach(function(el) {
    el.addEventListener('change', renderRavv);
    el.addEventListener('blur', renderRavv);
});

document.addEventListener('DOMContentLoaded', renderRavv);
</script>
<script src="assets/uppercase.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const soggetti = {
        <?php
        $stmtAllSogg = $pdo->query("SELECT id, cognome, nome FROM soggetti ORDER BY cognome, nome");
        foreach ($stmtAllSogg->fetchAll() as $s) {
            echo "'{$s['id']}': '" . addslashes($s['cognome'] . ' ' . $s['nome']) . "',
";
        }
        ?>
    };

    const selectRichiedente = document.getElementById('nuovo_richiedente_select');
    const checkboxesLocatori = document.querySelectorAll('input[name="locatori[]"]');
    const checkboxesConduttori = document.querySelectorAll('input[name="conduttori[]"]');

    function aggiornaRichiedente() {
        const selezionati = [];
        checkboxesLocatori.forEach(cb => { if (cb.checked) selezionati.push(cb.value); });
        checkboxesConduttori.forEach(cb => { if (cb.checked) selezionati.push(cb.value); });
        const valoreAttuale = selectRichiedente.value;
        selectRichiedente.innerHTML = '<option value="">— Seleziona —</option>';
        selezionati.forEach(id => {
            if (soggetti[id]) {
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = soggetti[id];
                if (id === valoreAttuale) opt.selected = true;
                selectRichiedente.appendChild(opt);
            }
        });
    }

    checkboxesLocatori.forEach(cb => cb.addEventListener('change', aggiornaRichiedente));
    checkboxesConduttori.forEach(cb => cb.addEventListener('change', aggiornaRichiedente));
    aggiornaRichiedente();
});
</script>
</body>
</html>
