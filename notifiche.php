<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

// CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') Security::requireCsrf();

$repo = new NotificaRepository($pdo);

// Azioni POST
if (isset($_POST['mark_read'])) {
    $repo->markAsRead((int)$_POST['mark_read']);
    Logger::azione('notifica_read', 'notifica', (int)$_POST['mark_read']);
    header("Location: notifiche.php?msg=Letta"); exit;
}
if (isset($_POST['mark_all'])) {
    $n = $repo->markAllAsRead();
    Logger::azione('notifica_read_all', 'notifica', null, ['n' => $n]);
    header("Location: notifiche.php?msg=Lette+{$n}+notifiche"); exit;
}
if (isset($_POST['delete_id'])) {
    $repo->delete((int)$_POST['delete_id']);
    header("Location: notifiche.php?msg=Eliminata"); exit;
}
if (isset($_POST['generate'])) {
    $create = $repo->generaDaScadenze($pdo);
    header("Location: notifiche.php?msg=" . urlencode(count($create) . ' notifiche generate')); exit;
}

// Filtro
$filtro = $_GET['filtro'] ?? 'tutte';
$soloNonLette = ($filtro === 'non_lette') ? true : (($filtro === 'lette') ? false : null);

$notifiche = $repo->all($soloNonLette, 200, $auth);
$countNonLette = $repo->countPendenti($auth);
$countTotali = $repo->countTotali();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Notifiche — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1100px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        .btn { padding: 9px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-red { background: #f56565; color: #fff; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .notif-list { display: flex; flex-direction: column; gap: 10px; }
        .notif { padding: 15px 20px; border-radius: 10px; border-left: 5px solid #cbd5e0; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; gap: 15px; align-items: flex-start; }
        .notif.unread { background: #f0f9ff; }
        .notif.info { border-color: #4299e1; }
        .notif.warning { border-color: #ed8936; }
        .notif.critical { border-color: #f56565; }
        .notif.letta { opacity: 0.6; }
        .notif-body { flex: 1; }
        .notif-msg { font-size: 0.95em; line-height: 1.4; color: #2d3748; }
        .notif-meta { font-size: 0.8em; color: #718096; margin-top: 6px; }
        .notif-actions { display: flex; flex-direction: column; gap: 5px; }
        .filtro-bar { display: flex; gap: 10px; margin-bottom: 15px; }
        .filtro-bar a { padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 0.9em; font-weight: bold; background: #e2e8f0; color: #2d3748; }
        .filtro-bar a.active { background: #4299e1; color: #fff; }
        .badge-p { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7em; font-weight: bold; text-transform: uppercase; }
        .badge-p.info { background: #bee3f8; color: #2c5282; }
        .badge-p.warning { background: #feebc8; color: #7b341e; }
        .badge-p.critical { background: #fed7d7; color: #742a2a; }
        .empty { text-align: center; padding: 60px 20px; color: #a0aec0; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="margin:0;">🔔 Notifiche <?php if ($countNonLette > 0): ?><span style="background:#f56565; color:#fff; padding:3px 12px; border-radius:12px; font-size:0.6em; vertical-align:middle;"><?= $countNonLette ?> non lette</span><?php endif; ?></h2>
        <div style="display:flex; gap:8px;">
            <form method="POST" style="display:inline;">
                <?= Security::csrfField() ?>
                <button type="submit" name="generate" value="1" class="btn btn-green" title="Genera notifiche da scadenze contratti">⚡ Genera da scadenze</button>
            </form>
            <?php if ($countNonLette > 0): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Segnare tutte come lette?')">
                    <?= Security::csrfField() ?>
                    <button type="submit" name="mark_all" value="1" class="btn btn-gray">✓ Segna tutte come lette</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="filtro-bar">
        <a href="?filtro=tutte" class="<?= $filtro === 'tutte' ? 'active' : '' ?>">Tutte (<?= $countTotali ?>)</a>
        <a href="?filtro=non_lette" class="<?= $filtro === 'non_lette' ? 'active' : '' ?>">Non lette (<?= $countNonLette ?>)</a>
        <a href="?filtro=lette" class="<?= $filtro === 'lette' ? 'active' : '' ?>">Lette</a>
    </div>

    <?php if (empty($notifiche)): ?>
        <div class="card empty">
            <h3>Nessuna notifica</h3>
            <p>Le notifiche di scadenza verranno generate automaticamente dal cron job,<br>oppure manualmente con il pulsante "⚡ Genera da scadenze".</p>
        </div>
    <?php else: ?>
        <div class="notif-list">
            <?php foreach ($notifiche as $n): ?>
                <div class="notif <?= $n['letta'] ? 'letta' : 'unread' ?> <?= Security::h($n['priorita']) ?>">
                    <div class="notif-body">
                        <div style="margin-bottom:6px;">
                            <span class="badge-p <?= Security::h($n['priorita']) ?>"><?= Security::h($n['priorita']) ?></span>
                            <span style="font-size:0.75em; color:#718096; margin-left:8px;"><?= Security::h($n['tipo']) ?></span>
                            <?php if (!empty($n['id_contratto_univoco'])): ?>
                                <a href="<?= url('contract.php') ?>?id=<?= (int)$n['id_contratto'] ?>" style="font-size:0.8em; color:#4299e1; margin-left:8px;">→ <?= Security::h($n['id_contratto_univoco']) ?></a>
                            <?php endif; ?>
                        </div>
                        <div class="notif-msg"><?= Security::h($n['messaggio']) ?></div>
                        <div class="notif-meta">
                            Creata: <?= date('d/m/Y H:i', strtotime($n['data_creazione'])) ?>
                            <?php if ($n['data_scadenza']): ?> · Scadenza: <strong><?= date('d/m/Y', strtotime($n['data_scadenza'])) ?></strong><?php endif; ?>
                            <?php if ($n['letta'] && $n['data_lettura']): ?> · Letta: <?= date('d/m/Y H:i', strtotime($n['data_lettura'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="notif-actions">
                        <?php if (!$n['letta']): ?>
                            <form method="POST">
                                <?= Security::csrfField() ?>
                                <button type="submit" name="mark_read" value="<?= (int)$n['id'] ?>" class="btn btn-primary" style="padding:5px 12px; font-size:0.8em;">✓ Letta</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($n['id_contratto']): ?>
                            <a href="<?= url('contract.php') ?>?id=<?= (int)$n['id_contratto'] ?>" class="btn btn-gray" style="padding:5px 12px; font-size:0.8em;">📂 Apri</a>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Eliminare?')">
                            <?= Security::csrfField() ?>
                            <button type="submit" name="delete_id" value="<?= (int)$n['id'] ?>" class="btn btn-red" style="padding:5px 12px; font-size:0.8em;">🗑</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script src="assets/uppercase.js"></script>
</body>
</html>
