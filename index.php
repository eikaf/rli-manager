<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/Validators.php';
require_once 'lib/ContrattoRepository.php';
require_once 'lib/RliTaxCalculator.php';
require_once 'lib/NotificaRepository.php';
$tax = new RliTaxCalculator($pdo);

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- AUTH ---
if (isset($_GET['logout'])) { $auth->logout(); header("Location: index.php"); exit; }

if (isset($_POST['login_username']) && isset($_POST['login_password'])) {
    if (!Security::validateCsrf()) {
        $auth_error = "Token di sicurezza non valido. Ricarica la pagina.";
        Logger::log('login_csrf_fail', ['esito' => 'errore']);
    } else {
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] > RLI_MAX_LOGIN_ATTEMPTS) {
            $auth_error = "Troppi tentativi. Riprova piu tardi.";
            Logger::log('login_bruteforce_block', ['esito' => 'errore']);
        } else {
            $username = trim($_POST['login_username']);
            $password = $_POST['login_password'];
            $utente = $auth->login($username, $password);
            if ($utente) {
                $_SESSION['login_attempts'] = 0;
                // Se manutenzione attiva e non admin, reindirizza a manutenzione_attiva.php
                $manutenzioneAttiva = false;
                try {
                    $stmtM = $pdo->prepare("SELECT valore FROM impostazioni WHERE chiave = 'manutenzione'");
                    $stmtM->execute();
                    $manutenzioneAttiva = ($stmtM->fetchColumn() === '1');
                } catch (Throwable $e) {}
                if ($manutenzioneAttiva && !$auth->isAdmin()) {
                    header("Location: manutenzione_attiva.php"); exit;
                }
                header("Location: index.php"); exit;
            } else {
                $auth_error = "Username o password errati.";
                Logger::log('login_fail', ['esito' => 'errore', 'dettagli' => ['username' => $username]]);
            }
        }
    }
}

