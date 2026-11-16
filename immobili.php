<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';
require_once 'lib/NotificaRepository.php';
$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

// CSRF check su tutte le POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

// --- Eliminazione (via POST) ---
if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $inUso = $pdo->prepare("SELECT COUNT(*) FROM contratto_immobili WHERE id_immobile = ?");
    $inUso->execute([$id]);
    if ($inUso->fetchColumn() > 0) {
        header("Location: immobili.php?error=Immobile+usato+in+contratti+attivi");
        exit;
    }
    $pdo->prepare("DELETE FROM immobili WHERE id = ?")->execute([$id]);
    Logger::azione('immobile_delete', 'immobile', $id);
    header("Location: immobili.php?msg=Immobile+eliminato");
    exit;
}

$edit = null;
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    $_GET['edit'] = null; // Forza modalità nuovo
}
if (isset($_GET['edit'])) {
    // Reindirizza alla pagina di modifica dedicata
    header("Location: immobile_new.php?edit=" . (int)$_GET['edit']);
    exit;
}

// Visibilità contratti (per lo stato di occupazione)
$visCond = '';
$visParams = [];
if (!$auth->isAdmin()) {
    $visCond = " AND (c.id_utente_creatore = :uid OR EXISTS (
        SELECT 1 FROM contratti_condivisioni cc
        WHERE cc.id_contratto = c.id AND cc.id_utente = :uid2
    ))";
    $visParams = [':uid' => $auth->id(), ':uid2' => $auth->id()];
}

