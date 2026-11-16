<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);
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
$tipo = $_GET['tipo'] ?? 'proroga';

if ($id <= 0) { header("Location: index.php"); exit; }

// Badge notifiche topbar
$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$repo = new ContrattoRepository($pdo);
require_once 'lib/SoggettoRepository.php';
$sogRepo = new SoggettoRepository($pdo);
$contratto = $repo->find($id);
if (!$contratto) { header("Location: index.php"); exit; }

$locatori = $repo->locatori($id);
$conduttori = $repo->conduttori($id);

$defaultCanone = $contratto['importo_canone'];
$defaultDal = $contratto['durata_al'];
$defaultAl = $contratto['durata_al'];

$tipoLabel = rli_evento_label($tipo);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($tipoLabel) ?> — Contratto #<?= $id ?></title>
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
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.95em; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-red { background: #f56565; color: #fff; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .check-list { max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 15px; }
        .check-item { display: flex; align-items: center; gap: 10px; padding: 6px 4px; border-bottom: 1px solid #edf2f7; }
        .check-item:last-child { border-bottom: none; }
        .check-item input { width: auto; margin: 0; }
        .check-item label { margin: 0; font-weight: normal; flex: 1; }
        .ravv-box { background: #fffbea; border: 2px solid #d69e2e; border-radius: 10px; padding: 20px; margin: 20px 0; }
        .ravv-box.ok { background: #f0fff4; border-color: #48bb78; }
        .ravv-box.error { background: #fff5f5; border-color: #e53e3e; }
        .ravv-box h3 { margin: 0 0 6px 0; color: #744210; font-size: 1em; }
        .ravv-box.error h3 { color: #742a2a; }
        .ravv-box.ok h3 { color: #22543d; }
        .ravv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 12px; }
        .ravv-field { background: #fff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .ravv-field .lbl { font-size: 0.72em; color: #718096; text-transform: uppercase; }
        .ravv-field .val { font-size: 1em; font-weight: bold; margin-top: 3px; font-family: monospace; }
        .ravv-field .val.warn { color: #742a2a; }
        .ravv-field .val.ok { color: #22543d; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .badge-ok { background: #c6f6d5; color: #22543d; }
        .badge-info { background: #bee3f8; color: #2c5282; }
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

<form method="POST" action="save_pratica.php" enctype="multipart/form-data" id="form-evento">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="save_evento">
    <input type="hidden" name="id_contratto" value="<?= $id ?>">
    <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($tipo) ?>">

    <div class="card">
        <div class="info-box">
            <strong>Contratto attuale:</strong><br>
            Locatori: <?= htmlspecialchars(implode(', ', array_map(fn($l) => $l['cognome'] . ' ' . $l['nome'], $locatori))) ?><br>
            Conduttori: <?= htmlspecialchars(implode(', ', array_map(fn($c) => $c['cognome'] . ' ' . $c['nome'], $conduttori))) ?><br>
            Durata: <?= $contratto['durata_dal'] ? date('d/m/Y', strtotime($contratto['durata_dal'])) : '—' ?>
                    → <?= $contratto['durata_al'] ? date('d/m/Y', strtotime($contratto['durata_al'])) : '—' ?>
            · Canone: €<?= number_format((float)$contratto['importo_canone'], 2, ',', '.') ?>
        </div>
    </div>

    <!-- DATA INVIO TELEMATICO -->
    <div class="card">
        <div class="data-invio-box">
            <h4>📅 Data invio telematico (prevista)</h4>
            <p style="font-size:0.85em; color:#2c5282; margin:0 0 10px 0;">
                Data in cui prevedi di trasmettere questo adempimento all'Agenzia delle Entrate.
                <strong>Determina i giorni di ritardo e quindi sanzioni e interessi del ravvedimento operoso.</strong>
                Lascia la data odierna se trasmetti subito.
            </p>
            <div class="row">
                <div>
                    <label for="data_invio_input">Data invio telematico *</label>
                    <input type="date" name="data_invio" id="data_invio_input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label for="data_impegno_input">Data impegno intermediario</label>
                        <input type="date" name="data_impegno_intermediario" id="data_impegno_input" value="<?= date('Y-m-d') ?>">
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="button" class="btn btn-gray" onclick="resetDataInvio()" style="margin-bottom:15px; padding:10px 18px;">↺ Oggi</button>
                </div>
            </div>
            <span class="hint" id="data_invio_hint"></span>
        </div>
    </div>

    <div class="card">
        <?php if ($tipo === 'annualita'): ?>
            <h4>Annualità successiva</h4>
            <label>Annualità (anno di riferimento) *</label>
            <input type="text" name="annualita" maxlength="4" placeholder="es. <?= date('Y') ?>" required>
            <label>Tardività</label>
            <select name="tardivita_annualita" id="tardivita_annualita">
                <option value="">Nessuna tardività</option>
                <option value="1">1 - Tardiva con modifica regime</option>
                <option value="2">2 - Tardiva senza modifica regime</option>
            </select>
            <label>Data evento *</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= date('Y-m-d') ?>" required>
        <?php elseif ($tipo === 'proroga'): ?>
            <h4>Proroga del contratto</h4>
            <label>Nuova data fine *</label>
            <input type="date" name="data_fine_proroga" id="data_fine_proroga" required>
            <label>Tipologia proroga *</label>
            <select name="tipologia_proroga" required>
                <option value="1">1 - Contratto che era in registro</option>
                <option value="2">2 - Contratto che era in cedolare secca</option>
                <option value="3">3 - Contratto misto (registro + cedolare)</option>
            </select>
            <label>Data evento *</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= $contratto['durata_al'] ?>" required>
        <?php elseif ($tipo === 'cessione'): ?>
            <h4>Cessione del contratto</h4>
            <label>Data effettiva cessione *</label>
            <input type="date" name="data_cessione" id="data_cessione" required>
            <label>Corrispettivo (opzionale)</label>
            <input type="number" step="0.01" name="corrispettivo" placeholder="€">
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= date('Y-m-d') ?>">
        <?php elseif ($tipo === 'subentro'): ?>
            <h4>Subentro nel contratto</h4>
            <label>Data subentro *</label>
            <input type="date" name="data_subentro" id="data_subentro" required>
            <label>Tipologia subentro *</label>
            <select name="tipologia_subentro" required>
                <option value="1">1 - Decesso</option>
                <option value="2">2 - Trasferimento immobiliare</option>
                <option value="3">3 - Trasformazione</option>
                <option value="4">4 - Fusione</option>
                <option value="5">5 - Scissione</option>
                <option value="6">6 - Altro</option>
            </select>
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= date('Y-m-d') ?>">
        <?php elseif ($tipo === 'risoluzione' || $tipo === 'risoluzione_corrispettivo'): ?>
            <h4>Risoluzione del contratto</h4>
            <label>Data risoluzione *</label>
            <input type="date" name="durata_al" id="durata_al" required>
            <?php if ($tipo === 'risoluzione_corrispettivo'): ?>
                <label>Corrispettivo *</label>
                <input type="number" step="0.01" name="corrispettivo" required>
            <?php endif; ?>
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= date('Y-m-d') ?>">
        <?php elseif ($tipo === 'rinegoziazione'): ?>
            <h4>Rinegoziazione canone</h4>
            <label>Nuovo canone *</label>
            <input type="number" step="0.01" name="corrispettivo" required>
            <label>Tipo</label>
            <select name="rinegoziazione_tipo" id="rinegoziazione_tipo">
                <option value="aumento">Aumento</option>
                <option value="diminuzione">Diminuzione</option>
            </select>
            <label>Data inizio rinegoziazione</label>
            <input type="date" name="data_inizio_rinegoziazione" id="data_inizio_rinegoziazione">
            <label>Data stipula rinegoziazione *</label>
            <input type="date" name="data_stipula" id="data_stipula_rineg" required>
            <label>Data evento</label>
            <input type="date" name="data_evento" id="data_evento" value="<?= date('Y-m-d') ?>">
        <?php endif; ?>

        <!-- RAVVEDIMENTO OPEROSO -->
        <div class="ravv-box" id="ravv-box">
            <h3>⏱️ Ravvedimento Operoso — Anteprima</h3>
            <p style="font-size:0.85em; color:#744210; margin:0 0 10px 0;">
                Il calcolo effettivo verrà eseguito al salvataggio, in base ai dati inseriti.
                Compila i campi sopra per vedere una stima.
            </p>
            <div id="ravv-content">
                <div style="color:#a0aec0; text-align:center; padding:15px;">Compila i campi del nuovo evento.</div>
            </div>
        </div>

        <h4>Modifiche soggetti (opzionale)</h4>
        <p style="color:#718096; font-size:0.9em; margin-bottom: 15px;">Seleziona TUTTI i soggetti che devono rimanere o entrare nel contratto. Chi non è spuntato verrà rimosso.</p>

        <div style="background: #f0f9ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #4299e1;">
            <h4 style="margin-top: 0; color: #2b6cb0; margin-bottom: 15px;">👥 Soggetti del Contratto (Locatori)</h4>
            <div class="check-list-light" style="max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
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

        <div class="status-ok" style="background: #f0fff4; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #48bb78;">
            <h4 style="margin-top: 0; color: #22543d; margin-bottom: 15px;">👥 Soggetti del Contratto (Conduttori)</h4>
            <div class="check-list-light" style="max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
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

        <div class="status-warn" style="background: #fffaf0; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ed8936;">
            <h4 style="margin-top: 0; color: #7b341e; margin-bottom: 15px;">⚙️ Dati Fiscali del Subentro</h4>
            <div class="row">
                <div>
                    <label>Lato del Subentro *</label>
                    <select name="lato_subentro" required>
                        <option value="conduttore">Conduttore (es. muore l'inquilino)</option>
                        <option value="locatore">Locatore (es. muore il proprietario)</option>
                        <option value="entrambi">Entrambi</option>
                    </select>
                </div>
                <div>
                    <label>Soggetto Cedente (chi esce) *</label>
                    <select name="soggetto_cedente" id="soggetto_cedente" data-id-contratto="<?= $id ?>" required>
                        <option value="">— Seleziona chi esce —</option>
                        <?php
                        $stmtAllCed = $pdo->query("SELECT id, cognome, nome FROM soggetti ORDER BY cognome, nome");
                        foreach ($stmtAllCed->fetchAll() as $sogg) {
                            echo '<option value="' . $sogg['id'] . '">' . htmlspecialchars($sogg['cognome'] . ' ' . $sogg['nome']) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Nuovo Richiedente *</label>
                    <select name="nuovo_richiedente" id="nuovo_richiedente_select" required>
                        <option value="">— Seleziona —</option>
                        <!-- Le opzioni verranno popolate via JS in base alle spunte -->
                    </select>
                </div>

            </div>
        </div>

        <h4>Note interne</h4>
        <textarea name="note_evento" rows="2" placeholder="Note sull'adempimento"></textarea>

        <h4>Allegato PDF/A (opzionale)</h4>
        <input type="file" name="allegato_pdf" accept="application/pdf,.pdf">
        <div class="checkbox-row checkbox-row-light" style="margin-top:10px; background:#fff; padding:8px 12px; border-radius:6px; border:1px dashed #4299e1;">
            <input type="checkbox" name="converti_pdfa_auto" value="1" checked style="width:auto; margin:0;">
            <label style="font-weight:600; color:#2c5282; cursor:pointer; margin:0 0 0 8px;">🔄 Converti automaticamente in PDF/A se necessario</label>
        </div>
    </div>

    <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-green">💾 Salva adempimento</button>
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
    'numero_pagine' => $contratto['numero_pagine'] ?? 1,
    'numero_copie' => $contratto['numero_copie'] ?? 1,
    'tipologia_contratto' => $contratto['tipologia_contratto'],
    'durata_dal' => $contratto['durata_dal'],
]) ?>;

/**
 * Anteprima ravvedimento (client-side, stima semplificata).
 * Allineata alla logica server in FiscalCalculator::calcolaTermineRiferimento().
 */
function calcolaRavvPreview() {
    let termineRif = null;
    let escluso = false;
    let motivoEsc = '';

    // Data invio (o oggi)
    const dataInvioEl = document.querySelector('[name="data_invio"]');
    const dataInvio = (dataInvioEl && dataInvioEl.value) ? dataInvioEl.value : new Date().toISOString().slice(0, 10);

    if (TIPO === 'annualita') {
        const anno = parseInt(document.querySelector('[name="annualita"]').value);
        if (!anno || anno < 1900 || anno > 2100) return null;

        // Base per giorno/mese: durata_dal (preferito) o data_stipula
        const baseStr = CONTRATTO.durata_dal || CONTRATTO.data_stipula;
        if (!baseStr) return null;

        const base = new Date(baseStr);
        // Costruisci data anniversario nell'anno indicato
        termineRif = new Date(anno, base.getMonth(), base.getDate()).toISOString().slice(0, 10);
    } else if (TIPO === 'proroga') {
        termineRif = document.querySelector('[name="data_fine_proroga"]').value;
    } else if (TIPO === 'cessione') {
        termineRif = document.querySelector('[name="data_cessione"]').value;
    } else if (TIPO === 'risoluzione' || TIPO === 'risoluzione_corrispettivo') {\n        escluso = true;\n        motivoEsc = 'Risoluzione: sanzioni e interessi non dovuti (sanzione abolita dal DL Crescita)';\n    } else if (TIPO === 'subentro') {
        escluso = true;
        motivoEsc = 'Subentro: sanzioni e interessi non dovuti';
        termineRif = document.querySelector('[name="data_subentro"]').value;
    } else if (TIPO === 'risoluzione' || TIPO === 'risoluzione_corrispettivo') {
        termineRif = document.querySelector('[name="durata_al"]').value;
    } else if (TIPO === 'rinegoziazione') {
        const tipoR = document.querySelector('[name="rinegoziazione_tipo"]').value;
        if (tipoR === 'diminuzione') {
            escluso = true;
            motivoEsc = 'Rinegoziazione in diminuzione: sanzioni e interessi non dovuti';
        }
        const ds = document.querySelector('[name="data_stipula"]').value;
        const dr = document.querySelector('[name="data_inizio_rinegoziazione"]').value;
        termineRif = (dr && ds && new Date(dr) < new Date(ds)) ? dr : (ds || dr);
    }

    if (!termineRif) return null;

    const gg = Math.floor((new Date(dataInvio) - new Date(termineRif)) / 86400000);
    const giorni = Math.max(0, gg);

    // Aggiorna hint
    const hint = document.getElementById('data_invio_hint');
    if (hint) {
        if (giorni <= 30) {
            hint.innerHTML = `In termine (${giorni} giorni dal termine di riferimento ${new Date(termineRif).toLocaleDateString('it-IT')}).`;
            hint.style.color = '#22543d';
        } else {
            hint.innerHTML = `⚠ ${giorni} giorni di ritardo rispetto al termine ${new Date(termineRif).toLocaleDateString('it-IT')}. Sanzioni e interessi applicati.`;
            hint.style.color = '#744210';
        }
    }

    // Stima imposta
    let impReg = 67;
    let impBol = 0;
    if (TIPO === 'annualita' || TIPO === 'proroga') {
        impReg = Math.max(67, Math.round(CONTRATTO.importo_canone * 0.02));
    } else if (TIPO === 'cessione') {
        impReg = 67;
    } else if (TIPO === 'risoluzione' || TIPO === 'risoluzione_corrispettivo') {
        impReg = 67;
    } else if (TIPO === 'rinegoziazione') {
        impReg = 67;
    }

    let aliquota = 0;
    let desc = '';
    if (giorni <= 30) {
        desc = 'Nei termini (≤ 30 giorni)';
    } else if (giorni <= 90) {
        aliquota = 0.0139;
        desc = 'Ravvedimento entro 90gg';
    } else if (giorni <= 365) {
        aliquota = 0.0312;
        desc = 'Ravvedimento entro 1 anno';
    } else {
        aliquota = 0.0357;
        desc = 'Ravvedimento oltre 1 anno';
    }

    let sanzReg = 0, intReg = 0;
    if (!escluso && aliquota > 0 && impReg > 0) {
        sanzReg = Math.round(impReg * aliquota * 100) / 100;
        intReg = Math.round(impReg * 0.02 / 365 * giorni * 100) / 100;
    }

    return {
        termine_riferimento: termineRif,
        giorni_ritardo: giorni,
        in_termine: giorni <= 30,
        escluso: escluso,
        motivo_esclusione: motivoEsc,
        descrizione: escluso ? motivoEsc : desc,
        imposta_registro: impReg,
        imposta_bollo: impBol,
        sanzione_registro: sanzReg,
        sanzione_bollo: 0,
        interessi_registro: intReg,
        interessi_bollo: 0,
        totale: sanzReg + intReg,
    };
}

function renderRavv() {
    const content = document.getElementById('ravv-content');
    const box = document.getElementById('ravv-box');
    const r = calcolaRavvPreview();
    if (!r) {
        box.className = 'ravv-box';
        content.innerHTML = '<div style="color:#a0aec0; text-align:center; padding:15px;">Compila i campi obbligatori del nuovo evento.</div>';
        return;
    }
    const fmt = (n) => '€ ' + parseFloat(n || 0).toFixed(2).replace('.', ',');
    const fmtDate = (d) => d ? new Date(d).toLocaleDateString('it-IT') : '—';

    box.className = r.escluso || r.in_termine ? 'ravv-box ok' : (r.totale > 0 ? 'ravv-box error' : 'ravv-box');

    let html = '<h3>' + (r.escluso ? '✅ Escluso' : (r.in_termine ? '✅ Nei termini' : (r.totale > 0 ? '⚠️ Sanzioni dovute' : 'Ravvedimento'))) + '</h3>';
    html += '<p style="font-size:0.85em; margin:0 0 8px 0;">Termine: <strong>' + fmtDate(r.termine_riferimento) + '</strong> · Giorni ritardo: <strong>' + r.giorni_ritardo + '</strong> · ' + r.descrizione + '</p>';
    html += '<div class="ravv-grid">';
    html += '<div class="ravv-field"><div class="lbl">Imposta Registro</div><div class="val">' + fmt(r.imposta_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Sanzione Registro</div><div class="val' + (r.sanzione_registro > 0 ? ' warn' : '') + '">' + fmt(r.sanzione_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Interessi Registro</div><div class="val' + (r.interessi_registro > 0 ? ' warn' : '') + '">' + fmt(r.interessi_registro) + '</div></div>';
    html += '<div class="ravv-field ravv-total"><div class="lbl">Totale Ravvedimento</div><div class="val' + (r.totale > 0 ? ' warn' : ' ok') + '">' + fmt(r.totale) + '</div></div>';
    html += '</div>';
    content.innerHTML = html;
}

function resetDataInvio() {
    const el = document.getElementById('data_invio_input');
    if (!el) return;
    el.value = new Date().toISOString().slice(0, 10);
    el.dispatchEvent(new Event('change'));
}

// Event listeners per ricalcolo
document.querySelectorAll('input, select').forEach(function(el) {
    el.addEventListener('change', renderRavv);
    el.addEventListener('blur', renderRavv);
});

document.addEventListener('DOMContentLoaded', renderRavv);
</script>
<script src="assets/uppercase.js"></script>

<script src="assets/cf_utils.js"></script>
<script src="assets/soggetto_modal.js"></script>
<script>
const CSRF_TOKEN_CN = <?= json_encode(Security::csrfToken()) ?>;

function aggiungiNuovoSoggetto(tipologia) {
    SoggettoModal.open({
        csrfToken: CSRF_TOKEN_CN,
        tipologiaDefault: tipologia,
        onSave: function(soggetto) {
            // Scegliamo in quale select inserirlo in base alla tipologia
            const selectId = tipologia === 'LOCATORE' ? 'nuovo_locatore_select' : 'nuovo_conduttore_select';
            const select = document.getElementById(selectId);
            
            // Creiamo la nuova option
            const option = document.createElement('option');
            option.value = soggetto.id;
            option.textContent = soggetto.cognome + ' ' + (soggetto.nome || '') + ' (' + soggetto.codice_fiscale + ')';
            option.selected = true; // Selezionalo automaticamente
            
            // Lo inseriamo in cima (dopo "Nessuno")
            select.insertBefore(option, select.options[1]);
            
            // Feedback visivo
            select.style.borderColor = '#48bb78';
            select.style.boxShadow = '0 0 0 3px rgba(72, 187, 120, 0.3)';
            setTimeout(() => {
                select.style.borderColor = '#cbd5e0';
                select.style.boxShadow = 'none';
            }, 2000);
        }
    });
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mappatura degli ID dei soggetti per recuperare i nomi
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
        
        // Prendi tutti i locatori e conduttori spuntati
        checkboxesLocatori.forEach(cb => { if (cb.checked) selezionati.push(cb.value); });
        checkboxesConduttori.forEach(cb => { if (cb.checked) selezionati.push(cb.value); });

        // Salva il valore attualmente selezionato per non perderlo se possibile
        const valoreAttuale = selectRichiedente.value;
        
        // Svuota il select
        selectRichiedente.innerHTML = '<option value="">— Seleziona —</option>';
        
        // Ripopola
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

    // Aggiungi l'evento 'change' a tutte le caselle
    checkboxesLocatori.forEach(cb => cb.addEventListener('change', aggiornaRichiedente));
    checkboxesConduttori.forEach(cb => cb.addEventListener('change', aggiornaRichiedente));

    // Esegui all'avvio della pagina
    aggiornaRichiedente();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectRichiedente = document.getElementById('nuovo_richiedente_select');
    const checkboxesLocatori = document.querySelectorAll('input[name="locatori[]"]');
    const checkboxesConduttori = document.querySelectorAll('input[name="conduttori[]"]');

    // Array di tutti i soggetti dal DB
    const soggetti = {
        <?php
        $stmtAllSogg = $pdo->query("SELECT id, cognome, nome FROM soggetti ORDER BY cognome, nome");
        foreach ($stmtAllSogg->fetchAll() as $s) {
            echo "'{$s['id']}': '" . addslashes($s['cognome'] . ' ' . $s['nome']) . "',\n";
        }
        ?>
    };

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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectCedente = document.getElementById('soggetto_cedente');
    const idContratto = selectCedente ? selectCedente.dataset.idContratto : null;
    
    if (!selectCedente || !idContratto) return;

    // Effettua una chiamata AJAX per ottenere i proprietari dell'immobile
    fetch('get_proprietari.php?id_contratto=' + idContratto)
        .then(response => response.json())
        .then(data => {
            if (data.ok && data.ids && data.ids.length > 0) {
                // Se ci sono proprietari, filtra le opzioni
                const idProprietari = data.ids.map(Number);
                Array.from(selectCedente.options).forEach(option => {
                    if (option.value === "") return; // Mantieni l'opzione vuota
                    if (!idProprietari.includes(Number(option.value))) {
                        option.style.display = 'none';
                    }
                });
            }
        })
        .catch(err => console.error('Errore caricamento proprietari:', err));
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectCedente = document.getElementById('soggetto_cedente');
    const idContratto = selectCedente ? selectCedente.dataset.idContratto : null;
    
    if (!selectCedente || !idContratto) return;

    fetch('get_proprietari.php?id_contratto=' + idContratto)
        .then(response => response.json())
        .then(data => {
            if (data.ok && data.ids && data.ids.length > 0) {
                const idProprietari = data.ids.map(Number);
                Array.from(selectCedente.options).forEach(option => {
                    if (option.value === "") return;
                    if (!idProprietari.includes(Number(option.value))) {
                        option.style.display = 'none';
                    }
                });
            }
        })
        .catch(err => console.error('Errore caricamento proprietari:', err));
});
</script>
</body>
</html>
