<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/SoggettoRepository.php';
require_once 'lib/ImmobileRepository.php';
require_once 'lib/ContrattoRepository.php';
require_once 'lib/NotificaRepository.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (empty($_SESSION['utente_id'])) { header("Location: index.php"); exit; }

// Badge notifiche topbar
$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$sogRepo = new SoggettoRepository($pdo);
$immRepo = new ImmobileRepository($pdo);
$conRepo = new ContrattoRepository($pdo);

$locatori = $sogRepo->findLocatori();
$conduttori = $sogRepo->findConduttori();
$immobili = $immRepo->all();

// Persone fisiche già censite (per il selettore del legale rappresentante nel modale)
$personeFisiche = $pdo->query("
    SELECT id, codice_fiscale, cognome, nome
    FROM soggetti
    WHERE CHAR_LENGTH(codice_fiscale) = 16
    ORDER BY cognome, nome
")->fetchAll();

// === MODALITÀ EDIT ===
$editMode = isset($_GET['id']) && (int)$_GET['id'] > 0;
$contrattoEdit = null;
$locatoriSel = [];
$conduttoriSel = [];
$immobiliSel = [];
$quoteSel = [];
$cedolareSel = [];
$richiedenteSel = null;
$ipSel = [];
$tipologiaImmSel = [];
$canoniVariabiliEdit = [];

if ($editMode) {
    $idEdit = (int)$_GET['id'];
    $contrattoEdit = $conRepo->find($idEdit);
    if (!$contrattoEdit) { header("Location: index.php?error=Contratto+non+trovato"); exit; }

    $lista_loc = $conRepo->locatori($idEdit);
    foreach ($lista_loc as $l) {
        $locatoriSel[] = (int)$l['id'];
        $quoteSel[$l['id']] = (float)$l['percentuale_possesso'];
        $cedolareSel[$l['id']] = (int)$l['opzione_cedolare'];
        if ($l['richiedente']) $richiedenteSel = (int)$l['id'];
    }

    $lista_cond = $conRepo->conduttori($idEdit);
    foreach ($lista_cond as $c) {
        $conduttoriSel[] = (int)$c['id'];
    }

    $lista_imm = $conRepo->immobili($idEdit);
    foreach ($lista_imm as $i) {
        $immobiliSel[] = (int)$i['id'];
        $ipSel[$i['id']] = $i['intero_porzione'];
        $tipologiaImmSel[$i['id']] = (int)$i['tipologia_immobile'];
    }

    if (!empty($contrattoEdit['canoni_variabili'])) {
        $canoniVariabiliEdit = json_decode($contrattoEdit['canoni_variabili'], true) ?: [];
    }
}

// === VERIFICA ALLEGATO ESISTENTE ===
$contrattoHaAllegato = false;
if ($editMode && $contrattoEdit) {
    $stmtAll = $pdo->prepare("
        SELECT COUNT(*) FROM eventi_contratto
        WHERE id_contratto = ?
          AND (
                (allegato_pdf IS NOT NULL AND LENGTH(allegato_pdf) > 0)
                OR allegato_path IS NOT NULL
          )
    ");
    $stmtAll->execute([$contrattoEdit['id']]);
    $contrattoHaAllegato = ((int)$stmtAll->fetchColumn()) > 0;
}

$proprieta = [];
foreach ($locatori as $l) {
    $proprieta[$l['id']] = array_map(function($i) {
        return [
            'id' => $i['id'],
            'identificativo' => $i['identificativo_interno'],
            'quota' => $i['quota_proprieta'],
            'comune' => $i['comune'],
            'categoria' => $i['categoria'] ?? '',
            'rendita' => $i['rendita'] ?? '',
            'is_pertinenza' => (int)($i['is_pertinenza'] ?? 0),
        ];
    }, $sogRepo->immobiliDiLocatore((int)$l['id']));
}

$storiaConduttori = [];
foreach ($conduttori as $c) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.id_contratto_univoco, c.stato,
               GROUP_CONCAT(DISTINCT s.cognome SEPARATOR ', ') AS locatori
        FROM contratto_conduttori cc
        JOIN contratti c ON c.id = cc.id_contratto
        JOIN contratto_locatori cl ON cl.id_contratto = c.id
        JOIN soggetti s ON s.id = cl.id_locatore
        WHERE cc.id_conduttore = ? AND c.stato IN ('ATTIVO','PROROGATO')
        GROUP BY c.id
    ");
    $stmt->execute([$c['id']]);
    $storiaConduttori[$c['id']] = $stmt->fetchAll();
}

$locatoriJs = [];
foreach ($locatori as $l) {
    $locatoriJs[] = ['id' => $l['id'], 'cf' => $l['codice_fiscale']];
}

$v = function($key, $default = '') use ($contrattoEdit) {
    return $contrattoEdit[$key] ?? $default;
};

$cfIntermediarioValue = !empty($contrattoEdit['cf_intermediario'])
    ? $contrattoEdit['cf_intermediario']
    : RLI_CF_INTERMEDIARIO;

$dataImpegnoValue = !empty($contrattoEdit['data_impegno_intermediario'])
    ? $contrattoEdit['data_impegno_intermediario']
    : '';

$codiceIdentificativoValue = $contrattoEdit['codice_identificativo_contratto'] ?? '';

$pageTitle = $editMode ? 'Modifica Contratto #' . $contrattoEdit['id'] : 'Nuovo Contratto di Locazione';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <base href="<?= url('/') ?>">
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?> — RLI Manager</title>
    <script src="assets/iban-validator.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 1150px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .step { display: none; }
        .step.active { display: block; }
        .step-indicator { display: flex; gap: 8px; margin-bottom: 25px; flex-wrap: wrap; }
        .step-indicator .step-dot { flex: 1; min-width: 130px; text-align: center; padding: 10px; background: #e2e8f0; border-radius: 8px; font-size: 0.8em; color: #718096; font-weight: bold; cursor: pointer; }
        .step-indicator .step-dot.active { background: #4299e1; color: #fff; }
        .step-indicator .step-dot.done { background: #48bb78; color: #fff; }
        h2 { color: #2b6cb0; margin-top: 0; }
        h4 { color: #4a5568; margin: 18px 0 8px 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; font-size: 1em; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 15px; font-size: 0.95em; }
        input:focus, select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 15px; }
        .row > div { flex: 1; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.95em; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-orange { background: #ed8936; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-sm { padding: 6px 14px; font-size: 0.85em; }
        .nav-buttons { display: flex; justify-content: space-between; margin-top: 25px; }
        .check-list { max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
        .check-item { display: flex; align-items: center; gap: 10px; padding: 8px 4px; border-bottom: 1px solid #edf2f7; }
        .check-item:last-child { border-bottom: none; }
        .check-item input[type="checkbox"], .check-item input[type="radio"] { width: auto; margin: 0; }
        .check-item label { margin: 0; font-weight: normal; flex: 1; cursor: pointer; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .badge-info { background: #bee3f8; color: #2c5282; }
        .badge-warn { background: #feebc8; color: #7b341e; }
        .badge-ok { background: #c6f6d5; color: #22543d; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .warn-box { background: #fff5f5; border-left: 4px solid #f56565; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .ok-box { background: #f0fff4; border-left: 4px solid #48bb78; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; }
        .edit-banner { background: #fff5f5; border-left: 4px solid #ed8936; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; }
        .edit-banner h3 { margin: 0 0 5px 0; color: #7b341e; }
        .edit-banner p { margin: 0; color: #744210; font-size: 0.9em; }
        .hint { color: #718096; font-size: 0.85em; margin-top: -10px; margin-bottom: 12px; display: block; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; font-weight: normal; font-size: 0.95em; }
        .checkbox-row input[type="checkbox"] { width: auto; margin: 0; }
        .checkbox-row label { margin: 0; font-weight: normal; cursor: pointer; }
        .ced-radio { display: inline-flex; align-items: center; gap: 4px; margin-right: 12px; font-size: 0.85em; font-weight: normal; }
        .ced-radio input { width: auto; margin: 0; }
        .imm-locatori { width: 100%; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #e2e8f0; display: flex; flex-direction: column; gap: 6px; }
        .imm-loc-row { display: flex; align-items: center; gap: 10px; font-size: 0.85em; }
        .imm-loc-name { flex: 1; color: #4a5568; }
        .imm-quota { width: 80px; padding: 4px 8px; margin: 0; }
        .imm-ced-label { display: inline-flex; align-items: center; gap: 4px; margin: 0; font-weight: normal; }
        .imm-ced-label input { width: auto; margin: 0; }
        .toggle-section { background: #f7fafc; border: 1px dashed #cbd5e0; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        details > summary { cursor: pointer; font-weight: 600; color: #4299e1; padding: 8px 0; user-select: none; }
        details[open] > summary { margin-bottom: 10px; }
        .iban-valid { border-color: #48bb78 !important; background-color: #f0fff4 !important; }
        .iban-invalid { border-color: #e53e3e !important; background-color: #fff5f5 !important; }
        .iban-feedback { font-size: 0.85em; margin-top: -8px; margin-bottom: 10px; display: block; min-height: 16px; }
        .iban-feedback-ok { color: #22543d; }
        .iban-feedback-err { color: #742a2a; }

        .intermediario-box { background: #fffaf0; border: 2px solid #ed8936; border-radius: 10px; padding: 20px; margin: 25px 0; }
        .intermediario-box h3 { margin: 0 0 6px 0; color: #7b341e; font-size: 1.05em; }
        .intermediario-box .subtitle { font-size: 0.85em; color: #744210; margin: 0 0 15px 0; }
        .intermediario-hint { font-size: 0.8em; margin-top: -10px; margin-bottom: 0; display: block; min-height: 16px; }
        .intermediario-hint.err { color: #742a2a; }
        .intermediario-hint.ok { color: #22543d; }
        .intermediario-hint.info { color: #2c5282; }
        .precompiled-label { font-size: 0.7em; color: #22543d; background: #c6f6d5; padding: 2px 8px; border-radius: 10px; margin-left: 8px; font-weight: bold; }

        .ravv-box { background: #fffbea; border: 2px solid #d69e2e; border-radius: 10px; padding: 20px; margin: 25px 0; }
        .ravv-box h3 { margin: 0 0 6px 0; color: #744210; font-size: 1.05em; }
        .ravv-box.error { background: #fff5f5; border-color: #e53e3e; }
        .ravv-box.error h3 { color: #742a2a; }
        .ravv-box.ok { background: #f0fff4; border-color: #48bb78; }
        .ravv-box.ok h3 { color: #22543d; }
        .ravv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .ravv-field { background: #fff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .ravv-field .lbl { font-size: 0.75em; color: #718096; text-transform: uppercase; }
        .ravv-field .val { font-size: 1.05em; color: #1a202c; font-weight: bold; margin-top: 3px; font-family: monospace; }
        .ravv-field .val.warn { color: #742a2a; }
        .ravv-field .val.ok { color: #22543d; }
        .ravv-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 15px; }
        .ravv-checkbox { background: #fefcbf; border-left: 4px solid #d69e2e; padding: 10px 12px; border-radius: 6px; display: flex; align-items: center; gap: 10px; font-weight: normal; margin-top: 10px; }
        .ravv-checkbox input { width: auto; margin: 0; }

        .ade-code-box { background: #f0fff4; border: 2px solid #48bb78; border-radius: 10px; padding: 15px 20px; margin: 15px 0; }
        .ade-code-box h3 { margin: 0 0 6px 0; color: #22543d; font-size: 1em; }
        .ade-code-box .subtitle { font-size: 0.85em; color: #22543d; margin: 0 0 10px 0; }
        .ade-code-box input { font-family: monospace; letter-spacing: 1px; text-transform: uppercase; background: #fff; }

        .allegato-box { margin-top: 20px; padding: 18px; border: 2px solid #4299e1; border-radius: 10px; background: #ebf8ff; transition: all 0.2s; }
        .allegato-box.optional { border-color: #a0aec0; background: #f7fafc; }
        .field-error { border-color: #e53e3e !important; background-color: #fff5f5 !important; }
        .field-ok { border-color: #48bb78 !important; background-color: #f0fff4 !important; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">

    <?php if ($editMode): ?>
        <div class="edit-banner">
            <h3>✏️ Modalità modifica</h3>
            <p>
                Stai modificando il contratto <strong>#<?php echo $contrattoEdit['id']; ?></strong>
                <?php if ($contrattoEdit['id_contratto_univoco']): ?>
                    (<code><?php echo htmlspecialchars($contrattoEdit['id_contratto_univoco']); ?></code>)
                <?php endif; ?>
                — Stato: <span class="badge badge-<?php echo $contrattoEdit['stato']; ?>"><?php echo $contrattoEdit['stato']; ?></span>
            </p>
        </div>
    <?php endif; ?>

    <h2 style="margin-bottom:25px;"><?php echo $editMode ? '✏️ ' . htmlspecialchars($pageTitle) : '➕ Nuovo Contratto di Locazione'; ?></h2>

    <div class="step-indicator">
        <div class="step-dot active" data-step="1">1. Dati base</div>
        <div class="step-dot" data-step="2">2. Locatori</div>
        <div class="step-dot" data-step="3">3. Conduttori</div>
        <div class="step-dot" data-step="4">4. Immobili</div>
        <div class="step-dot" data-step="5">5. Opzioni fiscali</div>
        <div class="step-dot" data-step="6">6. Riepilogo</div>
    </div>

    <form method="POST" action="save_pratica.php" id="form-contratto" enctype="multipart/form-data">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="<?php echo $editMode ? 'update_contratto' : 'save_contratto'; ?>">
        <?php if ($editMode): ?>
            <input type="hidden" name="id_contratto" value="<?php echo $contrattoEdit['id']; ?>">
        <?php endif; ?>

        <!-- STEP 1 -->
        <div class="step active" data-step="1">
            <div class="card">
                <h2>Dati base del contratto</h2>

                <label>ID Contratto Interno (nomenclatura utente) — opzionale</label>
                <input type="text" name="id_contratto_univoco" maxlength="20" value="<?php echo htmlspecialchars($v('id_contratto_univoco')); ?>" placeholder="es. ROSSI_BIANCHI_2425">
                <span class="hint">Nomenclatura interna per uso personale. Va nell'XML nel campo &lt;IDContratto&gt;. Se non lo conosci, verrà generato automaticamente.</span>

                <?php if ($editMode): ?>
                <div class="ade-code-box">
                    <h3>🏛️ Codice Identificativo Contratto (AdE)</h3>
                    <p class="subtitle">
                        Assegnato dall'Agenzia delle Entrate <strong>dopo la prima registrazione</strong>.
                        Formato: <code>TER20T016318000AA</code> (17 caratteri).
                    </p>
                    <input type="text" name="codice_identificativo_contratto" id="codice_identificativo_input"
                           maxlength="20"
                           value="<?php echo htmlspecialchars($codiceIdentificativoValue); ?>"
                           placeholder="es. TER20T016318000AA">
                    <span class="hint" id="codice_identificativo_hint"></span>
                </div>
                <?php endif; ?>

                <label>Tipologia contratto *</label>
                <select name="tipologia_contratto" id="tipologia_contratto" required>
                    <?php
                    $tipologie = [
                        'L1' => 'L1 - Locazione abitativa',
                        'L2' => 'L2 - Locazione agevolata',
                        'L3' => 'L3 - Abitativa con IVA',
                        'L4' => 'L4 - Locazione finanziaria abitativa',
                        'S1' => 'S1 - Uso diverso abitativo',
                        'S2' => 'S2 - Strumentale con IVA',
                        'S3' => 'S3 - Locazione finanziaria non abitativa',
                        'T1' => 'T1 - Fondo rustico',
                        'T2' => 'T2 - Fondo rustico agevolato',
                        'T3' => 'T3 - Terreni non edificabili',
                        'T4' => 'T4 - Terreni a parcheggio (IVA)',
                    ];
                    $tipCorr = $v('tipologia_contratto', 'L1');
                    foreach ($tipologie as $cod => $label) {
                        $sel = ($cod === $tipCorr) ? 'selected' : '';
                        echo "<option value=\"$cod\" $sel>$label</option>";
                    }
                    ?>
                </select>

                <label>Ufficio Territoriale AdE</label>
                <input type="text" name="ufficio_territoriale" placeholder="es. TDR" maxlength="10" value="<?php echo htmlspecialchars($v('ufficio_territoriale')); ?>">

                <div class="row">
                    <div>
                        <label>Data stipula *</label>
                        <input type="date" name="data_stipula" id="data_stipula" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo htmlspecialchars($v('data_stipula')); ?>">
                    </div>
                    <div>
                        <label>Durata dal *</label>
                        <input type="date" name="durata_dal" id="durata_dal" required value="<?php echo htmlspecialchars($v('durata_dal')); ?>">
                    </div>
                    <div>
                        <label>Durata al *</label>
                        <input type="date" name="durata_al" id="durata_al" required value="<?php echo htmlspecialchars($v('durata_al')); ?>">
                    </div>
                </div>

                <div class="row">
                    <div>
                        <label>Canone annuo € *</label>
                        <input type="number" step="0.01" name="importo_canone" id="importo_canone" required value="<?php echo htmlspecialchars($v('importo_canone')); ?>">
                    </div>
                    <div>
                        <label>Numero pagine</label>
                        <input type="number" name="numero_pagine" id="numero_pagine" value="<?php echo htmlspecialchars($v('numero_pagine', 1)); ?>" min="1">
                    </div>
                    <div>
                        <label>Numero copie</label>
                        <input type="number" name="numero_copie" id="numero_copie" value="<?php echo htmlspecialchars($v('numero_copie', 2)); ?>" min="1">
                    </div>
                </div>

                <label>Note interne</label>
                <textarea name="note" rows="2" placeholder="es. Contratto concordato 3+2"></textarea>
            </div>
        </div>

        <!-- STEP 2 -->
        <div class="step" data-step="2">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h2 style="margin:0;">Locatori del contratto</h2>
                    <button type="button" class="btn btn-green btn-sm" onclick="apriNuovoSoggettoModal('LOCATORE')" style="padding:10px 18px;">＋ Nuovo locatore</button>
                </div>
                <div class="info-box" style="margin-top:15px;">Seleziona uno o più locatori. La somma delle quote deve essere <strong>100%</strong>.</div>
                <label>Locatori disponibili</label>
                <div class="check-list" id="locatori-list">
                    <?php foreach ($locatori as $l): ?>
                        <div class="check-item">
                            <input type="checkbox" name="locatori[]" value="<?php echo $l['id']; ?>" class="loc-checkbox"
                                   data-nome="<?php echo htmlspecialchars($l['cognome'] . ' ' . $l['nome']); ?>"
                                   data-cf="<?php echo htmlspecialchars($l['codice_fiscale']); ?>"
                                   <?php echo in_array((int)$l['id'], $locatoriSel) ? 'checked' : ''; ?>>
                            <label>
                                <strong><?php echo htmlspecialchars($l['cognome'] . ' ' . $l['nome']); ?></strong>
                                <span style="color:#718096; font-size:0.85em;"> — <?php echo htmlspecialchars($l['codice_fiscale']); ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="locatori-config" style="margin-top:15px;"></div>
            </div>
        </div>

        <!-- STEP 3 -->
        <div class="step" data-step="3">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h2 style="margin:0;">Conduttori del contratto</h2>
                    <button type="button" class="btn btn-green btn-sm" onclick="apriNuovoSoggettoModal('CONDUTTORE')" style="padding:10px 18px;">＋ Nuovo conduttore</button>
                </div>
                <label style="margin-top:15px;">Conduttori disponibili</label>
                <div class="check-list" id="conduttori-list">
                    <?php foreach ($conduttori as $c): ?>
                        <div class="check-item">
                            <input type="checkbox" name="conduttori[]" value="<?php echo $c['id']; ?>" class="cond-checkbox"
                                   data-nome="<?php echo htmlspecialchars($c['cognome'] . ' ' . $c['nome']); ?>"
                                   <?php echo in_array((int)$c['id'], $conduttoriSel) ? 'checked' : ''; ?>>
                            <label>
                                <strong><?php echo htmlspecialchars($c['cognome'] . ' ' . $c['nome']); ?></strong>
                                <span style="color:#718096; font-size:0.85em;"> — <?php echo htmlspecialchars($c['codice_fiscale']); ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- STEP 4 -->
        <div class="step" data-step="4">
            <div class="card">
                <h2>Immobili del contratto</h2>
                <div class="info-box">Gli immobili mostrati sono quelli di proprietà dei locatori selezionati.</div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="margin:0;">Immobili disponibili</label>
                    <button type="button" class="btn btn-green btn-sm" onclick="apriNuovoImmobileModal()" style="padding:8px 16px;">＋ Nuovo immobile</button>
                </div>
                <div class="check-list" id="immobili-list">
                    <div style="color:#a0aec0; text-align:center; padding:20px;">Caricamento...</div>
                </div>
            </div>
        </div>

        <!-- STEP 5 -->
        <div class="step" data-step="5">
            <div class="card">
                <h2>Opzioni fiscali</h2>

                <h4>Casi particolari</h4>
                <select name="casi_particolari" id="casi_particolari">
                    <option value="" <?php echo $v('casi_particolari') === '' ? 'selected' : ''; ?>>Nessun caso particolare</option>
                    <option value="1" <?php echo $v('casi_particolari') == '1' ? 'selected' : ''; ?>>1 - Canone diverso per una o più annualità</option>
                    <option value="2" <?php echo $v('casi_particolari') == '2' ? 'selected' : ''; ?>>2 - Sublocazione</option>
                    <option value="3" <?php echo $v('casi_particolari') == '3' ? 'selected' : ''; ?>>3 - Canone diverso + intera durata</option>
                </select>

                <h4>Contratto esente</h4>
                <select name="contratto_esente">
                    <option value="" <?php echo $v('contratto_esente') === '' ? 'selected' : ''; ?>>Non esente (default)</option>
                    <option value="1" <?php echo $v('contratto_esente') == '1' ? 'selected' : ''; ?>>1 - Esente bollo</option>
                    <option value="2" <?php echo $v('contratto_esente') == '2' ? 'selected' : ''; ?>>2 - Esente bollo e registro</option>
                    <option value="3" <?php echo $v('contratto_esente') == '3' ? 'selected' : ''; ?>>3 - Esente registro</option>
                </select>

                <h4>Eventi eccezionali</h4>
                <select name="eventi_eccezionali">
                    <option value="" <?php echo $v('eventi_eccezionali') === '' ? 'selected' : ''; ?>>Nessun evento</option>
                    <option value="1" <?php echo $v('eventi_eccezionali') == '1' ? 'selected' : ''; ?>>1 - Agevolazioni da calamità naturali</option>
                    <option value="2" <?php echo $v('eventi_eccezionali') == '2' ? 'selected' : ''; ?>>2 - Calcolo imposta diverso da regole ordinarie</option>
                </select>

                <h4>Pagamento intera durata</h4>
                <div class="checkbox-row">
                    <input type="checkbox" name="pagamento_intera_durata" id="pagamento_intera_durata" value="1" <?php echo $v('pagamento_intera_durata') ? 'checked' : ''; ?>>
                    <label for="pagamento_intera_durata">Pago l'imposta di registro per l'intera durata</label>
                </div>

                <h4>Altre opzioni</h4>
                <div class="checkbox-row">
                    <input type="checkbox" name="agevolazioni" value="1" <?php echo $v('agevolazioni') ? 'checked' : ''; ?>>
                    <label>Agevolazioni Terzo Settore</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="contratto_soggetto_iva" value="1" <?php echo $v('contratto_soggetto_iva') ? 'checked' : ''; ?>>
                    <label>Contratto soggetto a IVA</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="condizione_sospensiva" value="1" <?php echo $v('condizione_sospensiva') ? 'checked' : ''; ?>>
                    <label>Condizione sospensiva</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="contratto_tempo_indeterminato" value="1" <?php echo $v('contratto_tempo_indeterminato') ? 'checked' : ''; ?>>
                    <label>Contratto a tempo indeterminato</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="clausola_penale_volontaria" value="1" <?php echo $v('clausola_penale_volontaria') ? 'checked' : ''; ?>>
                    <label>Clausola penale volontaria</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="comunica_dati_catastali" value="1" <?php echo $v('comunica_dati_catastali') ? 'checked' : ''; ?>>
                    <label>Comunica dati catastali (CDC)</label>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="contratto_non_allegato" id="contratto_non_allegato" value="1" <?php echo $v('contratto_non_allegato') ? 'checked' : ''; ?> onchange="aggiornaStatoAllegato()">
                    <label for="contratto_non_allegato">Contratto non allegato (spunta se NON vuoi allegare il PDF/A)</label>
                </div>

                <!-- BLOCCO ALLEGATO PDF/A -->
                <div class="allegato-box" id="allegato-box">
                    <h4 style="margin:0 0 8px 0; color:#2b6cb0; border:none; padding:0;">
                        📎 Allegato contratto PDF/A
                        <span id="allegato-required-badge" style="font-size:0.7em; background:#e53e3e; color:#fff; padding:2px 8px; border-radius:10px; margin-left:8px; vertical-align:middle;">OBBLIGATORIO</span>
                        <span id="allegato-optional-badge" style="font-size:0.7em; background:#a0aec0; color:#fff; padding:2px 8px; border-radius:10px; margin-left:8px; vertical-align:middle; display:none;">OPZIONALE</span>
                    </h4>
                    <p id="allegato-info" style="font-size:0.85em; color:#2c5282; margin:0 0 12px 0;">
                        AdE accetta solo file in formato <strong>PDF/A</strong> (ISO 19005). Il file verrà incluso nell'XML RLI.
                        Dimensione massima: <strong>5 MB</strong>.
                    </p>
                    <input type="file" name="allegato_pdf" id="allegato_pdf" accept="application/pdf,.pdf" class="file-input"
                           style="width:100%; padding:10px; border:1px solid #cbd5e0; border-radius:6px; background:#fff; margin:0;">

                    <div class="checkbox-row checkbox-row-light" style="margin-top:12px; background:#fff; padding:8px 12px; border-radius:6px; border:1px dashed #4299e1;">
                        <input type="checkbox" name="converti_pdfa_auto" id="converti_pdfa_auto" value="1" checked>
                        <label for="converti_pdfa_auto" style="font-weight:600; color:#2c5282; cursor:pointer;">
                            🔄 Converti automaticamente in PDF/A se necessario
                        </label>
                    </div>
                    <p style="font-size:0.8em; color:#4a5568; margin:8px 0 0 0;">
                        Se il file non è già PDF/A, il server proverà a convertirlo automaticamente con Ghostscript.
                    </p>

                    <div id="allegato-preview" style="margin-top:10px; font-size:0.85em; display:none;"></div>

                    <?php if ($editMode): ?>
                        <?php if ($contrattoHaAllegato): ?>
                            <p class="status-ok" style="font-size:0.85em; color:#22543d; background:#f0fff4; padding:8px 12px; border-radius:6px; margin:10px 0 0 0;">
                                ✅ <strong>Allegato già presente nel sistema.</strong>
                                Se non carichi un nuovo file, verrà mantenuto quello esistente.
                            </p>
                        <?php else: ?>
                            <p class="status-warn" style="font-size:0.85em; color:#742a2a; background:#fff5f5; padding:8px 12px; border-radius:6px; margin:10px 0 0 0;">
                                ⚠️ <strong>Nessun allegato presente.</strong>
                                Devi caricare il PDF/A oppure spuntare "Contratto non allegato".
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <h4>Allegati al contratto (per calcolo bollo)</h4>
                <div class="row">
                    <div><label>Scritture private</label><input type="number" name="numero_allegato_a" value="<?php echo htmlspecialchars($v('numero_allegato_a', 0)); ?>" min="0"></div>
                    <div><label>Ricevute / quietanze</label><input type="number" name="numero_allegato_b" value="<?php echo htmlspecialchars($v('numero_allegato_b', 0)); ?>" min="0"></div>
                    <div><label>Mappe / planimetrie</label><input type="number" name="numero_allegato_c" value="<?php echo htmlspecialchars($v('numero_allegato_c', 0)); ?>" min="0"></div>
                </div>

                <details style="margin-top:15px;" <?php echo $v('tipo_garanzia') ? 'open' : ''; ?>>
                    <summary>Garanzia / Fideiussione (opzionale)</summary>
                    <div class="toggle-section">
                        <label>Tipo Garanzia</label>
                        <select name="tipo_garanzia">
                            <option value="" <?php echo $v('tipo_garanzia') === '' ? 'selected' : ''; ?>>Nessuna garanzia</option>
                            <option value="1" <?php echo $v('tipo_garanzia') == '1' ? 'selected' : ''; ?>>1 - Fideiussione prestata da terzi</option>
                            <option value="2" <?php echo $v('tipo_garanzia') == '2' ? 'selected' : ''; ?>>2 - Garanzie di terzi (no fideiussione)</option>
                            <option value="3" <?php echo $v('tipo_garanzia') == '3' ? 'selected' : ''; ?>>3 - PAC (terreni agricoli)</option>
                            <option value="4" <?php echo $v('tipo_garanzia') == '4' ? 'selected' : ''; ?>>4 - PAC con garanzia</option>
                        </select>
                        <label>Importo Garanzia €</label>
                        <input type="number" step="0.01" name="importo_garanzia" value="<?php echo htmlspecialchars($v('importo_garanzia')); ?>">
                        <div class="row">
                            <div><label>CF Garante</label><input type="text" name="cf_garante" maxlength="16" value="<?php echo htmlspecialchars($v('cf_garante')); ?>"></div>
                            <div><label>CF 2° Garante</label><input type="text" name="cf_garante2" maxlength="16" value="<?php echo htmlspecialchars($v('cf_garante2')); ?>"></div>
                        </div>
                        <div class="checkbox-row">
                            <input type="checkbox" name="garanzia_soggetta_iva" value="1" <?php echo $v('garanzia_soggetta_iva') ? 'checked' : ''; ?>>
                            <label>Garanzia soggetta IVA</label>
                        </div>
                    </div>
                </details>

                <div class="intermediario-box" id="intermediario-box">
                    <h3>📤 Impegno alla presentazione (Intermediario)</h3>
                    <p class="subtitle">
                        Il CF è precompilato dalla configurazione (<code>RLI_CF_INTERMEDIARIO</code>). La <strong>Data Impegno è obbligatoria</strong> se il CF è compilato.
                    </p>
                    <div class="row">
                        <div>
                            <label for="cf_intermediario_input">
                                CF Intermediario <span class="precompiled-label">PRECOMPILATO</span>
                            </label>
                            <input type="text" name="cf_intermediario" id="cf_intermediario_input" maxlength="16"
                                   value="<?php echo htmlspecialchars($cfIntermediarioValue); ?>">
                            <span class="intermediario-hint info" id="cf_intermediario_hint"></span>
                        </div>
                        <div>
                            <label for="data_impegno_input">Data Impegno <span style="color:#e53e3e;">*</span></label>
                            <input type="date" name="data_impegno_intermediario" id="data_impegno_input"
                                   value="<?php echo htmlspecialchars($dataImpegnoValue); ?>">
                            <span class="intermediario-hint" id="data_impegno_hint"></span>
                        </div>
                    </div>
                </div>

                <div class="info-box" style="background:#f0f9ff; border-left-color:#4299e1;">
                    <h4 style="margin:0 0 10px 0; border:none; padding:0; color:#2b6cb0;">📅 Data invio telematico (prevista)</h4>
                    <p style="font-size:0.85em; color:#2c5282; margin:0 0 10px 0;">
                        Data in cui prevedi di trasmettere questo adempimento all'Agenzia delle Entrate.
                        <strong>Determina i giorni di ritardo e quindi sanzioni e interessi del ravvedimento operoso.</strong>
                    </p>
                    <div class="row">
                        <div>
                            <label for="data_invio_input">Data invio telematico *</label>
                            <input type="date" name="data_invio" id="data_invio_input"
                                   value="<?php echo htmlspecialchars($contrattoEdit['data_invio'] ?? date('Y-m-d')); ?>"
                                   required>
                        </div>
                        <div style="display:flex; align-items:flex-end;">
                            <button type="button" class="btn btn-gray btn-sm" onclick="resetDataInvio()" style="margin-bottom:15px;">
                                ↺ Oggi
                            </button>
                        </div>
                    </div>
                    <span class="hint" id="data_invio_hint"></span>
                </div>

                <div class="ravv-box" id="ravv-box">
                    <h3>⏱️ Ravvedimento Operoso</h3>
                    <div id="ravv-content">
                        <div style="color:#a0aec0; text-align:center; padding:20px;">Compila data stipula e durata al Step 1 per calcolare il ravvedimento.</div>
                    </div>
                </div>

                <input type="hidden" name="ravvedimento_accettato" id="ravv_accettato_hidden" value="<?php echo $v('ravvedimento_accettato', 0) ? '1' : '0'; ?>">
                <input type="hidden" name="ravvedimento_dettagli_json" id="ravv_dettagli_hidden" value="<?php echo htmlspecialchars($v('ravvedimento_dettagli_json', '')); ?>">

                <details style="margin-top:10px;" id="details_canoni_variabili" <?php echo !empty($canoniVariabiliEdit) ? 'open' : ''; ?>>
                    <summary>Canoni variabili (Quadro E)</summary>
                    <div class="toggle-section">
                        <table style="width:100%; border-collapse:collapse; margin-bottom:10px;">
                            <thead>
                                <tr class="table-head-light" style="background:#fff;">
                                    <th style="padding:8px; text-align:left; font-size:0.85em;">Annualità</th>
                                    <th style="padding:8px; text-align:left; font-size:0.85em;">Anno rif.</th>
                                    <th style="padding:8px; text-align:left; font-size:0.85em;">Canone €</th>
                                    <th style="padding:8px; text-align:center; font-size:0.85em;">×</th>
                                </tr>
                            </thead>
                            <tbody id="canoni_tbody"></tbody>
                        </table>
                        <button type="button" class="btn btn-primary btn-sm" onclick="aggiungiCanone()">+ Aggiungi</button>
                        <button type="button" class="btn btn-gray btn-sm" onclick="azzeraCanoni()">✕ Azzera</button>
                        <input type="hidden" name="canoni_variabili" id="canoni_variabili_hidden" value="">
                    </div>
                </details>
            </div>
        </div>

        <!-- STEP 6 -->
        <div class="step" data-step="6">
            <div class="card">
                <h2>Riepilogo</h2>
                <div id="riepilogo"></div>
                <div id="riepilogo-fiscale" style="margin-top:15px;"></div>
            </div>
        </div>

        <div class="nav-buttons">
            <button type="button" class="btn btn-gray" id="btn-prev" onclick="goStep(-1)" style="visibility:hidden;">← Indietro</button>
            <div>
                <button type="button" class="btn btn-gray" id="btn-bozza" onclick="salvaBozza(true)" title="Salva come bozza e continua dopo" style="margin-right: 10px;">💾 Salva bozza</button>
                <button type="button" class="btn btn-primary" id="btn-next" onclick="goStep(1)">Avanti →</button>
                <button type="submit" class="btn <?php echo $editMode ? 'btn-orange' : 'btn-green'; ?>" id="btn-submit" style="display:none;">
                    <?php echo $editMode ? '💾 Salva Modifiche' : '💾 Crea Contratto'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// === COSTANTI PASSATE DAL PHP ===
const PROPRIETA = <?= json_encode($proprieta) ?: '{}' ?>;
const EDIT_MODE = <?= $editMode ? 'true' : 'false' ?>;
const CONTRATTO_EDIT = <?= $editMode ? (json_encode($contrattoEdit) ?: 'null') : 'null' ?>;
const QUOTE_SEL = <?= json_encode($quoteSel) ?: '{}' ?>;
const CEDOLARE_SEL = <?= json_encode($cedolareSel) ?: '{}' ?>;
const RICHIEDENTE_SEL = <?= $richiedenteSel ? (int)$richiedenteSel : 'null' ?>;
const IP_SEL = <?= json_encode($ipSel) ?: '{}' ?>;
const TIPOLOGIA_IMM_SEL = <?= json_encode($tipologiaImmSel) ?: '{}' ?>;
const IMM_QUOTA_SEL = {};
const IMM_CEDOLARE_SEL = {};
const CANONI_INIT = <?= json_encode($canoniVariabiliEdit) ?: '[]' ?>;
const RAVV_INIT = <?= $editMode && !empty($contrattoEdit['ravvedimento_dettagli_json']) ? json_encode($contrattoEdit['ravvedimento_dettagli_json']) : 'null' ?>;
const RAVV_ACCETTATO_INIT = <?= $editMode && !empty($contrattoEdit['ravvedimento_accettato']) ? 'true' : 'false' ?>;
const CONTRATTO_HA_ALLEGATO = <?= !empty($contrattoHaAllegato) ? 'true' : 'false' ?>;
const CSRF_TOKEN_CN = <?= json_encode(Security::csrfToken()) ?>;
const PERSONE_FISICHE = <?= json_encode($personeFisiche) ?: '[]' ?>;

let currentStep = 1;
const totalSteps = 6;
let canoniRows = Array.isArray(CANONI_INIT) ? [...CANONI_INIT] : [];
let ravvCalcolato = null;

// === AUTOSALVATAGGIO BOZZA ===
let idContrattoBozza = <?php echo $editMode ? (int)$contrattoEdit['id'] : 0; ?>;

function salvaBozza(mostraAvviso) {
    const form = document.getElementById('form-contratto');
    
    // FIX: Salva lo stato dei radio "richiedente" prima di renderizzare
    const richiedenteScelto = document.querySelector('input[name="richiedente_id"]:checked');
    const idRichiedenteScelto = richiedenteScelto ? richiedenteScelto.value : null;

    // Forza il rendering dei locatori e immobili prima di salvare
    if (typeof renderLocatoriConfig === 'function') renderLocatoriConfig();
    if (typeof renderImmobili === 'function') renderImmobili();

    // Ripristina il radio scelto
    if (idRichiedenteScelto) {
        const radioDaSelezionare = document.querySelector('input[name="richiedente_id"][value="' + idRichiedenteScelto + '"]');
        if (radioDaSelezionare) radioDaSelezionare.checked = true;
    }
    
    // FIX BOZZA: Se il totale calcolato è 0, l'IBAN non è necessario.
    // Rimuoviamo il required per evitare il blocco del server.
    const ibanEl = document.getElementById('iban_input');
    const cfIntEl = document.getElementById('cf_int_input');
    if (ibanEl && cfIntEl) {
        const totStr = (document.getElementById('riepilogo-fiscale')?.innerText || '').match(/TOTALE:\s*€\s*([\d.,]+)/);
        const tot = totStr ? parseFloat(totStr[1].replace('.','').replace(',','.')) : 0;
        if (tot === 0 || ravvCalcolato?.totale === 0) {
            ibanEl.value = 'IT00X0000000000000000000000'; // IBAN fittizio per la bozza
            cfIntEl.value = '0000000000000000'; // CF fittizio per la bozza
        }
    }
    
    // Se c'è un file selezionato, avvisa l'utente (il file non viene inviato in background)
    const fileInput = document.getElementById('allegato_pdf');
    if (fileInput && fileInput.files.length > 0) {
        if (mostraAvviso) alert('⚠ L\'allegato PDF/A verrà salvato solo cliccando "Salva Modifiche" o "Crea Contratto".\nL\'autosalvataggio salva solo i dati testuali.');
    }

    // FIX: Assicurati che i componenti dinamici siano renderizzati prima di creare il FormData
    if (typeof renderLocatoriConfig === 'function') renderLocatoriConfig();
    if (typeof renderImmobili === 'function') renderImmobili();
    
    const fd = new FormData(form);
    fd.append('action', 'save_bozza');
    if (idContrattoBozza > 0) fd.append('id_contratto', idContrattoBozza);

    const btn = document.getElementById('btn-bozza');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Salvataggio...'; }

    return fetch('save_pratica.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (btn) { btn.disabled = false; btn.textContent = '💾 Salva bozza'; }

        if (!data.ok) {
            if (mostraAvviso) alert('Errore salvataggio bozza: ' + data.error);
            return false;
        }

        if (data.id_contratto && idContrattoBozza === 0) {
            idContrattoBozza = data.id_contratto;
            if (!form.querySelector('input[name="id_contratto"]')) {
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'id_contratto';
                h.value = idContrattoBozza;
                form.appendChild(h);
            }
            form.querySelector('input[name="action"]').value = 'update_contratto';
            if (history.replaceState) {
                history.replaceState(null, '', 'contract_new.php?id=' + idContrattoBozza);
            }
        }

        if (mostraAvviso) {
            alert('💾 Bozza salvata correttamente (ID #' + idContrattoBozza + ').\n\nPuoi riprenderla dalla home dei contratti.');
        }
        return true;
    })
    .catch(err => {
        if (btn) { btn.disabled = false; btn.textContent = '💾 Salva bozza'; }
        if (mostraAvviso) alert('Errore di rete. Riprova.');
        return false;
    });
}

</script>

<script>
// === VALIDAZIONE CODICE ADE ===
function validaCodiceAdE() {
    const el = document.getElementById('codice_identificativo_input');
    if (!el) return true;
    const hint = document.getElementById('codice_identificativo_hint');
    if (!hint) return true;
    const val = el.value.trim().toUpperCase();
    el.value = val;
    hint.textContent = '';
    hint.className = 'hint';

    if (val === '') {
        hint.textContent = 'Vuoto: verrà usato l\'ID interno negli adempimenti successivi.';
        return true;
    }
    if (!/^[0-9A-Z]{17}$/.test(val)) {
        hint.textContent = '⚠ Il codice AdE deve avere 17 caratteri alfanumerici (es. TER20T016318000AA).';
        hint.style.color = '#742a2a';
        return false;
    }
    hint.textContent = '✓ Formato valido.';
    hint.style.color = '#22543d';
    return true;
}

const codEl = document.getElementById('codice_identificativo_input');
if (codEl) {
    codEl.addEventListener('input', validaCodiceAdE);
    codEl.addEventListener('blur', validaCodiceAdE);
}

// === MODALE NUOVO SOGGETTO ===
function apriNuovoSoggettoModal(tipologia) {
    SoggettoModal.open({
        csrfToken: CSRF_TOKEN_CN,
        tipologiaDefault: tipologia,
        personeFisiche: PERSONE_FISICHE,
        onSave: function(soggetto) {
            if (tipologia === 'LOCATORE') {
                aggiungiSoggettoAllaLista('locatori', soggetto);
            } else if (tipologia === 'CONDUTTORE') {
                aggiungiSoggettoAllaLista('conduttori', soggetto);
            } else {
                if (currentStep === 2) aggiungiSoggettoAllaLista('locatori', soggetto);
                else if (currentStep === 3) aggiungiSoggettoAllaLista('conduttori', soggetto);
            }
        }
    });
}

function apriNuovoImmobileModal() {
    const locatoriSel = Array.from(document.querySelectorAll('.loc-checkbox:checked')).map(function(cb) { return cb.value; });
    if (locatoriSel.length === 0) { alert('Seleziona prima almeno un locatore.'); return; }
    ImmobileModal.open({
        csrfToken: CSRF_TOKEN_CN,
        idLocatore: locatoriSel[0],
        onSave: function(immobile) {
            const lid = String(locatoriSel[0]);
            if (!PROPRIETA[lid]) PROPRIETA[lid] = [];
            PROPRIETA[lid].push({
                id: immobile.id,
                identificativo: immobile.identificativo_interno,
                quota: 100,
                comune: immobile.comune,
                categoria: immobile.categoria || '',
                rendita: immobile.rendita || '',
                is_pertinenza: parseInt(immobile.is_pertinenza || 0)
            });
            IP_SEL[immobile.id] = 'I';
            TIPOLOGIA_IMM_SEL[immobile.id] = parseInt(immobile.is_pertinenza || 0) ? 2 : 1;
            renderImmobili();
        }
    });
}

function aggiungiSoggettoAllaLista(tipoLista, soggetto) {
    const containerId = tipoLista === 'locatori' ? 'locatori-list' : 'conduttori-list';
    const container = document.getElementById(containerId);
    if (!container) { return; }

    const existing = container.querySelector(`input[value="${soggetto.id}"]`);
    if (existing) {
        existing.checked = true;
        existing.dispatchEvent(new Event('change'));
        flashElement(existing.closest('.check-item'));
        return;
    }

    const item = document.createElement('div');
    item.className = 'check-item';
    item.style.background = '#f0fff4';
    item.style.transition = 'background 1.5s';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.name = tipoLista === 'locatori' ? 'locatori[]' : 'conduttori[]';
    cb.value = soggetto.id;
    cb.checked = true;
    cb.className = tipoLista === 'locatori' ? 'loc-checkbox' : 'cond-checkbox';
    cb.dataset.nome = (soggetto.cognome + ' ' + (soggetto.nome || '')).trim();
    cb.dataset.cf = soggetto.codice_fiscale;

    const label = document.createElement('label');
    label.innerHTML = '<strong>' + escapeHtml(soggetto.cognome + ' ' + (soggetto.nome || '')) + '</strong>'
                    + '<span style="color:#718096; font-size:0.85em;"> — ' + escapeHtml(soggetto.codice_fiscale) + '</span>';

    item.appendChild(cb);
    item.appendChild(label);
    container.appendChild(item);

    if (tipoLista === 'locatori') {
        cb.addEventListener('change', renderLocatoriConfig);
        if (typeof renderLocatoriConfig === 'function') renderLocatoriConfig();
    }

    flashElement(item);
}

function flashElement(el) {
    if (!el) return;
    el.style.background = '#c6f6d5';
    setTimeout(() => { el.style.background = ''; }, 1500);
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// === VALIDAZIONE INTERMEDIARIO ===
function validaIntermediario() {
    const cfEl = document.getElementById('cf_intermediario_input');
    const dataEl = document.getElementById('data_impegno_input');
    const cfHint = document.getElementById('cf_intermediario_hint');
    const dataHint = document.getElementById('data_impegno_hint');
    const cfVal = cfEl.value.trim();
    const dataVal = dataEl.value.trim();
    cfEl.classList.remove('field-error', 'field-ok');
    dataEl.classList.remove('field-error', 'field-ok');
    cfHint.textContent = ''; cfHint.className = 'intermediario-hint';
    dataHint.textContent = ''; dataHint.className = 'intermediario-hint';

    if (cfVal && dataVal) {
        cfEl.classList.add('field-ok'); dataEl.classList.add('field-ok');
        cfHint.classList.add('ok'); cfHint.textContent = '✓ CF compilato';
        dataHint.classList.add('ok'); dataHint.textContent = '✓ Data compilata';
        return true;
    }
    if (cfVal && !dataVal) {
        cfEl.classList.add('field-ok'); dataEl.classList.add('field-error');
        cfHint.classList.add('ok'); cfHint.textContent = '✓ CF compilato';
        dataHint.classList.add('err'); dataHint.textContent = '⚠ Data Impegno obbligatoria';
        return false;
    }
    if (!cfVal && dataVal) {
        dataEl.classList.add('field-ok'); cfEl.classList.add('field-error');
        dataHint.classList.add('ok'); dataHint.textContent = '✓ Data compilata';
        cfHint.classList.add('err'); cfHint.textContent = '⚠ Inserisci il CF';
        return false;
    }
    cfHint.classList.add('info');
    cfHint.textContent = 'Nessun intermediario configurato';
    return true;
}
document.getElementById('cf_intermediario_input').addEventListener('input', () => validaIntermediario());
document.getElementById('data_impegno_input').addEventListener('change', () => validaIntermediario());

// === RAVVEDIMENTO ===
function calcolaRavvedimentoClient() {
    // === RILEVAMENTO CEDOLARE SECCA E QUOTE ===
    // Legge gli input hidden sincronizzati dai controlli per-immobile.
    const locChecked = Array.from(document.querySelectorAll('.loc-checkbox:checked'));
    let totalQuota = 0, ordinaryQuota = 0, cedQuota = 0;
    locChecked.forEach(function(cb) {
        const lid = cb.value;
        const quotaEl = document.querySelector('[name="quota_' + lid + '"]');
        const quota = quotaEl ? (parseFloat(quotaEl.value) || 0) : 100;
        const cedEl = document.querySelector('[name="cedolare_' + lid + '"]');
        const isCed = cedEl
            ? (cedEl.type === 'checkbox' ? cedEl.checked : cedEl.value === '1')
            : false;
        totalQuota += quota;
        if (isCed) cedQuota += quota; else ordinaryQuota += quota;
    });
    const isCedolareSecca = totalQuota > 0 && ordinaryQuota <= 0;
    const isCedolareParziale = cedQuota > 0 && ordinaryQuota > 0;

    const dataStipula = document.querySelector('[name="data_stipula"]').value;
    const durataDal = document.querySelector('[name="durata_dal"]').value;
    const durataAl = document.querySelector('[name="durata_al"]').value;
    const canone = parseFloat(document.querySelector('[name="importo_canone"]').value) || 0;
    const tipologia = document.getElementById('tipologia_contratto').value;

    const dataInvioEl = document.querySelector('[name="data_invio"]');
    const dataInvio = (dataInvioEl && dataInvioEl.value) ? dataInvioEl.value : new Date().toISOString().slice(0, 10);

    if (!dataStipula || !durataDal) return null;

    let termineRif = dataStipula;
    if (durataDal && new Date(dataStipula) > new Date(durataDal)) termineRif = durataDal;

    // Calcola i giorni di ritardo: la scadenza è 30 giorni dopo la data di riferimento (termineRif), il ritardo inizia dal 31° giorno
    // Esempio: data di riferimento 01/10/2026 → scadenza 31/10/2026; invio il 01/11/2026 → ritardo di 1 giorno
    const msPerGiorno = 86400000;
    const dataScadenza = new Date(new Date(termineRif).getTime() + 30 * msPerGiorno);
    const gg = Math.floor((new Date(dataInvio) - dataScadenza) / msPerGiorno);
    const giorni = Math.max(0, gg);

    const hint = document.getElementById('data_invio_hint');
    if (hint) {
        if (giorni <= 0) {
            hint.innerHTML = `✅ In termine (scadenza ${new Date(dataScadenza).toLocaleDateString('it-IT')}).`;
            hint.style.color = '#22543d';
        } else if (giorni <= 30) {
            hint.innerHTML = `⚠ ${giorni} giorni di ritardo (entro 30 gg, scadenza era ${new Date(dataScadenza).toLocaleDateString('it-IT')}).`;
            hint.style.color = '#744210';
        } else {
            hint.innerHTML = `⚠ ${giorni} giorni di ritardo (oltre 30 gg, scadenza era ${new Date(dataScadenza).toLocaleDateString('it-IT')}).`;
            hint.style.color = '#742a2a';
        }
    }

    let escluso = false, motivoEsclusione = '';
    const durataGiorni = durataAl && durataDal ? Math.floor((new Date(durataAl) - new Date(durataDal)) / 86400000) : 0;
    if (durataGiorni <= 30 && durataGiorni > 0) { escluso = true; motivoEsclusione = 'Durata contratto ≤ 30 giorni'; }
    if (['L4', 'S3', 'T2'].includes(tipologia)) { escluso = true; motivoEsclusione = 'Tipologia ' + tipologia + ' esente da sanzioni'; }

    const tsInizio = new Date(termineRif);
    const dal2024 = new Date('2024-09-01');
    const al2025 = new Date('2025-06-13');

    let aliquota = 0, aliquotaBollo = 0, minimo = 0, desc = '';

    if (tsInizio < dal2024) {
        if (giorni <= 30) { aliquota = 0; desc = 'In termine'; }
        else if (giorni <= 90) { aliquota = 0.12; minimo = 20; desc = '90gg (pre-2024)'; }
        else if (giorni <= 365) { aliquota = 0.15; desc = '1 anno (pre-2024)'; }
        else if (giorni <= 730) { aliquota = 0.1714; desc = '1-2 anni (pre-2024)'; }
        else { aliquota = 0.20; desc = '>2 anni (pre-2024)'; }
        if (giorni <= 90) aliquotaBollo = 0.0888;
        else if (giorni <= 365) aliquotaBollo = 0.10;
        else aliquotaBollo = 0.1143;
    } else if (tsInizio < al2025) {
        if (giorni <= 30) { aliquota = 0; desc = 'In termine'; }
        else if (giorni <= 90) { aliquota = 0.12; desc = '90gg (2024-2025)'; }
        else if (giorni <= 365) { aliquota = 0.15; desc = '1 anno (2024-2025)'; }
        else { aliquota = 0.1714; desc = '>1 anno (2024-2025)'; }
        if (giorni <= 90) aliquotaBollo = 0.0888;
        else if (giorni <= 365) aliquotaBollo = 0.10;
        else aliquotaBollo = 0.1143;
    } else {
        if (giorni <= 30) { aliquota = 0; desc = 'In termine'; }
        else if (giorni <= 90) { aliquota = 0.12; minimo = 25; desc = '90gg (post-2025)'; }
        else if (giorni <= 365) { aliquota = 0.15; minimo = 31.25; desc = '1 anno (post-2025)'; }
        else { aliquota = 0.1714; minimo = 35.71; desc = '>1 anno (post-2025)'; }
        if (giorni <= 90) aliquotaBollo = 0.0888;
        else if (giorni <= 365) aliquotaBollo = 0.10;
        else aliquotaBollo = 0.1143;
    }

    let impReg = 0;
    if (isCedolareSecca) {
        impReg = 0;
    } else if (!['L3', 'L4', 'S3', 'T2', 'T4'].includes(tipologia)) {
        const aliq = tipologia === 'L2' ? 0.014 : (tipologia === 'S2' ? 0.01 : (tipologia === 'T1' ? 0.005 : 0.02));
        if (isCedolareParziale) {
            // Imposta proporzionale alla sola quota dei locatori in regime ordinario.
            const prop = canone * aliq * (ordinaryQuota / 100);
            impReg = prop > 0 ? Math.max(67, Math.round(prop)) : 0;
        } else {
            impReg = Math.max(67, Math.round(canone * aliq));
        }
    } else {
        impReg = (tipologia === 'L4' || tipologia === 'S3') ? 200 : 67;
    }

    const numPagine = parseInt(document.querySelector('[name="numero_pagine"]').value) || 1;
    const numCopie = parseInt(document.querySelector('[name="numero_copie"]').value) || 1;
    let impBol = isCedolareSecca ? 0 : 16 * numPagine * numCopie;

    // === RAMO CEDOLARE SECCA 100%: imposte, sanzioni e interessi a zero ===
    // Coerente con RliTaxCalculator: imposta base 0 => nessuna sanzione/interesse.
    if (isCedolareSecca) {
        return {
            termine_riferimento: termineRif, giorni_ritardo: giorni,
            in_termine: giorni <= 0, escluso: true, motivo_esclusione: 'Cedolare secca 100%',
            descrizione: 'Cedolare secca 100%: imposte, sanzioni e interessi non dovuti',
            imposta_registro: 0, imposta_bollo: 0,
            aliquota_sanz_registro: 0, aliquota_sanz_bollo: 0,
            sanzione_registro: 0, sanzione_bollo: 0,
            interessi_registro: 0, interessi_bollo: 0, totale: 0,
        };
    }

    if (escluso || giorni <= 30) {
        return {
            termine_riferimento: termineRif, giorni_ritardo: giorni,
            in_termine: giorni <= 30, escluso, motivo_esclusione: motivoEsclusione,
            descrizione: escluso ? motivoEsclusione : 'Nei termini',
            imposta_registro: impReg, imposta_bollo: impBol,
            aliquota_sanz_registro: 0, aliquota_sanz_bollo: 0,
            sanzione_registro: 0, sanzione_bollo: 0,
            interessi_registro: 0, interessi_bollo: 0, totale: 0,
        };
    }

    let sanzReg = Math.round(impReg * aliquota * 100) / 100;
    if (minimo > 0 && sanzReg < minimo) sanzReg = minimo;
    let sanzBol = Math.round(impBol * aliquotaBollo * 100) / 100;

    const tasso = 0.02;
    let intReg = sanzReg > 0 ? Math.round(impReg * tasso / 365 * giorni * 100) / 100 : 0;
    let intBol = sanzBol > 0 ? Math.round(impBol * tasso / 365 * giorni * 100) / 100 : 0;

    return {
        termine_riferimento: termineRif, giorni_ritardo: giorni,
        in_termine: false, escluso: false, motivo_esclusione: '',
        descrizione: desc,
        imposta_registro: impReg, imposta_bollo: impBol,
        aliquota_sanz_registro: aliquota, aliquota_sanz_bollo: aliquotaBollo,
        sanzione_registro: sanzReg, sanzione_bollo: sanzBol,
        interessi_registro: intReg, interessi_bollo: intBol,
        totale: sanzReg + sanzBol + intReg + intBol,
    };
}

function renderRavvedimento() {
    const content = document.getElementById('ravv-content');
    if (EDIT_MODE && RAVV_ACCETTATO_INIT && RAVV_INIT) {
        try {
            const saved = JSON.parse(RAVV_INIT);
            ravvCalcolato = saved;
            renderRavvedimentoUI(saved, true);
            return;
        } catch (e) {}
    }
    const r = calcolaRavvedimentoClient();
    if (!r) {
        content.innerHTML = '<div style="color:#a0aec0; text-align:center; padding:20px;">Compila data stipula e durata al Step 1.</div>';
        return;
    }
    ravvCalcolato = r;
    renderRavvedimentoUI(r, false);
}

function renderRavvedimentoUI(r, salvato) {
    const box = document.getElementById('ravv-box');
    const content = document.getElementById('ravv-content');
    let cls = 'ravv-box', titolo = '⏱️ Ravvedimento Operoso';
    if (r.escluso) { cls = 'ravv-box ok'; titolo = '✅ Escluso'; }
    else if (r.in_termine) { cls = 'ravv-box ok'; titolo = '✅ Nei Termini'; }
    else if (r.totale > 0) { cls = 'ravv-box error'; titolo = '⚠️ Sanzioni Dovute'; }
    box.className = cls;

    const fmt = (n) => '€ ' + parseFloat(n || 0).toFixed(2).replace('.', ',');
    const fmtDate = (d) => d ? new Date(d).toLocaleDateString('it-IT') : '—';

    let html = '<h3>' + titolo + '</h3>';
    html += '<p style="font-size:0.85em; margin:0 0 10px 0;">Termine: <strong>' + fmtDate(r.termine_riferimento) + '</strong> · Giorni ritardo: <strong>' + (r.giorni_ritardo || 0) + '</strong> · ' + (r.descrizione || '') + '</p>';
    html += '<div class="ravv-grid">';
    html += '<div class="ravv-field"><div class="lbl">Imposta Registro</div><div class="val">' + fmt(r.imposta_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Imposta Bollo</div><div class="val">' + fmt(r.imposta_bollo) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Sanzione Registro</div><div class="val' + (r.sanzione_registro > 0 ? ' warn' : '') + '">' + fmt(r.sanzione_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Sanzione Bollo</div><div class="val' + (r.sanzione_bollo > 0 ? ' warn' : '') + '">' + fmt(r.sanzione_bollo) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Interessi Registro</div><div class="val' + (r.interessi_registro > 0 ? ' warn' : '') + '">' + fmt(r.interessi_registro) + '</div></div>';
    html += '<div class="ravv-field"><div class="lbl">Interessi Bollo</div><div class="val' + (r.interessi_bollo > 0 ? ' warn' : '') + '">' + fmt(r.interessi_bollo) + '</div></div>';
    html += '<div class="ravv-field ravv-total" style="grid-column: span 2;"><div class="lbl">TOTALE RAVVEDIMENTO</div><div class="val' + (r.totale > 0 ? ' warn' : ' ok') + '">' + fmt(r.totale) + '</div></div>';
    html += '</div>';

    if (salvato) {
        html += '<div class="ravv-actions"><span class="badge badge-ok">✓ Valori salvati</span> <button type="button" class="btn btn-gray btn-sm" onclick="ricaricaRavvedimento()">🔄 Ricalcola</button></div>';
    } else if (r.totale > 0) {
        // Se c'è un totale, mostra SEMPRE la spunta
        html += '<div class="ravv-checkbox"><input type="checkbox" id="ravv_accetta_checkbox" onchange="toggleAccettaRavv(this.checked)"><label for="ravv_accetta_checkbox" style="cursor:pointer;">Accetto i valori calcolati</label></div>';
        html += '<div class="ravv-actions"><button type="button" class="btn btn-gray btn-sm" onclick="ricalcolaRavvedimento()">🔄 Ricalcola</button></div>';
    } else {
        html += '<div class="ok-box" style="margin-top:10px;"><strong>✓ Nessun importo per ravvedimento</strong></div>';
    }
    content.innerHTML = html;
    document.getElementById('ravv_dettagli_hidden').value = JSON.stringify(r);
    // Imposta accettato a 1 SOLO se non ci sono sanzioni da pagare (in termine o escluso)
    if (r.totale === 0 || r.escluso || r.in_termine) {
        document.getElementById('ravv_accettato_hidden').value = '1';
    } else if (document.getElementById('ravv_accettato_hidden').value === '1' && !salvato) {
        // Se c'era un vecchio 1 ma ora ci sono sanzioni e non è "salvato", riportalo a 0
        document.getElementById('ravv_accettato_hidden').value = '0';
    }
}
function toggleAccettaRavv(valore) {
    document.getElementById('ravv_accettato_hidden').value = valore ? '1' : '0';
    if (ravvCalcolato) { ravvCalcolato.accettato = valore; document.getElementById('ravv_dettagli_hidden').value = JSON.stringify(ravvCalcolato); }
}
function ricalcolaRavvedimento() { renderRavvedimento(); }
function ricaricaRavvedimento() {
    document.getElementById('ravv_accettato_hidden').value = '0';
    document.getElementById('ravv_dettagli_hidden').value = '';
    // Forza il ricalcolo ignorando i valori salvati
    const r = calcolaRavvedimentoClient();
    if (r) {
        ravvCalcolato = r;
        renderRavvedimentoUI(r, false); // false = non salvato, mostra la spunta
    }
}

['data_stipula', 'durata_dal', 'durata_al', 'importo_canone', 'tipologia_contratto', 'data_invio'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', function() { if (currentStep === 5 || currentStep === 1) renderRavvedimento(); });
});

// === ALLEGATO PDF/A ===
function statoAllegato() {
    const nonAllegato = document.getElementById('contratto_non_allegato')?.checked || false;
    const fileInput = document.getElementById('allegato_pdf');
    const file = (fileInput && fileInput.files && fileInput.files.length > 0) ? fileInput.files[0] : null;
    return {
        obbligatorio: !nonAllegato,
        nonAllegato: nonAllegato,
        file: file
    };
}

function aggiornaStatoAllegato() {
    const box = document.getElementById('allegato-box');
    const badgeReq = document.getElementById('allegato-required-badge');
    const badgeOpt = document.getElementById('allegato-optional-badge');
    const info = document.getElementById('allegato-info');
    const nonAllegato = document.getElementById('contratto_non_allegato')?.checked || false;

    if (!box) return;

    if (nonAllegato) {
        box.classList.add('optional');
        badgeReq.style.display = 'none';
        badgeOpt.style.display = 'inline-block';
        if (info) {
            info.style.color = '#4a5568';
            info.innerHTML = 'Hai spuntato <strong>"Contratto non allegato"</strong>. Il PDF/A non è richiesto, ma puoi comunque caricarlo se vuoi.';
        }
    } else if (typeof EDIT_MODE !== 'undefined' && EDIT_MODE
            && typeof CONTRATTO_HA_ALLEGATO !== 'undefined' && CONTRATTO_HA_ALLEGATO) {
        // Modifica con allegato esistente
        box.classList.add('optional');
        badgeReq.style.display = 'none';
        badgeOpt.style.display = 'inline-block';
        if (info) {
            info.style.color = '#22543d';
            info.innerHTML = '✅ <strong>Allegato già presente nel sistema.</strong> Carica un nuovo PDF solo se vuoi sostituirlo.';
        }
    } else {
        box.classList.remove('optional');
        badgeReq.style.display = 'inline-block';
        badgeOpt.style.display = 'none';
        if (info) {
            info.style.color = '#2c5282';
            info.innerHTML = 'Allega il contratto in <strong>PDF/A</strong> (max 5 MB). <strong>Obbligatorio</strong> se non hai spuntato "Contratto non allegato".';
        }
    }
}

function validaAllegato() {
    const stato = statoAllegato();

    // Caso 1: "Contratto non allegato" spuntato → OK
    if (stato.nonAllegato) {
        return { ok: true, msg: '', warning: null };
    }

    // Caso 2: nessun nuovo file caricato
    if (!stato.file) {
        // In modifica, se esiste già un allegato nel DB → OK, verrà mantenuto
        if (typeof EDIT_MODE !== 'undefined' && EDIT_MODE
            && typeof CONTRATTO_HA_ALLEGATO !== 'undefined' && CONTRATTO_HA_ALLEGATO) {
            return { ok: true, msg: '', warning: null };
        }
        // Altrimenti è un errore bloccante
        return {
            ok: false,
            msg: '⚠ Allegato PDF/A mancante!\n\n' +
                 'AdE accetta solo file in formato PDF/A.\n' +
                 'Devi allegare il contratto in PDF/A, oppure spuntare "Contratto non allegato" nelle opzioni fiscali.\n\n' +
                 'Vai allo Step 5 → sezione "Allegato contratto PDF/A".'
        };
    }

    // Caso 3: nuovo file caricato → valida
    if (stato.file.type && stato.file.type !== 'application/pdf') {
        return { ok: false, msg: '⚠ Il file allegato non è un PDF (tipo rilevato: ' + stato.file.type + ').' };
    }
    if (!stato.file.name.toLowerCase().endsWith('.pdf')) {
        return { ok: false, msg: '⚠ Il file deve avere estensione .pdf' };
    }

    const MAX = 5 * 1024 * 1024;
    if (stato.file.size > MAX) {
        return { ok: false, msg: '⚠ Il PDF è troppo grande (' + (stato.file.size / 1024 / 1024).toFixed(2) + ' MB).\nMassimo consentito: 5 MB.' };
    }

    return {
        ok: true,
        msg: '',
        warning: 'ℹ️ Il formato PDF/A verrà verificato al salvataggio. Se non è conforme, il server tenterà la conversione automatica (se abilitata).'
    };
}

function aggiornaAnteprimaAllegato() {
    const input = document.getElementById('allegato_pdf');
    const preview = document.getElementById('allegato-preview');
    if (!input || !preview) return;

    if (!input.files || input.files.length === 0) {
        preview.style.display = 'none';
        preview.innerHTML = '';
        return;
    }

    const f = input.files[0];
    const kb = (f.size / 1024).toFixed(1);
    const mb = (f.size / 1024 / 1024).toFixed(2);
    const sizeStr = (f.size < 1024 * 1024) ? kb + ' KB' : mb + ' MB';

    let icona = '📄';
    let colore = '#22543d';
    if (f.type !== 'application/pdf' && !f.name.toLowerCase().endsWith('.pdf')) {
        icona = '⚠️';
        colore = '#742a2a';
    } else if (f.size > 5 * 1024 * 1024) {
        icona = '⚠️';
        colore = '#742a2a';
    }

    preview.style.display = 'block';
    preview.innerHTML = '<span style="color:' + colore + '; font-weight:bold;">' + icona + ' ' +
        escapeHtml(f.name) + '</span> <span style="color:#718096;">(' + sizeStr + ')</span>';
}

// === NAVIGAZIONE ===
function goStep(delta) {
    const newStep = currentStep + delta;
    if (newStep < 1 || newStep > totalSteps) return;
    if (delta > 0 && !validaStep(currentStep)) return;

    // === AUTOSALVATAGGIO IN BACKGROUND QUANDO SI CAMBIA STEP ===
    const dataStip = document.querySelector('[name="data_stipula"]').value;
    if (dataStip && delta > 0 && typeof salvaBozza === 'function') {
        salvaBozza(false); // salva in background senza avvisare
    }

    document.querySelector('.step[data-step="' + currentStep + '"]').classList.remove('active');
    document.querySelector('.step[data-step="' + newStep + '"]').classList.add('active');
    currentStep = newStep;

    document.querySelectorAll('.step-dot').forEach(function(dot) {
        const s = parseInt(dot.dataset.step);
        dot.classList.toggle('active', s === currentStep);
        dot.classList.toggle('done', s < currentStep);
    });

    document.getElementById('btn-prev').style.visibility = currentStep > 1 ? 'visible' : 'hidden';
    document.getElementById('btn-next').style.display = currentStep < totalSteps ? 'inline-block' : 'none';
    document.getElementById('btn-submit').style.display = currentStep === totalSteps ? 'inline-block' : 'none';

    // I componenti sono già nel DOM, non serve rigenerarli se non per aggiornamenti specifici
    if (currentStep === 2) renderLocatoriConfig();
    // if (currentStep === 4) renderImmobili(); // Rimosso: già generati all'avvio per non perdere i dati

    if (currentStep === 5) { renderRavvedimento(); aggiornaStatoAllegato(); }
    if (currentStep === 6) renderRiepilogo();
}

function validaStep(step) {
    const form = document.getElementById('form-contratto');
    
    if (step === 1) {
        const fields = ['[name="data_stipula"]', '[name="durata_dal"]', '[name="durata_al"]', '[name="importo_canone"]'];
        if (!FormValidator.validateRequired(form, fields)) {
            return false;
        }
        const dal = document.querySelector('[name="durata_dal"]').value;
        const al = document.querySelector('[name="durata_al"]').value;
        if (dal && al && al < dal) {
            FormValidator.markError(document.querySelector('[name="durata_al"]'), 'La data fine deve essere successiva alla data inizio');
            return false;
        }
        if (!validaCodiceAdE()) {
            FormValidator.markError(document.getElementById('codice_identificativo_input'), 'Il codice identificativo AdE non è valido (17 caratteri alfanumerici).');
            return false;
        }
        return true;
    }
    if (step === 2) {
        const checked = document.querySelectorAll('.loc-checkbox:checked');
        if (checked.length === 0) {
            // Evidenzia la lista dei locatori
            const listEl = document.getElementById('locatori-list');
            if (listEl) {
                listEl.classList.add('field-error');
                listEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
        let sum = 0;
        checked.forEach(function(cb) { const inp = document.querySelector('[name="quota_' + cb.value + '"]'); if (inp) sum += parseFloat(inp.value) || 0; });
        if (Math.round(sum * 100) / 100 !== 100) {
            const firstQuota = document.querySelector('[name^="quota_"]');
            if (firstQuota) {
                FormValidator.markError(firstQuota, 'La somma delle quote è ' + sum.toFixed(2) + '%. Deve essere 100%.');
            }
            return false;
        }
        if (!document.querySelector('input[name="richiedente_id"]:checked')) {
            const listEl = document.querySelector('input[name="richiedente_id"]');
            if (listEl) {
                listEl.classList.add('field-error');
                listEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
        return true;
    }
    if (step === 3) {
        if (document.querySelectorAll('.cond-checkbox:checked').length === 0) {
            const listEl = document.getElementById('conduttori-list');
            if (listEl) {
                listEl.classList.add('field-error');
                listEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
        return true;
    }
    if (step === 4) {
        if (document.querySelectorAll('.imm-checkbox:checked').length === 0) {
            const listEl = document.getElementById('immobili-list');
            if (listEl) {
                listEl.classList.add('field-error');
                listEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
        const checked = document.querySelectorAll('.imm-checkbox:checked');
        for (let i = 0; i < checked.length; i++) {
            const iCheck = document.querySelector('input[name="ip_i_' + checked[i].value + '"]');
            const pCheck = document.querySelector('input[name="ip_p_' + checked[i].value + '"]');
            if (!iCheck.checked && !pCheck.checked) {
                FormValidator.markError(pCheck, 'Specifica Intero/Porzione');
                return false;
            }
        }
        return true;
    }
    if (step === 5) {
        if (!validaIntermediario()) {
            return false;
        }
        if (ravvCalcolato && ravvCalcolato.totale > 0 && document.getElementById('ravv_accettato_hidden').value !== '1') {
            const cb = document.getElementById('ravv_accetta_checkbox');
            if (cb) {
                FormValidator.markError(cb, 'Accetta i valori del ravvedimento per procedere.');
            }
            return false;
        }
        const casi = document.querySelector('[name="casi_particolari"]').value;
        if ((casi === '1' || casi === '3') && canoniRows.length === 0) {
            const casiEl = document.querySelector('[name="casi_particolari"]');
            if (casiEl) {
                FormValidator.markError(casiEl, 'Casi particolari: inserisci almeno una annualità');
            }
            return false;
        }
        if (canoniRows.length > 1) {
            for (let i = 1; i < canoniRows.length; i++) {
                if (canoniRows[i].anno !== canoniRows[i-1].anno + 1) {
                    // Evidenzia la tabella canoni
                    return false;
                }
            }
        }
        return true;
    }
    return true;
}

// === LOCATORI ===
document.querySelectorAll('.loc-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
        renderLocatoriConfig();
        // Rigenera la lista immobili in base ai locatori selezionati,
        // preservando le selezioni già effettuate (vedi captureImmobiliState).
        if (typeof renderImmobili === 'function') renderImmobili();
        if (typeof renderRavvedimento === 'function') renderRavvedimento();
    });
});
function renderLocatoriConfig() {
    const container = document.getElementById('locatori-config');
    const checked = Array.from(document.querySelectorAll('.loc-checkbox:checked'));
    if (checked.length === 0) { container.innerHTML = '<div style="color:#a0aec0;">Seleziona i locatori.</div>'; return; }

    // FIX: Leggi i valori ATTUALI dal DOM (se presenti) per non perderli al re-render
    const currentRadio = document.querySelector('input[name="richiedente_id"]:checked');
    const currentRichiedente = currentRadio ? currentRadio.value : null;

    let html = '<h4>Locatori selezionati</h4><table style="width:100%; border-collapse:collapse; font-size:0.9em;"><thead><tr style="background:#f7fafc;">';
    html += '<th style="padding:8px; text-align:left;">Locatore</th><th style="padding:8px; text-align:left;">Richiedente / Firmatario</th></tr></thead><tbody>';
    checked.forEach(function(cb, idx) {
        const id = cb.value, nome = cb.dataset.nome;
        // Quota e cedolare restano come input hidden per compatibilità con save_pratica.php/XmlBuilder;
        // vengono sincronizzati dai controlli per-immobile nella sezione Immobili.
        const quotaEl = document.querySelector('[name="quota_' + id + '"]');
        const quotaPrec = quotaEl ? quotaEl.value : (QUOTE_SEL[id] !== undefined ? QUOTE_SEL[id] : (checked.length === 1 ? 100 : ''));
        const cedEl = document.querySelector('[name="cedolare_' + id + '"]');
        const cedPrec = cedEl
            ? (cedEl.type === 'checkbox' ? (cedEl.checked ? 'checked' : '') : (cedEl.value === '1' ? 'checked' : ''))
            : (CEDOLARE_SEL[id] ? 'checked' : '');
        let richPrec = '';
        if (currentRichiedente !== null) {
            richPrec = (currentRichiedente == id) ? 'checked' : '';
        } else {
            richPrec = (RICHIEDENTE_SEL == id || (RICHIEDENTE_SEL === null && idx === 0)) ? 'checked' : '';
        }
        html += '<tr style="border-bottom:1px solid #edf2f7;"><td style="padding:8px;"><strong>' + nome + '</strong>' +
            '<input type="hidden" name="quota_' + id + '" value="' + quotaPrec + '">' +
            '<input type="hidden" name="cedolare_' + id + '" value="' + (cedPrec ? '1' : '') + '"></td>' +
            '<td style="padding:8px;"><input type="radio" name="richiedente_id" value="' + id + '" ' + richPrec + ' style="width:auto; margin:0;"></td></tr>';
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}
function distribuisciQuote() {
    const checked = Array.from(document.querySelectorAll('.loc-checkbox:checked'));
    if (checked.length === 0) return;
    const base = Math.floor((100 / checked.length) * 100) / 100;
    const resto = Math.round((100 - base * checked.length) * 100) / 100;
    checked.forEach(function(cb, i) { const input = document.querySelector('[name="quota_' + cb.value + '"]'); if (input) input.value = i === checked.length - 1 ? (base + resto).toFixed(2) : base.toFixed(2); });
}
function tuttiCedolare(valore) {
    document.querySelectorAll('input[name^="cedolare_"]').forEach(function(el) {
        if (el.type === 'checkbox') { el.checked = valore; }
        else { el.value = valore ? '1' : ''; }
    });
    if (typeof renderRavvedimento === 'function') renderRavvedimento();
}

// === IMMOBILI ===
// Cattura lo stato corrente dei checkbox immobili dal DOM e lo salva in
// IP_SEL / TIPOLOGIA_IMM_SEL, così un re-render (cambio locatori o submit)
// non perde le selezioni effettuate dall'utente.
function captureImmobiliState() {
    const boxes = document.querySelectorAll('.imm-checkbox');
    if (boxes.length === 0) return; // primo render: usa i valori iniziali forniti da PHP
    Object.keys(IP_SEL).forEach(function(k) { delete IP_SEL[k]; });
    boxes.forEach(function(cb) {
        const iid = cb.value;
        if (cb.checked) {
            const ipI = document.querySelector('[name="ip_i_' + iid + '"]');
            IP_SEL[iid] = (ipI && ipI.checked) ? 'I' : 'P';
        }
        const sel = document.querySelector('[name="tipologia_imm_' + iid + '"]');
        if (sel) TIPOLOGIA_IMM_SEL[iid] = sel.value;
    });
    // Cattura quota e cedolare per-immobile/locatore
    document.querySelectorAll('.imm-quota').forEach(function(inp) {
        const iid = inp.dataset.imm, lid = inp.dataset.loc;
        if (!IMM_QUOTA_SEL[iid]) IMM_QUOTA_SEL[iid] = {};
        IMM_QUOTA_SEL[iid][lid] = inp.value;
    });
    document.querySelectorAll('.imm-cedolare').forEach(function(cb) {
        const iid = cb.dataset.imm, lid = cb.dataset.loc;
        if (!IMM_CEDOLARE_SEL[iid]) IMM_CEDOLARE_SEL[iid] = {};
        IMM_CEDOLARE_SEL[iid][lid] = cb.checked;
    });
}

function getImmQuota(iid, lid) {
    if (IMM_QUOTA_SEL[iid] && IMM_QUOTA_SEL[iid][lid] !== undefined) return IMM_QUOTA_SEL[iid][lid];
    const imm = (PROPRIETA[lid] || []).find(function(x) { return x.id == iid; });
    return imm && imm.quota !== undefined ? imm.quota : 100;
}
function getImmCedolare(iid, lid) {
    if (IMM_CEDOLARE_SEL[iid] && IMM_CEDOLARE_SEL[iid][lid] !== undefined) return IMM_CEDOLARE_SEL[iid][lid];
    return !!CEDOLARE_SEL[lid];
}

function renderImmobili() {
    captureImmobiliState();
    const container = document.getElementById('immobili-list');
    const locatoriSel = Array.from(document.querySelectorAll('.loc-checkbox:checked')).map(function(cb) { return cb.value; });
    if (locatoriSel.length === 0) { container.innerHTML = '<div style="color:#a0aec0;">Seleziona prima un locatore</div>'; return; }
    let immobiliComuni = null;
    locatoriSel.forEach(function(lid) {
        const suoi = (PROPRIETA[lid] || []).map(function(i) { return String(i.id); });
        if (immobiliComuni === null) immobiliComuni = new Set(suoi);
        else immobiliComuni = new Set(Array.from(immobiliComuni).filter(function(x) { return suoi.includes(x); }));
    });
    if (!immobiliComuni || immobiliComuni.size === 0) { container.innerHTML = '<div class="warn-box">Nessun immobile in comune. Associa da <a href="proprieta.php">Proprietà</a>.</div>'; return; }
    let html = '';
    Array.from(immobiliComuni).forEach(function(iid) {
        let imm = null;
        for (let i = 0; i < locatoriSel.length; i++) { const found = (PROPRIETA[locatoriSel[i]] || []).find(function(x) { return x.id == iid; }); if (found) { imm = found; break; } }
        if (!imm) return;
        const eraSelezionato = Object.keys(IP_SEL).includes(String(iid));
        const ipAttuale = IP_SEL[iid] || '';
        const tipAttuale = TIPOLOGIA_IMM_SEL[iid] || 1;
        const selStr = eraSelezionato ? 'checked' : '';
        const ipICheck = ipAttuale === 'I' ? 'checked' : '';
        const ipPCheck = ipAttuale === 'P' ? 'checked' : '';
        const catLabel = imm.categoria ? ' · ' + imm.categoria : '';
        const rendLabel = imm.rendita ? ' · € ' + imm.rendita : '';
        let locRows = '';
        locatoriSel.forEach(function(lid) {
            const locCb = document.querySelector('.loc-checkbox[value="' + lid + '"]');
            const locNome = locCb ? locCb.dataset.nome : ('Locatore #' + lid);
            const quota = getImmQuota(iid, lid);
            const ced = getImmCedolare(iid, lid);
            locRows += '<div class="imm-loc-row">' +
                '<span class="imm-loc-name">' + locNome + '</span>' +
                '<input type="number" step="0.01" min="0" max="100" class="imm-quota" data-imm="' + iid + '" data-loc="' + lid + '" value="' + quota + '">' +
                '<label class="imm-ced-label"><input type="checkbox" class="imm-cedolare" data-imm="' + iid + '" data-loc="' + lid + '" ' + (ced ? 'checked' : '') + '> Cedolare</label>' +
                '</div>';
        });
        html += '<div class="check-item"><input type="checkbox" name="immobili[]" value="' + iid + '" class="imm-checkbox" data-nome="' + imm.identificativo + '" data-pertinenza="' + (imm.is_pertinenza ? '1' : '0') + '" ' + selStr + '>' +
            '<label style="flex:1;"><strong>' + imm.identificativo + '</strong><span style="color:#718096; font-size:0.85em;"> — ' + imm.comune + catLabel + rendLabel + '</span></label>' +
            '<div style="display:flex; gap:8px; align-items:center;">' +
            '<label class="ced-radio"><input type="checkbox" name="ip_i_' + iid + '" class="ip-check" data-imm="' + iid + '" data-tipo="I" ' + ipICheck + '> Intero</label>' +
            '<label class="ced-radio"><input type="checkbox" name="ip_p_' + iid + '" class="ip-check" data-imm="' + iid + '" data-tipo="P" ' + ipPCheck + '> Porzione</label>' +
            '<select name="tipologia_imm_' + iid + '" style="margin:0; padding:4px; width:auto; font-size:0.85em;">' +
            '<option value="1" ' + (tipAttuale == 1 ? 'selected' : '') + '>Principale</option>' +
            '<option value="2" ' + (tipAttuale == 2 ? 'selected' : '') + '>Pertinenza congiunta</option>' +
            '<option value="3" ' + (tipAttuale == 3 ? 'selected' : '') + '>Pertinenza disgiunta</option>' +
            '<option value="4" ' + (tipAttuale == 4 ? 'selected' : '') + '>Pertinenza atto separato</option>' +
            '</select></div>' +
            '<div class="imm-locatori">' + locRows + '</div></div>';
    });
    container.innerHTML = html;
    container.querySelectorAll('.ip-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const imm = this.dataset.imm, tipo = this.dataset.tipo;
            if (this.checked) { const other = document.querySelector('.ip-check[data-imm="' + imm + '"][data-tipo="' + (tipo === 'I' ? 'P' : 'I') + '"]'); if (other) other.checked = false; }
        });
    });
    // Se l'immobile è una pertinenza, pre-seleziona la tipologia pertinenziale
    container.querySelectorAll('.imm-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (this.checked && this.dataset.pertinenza === '1') {
                const sel = document.querySelector('[name="tipologia_imm_' + this.value + '"]');
                if (sel && sel.value === '1') sel.value = '2';
            }
            if (typeof renderRavvedimento === 'function') renderRavvedimento();
        });
    });
    // Sincronizza quota/cedolare per-immobile con gli input hidden per-locatore
    container.querySelectorAll('.imm-quota').forEach(function(inp) {
        inp.addEventListener('input', function() {
            const lid = this.dataset.loc;
            const hidden = document.querySelector('input[name="quota_' + lid + '"]');
            if (hidden) hidden.value = this.value;
            if (typeof renderRavvedimento === 'function') renderRavvedimento();
        });
    });
    container.querySelectorAll('.imm-cedolare').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const lid = this.dataset.loc;
            const hidden = document.querySelector('input[name="cedolare_' + lid + '"]');
            if (hidden) hidden.value = this.checked ? '1' : '';
            if (typeof renderRavvedimento === 'function') renderRavvedimento();
        });
    });
}

// === CANONI ===
function aggiungiCanone() {
    if (canoniRows.length >= 8) { alert('Massimo 8'); return; }
    let annoDefault;
    if (canoniRows.length > 0) annoDefault = canoniRows[canoniRows.length - 1].anno + 1;
    else { const dd = document.querySelector('[name="durata_dal"]').value; annoDefault = dd ? new Date(dd).getFullYear() + 1 : new Date().getFullYear() + 1; }
    const canoneAttuale = parseFloat(document.querySelector('[name="importo_canone"]').value) || 0;
    canoniRows.push({ anno: annoDefault, importo: canoneAttuale });
    renderCanoni();
}
function rimuoviCanone(idx) { if (!confirm('Rimuovere?')) return; canoniRows.splice(idx, 1); renderCanoni(); }
function azzeraCanoni() { if (canoniRows.length === 0) return; if (!confirm('Azzerare?')) return; canoniRows = []; renderCanoni(); }
function renderCanoni() {
    const tbody = document.getElementById('canoni_tbody');
    if (!tbody) return;
    if (canoniRows.length === 0) { tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px; color:#a0aec0;">Nessuna annualità.</td></tr>'; aggiornaCanoniHidden(); return; }
    let html = '';
    canoniRows.forEach(function(row, idx) {
        html += '<tr style="border-bottom:1px solid #edf2f7;"><td style="padding:8px;"><strong>Anno ' + (idx + 2) + '°</strong></td>' +
            '<td style="padding:8px;"><input type="number" min="1900" max="2100" value="' + row.anno + '" onchange="aggiornaAnno(' + idx + ', this.value)" style="width:110px; padding:6px; margin:0;"></td>' +
            '<td style="padding:8px;"><input type="number" step="0.01" min="0" value="' + row.importo + '" onchange="aggiornaImporto(' + idx + ', this.value)" style="width:130px; padding:6px; margin:0;"></td>' +
            '<td style="padding:8px; text-align:center;"><button type="button" onclick="rimuoviCanone(' + idx + ')" style="background:#fed7d7; color:#742a2a; border:none; border-radius:6px; padding:5px 12px; cursor:pointer; font-weight:bold;">×</button></td></tr>';
    });
    tbody.innerHTML = html;
    aggiornaCanoniHidden();
}
function aggiornaAnno(idx, valore) { const anno = parseInt(valore, 10); if (isNaN(anno) || anno < 1900 || anno > 2100) { alert('Anno non valido'); renderCanoni(); return; } canoniRows[idx].anno = anno; renderCanoni(); }
function aggiornaImporto(idx, valore) { const importo = parseFloat(valore); if (isNaN(importo) || importo < 0) { alert('Importo non valido'); renderCanoni(); return; } canoniRows[idx].importo = importo; aggiornaCanoniHidden(); }
function aggiornaCanoniHidden() { const el = document.getElementById('canoni_variabili_hidden'); if (el) el.value = canoniRows.length > 0 ? JSON.stringify(canoniRows) : ''; }

// === RIEPILOGO ===
function renderRiepilogo() {
    const container = document.getElementById('riepilogo');
    const locatori = Array.from(document.querySelectorAll('.loc-checkbox:checked')).map(function(cb) {
        const quotaEl = document.querySelector('[name="quota_' + cb.value + '"]');
        const cedEl = document.querySelector('[name="cedolare_' + cb.value + '"]');
        // Gli input quota_/cedolare_ sono hidden (sincronizzati dai controlli per-immobile):
        // leggere .value === '1' per la cedolare, non .checked (sempre false su hidden).
        const isCed = cedEl ? (cedEl.type === 'checkbox' ? cedEl.checked : cedEl.value === '1') : false;
        return { id: cb.value, nome: cb.dataset.nome, cf: cb.dataset.cf,
            quota: quotaEl ? quotaEl.value : '',
            rich: document.querySelector('[name="richiedente_id"][value="' + cb.value + '"]') ? document.querySelector('[name="richiedente_id"][value="' + cb.value + '"]').checked : false,
            cedolare: isCed };
    });
    const conduttori = Array.from(document.querySelectorAll('.cond-checkbox:checked')).map(function(cb) { return { id: cb.value, nome: cb.dataset.nome }; });
    const immobili = Array.from(document.querySelectorAll('.imm-checkbox:checked')).map(function(cb) {
        const iid = cb.value;
        return { id: iid, nome: cb.dataset.nome, ip: document.querySelector('[name="ip_i_' + iid + '"]').checked ? 'I' : 'P', tipologia: document.querySelector('[name="tipologia_imm_' + iid + '"]').value };
    });

    const tipologia = document.getElementById('tipologia_contratto').value;
    const canone = parseFloat(document.querySelector('[name="importo_canone"]').value) || 0;
    const dataStipula = document.querySelector('[name="data_stipula"]').value;
    const durataDal = document.querySelector('[name="durata_dal"]').value;
    const durataAl = document.querySelector('[name="durata_al"]').value;
    const numPagine = parseInt(document.querySelector('[name="numero_pagine"]').value) || 1;
    const numCopie = parseInt(document.querySelector('[name="numero_copie"]').value) || 1;
    const casi = document.querySelector('[name="casi_particolari"]').value;
    const esente = document.querySelector('[name="contratto_esente"]').value;
    const interaDurata = document.querySelector('[name="pagamento_intera_durata"]').checked;
    const agevolazioni = document.querySelector('[name="agevolazioni"]').checked;
    const nAllegatiA = parseInt(document.querySelector('[name="numero_allegato_a"]').value) || 0;
    const nAllegatiB = parseInt(document.querySelector('[name="numero_allegato_b"]').value) || 0;
    const nAllegatiC = parseInt(document.querySelector('[name="numero_allegato_c"]').value) || 0;
    const cfIntermediario = document.getElementById('cf_intermediario_input').value.trim();
    const dataImpegno = document.getElementById('data_impegno_input').value.trim();
    const codAdE = document.getElementById('codice_identificativo_input') ? document.getElementById('codice_identificativo_input').value.trim() : '';
    const nonAllegato = document.getElementById('contratto_non_allegato')?.checked || false;
    const dataInvio = document.getElementById('data_invio_input') ? document.getElementById('data_invio_input').value : '';

    let html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;"><div><h4>Dati contratto</h4>' +
        '<p><strong>ID Interno:</strong> ' + (document.querySelector('[name="id_contratto_univoco"]').value || '—') + '</p>' +
        (codAdE ? '<p><strong>Codice AdE:</strong> <code>' + codAdE + '</code></p>' : '') +
        '<p><strong>Tipologia:</strong> ' + tipologia + '</p>' +
        '<p><strong>Canone:</strong> € ' + canone.toFixed(2).replace('.', ',') + '</p>' +
        '<p><strong>Stipula:</strong> ' + dataStipula + '</p>' +
        '<p><strong>Durata:</strong> ' + durataDal + ' → ' + durataAl + '</p>' +
        '<p><strong>Pagine / Copie:</strong> ' + numPagine + ' / ' + numCopie + '</p>' +
        '<p><strong>Data invio telematico:</strong> ' + (dataInvio || '—') + '</p>' +
        '<p><strong>Allegato PDF/A:</strong> ' + (nonAllegato ? '<span style="color:#718096;">Non allegato</span>' : (document.getElementById('allegato_pdf')?.files?.length ? '<span style="color:#22543d;">Nuovo file</span>' : (EDIT_MODE && CONTRATTO_HA_ALLEGATO ? '<span style="color:#22543d;">Mantenuto esistente</span>' : '<span style="color:#742a2a;">NO (mancante!)</span>'))) + '</p>' +
        (esente ? '<p style="color:#38a169;"><strong>Esente:</strong> ' + esente + '</p>' : '') +
        (agevolazioni ? '<p style="color:#38a169;"><strong>Agevolazioni Terzo Settore</strong></p>' : '') +
        (interaDurata ? '<p style="color:#38a169;"><strong>Pagamento intera durata</strong></p>' : '') +
        (casi ? '<p><strong>Casi particolari:</strong> ' + casi + '</p>' : '') + '</div>';
    html += '<div><h4>Soggetti</h4><p><strong>Locatori:</strong></p><ul style="margin:0 0 10px 20px;">' +
        locatori.map(function(l) { return '<li>' + l.nome + ' (' + l.quota + '%)' + (l.rich ? ' <span class="badge badge-ok">Rich.</span>' : '') + (l.cedolare ? ' <span class="badge badge-info">Ced.</span>' : '') + '</li>'; }).join('') +
        '</ul><p><strong>Conduttori:</strong></p><ul style="margin:0 0 0 20px;">' +
        conduttori.map(function(c) { return '<li>' + c.nome + '</li>'; }).join('') + '</ul>' +
        '<h4>Intermediario</h4>' + (cfIntermediario && dataImpegno ? '<p><strong>CF:</strong> ' + cfIntermediario + '</p><p><strong>Data:</strong> ' + dataImpegno + '</p>' : '<p style="color:#a0aec0;">Nessuno</p>') +
        '</div></div>' +
        '<h4>Immobili</h4><ul style="margin:0 0 0 20px;">' + immobili.map(function(i) { return '<li>' + i.nome + ' (' + (i.ip === 'I' ? 'Intero' : 'Porzione') + ', tipo ' + i.tipologia + ')</li>'; }).join('') + '</ul>';
    if (canoniRows.length > 0) {
        html += '<h4>Canoni variabili</h4><table style="width:100%; border-collapse:collapse;"><thead><tr style="background:#f7fafc;"><th style="padding:6px;">Annualità</th><th style="padding:6px;">Anno</th><th style="padding:6px;">Canone</th></tr></thead><tbody>' +
            canoniRows.map(function(c, i) { return '<tr><td style="padding:6px;">' + (i+2) + '°</td><td style="padding:6px;">' + c.anno + '</td><td style="padding:6px;">' + parseFloat(c.importo).toFixed(2).replace('.', ',') + '</td></tr>'; }).join('') + '</tbody></table>';
    }
    container.innerHTML = html;

    const locatoriCed = locatori.filter(function(l) { return l.cedolare; }).length;
    let cedStato = '3';
    if (locatori.length > 0 && locatoriCed === locatori.length) cedStato = '1';
    else if (locatoriCed > 0) cedStato = '2';

    let aliquota = 0.02;
    if (tipologia === 'L2') aliquota = 0.014;
    else if (tipologia === 'S2') aliquota = 0.01;
    else if (tipologia === 'T1') aliquota = 0.005;

    let impReg = 67;
    if (esente) impReg = 0;
    else if (agevolazioni) impReg = ['L4', 'S3'].indexOf(tipologia) !== -1 ? 200 : 67;
    else if (cedStato === '1' && ['L1','L2','S1'].indexOf(tipologia) !== -1) impReg = 0;
    else if (['L3','T2','T4'].indexOf(tipologia) !== -1) impReg = 67;
    else if (['L4','S3'].indexOf(tipologia) !== -1) impReg = 200;
    else impReg = Math.max(67, Math.round(canone * aliquota));

    let impBollo = 0;
    if (esente === '1' || esente === '2' || agevolazioni || cedStato === '1') impBollo = 0;
    else if (['L4', 'S3'].indexOf(tipologia) !== -1) impBollo = 16;
    else { impBollo = 16 * numPagine * numCopie + 16 * nAllegatiA * numCopie + 2 * nAllegatiB * numCopie + 1 * nAllegatiC * numCopie; }

    let sanzReg = 0, sanzBol = 0, intReg = 0, intBol = 0;
    if (ravvCalcolato && ravvCalcolato.totale > 0) {
        sanzReg = ravvCalcolato.sanzione_registro || 0;
        sanzBol = ravvCalcolato.sanzione_bollo || 0;
        intReg = ravvCalcolato.interessi_registro || 0;
        intBol = ravvCalcolato.interessi_bollo || 0;
    }

    const totale = impReg + impBollo + sanzReg + sanzBol + intReg + intBol;

    let ibanBox = '';
    if (totale > 0) {
        const richLoc = locatori.find(function(l) { return l.rich; });
        const cfPrec = (EDIT_MODE && CONTRATTO_EDIT && CONTRATTO_EDIT.cf_intestatario_cc) ? CONTRATTO_EDIT.cf_intestatario_cc : (richLoc ? richLoc.cf : '');
        const ibanPrec = (EDIT_MODE && CONTRATTO_EDIT && CONTRATTO_EDIT.iban) ? CONTRATTO_EDIT.iban : '';
        ibanBox = '<div class="iban-box" style="background:#ebf8ff; border:2px solid #4299e1; padding:15px; border-radius:8px; margin-top:15px;">' +
            '<h4 style="margin:0 0 10px 0; color:#2b6cb0;">💳 Coordinate di addebito</h4>' +
            '<label style="display:block; font-weight:600; margin-bottom:5px; font-size:0.9em;">IBAN *</label>' +
            '<input type="text" name="iban" id="iban_input" required maxlength="34" value="' + ibanPrec + '" placeholder="IT60X0542811101000000123456" style="width:100%; padding:10px; border:1px solid #cbd5e0; border-radius:6px; font-family:monospace; text-transform:uppercase; margin-bottom:5px;">' +
            '<span class="iban-feedback" id="iban_input_feedback"></span>' +
            '<label style="display:block; font-weight:600; margin-bottom:5px; font-size:0.9em; margin-top:10px;">CF Intestatario conto *</label>' +
            '<input type="text" name="cf_intestatario_cc" id="cf_int_input" required maxlength="16" value="' + cfPrec + '" style="width:100%; padding:10px; border:1px solid #cbd5e0; border-radius:6px; font-family:monospace; text-transform:uppercase; margin-bottom:5px;"></div>';
    } else {
        ibanBox = '<div class="info-box iban-box-ok" style="background:#f0fff4; border-color:#48bb78;"><strong>✓ Nessun importo da versare</strong></div>';
    }

    document.getElementById('riepilogo-fiscale').innerHTML =
        '<div class="info-box"><strong>Stima imposte:</strong><br>Registro: <strong>€ ' + impReg.toFixed(2).replace('.', ',') + '</strong><br>Bollo: <strong>€ ' + impBollo.toFixed(2).replace('.', ',') + '</strong><br>' +
        (sanzReg > 0 ? 'Sanz. Registro: <strong>€ ' + sanzReg.toFixed(2).replace('.', ',') + '</strong><br>' : '') +
        (sanzBol > 0 ? 'Sanz. Bollo: <strong>€ ' + sanzBol.toFixed(2).replace('.', ',') + '</strong><br>' : '') +
        (intReg > 0 ? 'Int. Registro: <strong>€ ' + intReg.toFixed(2).replace('.', ',') + '</strong><br>' : '') +
        (intBol > 0 ? 'Int. Bollo: <strong>€ ' + intBol.toFixed(2).replace('.', ',') + '</strong><br>' : '') +
        '<strong>TOTALE: € ' + totale.toFixed(2).replace('.', ',') + '</strong></div>' + ibanBox;

    if (totale > 0) {
        setTimeout(function() {
            const ibanInput = document.getElementById('iban_input');
            if (ibanInput && window.IBANValidator) { window.IBANValidator.attach(ibanInput); if (ibanInput.value) ibanInput.dispatchEvent(new Event('blur')); }
        }, 10);
    }
}

// === SUBMIT ===
document.getElementById('form-contratto').addEventListener('submit', function(e) {
    // FIX: Salva lo stato dei radio "richiedente" prima di renderizzare
    // (altrimenti renderLocatoriConfig li resetta al valore iniziale)
    const richiedenteScelto = document.querySelector('input[name="richiedente_id"]:checked');
    const idRichiedenteScelto = richiedenteScelto ? richiedenteScelto.value : null;

    // Forza il rendering dei locatori e immobili prima dell'invio
    if (typeof renderLocatoriConfig === 'function') renderLocatoriConfig();
    if (typeof renderImmobili === 'function') renderImmobili();

    // Ripristina il radio "richiedente" scelto dall'utente
    if (idRichiedenteScelto) {
        const radioDaSelezionare = document.querySelector('input[name="richiedente_id"][value="' + idRichiedenteScelto + '"]');
        if (radioDaSelezionare) radioDaSelezionare.checked = true;
    }

    // Ripristina l'azione corretta in base alla modalità (creazione vs modifica).
    // IMPORTANTE: se esiste già una bozza salvata (idContrattoBozza > 0) o un
    // id_contratto nascosto, dobbiamo AGGIORNARE quella bozza e NON crearne una
    // nuova, altrimenti si genera un contratto duplicato con lo stesso contenuto.
    const actionInput = this.querySelector('input[name="action"]');
    if (actionInput) {
        const hasDraft = (typeof idContrattoBozza !== 'undefined' && idContrattoBozza > 0)
            || !!this.querySelector('input[name="id_contratto"]');
        const isEdit = <?php echo $editMode ? 'true' : 'false'; ?>;
        actionInput.value = (isEdit || hasDraft) ? 'update_contratto' : 'save_contratto';
    }

    // FIX: rimuovi la validazione HTML5 da TUTTI i campi prima del submit
    this.querySelectorAll('[required]').forEach(function(el) {
        el.removeAttribute('required');
    });
    this.querySelectorAll('[min]').forEach(function(el) {
        el.removeAttribute('min');
    });
    this.querySelectorAll('[max]').forEach(function(el) {
        el.removeAttribute('max');
    });

    if (currentStep !== 6) { e.preventDefault(); return; }
    if (!validaIntermediario()) { e.preventDefault(); return; }
    if (!validaCodiceAdE()) { e.preventDefault(); return; }
    if (ravvCalcolato && ravvCalcolato.totale > 0 && document.getElementById('ravv_accettato_hidden').value !== '1') {
        e.preventDefault();
        const cb = document.getElementById('ravv_accetta_checkbox');
        if (cb) FormValidator.markError(cb, 'Accetta i valori del ravvedimento');
        return;
    }

    const allegatoCheck = validaAllegato();
    if (!allegatoCheck.ok) {
        e.preventDefault();
        if (currentStep !== 5) goStep(5 - currentStep);
        setTimeout(function() {
            const el = document.getElementById('allegato_pdf');
            if (el) FormValidator.markError(el, allegatoCheck.msg);
            const box = document.getElementById('allegato-box');
            if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 200);
        return;
    }

    const ibanEl = document.getElementById('iban_input');
    const cfEl = document.getElementById('cf_int_input');
    // FIX: Se il totale è zero, non validare IBAN e CF
    const totStr = (document.getElementById('riepilogo-fiscale')?.innerText || '').match(/TOTALE:\s*€\s*([\d.,]+)/);
    const totaleFinale = totStr ? parseFloat(totStr[1].replace('.','').replace(',','.')) : 0;
    const zeroTotale = (totaleFinale === 0 || ravvCalcolato?.totale === 0);

    if (ibanEl && !zeroTotale) {
        if (!ibanEl.value.trim()) { e.preventDefault(); FormValidator.markError(ibanEl, 'Inserisci IBAN'); return; }
        if (window.IBANValidator) { const r = window.IBANValidator.validate(ibanEl.value); if (!r.valid) { e.preventDefault(); FormValidator.markError(ibanEl, 'IBAN non valido: ' + r.error); return; } }
    }
    if (cfEl && !zeroTotale && !cfEl.value.trim()) { e.preventDefault(); FormValidator.markError(cfEl, 'Inserisci CF intestatario'); return; }
});

document.querySelectorAll('.step-dot').forEach(function(dot) {
    dot.addEventListener('click', function() {
        const target = parseInt(dot.dataset.step);
        if (target < currentStep || validaStep(currentStep)) { const delta = target - currentStep; if (delta !== 0) goStep(delta); }
    });
});

function resetDataInvio() {
    const el = document.getElementById('data_invio_input');
    if (!el) return;
    el.value = new Date().toISOString().slice(0, 10);
    el.dispatchEvent(new Event('change'));
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('.step[data-step="1"]').classList.add('active');
    
    // FIX BOZZA: Genera subito tutti i componenti nel DOM per non perdere i dati al salvataggio
    renderLocatoriConfig();
    renderCanoni();
    renderImmobili(); // Aggiunto per forzare la creazione dei checkbox immobili

    validaIntermediario();
    validaCodiceAdE();
    renderRavvedimento();
    aggiornaStatoAllegato();

    const inp = document.getElementById('allegato_pdf');
    if (inp) inp.addEventListener('change', aggiornaAnteprimaAllegato);

    const durataInput = document.querySelector('[name="durata_dal"]');
    if (durataInput) durataInput.addEventListener('change', function() {
        if (canoniRows.length > 0 && !EDIT_MODE) if (confirm('Rinumerare gli anni?')) { const ab = new Date(this.value).getFullYear(); canoniRows.forEach(function(row, idx) { row.anno = ab + idx + 1; }); renderCanoni(); }
    });
});
</script>

<script src="assets/cf_utils.js"></script>
<script src="assets/soggetto_modal.js"></script>
<script src="assets/immobile_modal.js"></script>
<script src="assets/uppercase.js"></script>
<script src="assets/validation.js"></script>
</body>
</html>