$stmtImm = $pdo->prepare("
    SELECT i.*,
        (SELECT c.id FROM contratto_immobili ci
         JOIN contratti c ON c.id = ci.id_contratto
         WHERE ci.id_immobile = i.id AND c.stato IN ('ATTIVO','PROROGATO') $visCond
         ORDER BY COALESCE(c.data_fine_proroga, c.durata_al) DESC LIMIT 1) AS contratto_attivo_id,
        (SELECT COALESCE(c.data_fine_proroga, c.durata_al) FROM contratto_immobili ci
         JOIN contratti c ON c.id = ci.id_contratto
         WHERE ci.id_immobile = i.id AND c.stato IN ('ATTIVO','PROROGATO') $visCond
         ORDER BY COALESCE(c.data_fine_proroga, c.durata_al) DESC LIMIT 1) AS contratto_attivo_scadenza,
        (SELECT GROUP_CONCAT(CONCAT(s.cognome, ' ', COALESCE(s.nome,''), ' (', FORMAT(p.percentuale, 0), '%)') ORDER BY p.percentuale DESC SEPARATOR ', ')
         FROM proprieta_immobili p
         JOIN soggetti s ON s.id = p.id_locatore
         WHERE p.id_immobile = i.id) AS proprietari
    FROM immobili i
    ORDER BY i.identificativo_interno
");
$stmtImm->execute($visParams);
$immobili = $stmtImm->fetchAll();

$categorie_cat = [
    'Gruppo A — Abitazioni' => [
        'A1' => 'A/1 — Abitazioni di tipo signorile', 'A2' => 'A/2 — Abitazioni di tipo civile',
        'A3' => 'A/3 — Abitazioni di tipo economico', 'A4' => 'A/4 — Abitazioni di tipo popolare',
        'A5' => 'A/5 — Abitazioni di tipo ultrapopolare', 'A6' => 'A/6 — Abitazioni di tipo rurale',
        'A7' => 'A/7 — Abitazioni in villini', 'A8' => 'A/8 — Abitazioni in ville',
        'A9' => 'A/9 — Castelli, palazzi di eminenti pregi', 'A10' => 'A/10 — Uffici e studi privati',
        'A11' => 'A/11 — Abitazioni e alloggi tipici dei luoghi',
    ],
    'Gruppo B — Uso collettivo' => [
        'B1' => 'B/1 — Collegi, convitti, educandati, ricoveri, orfanotrofi',
        'B2' => 'B/2 — Case di cura ed ospedali (senza fine di lucro)',
        'B3' => 'B/3 — Prigioni e riformatori', 'B4' => 'B/4 — Uffici pubblici',
        'B5' => 'B/5 — Scuole e laboratori scientifici', 'B6' => 'B/6 — Biblioteche, pinacoteche, musei, gallerie',
        'B7' => 'B/7 — Cappelle ed oratori non destinati al pubblico', 'B8' => 'B/8 — Magazzini sotterranei per depositi di derrate',
    ],
    'Gruppo C — Commerciale/Varia' => [
        'C1' => 'C/1 — Negozi e botteghe', 'C2' => 'C/2 — Magazzini e locali di deposito',
        'C3' => 'C/3 — Laboratori per arti e mestieri', 'C4' => 'C/4 — Fabbricati e locali per esercizi sportivi',
        'C5' => 'C/5 — Stabilimenti balneari e di acque curative', 'C6' => 'C/6 — Stalle, scuderie, rimesse, autorimesse',
        'C7' => 'C/7 — Tettoie, posti auto su aree private, posti auto coperti',
    ],
    'Gruppo D — Destinazione speciale' => [
        'D1' => 'D/1 — Opifici', 'D2' => 'D/2 — Alberghi e pensioni', 'D3' => 'D/3 — Teatri, cinematografi, sale spettacoli',
        'D4' => 'D/4 — Case di cura e ospedali', 'D5' => 'D/5 — Istituti di credito, cambio, assicurazione',
        'D6' => 'D/6 — Fabbricati per esercizi sportivi', 'D7' => 'D/7 — Fabbricati per attività industriale',
        'D8' => 'D/8 — Fabbricati per attività commerciale', 'D9' => 'D/9 — Edifici galleggianti, ponti privati',
        'D10' => 'D/10 — Fabbricati per attività agricole',
    ],
    'Gruppo E — Destinazione particolare' => [
        'E1' => 'E/1 — Stazioni per servizi di trasporto', 'E2' => 'E/2 — Ponti comunali e provinciali soggetti a pedaggio',
        'E3' => 'E/3 — Costruzioni e fabbricati per esigenze pubbliche',
        'E4' => 'E/4 — Recinti chiusi per mercati, fiere, posteggio bestiame', 'E5' => 'E/5 — Fabbricati costituenti fortificazioni',
        'E6' => 'E/6 — Fari, semafori, torri per orologio comunale', 'E7' => 'E/7 — Fabbricati per l\'esercizio pubblico dei culti',
        'E8' => 'E/8 — Fabbricati e costruzioni nei cimiteri', 'E9' => 'E/9 — Edifici a destinazione particolare',
    ],
    'Gruppo F — Non classati' => [
        'F1' => 'F/1 — Area urbana', 'F2' => 'F/2 — Unità collabenti', 'F3' => 'F/3 — Unità in corso di costruzione',
        'F4' => 'F/4 — Unità in corso di definizione', 'F5' => 'F/5 — Lastrico solare',
        'F6' => 'F/6 — Fabbricato in attesa di dichiarazione', 'F7' => 'F/7 — Infrastrutture di reti pubbliche di comunicazione',
    ],
];

$toponimi = [
    'VIA' => 'Via', 'VIALE' => 'Viale', 'PIAZZA' => 'Piazza', 'PIAZZALE' => 'Piazzale',
    'CORSO' => 'Corso', 'LARGO' => 'Largo', 'VICO' => 'Vico', 'VICOLO' => 'Vicolo',
    'CONTRADA' => 'Contrada', 'STRADA' => 'Strada', 'TRAVERSA' => 'Traversa',
    'SALITA' => 'Salita', 'DISCESA' => 'Discesa', 'BORGO' => 'Borgo',
    'LOCALITA' => 'Località', 'FRAZIONE' => 'Frazione', 'ROTONDA' => 'Rotonda',
];

$nature = [
    'T' => 'T — Terreno', 'F' => 'F — Bene futuro', 'EU' => 'EU — Ente urbano',
    'C' => 'C — Fabbricato in corso di costruzione', 'R' => 'R — Fabbricato rurale',
    'LE' => 'LE — Lotto edificabile', 'PF' => 'PF — Porzione di bene futuro',
    'Q' => 'Q — Porzione di fabbricato in corso di costruzione', 'P' => 'P — Porzione di immobile in corso di costruzione',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Immobili — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1200px; margin: 0 auto; padding: 25px; }
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
        .btn-red { background: #f56565; color: #fff; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #edf2f7; }
        th { color: #718096; font-size: 0.8em; text-transform: uppercase; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .badge-libero { background: #c6f6d5; color: #22543d; }
        .badge-locato { background: #bee3f8; color: #2c5282; text-decoration: none; }

        .searchable { position: relative; }
        .searchable input[type="text"] { padding-right: 35px; cursor: text; }
        .searchable .dropdown-arrow { position: absolute; right: 12px; top: 12px; color: #a0aec0; pointer-events: none; font-size: 0.8em; }
        .searchable .options-list {
            position: absolute; top: 100%; left: 0; right: 0; background: #fff;
            border: 1px solid #cbd5e0; border-top: none; border-radius: 0 0 8px 8px;
            max-height: 300px; overflow-y: auto; z-index: 1000; display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-top: -8px;
        }
        .searchable.open .options-list { display: block; }
        .searchable .options-list .group-header {
            padding: 8px 12px; background: #edf2f7; color: #4a5568;
            font-size: 0.75em; font-weight: bold; text-transform: uppercase;
            letter-spacing: 0.5px; position: sticky; top: 0;
        }
        .searchable .options-list .option {
            padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f7fafc;
            font-size: 0.9em; transition: background 0.1s;
        }
        .searchable .options-list .option:hover, .searchable .options-list .option.highlighted { background: #ebf8ff; }
        .searchable .options-list .option.selected { background: #bee3f8; font-weight: bold; }
        .searchable .options-list .option .code { display: inline-block; min-width: 50px; font-weight: bold; color: #2b6cb0; font-family: monospace; }
        .searchable .options-list .option .label { color: #4a5568; }
        .searchable .options-list .no-results { padding: 15px 12px; color: #a0aec0; text-align: center; font-style: italic; font-size: 0.9em; }
        .searchable .options-list mark { background: #fefcbf; color: #744210; font-weight: bold; padding: 0 2px; border-radius: 2px; }
        .searchable .clear-btn {
            position: absolute; right: 30px; top: 10px; background: transparent;
            border: none; cursor: pointer; color: #a0aec0; font-size: 1.1em;
            padding: 2px 6px; border-radius: 4px; display: none;
        }
        .searchable.has-value .clear-btn { display: block; }
        .searchable .clear-btn:hover { color: #e53e3e; background: #fed7d7; }
        .searchable .selected-hint { font-size: 0.8em; color: #718096; margin-top: -8px; margin-bottom: 10px; display: block; min-height: 16px; }
        .searchable .selected-hint.valid { color: #38a169; }
        .searchable .selected-hint.invalid { color: #e53e3e; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if (isset($_GET['error'])) echo "<div class='alert alert-error'>⚠ " . Security::h($_GET['error']) . "</div>"; ?>
    <?php if (isset($error)) echo "<div class='alert alert-error'>⚠ " . Security::h($error) . "</div>"; ?>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2 style="margin:0;">Immobili censiti (<?php echo count($immobili); ?>)</h2>
            <a href="<?= url('immobile_new.php') ?>" class="btn btn-green">➕ Nuovo Immobile</a>
        </div>
        <?php if (empty($immobili)): ?>
            <p style="color:#a0aec0;">Nessun immobile.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>ID</th><th>Indirizzo</th><th>Comune</th><th>Cat.</th><th>Proprietari</th><th>Stato</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($immobili as $i): ?>
                        <tr>
                            <td><strong><?php echo Security::h($i['identificativo_interno']); ?></strong></td>
                            <td><?php echo Security::h(trim(($i['toponimo'] ?? '') . ' ' . ($i['indirizzo'] ?? '') . ' ' . ($i['civico'] ?? ''))); ?></td>
                            <td><?php echo Security::h($i['comune']); ?> (<?php echo Security::h($i['provincia']); ?>)</td>
                            <td><?php echo Security::h($i['categoria'] ?: $i['tipo_catasto']); ?></td>
                            <td style="font-size:0.85em;"><?php echo $i['proprietari'] ? Security::h($i['proprietari']) : '—'; ?></td>
                            <td>
                                <?php if (!empty($i['contratto_attivo_id'])): ?>
                                    <a href="contract.php?id=<?= (int)$i['contratto_attivo_id'] ?>" class="badge badge-locato"
                                       title="Contratto attivo/prorogato">
                                        LOCATO<?= $i['contratto_attivo_scadenza'] ? ' · ' . date('d/m/Y', strtotime($i['contratto_attivo_scadenza'])) : '' ?>
                                    </a>
                                <?php else: ?>
                                    <span class="badge badge-libero">LIBERO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?edit=<?php echo $i['id']; ?>" class="btn btn-primary" style="padding:5px 12px; font-size:0.85em;">✎</a>
                                <a href="immobile_new.php?duplica=<?php echo $i['id']; ?>" class="btn btn-gray" style="padding:5px 12px; font-size:0.85em;" title="Duplica immobile">⧉</a>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="delete_id" value="<?php echo $i['id']; ?>">
                                    <button class="btn btn-red" style="padding:5px 12px; font-size:0.85em;">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// === SEARCHABLE COMBOBOX (Categoria + Toponimo) ===
const CATEGORIE = [
<?php
$allCats = [];
foreach ($categorie_cat as $group => $cats) {
    foreach ($cats as $code => $label) {
        $allCats[] = "  { code: '" . addslashes($code) . "', label: '" . addslashes($label) . "', group: '" . addslashes($group) . "' }";
    }
}
echo implode(",\n", $allCats) . "\n";
?>
];

const TOPONIMI = [
<?php
$allTop = [];
foreach ($toponimi as $code => $label) {
    $allTop[] = "  { code: '" . addslashes($code) . "', label: '" . addslashes($label) . "' }";
}
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
        this.options = options;
        this.filtered = [...options];
        this.highlightedIndex = -1;
        this.hasGroups = opts.hasGroups || false;
        this.allowCustom = opts.allowCustom || false;
        this.customHint = opts.customHint || '';
        this.init();
    }
    init() {
        this.searchInput.addEventListener('focus', () => this.open());
        this.searchInput.addEventListener('click', () => this.open());
        this.searchInput.addEventListener('input', () => { this.filter(); this.open(); });
        this.searchInput.addEventListener('keydown', (e) => this.handleKey(e));
        document.addEventListener('click', (e) => { if (!this.wrapper.contains(e.target)) this.close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.close(); });
        this.showCurrentSelection();
        this.render();
    }
    showCurrentSelection() {
        const currentVal = this.hiddenInput.value;
        if (!currentVal) return;
        const found = this.options.find(o => o.code === currentVal);
        if (found) {
            this.hintEl.textContent = '✓ ' + found.label;
            this.hintEl.className = 'selected-hint valid';
        } else if (this.allowCustom) {
            this.hintEl.textContent = 'Valore personalizzato: ' + currentVal;
            this.hintEl.className = 'selected-hint';
        } else {
            this.hintEl.textContent = '⚠ Valore non standard: ' + currentVal;
            this.hintEl.className = 'selected-hint invalid';
        }
    }
    filter() {
        const q = this.searchInput.value.toUpperCase().trim().replace(/[/\s-]/g, '');
        if (!q) this.filtered = [...this.options];
        else this.filtered = this.options.filter(o => {
            const code = o.code.toUpperCase().replace(/[/\s-]/g, '');
            const label = o.label.toUpperCase();
            return code.startsWith(q) || code.includes(q) || label.includes(q);
        });
        this.highlightedIndex = -1;
        this.render();
    }
    render() {
        this.optionsList.innerHTML = '';
        if (this.filtered.length === 0) {
            const div = document.createElement('div');
            div.className = 'no-results';
            if (this.allowCustom && this.searchInput.value.trim()) {
                div.innerHTML = `Nessun risultato. <br>Premi <strong>Invio</strong> per usare "<strong>${this.escape(this.searchInput.value.trim())}</strong>" ${this.customHint}`;
            } else div.textContent = 'Nessun risultato';
            this.optionsList.appendChild(div);
            return;
        }
        let currentGroup = null;
        const query = this.searchInput.value.trim();
        this.filtered.forEach((opt, idx) => {
            if (this.hasGroups && opt.group && opt.group !== currentGroup) {
                currentGroup = opt.group;
                const header = document.createElement('div');
                header.className = 'group-header';
                header.textContent = currentGroup;
                this.optionsList.appendChild(header);
            }
            const div = document.createElement('div');
            div.className = 'option';
            if (this.hiddenInput.value === opt.code) div.classList.add('selected');
            if (idx === this.highlightedIndex) div.classList.add('highlighted');
            let codeHtml = this.escape(opt.code);
            let labelHtml = this.escape(opt.label);
            if (query) {
                codeHtml = this.highlight(opt.code, query);
                labelHtml = this.highlight(opt.label, query);
            }
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
                this.hiddenInput.value = custom;
                this.searchInput.value = custom;
                this.hintEl.textContent = 'Valore personalizzato: ' + custom;
                this.hintEl.className = 'selected-hint';
                this.close();
            } else if (this.filtered.length === 1) this.select(this.filtered[0]);
        } else if (e.key === 'Tab') this.close();
    }
    select(opt) {
        this.hiddenInput.value = opt.code;
        this.searchInput.value = opt.code;
        this.hintEl.textContent = '✓ ' + opt.label;
        this.hintEl.className = 'selected-hint valid';
        this.wrapper.classList.add('has-value');
        this.close();
        this.hiddenInput.dispatchEvent(new Event('change'));
    }
    open() {
        document.querySelectorAll('.searchable.open').forEach(el => { if (el !== this.wrapper) el.classList.remove('open'); });
        this.wrapper.classList.add('open');
        this.render();
    }
    close() { this.wrapper.classList.remove('open'); }
    escape(str) { const div = document.createElement('div'); div.textContent = str; return div.innerHTML; }
    highlight(text, query) {
        if (!query) return this.escape(text);
        const safeText = this.escape(text);
        const safeQuery = this.escape(query);
        const regex = new RegExp('(' + safeQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return safeText.replace(regex, '<mark>$1</mark>');
    }
}

let searchCategoria = null;
if (document.getElementById('searchable_categoria')) {
    searchCategoria = new SearchableSelect('searchable_categoria', CATEGORIE, { hasGroups: true, allowCustom: false });
}
let searchToponimo = null;
if (document.getElementById('searchable_toponimo')) {
    searchToponimo = new SearchableSelect('searchable_toponimo', TOPONIMI, { hasGroups: false, allowCustom: true, customHint: '(sarà salvato così com\'è)' });
}

function clearCategoria() {
    const el = document.getElementById('categoria_value');
    if (!el) return;
    el.value = '';
    document.getElementById('categoria_search').value = '';
    document.getElementById('categoria_hint').textContent = '';
    document.getElementById('searchable_categoria').classList.remove('has-value');
    document.getElementById('categoria_search').focus();
}
function clearToponimo() {
    const el = document.getElementById('toponimo_value');
    if (!el) return;
    el.value = '';
    document.getElementById('toponimo_search').value = '';
    document.getElementById('toponimo_hint').textContent = '';
    document.getElementById('searchable_toponimo').classList.remove('has-value');
    document.getElementById('toponimo_search').focus();
}

// AUTOCOMPLETAMENTO COMUNE
let dbComuni = [];
fetch('https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json')
    .then(r => r.json())
    .then(d => { dbComuni = d; })
    .catch(() => {});

const comuneField = document.getElementById('comune_field');
if (comuneField) {
    comuneField.addEventListener('input', function() {
        if (dbComuni.length === 0) return;
        const val = this.value.toUpperCase().trim();
        if (val.length < 2) return;
        const matches = dbComuni.filter(c => c.nome.toUpperCase().startsWith(val)).slice(0, 15);
        const dl = document.getElementById('lista_comuni');
        dl.innerHTML = '';
        matches.forEach(m => {
            const o = document.createElement('option');
            o.value = m.nome.toUpperCase();
            dl.appendChild(o);
        });
        const ex = dbComuni.find(c => c.nome.toUpperCase() === val);
        if (ex) {
            document.getElementById('provincia_field').value = ex.sigla;
            document.getElementById('catasto_field').value = ex.codiceCatastale;
        }
    });
}

// === VALIDAZIONE TOPONIMO OBBLIGATORIO ===
const formImmobile = document.getElementById('form-immobile');
if (formImmobile) {
    formImmobile.addEventListener('submit', function(e) {
        const toponimoValue = document.getElementById('toponimo_value');
        if (!toponimoValue) return; // Se il campo non esiste (pagina di modifica), salta
        if (!toponimoValue.value.trim()) {
            e.preventDefault();
            alert('⚠ Il campo Toponimo è obbligatorio.\n\nSeleziona VIA, PIAZZA, CORSO, ecc.');
            const searchEl = document.getElementById('toponimo_search');
            if (searchEl) searchEl.focus();
            return false;
        }
    });
}

console.log('immobili.php: autocompletamento categoria e toponimo attivo');

// Se l'URL ha ?action=new, scorri al form e metti il focus
if (window.location.search.includes('action=new')) {
    const form = document.getElementById('form-immobile');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const firstInput = form.querySelector('input[name="identificativo_interno"]');
        if (firstInput) firstInput.focus();
    }
}
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