if (!$auth->isLoggedIn()) {
    // Se la manutenzione è attiva, mostra un avviso nella pagina di login
    $manutenzioneAttiva = false;
    if (isset($pdo)) {
        try {
            $stmtM = $pdo->prepare("SELECT valore FROM impostazioni WHERE chiave = 'manutenzione'");
            $stmtM->execute();
            $manutenzioneAttiva = ($stmtM->fetchColumn() === '1');
        } catch (Throwable $e) {}
    }
    $auth_error_html = isset($auth_error) ? "<p style=\"color:#fc8181;\">" . htmlspecialchars($auth_error) . "</p>" : "";
    $csrf_html = Security::csrfField();
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <title>Login RLI Manager</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #1a202c; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: #2d3748; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; color: #e2e8f0; width: 340px; }
            .login-box input { width: 100%; padding: 12px; border: 1px solid #4a5568; background: #1a202c; color: #e2e8f0; border-radius: 6px; box-sizing: border-box; font-size: 1em; margin-bottom: 15px; }
            .login-box .pwd-wrapper { position: relative; width: 100%; margin-bottom: 15px; }
            .login-box .pwd-wrapper input { margin-bottom: 0; padding-right: 45px; }
            .login-box .pwd-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #a0aec0; cursor: pointer; font-size: 1.2em; padding: 5px; z-index: 10; }
            .login-box button[type="submit"] { background: #4299e1; color: #fff; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; width: 100%; font-weight: bold; font-size: 1em; margin-top: 5px; }
            .login-box button[type="submit"]:hover { background: #3182ce; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h3>RLI Manager</h3>
            <?= $auth_error_html ?>
            <form method="POST">
                <?= $csrf_html ?>
                <input type="text" name="login_username" placeholder="Username" required autofocus>
                <div class="pwd-wrapper">
                    <input type="password" name="login_password" id="login_password" placeholder="Password" required>
                    <button type="button" class="pwd-toggle" onclick="togglePassword('login_password', this)">👁</button>
                </div>
                <button type="submit">Accedi</button>
            </form>
        </div>
        <script>
            function togglePassword(inputId, btn) {
                const input = document.getElementById(inputId);
                if (input.type === 'password') {
                    input.type = 'text';
                    btn.textContent = '🙈';
                } else {
                    input.type = 'password';
                    btn.textContent = '👁';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

$repo = new ContrattoRepository($pdo);

// --- FILTRI E PAGINAZIONE ---
$regFilter = $_GET['reg'] ?? '';
if (!in_array($regFilter, ['registrati', 'da_registrare'], true)) {
    $regFilter = '';
}
$filters = [
    'stato'     => trim($_GET['stato'] ?? ''),
    'tipologia' => trim($_GET['tipologia'] ?? ''),
    'search'    => trim($_GET['search'] ?? ''),
    'reg'       => $regFilter,
];
$filters = array_filter($filters, fn($v) => $v !== '');

// Ordinamento (separato dai filtri "veri" per non alterare il badge N risultati)
$orderKey  = $_GET['order'] ?? 'nome';
$orderDir  = strtoupper($_GET['dir'] ?? '');
$validOrderKeys = ['nome','data_creazione','scadenza','canone','stato','id'];
if (!in_array($orderKey, $validOrderKeys, true)) { $orderKey = 'nome'; }
if (!in_array($orderDir, ['ASC','DESC'], true)) {
    $defaultDir = ['nome'=>'ASC','data_creazione'=>'DESC','scadenza'=>'ASC','canone'=>'DESC','stato'=>'ASC','id'=>'DESC'];
    $orderDir = $defaultDir[$orderKey];
}
// Passa l'ordinamento al repository (non finisce in $filters "attivi")
$repoFilters = $filters + ['order' => $orderKey, 'dir' => $orderDir];

$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Elementi per pagina: selezionabile dall'utente, default 100.
$perPageOptions = [10, 25, 50, 100, 200, 500];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 100;
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 100;
}
$limit  = $perPage;
$offset = ($page - 1) * $limit;

$result          = $repo->searchAndPaginate($repoFilters, $limit, $offset, $auth);
$contratti       = $result['data'];
$totalContratti  = $result['total'];
$totalPages      = (int)ceil($totalContratti / $limit);

// Statistiche filtrate per l'utente
$statsVis = $repo->statsByVisibilita($auth);
$stats = $statsVis['stati'];

// Dettaglio ATTIVI (ATTIVO + PROROGATO) per presenza del codice AdE.
$totAttivi          = (int)($stats['ATTIVO'] ?? 0) + (int)($stats['PROROGATO'] ?? 0);
$attiviRegistrati   = (int)($statsVis['attivi_registrati'] ?? 0);
$attiviDaRegistrare = (int)($statsVis['attivi_da_registrare'] ?? 0);

if ($auth->isAdmin()) {
    $totSoggetti  = $pdo->query("SELECT COUNT(*) FROM soggetti")->fetchColumn();
    $totImmobili  = $pdo->query("SELECT COUNT(*) FROM immobili")->fetchColumn();
} else {
    $visFilter = "(c.id_utente_creatore = :id_utente OR EXISTS (SELECT 1 FROM contratti_condivisioni cc WHERE cc.id_contratto = c.id AND cc.id_utente = :id_utente_cond))";
    $params = [':id_utente' => $auth->id(), ':id_utente_cond' => $auth->id()];
    
    $stmtSogg = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) FROM soggetti s 
        JOIN contratto_locatori cl ON cl.id_locatore = s.id 
        JOIN contratti c ON c.id = cl.id_contratto WHERE $visFilter
        UNION 
        SELECT COUNT(DISTINCT s.id) FROM soggetti s 
        JOIN contratto_conduttori cc ON cc.id_conduttore = s.id 
        JOIN contratti c ON c.id = cc.id_contratto WHERE $visFilter
    ");
    $stmtSogg->execute($params);
    $totSoggetti = array_sum($stmtSogg->fetchAll(PDO::FETCH_COLUMN));

    $stmtImm = $pdo->prepare("
        SELECT COUNT(DISTINCT i.id) FROM immobili i 
        JOIN contratto_immobili ci ON ci.id_immobile = i.id 
        JOIN contratti c ON c.id = ci.id_contratto WHERE $visFilter
    ");
    $stmtImm->execute($params);
    $totImmobili = $stmtImm->fetchColumn();
}
$totProprieta = 0;

// Scadenze (card rossa in alto) - Filtro visibilità
$scadWhere = "WHERE c.stato IN ('ATTIVO', 'PROROGATO')
      AND c.durata_al IS NOT NULL
      AND c.durata_al BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
$scadParams = [];

if (!$auth->isAdmin()) {
    $scadWhere .= " AND (c.id_utente_creatore = :id_utente OR EXISTS (SELECT 1 FROM contratti_condivisioni cc WHERE cc.id_contratto = c.id AND cc.id_utente = :id_utente_cond))";
    $scadParams[':id_utente'] = $auth->id();
    $scadParams[':id_utente_cond'] = $auth->id();
}

$stmtScad = $pdo->prepare("
    SELECT c.id, c.id_contratto_univoco, c.durata_al, c.stato,
           DATEDIFF(c.durata_al, CURDATE()) AS giorni_rimasti,
           (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
            FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
            WHERE cl.id_contratto = c.id LIMIT 1) AS locatore,
           (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
            FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
            WHERE cc.id_contratto = c.id LIMIT 1) AS conduttore
    FROM contratti c
    $scadWhere
    ORDER BY c.durata_al ASC
");
$stmtScad->execute($scadParams);
$scadenze = $stmtScad->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <base href="<?= url('/') ?>">
    <link rel="stylesheet" href="assets/uppercase.css">
    <meta charset="UTF-8">
    <title>RLI Manager — Contratti</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', -apple-system, sans-serif; background: #f7fafc; padding: 0; margin: 0; color: #2d3748; }
        .container { max-width: 1400px; margin: 0 auto; padding: 25px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: #fff; padding: 18px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #4299e1; }
        .stat-card.green { border-color: #48bb78; }
        .stat-card.orange { border-color: #ed8936; }
        .stat-card.gray { border-color: #a0aec0; }
        .stat-card .label { color: #718096; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .value { font-size: 1.8em; font-weight: bold; color: #1a202c; margin-top: 5px; }
        .stat-sub { margin-top: 8px; display: flex; flex-direction: column; gap: 2px; font-size: 0.8em; }
        .stat-sub-item { text-decoration: none; font-weight: 600; }
        .stat-sub-item:hover { text-decoration: underline; }
        .stat-sub-item.reg { color: #2f855a; }
        .stat-sub-item.dareg { color: #b7791f; }
        .contratti-list { display: grid; gap: 15px; }
        .contratto-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); border-left: 4px solid #cbd5e0; transition: all 0.2s; }
        .contratto-card:hover { box-shadow: 0 6px 14px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .contratto-card.ATTIVO { border-color: #48bb78; }
        .contratto-card.PROROGATO { border-color: #4299e1; }
        .contratto-card.CESSATO { border-color: #ed8936; }
        .contratto-card.RISOLTO, .contratto-card.SCADUTO { border-color: #a0aec0; opacity: 0.75; }
        .contratto-card.BOZZA { border-color: #ecc94b; background: #fffff0; }
        .contratto-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .contratto-title { font-size: 1.15em; font-weight: bold; color: #1a202c; }
        .contratto-id { font-size: 0.85em; color: #718096; font-family: 'Courier New', monospace; margin-top: 3px; }
        .contratto-body { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 12px 0; font-size: 0.9em; }
        .contratto-body .field-label { color: #718096; font-size: 0.8em; text-transform: uppercase; }
        .contratto-body .field-value { color: #2d3748; margin-top: 2px; }
        .contratto-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #edf2f7; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; text-transform: uppercase; }
        .badge-ATTIVO { background: #c6f6d5; color: #22543d; }
        .badge-PROROGATO { background: #bee3f8; color: #2c5282; }
        .badge-CESSATO { background: #feebc8; color: #7b341e; }
        .badge-RISOLTO { background: #e2e8f0; color: #4a5568; }
        .badge-SCADUTO { background: #fed7d7; color: #742a2a; }
        .badge-BOZZA { background: #fefcbf; color: #744210; }
        .badge-L2 { background: #c6f6d5; color: #22543d; border: 1px solid #48bb78; }
        .badge-share { background: #e9d8fd; color: #553c9a; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.85em; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-primary:hover { background: #3182ce; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-green:hover { background: #38a169; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-gray:hover { background: #cbd5e0; }
        .btn-orange { background: #ed8936; color: #fff; }
        .btn-red { background: #f56565; color: #fff; }
        .btn-red:hover { background: #e53e3e; }
        .btn-lg { padding: 14px 28px; font-size: 1em; }
        .empty-state { text-align: center; padding: 80px 20px; color: #a0aec0; }
        .empty-state h2 { color: #4a5568; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }

        .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-form .field { flex: 1; min-width: 180px; }
        .filter-form label { display: block; font-weight: 600; font-size: 0.85em; color: #4a5568; margin-bottom: 5px; }
        .filter-form input, .filter-form select { width: 100%; padding: 9px 12px; border: 1px solid #cbd5e0; border-radius: 8px; font-size: 0.95em; margin: 0; }
        .filter-form input:focus, .filter-form select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }

        .fiscal-summary { background: #f0fff4; border-left: 4px solid #48bb78; padding: 10px 15px; margin: 10px 0; border-radius: 6px; font-size: 0.88em; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; }
        .fiscal-summary .lbl { color: #4a5568; font-weight: 600; }
        .fiscal-summary .val { color: #22543d; font-weight: bold; font-family: monospace; }
        .fiscal-summary .tot { color: #744210; font-weight: bold; font-family: monospace; font-size: 1.05em; }

        .scad-alert { margin-top: 5px; font-size: 0.85em; font-weight: bold; padding: 3px 10px; border-radius: 10px; display: inline-block; }
        .scad-alert.warn { background: #feebc8; color: #7b341e; }
        .scad-alert.danger { background: #fed7d7; color: #742a2a; }

        .stato-extra { margin-top: 4px; font-size: 0.8em; font-weight: bold; padding: 3px 8px; border-radius: 6px; display: inline-block; }
        .stato-extra.prorogato { background: #ebf8ff; color: #2c5282; }
        .stato-extra.risolto   { background: #fff5f5; color: #742a2a; }
        .stato-extra.ceduto    { background: #fffaf0; color: #7b341e; }

        .pagination { text-align: center; margin-top: 25px; display: flex; justify-content: center; align-items: center; gap: 10px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 0.9em; }
        .pagination a { background: #e2e8f0; color: #2d3748; }
        .pagination a:hover { background: #cbd5e0; }
        .pagination .current { background: #4299e1; color: #fff; }
        .pagination .info { color: #718096; font-weight: normal; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if (isset($_GET['error'])) echo "<div class='alert alert-error'>⚠ " . Security::h($_GET['error']) . "</div>"; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="margin:0;">Contratti</h2>
        <a href="contract_new.php" class="btn btn-primary btn-lg">+ Nuovo Contratto</a>
    </div>

    <!-- FORM FILTRI -->
    <div class="card" style="background:#fff; padding:20px; border-radius:12px; box-shadow:0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px;">
        <form method="GET" action="index.php" class="filter-form" id="form-filtri">
            <div class="field" style="flex: 2;">
                <label for="search">🔍 Ricerca contratti <span style="font-weight:normal; color:#a0aec0; font-size:0.8em;">(ricerca automatica)</span></label>
                <input type="text" name="search" id="search" placeholder="Nome, CF, immobile, indirizzo, note..." value="<?= Security::h($filters['search'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="field">
                <label for="stato">Stato</label>
                <select name="stato" id="stato">
                    <option value="">Tutti</option>
                    <?php foreach (['ATTIVO', 'PROROGATO', 'BOZZA', 'CESSATO', 'RISOLTO', 'SCADUTO'] as $stato): ?>
                        <option value="<?= $stato ?>" <?= ($filters['stato'] ?? '') === $stato ? 'selected' : '' ?>><?= $stato ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tipologia">Tipologia</label>
                <select name="tipologia" id="tipologia">
                    <option value="">Tutte</option>
                    <?php foreach (rli_tipologie_contratto() as $t): ?>
                        <option value="<?= $t ?>" <?= ($filters['tipologia'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="order">Ordina per</label>
                <select name="order" id="order">
                    <option value="nome"           <?= $orderKey === 'nome'           ? 'selected' : '' ?>>Nome (cognome + nome)</option>
                    <option value="data_creazione" <?= $orderKey === 'data_creazione' ? 'selected' : '' ?>>Data creazione</option>
                    <option value="scadenza"       <?= $orderKey === 'scadenza'       ? 'selected' : '' ?>>Scadenza</option>
                    <option value="canone"         <?= $orderKey === 'canone'         ? 'selected' : '' ?>>Canone</option>
                    <option value="stato"          <?= $orderKey === 'stato'          ? 'selected' : '' ?>>Stato</option>
                    <option value="id"             <?= $orderKey === 'id'             ? 'selected' : '' ?>>ID contratto</option>
                </select>
            </div>
            <div class="field" style="flex: 0 0 150px;">
                <label for="dir">Direzione</label>
                <select name="dir" id="dir">
                    <option value="ASC"  <?= $orderDir === 'ASC'  ? 'selected' : '' ?>>↑ Crescente</option>
                    <option value="DESC" <?= $orderDir === 'DESC' ? 'selected' : '' ?>>↓ Decrescente</option>
                </select>
            </div>
            <div class="field" style="flex: 0 0 130px;">
                <label for="per_page">Per pagina</label>
                <select name="per_page" id="per_page">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; gap:8px; align-items:flex-end;">
                <button type="submit" class="btn btn-gray" id="btn-filtra" title="I filtri si applicano automaticamente">Filtra</button>
                <?php if (!empty($filters)): ?>
                    <a href="index.php" class="btn btn-gray" id="btn-reset">Reset</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if (!empty($filters)): ?>
            <p style="margin: 12px 0 0 0; font-size: 0.85em; color: #718096;">
                <strong><?= $totalContratti ?></strong> contratt<?= $totalContratti === 1 ? 'o' : 'i' ?> trovat<?= $totalContratti === 1 ? 'o' : 'i' ?>
                <?php if ($filters['search'] ?? ''): ?>per "<em><?= Security::h($filters['search']) ?></em>"<?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- STATISTICHE -->
    <div class="stats">
        <?php if (count($scadenze) > 0): ?>
        <div class="stat-card" style="background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%); border-left-color: #f56565; grid-column: 1 / -1;">
            <div class="label" style="color: #742a2a;">⏰ Contratti in scadenza (prossimi 90 giorni)</div>
            <div style="margin-top: 10px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                    <tbody>
                        <?php foreach ($scadenze as $s):
                            $urgente = $s['giorni_rimasti'] <= 30;
                            $allarme = $s['giorni_rimasti'] <= 60;
                            $colore = $urgente ? '#742a2a' : ($allarme ? '#7b341e' : '#22543d');
                            $bg = $urgente ? '#fed7d7' : ($allarme ? '#feebc8' : '#c6f6d5');
                        ?>
                            <tr style="border-bottom: 1px solid rgba(0,0,0,0.05);">
                                <td style="padding: 6px 8px;">
                                    <strong><?= Security::h($s['locatore'] ?: '?') ?></strong>
                                    <span style="color: #718096;">/</span>
                                    <?= Security::h($s['conduttore'] ?: '?') ?>
                                    <small style="color: #718096; font-family: monospace; margin-left: 8px;"><?= Security::h($s['id_contratto_univoco'] ?: 'ID #' . $s['id']) ?></small>
                                </td>
                                <td style="padding: 6px 8px; white-space: nowrap;"><?= date('d/m/Y', strtotime($s['durata_al'])) ?></td>
                                <td style="padding: 6px 8px; white-space: nowrap;">
                                    <span style="background: <?= $bg ?>; color: <?= $colore ?>; padding: 3px 10px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">
                                        <?= (int)$s['giorni_rimasti'] ?> gg
                                    </span>
                                </td>
                                <td style="padding: 6px 8px; text-align: right; white-space: nowrap;">
                                    <a href="contract.php?id=<?= $s['id'] ?>" class="btn btn-primary" style="padding: 4px 10px; font-size: 0.8em;">📂 Apri</a>
                                    <a href="contract_event_proroga.php?id=<?= $s['id'] ?>" class="btn btn-green" style="padding: 4px 10px; font-size: 0.8em;">🔄 Proroga</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="stat-card green">
            <div class="label">Attivi</div>
            <div class="value"><?= $totAttivi ?></div>
            <div class="stat-sub">
                <a class="stat-sub-item reg"
                   href="?<?= Security::h(http_build_query(array_merge($_GET, ['reg' => 'registrati', 'page' => 1]))) ?>"
                   title="Filtra i contratti attivi già registrati (con codice AdE)"><?= $attiviRegistrati ?> registrati</a>
                <a class="stat-sub-item dareg"
                   href="?<?= Security::h(http_build_query(array_merge($_GET, ['reg' => 'da_registrare', 'page' => 1]))) ?>"
                   title="Filtra i contratti attivi da registrare (senza codice AdE)"><?= $attiviDaRegistrare ?> da registrare</a>
            </div>
        </div>
        <div class="stat-card">
            <div class="label">Bozze</div>
            <div class="value"><?= (int)($stats['BOZZA'] ?? 0) ?></div>
        </div>
        <div class="stat-card gray">
            <div class="label">Chiusi</div>
            <div class="value"><?= (int)($stats['RISOLTO'] ?? 0) + (int)($stats['CESSATO'] ?? 0) + (int)($stats['SCADUTO'] ?? 0) ?></div>
        </div>
        <div class="stat-card orange">
            <div class="label">Soggetti</div>
            <div class="value"><?= (int)$totSoggetti ?></div>
        </div>
        <div class="stat-card orange">
            <div class="label">Immobili</div>
            <div class="value"><?= (int)$totImmobili ?></div>
        </div>
    </div>

    <?php if (empty($contratti)): ?>
        <div class="empty-state">
            <h2><?= !empty($filters) ? 'Nessun contratto trovato' : 'Nessun contratto' ?></h2>
            <p><?= !empty($filters) ? 'Prova a modificare i filtri di ricerca.' : 'Crea il tuo primo contratto di locazione per iniziare.' ?></p>
            <?php if (!empty($filters)): ?>
                <a href="index.php" class="btn btn-gray btn-lg">Reset filtri</a>
            <?php else: ?>
                <a href="contract_new.php" class="btn btn-primary btn-lg">+ Nuovo Contratto</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="contratti-list">
            <?php foreach ($contratti as $c):
                // ==== CALCOLO ALERT SCADENZA ====
                $oggiTs = time();
                // Se il contratto è prorogato e c'è data_fine_proroga, usa quella come scadenza
                $dataScadenzaEffettiva = $c['durata_al'];
                if ($c['stato'] === 'PROROGATO' && !empty($c['data_fine_proroga'])) {
                    $dataScadenzaEffettiva = $c['data_fine_proroga'];
                }
                $scadTs = !empty($dataScadenzaEffettiva) ? strtotime($dataScadenzaEffettiva) : null;
                $giorniRimasti = $scadTs ? (int)floor(($scadTs - $oggiTs) / 86400) : null;
                $inScadenza = ($scadTs && $scadTs >= $oggiTs && $giorniRimasti !== null && $giorniRimasti <= 90);
                $scaduto = ($scadTs && $scadTs < $oggiTs && in_array($c['stato'], ['ATTIVO', 'PROROGATO']));

                // ==== ETICHETTA EXTRA: proroga / risoluzione / cessione ====
                $extraLabel = null;
                $extraClass = '';
                if ($c['stato'] === 'PROROGATO' && !empty($c['data_fine_proroga'])) {
                    $extraLabel = '🔄 Prorogato fino al ' . date('d/m/Y', strtotime($c['data_fine_proroga']));
                    $extraClass = 'prorogato';
                } elseif ($c['stato'] === 'RISOLTO' && !empty($c['durata_al'])) {
                    $extraLabel = '❌ Risolto il ' . date('d/m/Y', strtotime($c['durata_al']));
                    $extraClass = 'risolto';
                } elseif ($c['stato'] === 'CESSATO' && !empty($c['data_cessione'])) {
                    $extraLabel = '🔀 Ceduto il ' . date('d/m/Y', strtotime($c['data_cessione']));
                    $extraClass = 'ceduto';
                }

                // ==== STIMA FISCALE VELOCE ====
                $praticaCalc = $c;
                $praticaCalc['tipologia_adempimento'] = $c['tipologia_adempimento'] ?? '1';
                $impReg = $tax->calcolaImpostaRegistro($praticaCalc);
                $impBol = $tax->calcolaImpostaBollo($praticaCalc);
                $totFisc = $impReg + $impBol;
            ?>
                <div class="contratto-card <?= Security::h($c['stato']) ?>">
                    <div class="contratto-header">
                        <div>
                            <div class="contratto-title">
                                <?php
                                // Mostra tutti i locatori/conduttori, tronca oltre 3
                                $locArr = array_filter(array_map('trim', explode('/', $c['locatori_list'] ?? '')));
                                $condArr = array_filter(array_map('trim', explode('/', $c['conduttori_list'] ?? '')));

                                $locMostra = count($locArr) > 3
                                    ? implode(', ', array_slice($locArr, 0, 3)) . ' +' . (count($locArr) - 3)
                                    : implode(', ', $locArr);
                                $condMostra = count($condArr) > 3
                                    ? implode(', ', array_slice($condArr, 0, 3)) . ' +' . (count($condArr) - 3)
                                    : implode(', ', $condArr);

                                if (empty($locArr)) $locMostra = 'Senza locatore';
                                if (empty($condArr)) $condMostra = 'Senza conduttore';
                                ?>
                                <?= Security::h($locMostra) ?>
                                <span style="color:#a0aec0;">/</span>
                                <?= Security::h($condMostra) ?>
                            </div>
                            <div class="contratto-id">
                                <?= Security::h($c['id_contratto_univoco'] ?: 'ID non assegnato') ?>
                                &nbsp;·&nbsp;
                                Contratto #<?= $c['id'] ?>
                                <?php if (!empty($c['codice_identificativo_contratto'])): ?>
                                    &nbsp;·&nbsp;
                                    <span class="badge-ade" style="display: inline-block; padding: 2px 8px; background: #c6f6d5; color: #22543d; border-radius: 6px; font-weight: bold; font-size: 0.9em; border: 1px solid #48bb78; margin-left: 5px;">
                                        🏛️ AdE: <?= Security::h($c['codice_identificativo_contratto']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <span class="badge badge-<?= Security::h($c['stato']) ?>"><?= Security::h($c['stato']) ?></span>
                            <?php if ($c['tipologia_contratto'] === 'L2'): ?>
                                <span class="badge badge-L2" title="Contratto a canone concordato (L2)">📜 CANONE CONCORDATO</span>
                            <?php endif; ?>
                            <?php if (($c['num_condivisioni'] ?? 0) > 0): ?>
                                <span class="badge badge-share" title="<?= (int)$c['num_condivisioni'] ?> utenti hanno accesso">
                                    👥 <?= (int)$c['num_condivisioni'] ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($inScadenza): ?>
                                <div class="scad-alert warn">⚠️ Scade tra <?= $giorniRimasti ?> gg</div>
                            <?php elseif ($scaduto): ?>
                                <div class="scad-alert danger">❌ Scaduto</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="contratto-body">
                        <div>
                            <div class="field-label">Tipologia</div>
                            <div class="field-value"><?= Security::h($c['tipologia_contratto']) ?></div>
                        </div>
                        <div>
                            <div class="field-label">Durata</div>
                            <div class="field-value">
                                <?php if ($c['durata_dal']): ?>
                                    <?= date('d/m/Y', strtotime($c['durata_dal'])) ?>
                                    <?= $c['durata_al'] ? ' → ' . date('d/m/Y', strtotime($c['durata_al'])) : '' ?>
                                <?php else: ?>—<?php endif; ?>
                            </div>
                            <?php if ($extraLabel): ?>
                                <div class="stato-extra <?= $extraClass ?>"><?= Security::h($extraLabel) ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="field-label">Canone</div>
                            <div class="field-value">
                                <?= $c['importo_canone'] ? '€ ' . number_format((float)$c['importo_canone'], 2, ',', '.') : '—' ?>
                            </div>
                        </div>
                        <div>
                            <div class="field-label">Eventi</div>
                            <div class="field-value">
                                <?= (int)$c['num_eventi'] ?>
                                <?= $c['ultimo_evento'] ? ' (ultimo: ' . date('d/m/Y', strtotime($c['ultimo_evento'])) . ')' : '' ?>
                            </div>
                        </div>
                        <div>
                            <div class="field-label">Cedolare Secca</div>
                            <div class="field-value">
                                <?php
                                $ced = (int)$c['cedolare_secca'];
                                echo $ced === 1 ? '100%' : ($ced === 2 ? 'Mista' : 'No');
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIEPILOGO FISCALE -->
                    <div class="fiscal-summary">
                        <span class="lbl">💰 Stima fiscale:</span>
                        <span><span class="lbl">Registro</span> <span class="val">€ <?= number_format($impReg, 2, ',', '.') ?></span></span>
                        <span><span class="lbl">Bollo</span> <span class="val">€ <?= number_format($impBol, 2, ',', '.') ?></span></span>
                        <span style="margin-left:auto;"><span class="lbl">TOTALE</span> <span class="tot">€ <?= number_format($totFisc, 2, ',', '.') ?></span></span>
                    </div>

                    <div class="contratto-actions">
                        <a href="contract.php?id=<?= $c['id'] ?>" class="btn btn-primary">📂 Apri</a>
                        <?php if (in_array($c['stato'], ['ATTIVO','PROROGATO','BOZZA'])): ?>
                            <a href="contract_new.php?id=<?= $c['id'] ?>" class="btn btn-orange" title="Modifica contratto">✏️ Modifica</a>
                            <a href="contract_event_proroga.php?id=<?= $c['id'] ?>" class="btn btn-green">🔄 Proroga</a>
                            <a href="contract_event_annualita.php?id=<?= $c['id'] ?>" class="btn btn-green">📅 Annualità</a>
                            <a href="contract_event_cessione.php?id=<?= $c['id'] ?>" class="btn btn-orange">🔀 Cessione</a>
                            <a href="contract_event_subentro.php?id=<?= $c['id'] ?>" class="btn btn-orange">👤 Subentro</a>
                            <a href="contract_event_risoluzione.php?id=<?= $c['id'] ?>&tipo=risoluzione" class="btn btn-red">❌ Risoluzione</a>
                        <?php endif; ?>
                        <form method="POST" action="genera_xml.php" style="display:inline">
                            <?= Security::csrfField() ?>
                            <input type="hidden" name="id_contratto" value="<?= $c['id'] ?>">
                            <button class="btn btn-gray" type="submit">⬇ .RLI</button>
                        </form>
                        <a href="?delete=<?= $c['id'] ?>" class="btn btn-red" style="margin-left:auto;"
                           onclick="return confirm('Eliminare contratto #<?= $c['id'] ?> e tutti i suoi eventi?')">🗑</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- PAGINAZIONE -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= Security::h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">« Precedente</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 3);
                $endPage   = min($totalPages, $page + 3);

                if ($startPage > 1) {
                    echo '<a href="?' . Security::h(http_build_query(array_merge($_GET, ['page' => 1]))) . '">1</a>';
                    if ($startPage > 2) echo '<span class="info">…</span>';
                }
                for ($p = $startPage; $p <= $endPage; $p++):
                    if ($p == $page):
                ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="?<?= Security::h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"><?= $p ?></a>
                <?php
                    endif;
                endfor;
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) echo '<span class="info">…</span>';
                    echo '<a href="?' . Security::h(http_build_query(array_merge($_GET, ['page' => $totalPages]))) . '">' . $totalPages . '</a>';
                }
                ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= Security::h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Successiva »</a>
                <?php endif; ?>

                <span class="info">(<?= $totalContratti ?> totali)</span>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
<?php if (isset($_GET['delete'])): ?>
fetch('save_pratica.php?action=delete_contratto&id=<?= (int)$_GET['delete'] ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: '_csrf=<?= Security::csrfToken() ?>'
})
    .then(r => r.json())
    .then(d => {
        if (d.ok) location.href = 'index.php?msg=Contratto+eliminato';
        else alert('Errore: ' + d.error);
    });
<?php endif; ?>
</script>
<script>
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
    } else {
        input.type = 'password';
        btn.textContent = '👁';
    }
}
</script>
<script src="assets/uppercase.js"></script>

<script>
// === AUTOFILTER ===
(function() {
    const form = document.getElementById('form-filtri');
    if (!form) return;

    const searchInput = document.getElementById('search');
    const selectStato = document.getElementById('stato');
    const selectTipologia = document.getElementById('tipologia');
    const selectPerPage = document.getElementById('per_page');
    const btnFiltra = document.getElementById('btn-filtra');

    let debounceTimer = null;

    // Funzione che invia il form
    function submitForm() {
        form.submit();
    }

    // Debounce per il campo di ricerca (500ms)
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(submitForm, 500);
        });

        // Submit immediato su "Invio"
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounceTimer);
                submitForm();
            }
        });
    }

    // Auto-submit sui select
    if (selectStato) {
        selectStato.addEventListener('change', submitForm);
    }
    if (selectTipologia) {
        selectTipologia.addEventListener('change', submitForm);
    }
    if (selectPerPage) {
        selectPerPage.addEventListener('change', submitForm);
    }

    // Ordinamento: auto-submit + direzione di default al cambio criterio
    const selectOrder = document.getElementById('order');
    const selectDir   = document.getElementById('dir');
    if (selectOrder) {
        selectOrder.addEventListener('change', function() {
            if (selectDir) {
                const defaults = { nome:'ASC', data_creazione:'DESC', scadenza:'ASC',
                                   canone:'DESC', stato:'ASC', id:'DESC' };
                selectDir.value = defaults[selectOrder.value] || 'ASC';
            }
            submitForm();
        });
    }
    if (selectDir) {
        selectDir.addEventListener('change', submitForm);
    }

    // Il pulsante "Filtra" rimane come fallback manuale
    if (btnFiltra) {
        btnFiltra.addEventListener('click', function(e) {
            e.preventDefault();
            submitForm();
        });
    }
})();
</script>
</body>
</html>
