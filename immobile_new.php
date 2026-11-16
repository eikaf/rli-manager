<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';
require_once 'lib/NotificaRepository.php';

$auth->requireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') Security::requireCsrf();

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);
$error = '';

// Modalità modifica
$editMode = isset($_GET['edit']) && (int)$_GET['edit'] > 0;
$immobileEdit = null;
if ($editMode) {
    $stmtEdit = $pdo->prepare("SELECT * FROM immobili WHERE id = ?");
    $stmtEdit->execute([(int)$_GET['edit']]);
    $immobileEdit = $stmtEdit->fetch();
    if (!$immobileEdit) { header("Location: immobili.php?error=Immobile+non+trovato"); exit; }
}

// Duplicazione: precompila dal immobile di origine, lasciando vuoti subalterno e rendita
$duplicaSource = null;
if (!$editMode && isset($_GET['duplica']) && (int)$_GET['duplica'] > 0) {
    $stmtDup = $pdo->prepare("SELECT * FROM immobili WHERE id = ?");
    $stmtDup->execute([(int)$_GET['duplica']]);
    $duplicaSource = $stmtDup->fetch();
    if ($duplicaSource) {
        $immobileEdit = $duplicaSource;
        $immobileEdit['subalterno'] = '';
        $immobileEdit['rendita'] = '';
        $immobileEdit['identificativo_interno'] = trim(($duplicaSource['identificativo_interno'] ?? '') . ' (copia)');
    }
}

// Helper per i valori
function v($key, $default = '') {
    global $immobileEdit;
    return $immobileEdit[$key] ?? $default;
}

