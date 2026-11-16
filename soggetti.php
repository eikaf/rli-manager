<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

// CSRF check su tutte le richieste POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

// --- Eliminazione (ora via POST) ---
if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];

    $inUso = $pdo->prepare("SELECT COUNT(*) FROM contratto_locatori WHERE id_locatore = ? UNION ALL SELECT COUNT(*) FROM contratto_conduttori WHERE id_conduttore = ?");
    $inUso->execute([$id, $id]);
    $countContratti = array_sum($inUso->fetchAll(PDO::FETCH_COLUMN));

    $prop = $pdo->prepare("SELECT COUNT(*) FROM proprieta_immobili WHERE id_locatore = ?");
    $prop->execute([$id]);
    $countProp = (int)$prop->fetchColumn();

    $rapp = $pdo->prepare("SELECT COUNT(*) FROM soggetti WHERE id_rappresentante = ?");
    $rapp->execute([$id]);
    $countRapp = (int)$rapp->fetchColumn();

    $motivi = [];
    if ($countContratti > 0) $motivi[] = "$countContratti contratto/i";
    if ($countProp > 0)      $motivi[] = "$countProp proprietà";
    if ($countRapp > 0)      $motivi[] = "$countRapp società rappresentate";

    if ($motivi) {
        header("Location: soggetti.php?error=" . urlencode("Impossibile eliminare il soggetto: collegato a " . implode(', ', $motivi)));
        exit;
    }

    $pdo->prepare("DELETE FROM soggetti WHERE id = ?")->execute([$id]);
    Logger::azione('soggetto_delete', 'soggetto', $id);
    header("Location: soggetti.php?msg=Soggetto+eliminato");
    exit;
}

$edit = null;
if (isset($_GET['action']) && $_GET['action'] === 'new') {
    $_GET['edit'] = null; // Forza modalità nuovo
}
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM soggetti WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit = $stmt->fetch();
}