// --- Salvataggio ---
if (isset($_POST['save_immobile'])) {
    try {
        $toponimo = strtoupper(trim($_POST['toponimo'] ?? ''));
        if ($toponimo === '') throw new Exception("Il campo Toponimo è obbligatorio (es. VIA, PIAZZA, CORSO).");

        // Provincia obbligatoria: sigla di 2 lettere maiuscole oppure "EE" per immobili esteri.
        $provincia = strtoupper(trim($_POST['provincia'] ?? ''));
        if ($provincia === '') {
            throw new Exception("Il campo Provincia è obbligatorio (sigla di 2 lettere, es. RM, oppure 'EE' per immobili esteri).");
        }
        if (!preg_match('/^[A-Z]{2}$/', $provincia)) {
            throw new Exception("La provincia '{$provincia}' non è valida: usa una sigla di 2 lettere (es. RM) oppure 'EE' per immobili esteri.");
        }

        $isPertinenza = !empty($_POST['is_pertinenza']) ? 1 : 0;

        // Se editMode, fai UPDATE
        if ($editMode) {
            $idImmobile = (int)$immobileEdit['id'];
            $pdo->prepare("UPDATE immobili SET
                identificativo_interno = ?, codice_comune = ?, provincia = ?, sezione = ?, tipo_catasto = ?,
                categoria = ?, is_pertinenza = ?, comune = ?, foglio = ?, particella = ?, subalterno = ?,
                rendita = ?, reddito_dominicale = ?, toponimo = ?, indirizzo = ?, civico = ?,
                in_via_di_accatastamento = ?, zona_censuaria = ?, classe = ?, consistenza = ?,
                partita_tavolare = ?, corpo_tavolare = ?, porzione_materiale = ?,
                superficie_ettari = ?, superficie_are = ?, superficie_centiare = ?,
                natura = ?, note = ?
                WHERE id = ?
            ")->execute([
                $_POST['identificativo_interno'], strtoupper(trim($_POST['codice_comune'] ?? '')),
                $provincia, strtoupper(trim($_POST['sezione'] ?? '')),
                $_POST['tipo_catasto'] ?? 'U', strtoupper(trim($_POST['categoria'] ?? '')), $isPertinenza,
                strtoupper(trim($_POST['comune'] ?? '')), $_POST['foglio'] ?? '', $_POST['particella'] ?? '',
                $_POST['subalterno'] ?? '', !empty($_POST['rendita']) ? str_replace(',', '.', $_POST['rendita']) : null,
                !empty($_POST['reddito_dominicale']) ? str_replace(',', '.', $_POST['reddito_dominicale']) : null,
                $toponimo, strtoupper(trim($_POST['indirizzo'] ?? '')), $_POST['civico'] ?? '',
                !empty($_POST['in_via_di_accatastamento']) ? 1 : 0, strtoupper(trim($_POST['zona_censuaria'] ?? '')),
                $_POST['classe'] ?? '', !empty($_POST['consistenza']) ? str_replace(',', '.', $_POST['consistenza']) : null,
                $_POST['partita_tavolare'] ?? '', $_POST['corpo_tavolare'] ?? '', $_POST['porzione_materiale'] ?? '',
                !empty($_POST['superficie_ettari']) ? (int)$_POST['superficie_ettari'] : null,
                !empty($_POST['superficie_are']) ? (int)$_POST['superficie_are'] : null,
                !empty($_POST['superficie_centiare']) ? (int)$_POST['superficie_centiare'] : null,
                strtoupper($_POST['nature'] ?? ''), trim($_POST['note'] ?? ''),
                $idImmobile
            ]);

            Logger::azione('immobile_update', 'immobile', $idImmobile);
            header("Location: immobili.php?msg=Immobile+aggiornato");
            exit;
        }

        $fields = [
            $_POST['identificativo_interno'], strtoupper(trim($_POST['codice_comune'] ?? '')),
            $provincia, strtoupper(trim($_POST['sezione'] ?? '')),
            $_POST['tipo_catasto'] ?? 'U', strtoupper(trim($_POST['categoria'] ?? '')), $isPertinenza,
            strtoupper(trim($_POST['comune'] ?? '')), $_POST['foglio'] ?? '', $_POST['particella'] ?? '',
            $_POST['subalterno'] ?? '', !empty($_POST['rendita']) ? str_replace(',', '.', $_POST['rendita']) : null,
            !empty($_POST['reddito_dominicale']) ? str_replace(',', '.', $_POST['reddito_dominicale']) : null,
            $toponimo, strtoupper(trim($_POST['indirizzo'] ?? '')), $_POST['civico'] ?? '',
            !empty($_POST['in_via_di_accatastamento']) ? 1 : 0, strtoupper(trim($_POST['zona_censuaria'] ?? '')),
            $_POST['classe'] ?? '', !empty($_POST['consistenza']) ? str_replace(',', '.', $_POST['consistenza']) : null,
            $_POST['partita_tavolare'] ?? '', $_POST['corpo_tavolare'] ?? '', $_POST['porzione_materiale'] ?? '',
            !empty($_POST['superficie_ettari']) ? (int)$_POST['superficie_ettari'] : null,
            !empty($_POST['superficie_are']) ? (int)$_POST['superficie_are'] : null,
            !empty($_POST['superficie_centiare']) ? (int)$_POST['superficie_centiare'] : null,
            strtoupper($_POST['natura'] ?? ''), trim($_POST['note'] ?? ''),
        ];

        $pdo->prepare("
            INSERT INTO immobili (
                identificativo_interno, codice_comune, provincia, sezione, tipo_catasto,
                categoria, is_pertinenza, comune, foglio, particella, subalterno,
                rendita, reddito_dominicale, toponimo, indirizzo, civico,
                in_via_di_accatastamento, zona_censuaria, classe, consistenza,
                partita_tavolare, corpo_tavolare, porzione_materiale,
                superficie_ettari, superficie_are, superficie_centiare,
                natura, note
            ) VALUES (" . rtrim(str_repeat('?,', 28), ',') . ")
        ")->execute($fields);

        $newId = (int)$pdo->lastInsertId();

        // Se stiamo duplicando, copia anche i proprietari associati
        if ($duplicaSource) {
            $pdo->prepare("
                INSERT INTO proprieta_immobili (id_locatore, id_immobile, percentuale, data_inizio, data_fine, note)
                SELECT id_locatore, ?, percentuale, data_inizio, data_fine, note
                FROM proprieta_immobili WHERE id_immobile = ?
            ")->execute([$newId, (int)$duplicaSource['id']]);
        }

        Logger::azione('immobile_create', 'immobile', $newId);
        header("Location: immobili.php?msg=Immobile+salvato");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Variabili per i select
$categorie_cat = [
    'Gruppo A — Abitazioni' => ['A1' => 'A/1 — Abitazioni di tipo signorile', 'A2' => 'A/2 — Abitazioni di tipo civile', 'A3' => 'A/3 — Abitazioni di tipo economico', 'A4' => 'A/4 — Abitazioni di tipo popolare', 'A5' => 'A/5 — Abitazioni di tipo ultrapopolare', 'A6' => 'A/6 — Abitazioni di tipo rurale', 'A7' => 'A/7 — Abitazioni in villini', 'A8' => 'A/8 — Abitazioni in ville', 'A9' => 'A/9 — Castelli, palazzi di eminenti pregi', 'A10' => 'A/10 — Uffici e studi privati', 'A11' => 'A/11 — Abitazioni e alloggi tipici dei luoghi'],
    'Gruppo B — Uso collettivo' => ['B1' => 'B/1 — Collegi, convitti, educandati, ricoveri, orfanotrofi', 'B2' => 'B/2 — Case di cura ed ospedali', 'B3' => 'B/3 — Prigioni e riformatori', 'B4' => 'B/4 — Uffici pubblici', 'B5' => 'B/5 — Scuole e laboratori scientifici', 'B6' => 'B/6 — Biblioteche, pinacoteche, musei', 'B7' => 'B/7 — Cappelle ed oratori', 'B8' => 'B/8 — Magazzini sotterranei'],
    'Gruppo C — Commerciale' => ['C1' => 'C/1 — Negozi e botteghe', 'C2' => 'C/2 — Magazzini e locali di deposito', 'C3' => 'C/3 — Laboratori per arti e mestieri', 'C4' => 'C/4 — Fabbricati e locali per esercizi sportivi', 'C5' => 'C/5 — Stabilimenti balneari', 'C6' => 'C/6 — Stalle, scuderie, rimesse', 'C7' => 'C/7 — Tettoie, posti auto'],
    'Gruppo D — Destinazione speciale' => ['D1' => 'D/1 — Opifici', 'D2' => 'D/2 — Alberghi e pensioni', 'D3' => 'D/3 — Teatri, cinematografi', 'D4' => 'D/4 — Case di cura e ospedali', 'D5' => 'D/5 — Istituti di credito', 'D6' => 'D/6 — Fabbricati per esercizi sportivi', 'D7' => 'D/7 — Fabbricati per attività industriale', 'D8' => 'D/8 — Fabbricati per attività commerciale', 'D9' => 'D/9 — Edifici galleggianti', 'D10' => 'D/10 — Fabbricati per attività agricole'],
    'Gruppo E — Destinazione particolare' => ['E1' => 'E/1 — Stazioni per servizi di trasporto', 'E2' => 'E/2 — Ponti comunali', 'E3' => 'E/3 — Costruzioni per esigenze pubbliche', 'E4' => 'E/4 — Recinti chiusi', 'E5' => 'E/5 — Fortificazioni', 'E6' => 'E/6 — Fari, semafori', 'E7' => 'E/7 — Fabbricati per culti', 'E8' => 'E/8 — Cimiteri', 'E9' => 'E/9 — Edifici a destinazione particolare'],
    'Gruppo F — Non classati' => ['F1' => 'F/1 — Area urbana', 'F2' => 'F/2 — Unità collabenti', 'F3' => 'F/3 — Unità in corso di costruzione', 'F4' => 'F/4 — Unità in corso di definizione', 'F5' => 'F/5 — Lastrico solare', 'F6' => 'F/6 — Fabbricato in attesa di dichiarazione', 'F7' => 'F/7 — Infrastrutture di reti pubbliche']
];

$toponimi = ['VIA' => 'Via', 'VIALE' => 'Viale', 'PIAZZA' => 'Piazza', 'PIAZZALE' => 'Piazzale', 'CORSO' => 'Corso', 'LARGO' => 'Largo', 'VICO' => 'Vico', 'VICOLO' => 'Vicolo', 'CONTRADA' => 'Contrada', 'STRADA' => 'Strada', 'TRAVERSA' => 'Traversa', 'SALITA' => 'Salita', 'DISCESA' => 'Discesa', 'BORGO' => 'Borgo', 'LOCALITA' => 'Località', 'FRAZIONE' => 'Frazione', 'ROTONDA' => 'Rotonda'];

$nature = ['T' => 'T — Terreno', 'F' => 'F — Bene futuro', 'EU' => 'EU — Ente urbano', 'C' => 'C — Fabbricato in corso di costruzione', 'R' => 'R — Fabbricato rurale', 'LE' => 'LE — Lotto edificabile', 'PF' => 'PF — Porzione di bene futuro', 'Q' => 'Q — Porzione di fabbricato in corso di costruzione', 'P' => 'P — Porzione di immobile in corso di costruzione'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Nuovo Immobile — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; }
        input:focus, select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .duplica-banner { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 18px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9em; color: #2c5282; }
        .searchable { position: relative; }
        .searchable input[type="text"] { padding-right: 35px; cursor: text; }
        .searchable .dropdown-arrow { position: absolute; right: 12px; top: 12px; color: #a0aec0; pointer-events: none; font-size: 0.8em; }
        .searchable .options-list { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e0; border-top: none; border-radius: 0 0 8px 8px; max-height: 300px; overflow-y: auto; z-index: 1000; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-top: -8px; }
        .searchable.open .options-list { display: block; }
        .searchable .options-list .group-header { padding: 8px 12px; background: #edf2f7; color: #4a5568; font-size: 0.75em; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; }
        .searchable .options-list .option { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f7fafc; font-size: 0.9em; }
        .searchable .options-list .option:hover, .searchable .options-list .option.highlighted { background: #ebf8ff; }
        .searchable .options-list .option.selected { background: #bee3f8; font-weight: bold; }
        .searchable .options-list .option .code { display: inline-block; min-width: 50px; font-weight: bold; color: #2b6cb0; font-family: monospace; }
        .searchable .options-list mark { background: #fefcbf; color: #744210; font-weight: bold; padding: 0 2px; border-radius: 2px; }
        .searchable .clear-btn { position: absolute; right: 30px; top: 10px; background: transparent; border: none; cursor: pointer; color: #a0aec0; font-size: 1.1em; padding: 2px 6px; border-radius: 4px; display: none; }
        .searchable.has-value .clear-btn { display: block; }
        .searchable .selected-hint { font-size: 0.8em; color: #718096; margin-top: -8px; margin-bottom: 10px; display: block; min-height: 16px; }
        .searchable .selected-hint.valid { color: #38a169; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <div style="margin-bottom: 15px;">
        <a href="<?= url('immobili.php') ?>" class="btn btn-gray">← Torna agli immobili</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error">⚠ <?= Security::h($error) ?></div><?php endif; ?>

    <div class="card">
        <h2><?= $duplicaSource ? 'Duplica immobile' : 'Nuovo immobile' ?></h2>
        <?php if ($duplicaSource): ?>
            <div class="duplica-banner">
                ℹ️ Stai duplicando l'immobile <strong><?= Security::h($duplicaSource['identificativo_interno']) ?></strong>
                (<?= Security::h($duplicaSource['comune']) ?>). Subalterno e rendita sono stati azzerati: completali prima di salvare.
            </div>
        <?php endif; ?>
        <form method="POST" id="form-immobile" autocomplete="off">
            <?= Security::csrfField() ?>

            <label>Identificativo interno *</label>
            <input type="text" name="identificativo_interno" required value="<?= Security::h(v('identificativo_interno')) ?>" placeholder="es. Appartamento Roma Centro" autofocus>

            <div class="row">
                <div style="flex:2;"><label>Comune *</label><input type="text" name="comune" id="comune_field" required list="lista_comuni" value="<?= Security::h(v('comune')) ?>"><datalist id="lista_comuni"></datalist></div>
                <div><label>Cod. Catastale</label><input type="text" name="codice_comune" id="catasto_field" maxlength="5" value="<?= Security::h(v('codice_comune')) ?>"></div>
                <div style="flex:0.5;">
                    <label>Prov. *</label>
                    <?php $provSel = strtoupper(trim((string)v('provincia'))); ?>
                    <select name="provincia" id="provincia_field" required>
                        <option value="">—</option>
                        <option value="EE" <?= $provSel === 'EE' ? 'selected' : '' ?>>EE — Estero</option>
                        <?php foreach (rli_province_italiane() as $p): ?>
                            <option value="<?= $p ?>" <?= $provSel === $p ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Tipo catasto</label>
                    <select name="tipo_catasto" id="tipo_catasto_select">
                        <option value="U">Urbano</option>
                        <option value="T">Terreni</option>
                    </select>
                </div>
                <div>
                    <label>Categoria catastale</label>
                    <div class="searchable" id="searchable_categoria">
                        <input type="text" id="categoria_search" placeholder="Digita A, A/2, C/1..." autocomplete="off" value="">
                        <input type="hidden" name="categoria" id="categoria_value" value="<?= Security::h(v('categoria')) ?>">
                        <span class="dropdown-arrow">▼</span>
                        <button type="button" class="clear-btn" onclick="clearCategoria()">✕</button>
                        <div class="options-list" id="categoria_options"></div>
                    </div>
                    <span class="selected-hint" id="categoria_hint"></span>
                </div>
                <div><label>Sezione</label><input type="text" name="sezione" maxlength="3" value="<?= Security::h(v('sezione')) ?>" placeholder="es. A"></div>
            </div>

            <label style="display:flex; align-items:center; gap:6px; margin-top:4px;">
                <input type="checkbox" name="is_pertinenza" id="is_pertinenza" value="1" style="width:auto; margin:0;" <?= !empty(v('is_pertinenza')) ? 'checked' : '' ?>>
                Pertinenza (C/2, C/6, C/7 o unità accessoria)
            </label>

            <div class="row">
                <div><label>Foglio</label><input type="text" name="foglio" maxlength="4" value="<?= Security::h(v('foglio')) ?>"></div>
                <div><label>Particella</label><input type="text" name="particella" maxlength="5" value="<?= Security::h(v('particella')) ?>"></div>
                <div><label>Subalterno</label><input type="text" name="subalterno" maxlength="4" value="<?= Security::h(v('subalterno')) ?>"></div>
            </div>

            <div class="row">
                <div><label>Rendita €</label><input type="text" name="rendita" value="<?= Security::h(v('rendita')) ?>"></div>
                <div><label>Zona censuaria</label><input type="text" name="zona_censuaria" maxlength="3" value="<?= Security::h(v('zona_censuaria')) ?>"></div>
                <div><label>Classe</label><input type="text" name="classe" maxlength="2" value="<?= Security::h(v('classe')) ?>"></div>
                <div><label>Consistenza</label><input type="text" name="consistenza" value="<?= Security::h(v('consistenza')) ?>"></div>
            </div>

            <div class="row">
                <div style="flex:1;">
                    <label>Toponimo *</label>
                    <div class="searchable" id="searchable_toponimo">
                        <input type="text" id="toponimo_search" placeholder="Digita V, VIA, PIAZZA..." autocomplete="off" value="">
                        <input type="hidden" name="toponimo" id="toponimo_value" value="<?= Security::h(v('toponimo')) ?>" required>
                        <span class="dropdown-arrow">▼</span>
                        <button type="button" class="clear-btn" onclick="clearToponimo()">✕</button>
                        <div class="options-list" id="toponimo_options"></div>
                    </div>
                    <span class="selected-hint" id="toponimo_hint"></span>
                </div>
                <div style="flex:2;"><label>Indirizzo</label><input type="text" name="indirizzo" value="<?= Security::h(v('indirizzo')) ?>"></div>
                <div style="flex:0.6;"><label>Civico</label><input type="text" name="civico" maxlength="10" value="<?= Security::h(v('civico')) ?>"></div>
            </div>

            <label style="display:flex; align-items:center; gap:6px;">
                <input type="checkbox" name="in_via_di_accatastamento" value="1" style="width:auto; margin:0;">
                In via di accatastamento
            </label>

            <details style="margin-top:10px;">
                <summary style="cursor:pointer; color:#4299e1; font-weight:600;">Dati catasto tavolare / terreni (opzionale)</summary>
                <div style="margin-top:10px; padding:12px; background:#f7fafc; border-radius:8px;">
                    <div class="row">
                        <div><label>Partita tavolare</label><input type="text" name="partita_tavolare" maxlength="7"></div>
                        <div><label>Corpo tavolare</label><input type="text" name="corpo_tavolare" maxlength="3"></div>
                        <div><label>Porzione materiale</label><input type="text" name="porzione_materiale" maxlength="4"></div>
                    </div>
                    <div class="row">
                        <div><label>Ettari</label><input type="number" name="superficie_ettari"></div>
                        <div><label>Are</label><input type="number" name="superficie_are"></div>
                        <div><label>Centiare</label><input type="number" name="superficie_centiare"></div>
                    </div>
                    <div class="row">
                        <div>
                            <label>Natura</label>
                            <select name="natura">
                                <option value="">—</option>
                                <?php foreach ($nature as $code => $label): ?><option value="<?= $code ?>"><?= Security::h($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Reddito dominicale €</label><input type="text" name="reddito_dominicale"></div>
                    </div>
                </div>
            </details>

            <label style="margin-top:10px;">Note</label>
            <textarea name="note" rows="2"><?= Security::h(v('note')) ?></textarea>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" name="save_immobile" class="btn btn-green"><?= $editMode ? '💾 Salva Modifiche' : '💾 Salva Immobile' ?></button>
                <a href="<?= url('immobili.php') ?>" class="btn btn-gray">Annulla</a>
            </div>
        </form>
    </div>
</div>

<script>
const CATEGORIE = [
<?php
$allCats = [];
foreach ($categorie_cat as $group => $cats) {
    foreach ($cats as $code => $label) { $allCats[] = "  { code: '" . addslashes($code) . "', label: '" . addslashes($label) . "', group: '" . addslashes($group) . "' }"; }
}
echo implode(",\n", $allCats) . "\n";
?>
];
const TOPONIMI = [
<?php
$allTop = [];
foreach ($toponimi as $code => $label) { $allTop[] = "  { code: '" . addslashes($code) . "', label: '" . addslashes($label) . "' }"; }
echo implode(",\n", $allTop) . "\n";
?>
];
class SearchableSelect {
    constructor(wrapperId, options, opts = {}) {
        this.wrapper = document.getElementById(wrapperId);
        this.searchInput = this.wrapper.querySelector('input[type="text"]');
        this.hiddenInput = this.wrapper.querySelector('input[type="hidden"]');
        this.optionsList = this.wrapper.querySelector('.options-list');
        this.hintEl = this.wrapper.parentElement.querySelector('.selected-hint');
        this.options = options; this.filtered = [...options]; this.highlightedIndex = -1;
        this.hasGroups = opts.hasGroups || false; this.allowCustom = opts.allowCustom || false; this.customHint = opts.customHint || '';
        this.init();
    }
    init() {
        this.searchInput.addEventListener('focus', () => this.open());
        this.searchInput.addEventListener('click', () => this.open());
        this.searchInput.addEventListener('input', () => { this.filter(); this.open(); });
        this.searchInput.addEventListener('keydown', (e) => this.handleKey(e));
        document.addEventListener('click', (e) => { if (!this.wrapper.contains(e.target)) this.close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.close(); });
        this.render();
    }
    filter() {
        const q = this.searchInput.value.toUpperCase().trim().replace(/[/\s-]/g, '');
        if (!q) this.filtered = [...this.options];
        else this.filtered = this.options.filter(o => {
            const code = o.code.toUpperCase().replace(/[/\s-]/g, '');
            const label = o.label.toUpperCase();
            return code.startsWith(q) || code.includes(q) || label.includes(q);
        });
        this.highlightedIndex = -1; this.render();
    }
    render() {
        this.optionsList.innerHTML = '';
        if (this.filtered.length === 0) {
            const div = document.createElement('div'); div.className = 'no-results';
            if (this.allowCustom && this.searchInput.value.trim()) div.innerHTML = `Nessun risultato. <br>Premi <strong>Invio</strong> per usare "<strong>${this.escape(this.searchInput.value.trim())}</strong>"`;
            else div.textContent = 'Nessun risultato';
            this.optionsList.appendChild(div); return;
        }
        let currentGroup = null; const query = this.searchInput.value.trim();
        this.filtered.forEach((opt, idx) => {
            if (this.hasGroups && opt.group && opt.group !== currentGroup) {
                currentGroup = opt.group; const header = document.createElement('div');
                header.className = 'group-header'; header.textContent = currentGroup; this.optionsList.appendChild(header);
            }
            const div = document.createElement('div'); div.className = 'option';
            if (this.hiddenInput.value === opt.code) div.classList.add('selected');
            if (idx === this.highlightedIndex) div.classList.add('highlighted');
            let codeHtml = this.escape(opt.code); let labelHtml = this.escape(opt.label);
            if (query) { codeHtml = this.highlight(opt.code, query); labelHtml = this.highlight(opt.label, query); }
            div.innerHTML = `<span class="code">${codeHtml}</span> <span class="label">${labelHtml}</span>`;
            div.addEventListener('click', (e) => { e.stopPropagation(); this.select(opt); });
            div.addEventListener('mouseenter', () => { this.highlightedIndex = idx; this.updateHighlight(); });
            this.optionsList.appendChild(div);
        });
    }
    updateHighlight() {
        const items = this.optionsList.querySelectorAll('.option');
        items.forEach((el, i) => el.classList.toggle('highlighted', i === this.highlightedIndex));
        if (this.highlightedIndex >= 0 && items[this.highlightedIndex]) items[this.highlightedIndex].scrollIntoView({ block: 'nearest' });
    }
    handleKey(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); this.highlightedIndex = Math.min(this.highlightedIndex + 1, this.filtered.length - 1); this.updateHighlight(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); this.highlightedIndex = Math.max(this.highlightedIndex - 1, 0); this.updateHighlight(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (this.highlightedIndex >= 0 && this.filtered[this.highlightedIndex]) this.select(this.filtered[this.highlightedIndex]);
            else if (this.allowCustom && this.searchInput.value.trim()) {
                const custom = this.searchInput.value.trim().toUpperCase();
                this.hiddenInput.value = custom; this.searchInput.value = custom;
                this.hintEl.textContent = 'Valore personalizzato: ' + custom; this.hintEl.className = 'selected-hint'; this.close();
            } else if (this.filtered.length === 1) this.select(this.filtered[0]);
        } else if (e.key === 'Tab') this.close();
    }
    select(opt) {
        this.hiddenInput.value = opt.code; this.searchInput.value = opt.code;
        this.hintEl.textContent = '✓ ' + opt.label; this.hintEl.className = 'selected-hint valid';
        this.wrapper.classList.add('has-value'); this.close();
        this.hiddenInput.dispatchEvent(new Event('change'));
    }
    open() { document.querySelectorAll('.searchable.open').forEach(el => { if (el !== this.wrapper) el.classList.remove('open'); }); this.wrapper.classList.add('open'); this.render(); }
    close() { this.wrapper.classList.remove('open'); }
    escape(str) { const div = document.createElement('div'); div.textContent = str; return div.innerHTML; }
    highlight(text, query) {
        if (!query) return this.escape(text);
        const safeText = this.escape(text); const safeQuery = this.escape(query);
        const regex = new RegExp('(' + safeQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return safeText.replace(regex, '<mark>$1</mark>');
    }
}
const searchCategoria = new SearchableSelect('searchable_categoria', CATEGORIE, { hasGroups: true, allowCustom: false });
const searchToponimo = new SearchableSelect('searchable_toponimo', TOPONIMI, { hasGroups: false, allowCustom: true, customHint: '(sarà salvato così com\'è)' });

// Precompila il campo di ricerca del toponimo se c'è un valore salvato
document.addEventListener('DOMContentLoaded', function() {
    const toponimoValue = document.getElementById('toponimo_value');
    const toponimoSearch = document.getElementById('toponimo_search');
    const toponimoHint = document.getElementById('toponimo_hint');
    
    if (toponimoValue && toponimoValue.value && toponimoSearch) {
        toponimoSearch.value = toponimoValue.value;
        if (toponimoHint) {
            // Cerca il label corrispondente
            const found = TOPONIMI.find(t => t.code === toponimoValue.value);
            toponimoHint.textContent = found ? '✓ ' + found.label : 'Valore personalizzato: ' + toponimoValue.value;
            toponimoHint.className = found ? 'selected-hint valid' : 'selected-hint';
        }
        document.getElementById('searchable_toponimo').classList.add('has-value');
    }

    // Stessa cosa per la categoria
    const categoriaValue = document.getElementById('categoria_value');
    const categoriaSearch = document.getElementById('categoria_search');
    const categoriaHint = document.getElementById('categoria_hint');
    
    if (categoriaValue && categoriaValue.value && categoriaSearch) {
        categoriaSearch.value = categoriaValue.value;
        if (categoriaHint) {
            const found = CATEGORIE.find(c => c.code === categoriaValue.value);
            categoriaHint.textContent = found ? '✓ ' + found.label : 'Valore personalizzato: ' + categoriaValue.value;
            categoriaHint.className = found ? 'selected-hint valid' : 'selected-hint';
        }
        document.getElementById('searchable_categoria').classList.add('has-value');
    }
});

function clearCategoria() { document.getElementById('categoria_value').value = ''; document.getElementById('categoria_search').value = ''; document.getElementById('categoria_hint').textContent = ''; document.getElementById('searchable_categoria').classList.remove('has-value'); document.getElementById('categoria_search').focus(); }
function clearToponimo() { document.getElementById('toponimo_value').value = ''; document.getElementById('toponimo_search').value = ''; document.getElementById('toponimo_hint').textContent = ''; document.getElementById('searchable_toponimo').classList.remove('has-value'); document.getElementById('toponimo_search').focus(); }

// Lookup locale comuni (tabella comuni) con fallback al JSON esterno se la tabella è vuota
let dbComuni = [];
const comuneField = document.getElementById('comune_field');
if (comuneField) {
    let comuniTimer = null;
    comuneField.addEventListener('input', function() {
        const val = this.value.toUpperCase().trim();
        if (val.length < 2) return;
        clearTimeout(comuniTimer);
        comuniTimer = setTimeout(function() {
            fetch('ajax_comuni.php?q=' + encodeURIComponent(val), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(function(data) {
                    if (!data.ok) return;
                    if (data.fallback) {
                        if (dbComuni.length === 0) {
                            fetch('https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json')
                                .then(r => r.json())
                                .then(function(d) { dbComuni = d; applyComuni(val); })
                                .catch(function() {});
                        } else {
                            applyComuni(val);
                        }
                        return;
                    }
                    const dl = document.getElementById('lista_comuni');
                    dl.innerHTML = '';
                    (data.results || []).forEach(function(m) {
                        const o = document.createElement('option');
                        o.value = m.nome.toUpperCase();
                        o.label = m.provincia;
                        dl.appendChild(o);
                    });
                    const ex = (data.results || []).find(function(m) { return m.nome.toUpperCase() === val; });
                    if (ex) {
                        document.getElementById('provincia_field').value = ex.provincia;
                        document.getElementById('catasto_field').value = ex.codice_belfiore;
                    }
                })
                .catch(function() {});
        }, 250);
    });
}
function applyComuni(val) {
    const matches = dbComuni.filter(c => c.nome.toUpperCase().startsWith(val)).slice(0, 15);
    const dl = document.getElementById('lista_comuni'); dl.innerHTML = '';
    matches.forEach(m => { const o = document.createElement('option'); o.value = m.nome.toUpperCase(); dl.appendChild(o); });
    const ex = dbComuni.find(c => c.nome.toUpperCase() === val);
    if (ex) { document.getElementById('provincia_field').value = ex.sigla; document.getElementById('catasto_field').value = ex.codiceCatastale; }
}

// Auto-suggerisci pertinenza per categorie C/2, C/6, C/7
const categoriaValueEl = document.getElementById('categoria_value');
if (categoriaValueEl) {
    categoriaValueEl.addEventListener('change', function() {
        const cat = (this.value || '').toUpperCase().replace(/[\/\s-]/g, '');
        if (['C2', 'C6', 'C7'].includes(cat)) {
            const cb = document.getElementById('is_pertinenza');
            if (cb) cb.checked = true;
        }
    });
}
document.getElementById('form-immobile').addEventListener('submit', function(e) {
    const toponimoValue = document.getElementById('toponimo_value').value.trim();
    if (!toponimoValue) { e.preventDefault(); alert('⚠ Il campo Toponimo è obbligatorio.\n\nSeleziona VIA, PIAZZA, CORSO, ecc.'); document.getElementById('toponimo_search').focus(); return false; }

    const provField = document.getElementById('provincia_field');
    const provVal = provField ? provField.value.trim().toUpperCase() : '';
    if (!/^[A-Z]{2}$/.test(provVal)) {
        e.preventDefault();
        alert('⚠ Il campo Provincia è obbligatorio: inserisci una sigla di 2 lettere (es. RM) oppure EE per immobili esteri.');
        if (provField) provField.focus();
        return false;
    }
});
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