$soggetti = $pdo->query("
    SELECT s.*,
        COALESCE(cc.num_contratti, 0) AS num_contratti,
        COALESCE(pp.num_proprieta, 0) AS num_proprieta,
        COALESCE(rr.num_rappresentati, 0) AS num_rappresentati
    FROM soggetti s
    LEFT JOIN (
        SELECT id_soggetto, COUNT(DISTINCT id_contratto) AS num_contratti
        FROM (
            SELECT id_locatore AS id_soggetto, id_contratto FROM contratto_locatori
            UNION
            SELECT id_conduttore AS id_soggetto, id_contratto FROM contratto_conduttori
        ) u
        GROUP BY id_soggetto
    ) cc ON cc.id_soggetto = s.id
    LEFT JOIN (
        SELECT id_locatore AS id_soggetto, COUNT(*) AS num_proprieta
        FROM proprieta_immobili
        GROUP BY id_locatore
    ) pp ON pp.id_soggetto = s.id
    LEFT JOIN (
        SELECT id_rappresentante AS id_soggetto, COUNT(*) AS num_rappresentati
        FROM soggetti
        WHERE id_rappresentante IS NOT NULL
        GROUP BY id_rappresentante
    ) rr ON rr.id_soggetto = s.id
    ORDER BY s.cognome, s.nome
")->fetchAll();

function badge_class($tipologia) {
    if ($tipologia === 'LOCATORE') return 'badge-LOC';
    if ($tipologia === 'CONDUTTORE') return 'badge-COND';
    return 'badge-ENT';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <link rel="stylesheet" href="assets/uppercase.css">
    <meta charset="UTF-8">
    <title>Soggetti — RLI Manager</title>
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
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; transition: border-color 0.2s, background-color 0.2s; }
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
        .badge-LOC { background: #bee3f8; color: #2c5282; }
        .badge-COND { background: #feebc8; color: #7b341e; }
        .badge-ENT { background: #e2e8f0; color: #4a5568; }
        .auto-filled { background-color: #f0fff4 !important; border-color: #68d391 !important; }
        .cf-valid { border-color: #48bb78 !important; }
        .cf-warning { border-color: #ed8936 !important; background-color: #fffaf0 !important; }
        .cf-error { border-color: #e53e3e !important; background-color: #fff5f5 !important; }
        .hint { color: #718096; font-size: 0.8em; margin-top: -8px; margin-bottom: 10px; display: block; }
        .cf-feedback { padding: 8px 12px; border-radius: 6px; font-size: 0.85em; margin-top: -4px; margin-bottom: 12px; display: none; }
        .cf-feedback.ok { background: #c6f6d5; color: #22543d; display: block; }
        .cf-feedback.warn { background: #feebc8; color: #7b341e; display: block; }
        .cf-feedback.err { background: #fed7d7; color: #742a2a; display: block; }
        .filters-bar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .filters-bar input, .filters-bar select { margin: 0; }
        .filters-bar input { flex: 2; min-width: 220px; }
        .filters-bar select { flex: 0 0 200px; }
        .badge-count { background: #bee3f8; color: #2c5282; }
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
            <h2 style="margin:0;">Soggetti censiti (<?php echo count($soggetti); ?>)</h2>
            <a href="<?= url('soggetto_new.php') ?>" class="btn btn-green">➕ Nuovo Soggetto</a>
        </div>

        <?php if (empty($soggetti)): ?>
            <div style="text-align:center; padding: 40px 20px;">
                <p style="color:#a0aec0; font-size:1.1em;">Nessun soggetto registrato.</p>
                <button type="button" class="btn btn-green" onclick="apriNuovoSoggetto()" style="margin-top:10px; padding: 14px 28px; font-size: 1em;">＋ Aggiungi il primo soggetto</button>
            </div>
        <?php else: ?>
            <div class="filters-bar">
                <input type="text" id="sog-filter-search" placeholder="🔍 Cerca per nome, CF/P.IVA, email/PEC..." autocomplete="off">
                <select id="sog-filter-tipologia">
                    <option value="">Tutte le tipologie</option>
                    <option value="LOCATORE">LOCATORE</option>
                    <option value="CONDUTTORE">CONDUTTORE</option>
                    <option value="ENTRAMBI">ENTRAMBI</option>
                </select>
            </div>
            <table id="soggetti-table">
                <thead><tr><th>Nome</th><th>CF</th><th>Nascita</th><th>Tipologia</th><th>Contatti</th><th>Contratti</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($soggetti as $s): ?>
                        <?php
                        $hasConstraints = ((int)$s['num_contratti'] > 0)
                            || ((int)$s['num_proprieta'] > 0)
                            || ((int)$s['num_rappresentati'] > 0);
                        $searchBlob = strtoupper(trim(
                            $s['cognome'] . ' ' . $s['nome'] . ' ' .
                            $s['codice_fiscale'] . ' ' .
                            ($s['email'] ?? '') . ' ' . ($s['pec'] ?? '')
                        ));
                        ?>
                        <tr data-search="<?php echo Security::h($searchBlob); ?>" data-tipologia="<?php echo Security::h($s['tipologia_default']); ?>">
                            <td><strong><?php echo Security::h($s['cognome'] . ' ' . $s['nome']); ?></strong></td>
                            <td style="font-family:monospace; font-size:0.85em;"><?php echo Security::h($s['codice_fiscale']); ?></td>
                            <td style="font-size:0.85em;">
                                <?php if ($s['data_nascita']): ?>
                                    <?php echo date('d/m/Y', strtotime($s['data_nascita'])); ?>
                                    <?php if ($s['comune_nascita']): ?>
                                        <br><small style="color:#718096;"><?php echo Security::h($s['comune_nascita']); ?> (<?php echo Security::h($s['prov_nascita'] ?: '—'); ?>)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#a0aec0;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo badge_class($s['tipologia_default']); ?>">
                                    <?php echo Security::h($s['tipologia_default']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85em;">
                                <?php echo Security::h($s['email'] ?: '—'); ?>
                                <?php if (!empty($s['pec'])): ?>
                                    <br><small style="color:#718096;">PEC: <?php echo Security::h($s['pec']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$s['num_contratti'] > 0): ?>
                                    <span class="badge badge-count" title="<?php echo (int)$s['num_contratti']; ?> contratti collegati"><?php echo (int)$s['num_contratti']; ?></span>
                                <?php else: ?>
                                    <span style="color:#a0aec0;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="soggetto_edit.php?id=<?php echo $s['id']; ?>" class="btn btn-primary" style="padding:5px 12px; font-size:0.85em;">✎</a>
                                <?php if ($hasConstraints): ?>
                                    <button class="btn btn-red" style="padding:5px 12px; font-size:0.85em; opacity:0.5; cursor:not-allowed;" disabled
                                            title="Impossibile eliminare: collegato a contratti/proprietà/società">🗑</button>
                                <?php else: ?>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="delete_id" value="<?php echo $s['id']; ?>">
                                        <button class="btn btn-red" style="padding:5px 12px; font-size:0.85em;">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Filtro live della tabella soggetti
(function() {
    const search = document.getElementById('sog-filter-search');
    const tipologia = document.getElementById('sog-filter-tipologia');
    const table = document.getElementById('soggetti-table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tbody tr'));

    function applyFilter() {
        const q = (search ? search.value : '').toUpperCase().trim();
        const t = tipologia ? tipologia.value : '';
        rows.forEach(function(tr) {
            const hay = tr.dataset.search || '';
            const tip = tr.dataset.tipologia || '';
            const okQ = q === '' || hay.indexOf(q) !== -1;
            const okT = t === '' || tip === t;
            tr.style.display = (okQ && okT) ? '' : 'none';
        });
    }

    if (search) search.addEventListener('input', applyFilter);
    if (tipologia) tipologia.addEventListener('change', applyFilter);
})();
</script>
<script src="assets/cf_utils.js"></script>
<script src="assets/soggetto_modal.js"></script>

<script src="assets/uppercase.js"></script>
</body>
</html>
