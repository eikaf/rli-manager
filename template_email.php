<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/ComunicazioneRepository.php';

$auth->requireAdmin();
$comRepo = new ComunicazioneRepository($pdo);
$error = '';

// Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
    try {
        if (isset($_POST['save_template'])) {
            $id = (int)($_POST['id'] ?? 0);
            $codice = strtolower(trim($_POST['codice'] ?? ''));
            $nome = trim($_POST['nome'] ?? '');
            $oggetto = trim($_POST['oggetto'] ?? '');
            $corpo = trim($_POST['corpo'] ?? '');
            
            if ($codice === '' || $nome === '' || $oggetto === '' || $corpo === '') {
                throw new Exception('Tutti i campi sono obbligatori.');
            }
            
            $comRepo->saveTemplate([
                'id' => $id ?: null,
                'codice' => $codice,
                'nome' => $nome,
                'oggetto' => $oggetto,
                'corpo' => $corpo,
                'attivo' => 1
            ]);
            
            header('Location: template_email.php?msg=Template+salvato');
            exit;
        }
        
        if (isset($_POST['delete_id'])) {
            $id = (int)$_POST['delete_id'];
            $pdo->prepare("DELETE FROM template_email WHERE id = ?")->execute([$id]);
            header('Location: template_email.php?msg=Template+eliminato');
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Gestione modifica template
if (isset($_POST['update_template']) && !empty($_POST['update_id'])) {
    try {
        $updId = (int)$_POST['update_id'];
        $codice = trim($_POST['codice'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $oggetto = trim($_POST['oggetto'] ?? '');
        $corpo = $_POST['corpo'] ?? '';

        if ($codice === '' || $nome === '' || $oggetto === '' || $corpo === '') {
            throw new Exception('Tutti i campi sono obbligatori.');
        }

        $stmt = $pdo->prepare("UPDATE template_email SET codice = ?, nome = ?, oggetto = ?, corpo = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$codice, $nome, $oggetto, $corpo, $updId]);

        header('Location: template_email.php?msg=Template+aggiornato');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Carica template per modifica
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM template_email WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$templates = $comRepo->listaTemplate(false); // Carica tutti, anche inattivi
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Template Email — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .topbar { background: #1a202c; color: #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 1.4em; color: #fff; }
        .topbar nav a { color: #a0aec0; text-decoration: none; margin-left: 20px; font-size: 0.95em; }
        .topbar nav a:hover { color: #fff; }
        .topbar nav a.active { color: #4299e1; font-weight: bold; }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; font-family: inherit; }
        textarea { resize: vertical; min-height: 150px; font-family: monospace; }
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
    </style>
</head>
<body>
<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <h2>✉️ Gestione Template Email</h2>
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if ($error) echo "<div class='alert alert-error'>⚠ " . Security::h($error) . "</div>"; ?>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2 style="margin:0;">Template disponibili</h2>
            <a href="<?= url('template_email_new.php') ?>" class="btn btn-green">➕ Nuovo Template</a>
        </div>
        <table>
            <thead>
                <tr><th>Codice</th><th>Nome</th><th>Oggetto</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                    <tr>
                        <td><code><?= Security::h($t['codice']) ?></code></td>
                        <td><?= Security::h($t['nome']) ?></td>
                        <td><?= Security::h($t['oggetto']) ?></td>
                        <td style="text-align:right;">
                            <a href="?edit=<?= $t['id'] ?>" class="btn btn-primary" style="padding:4px 10px; font-size:0.8em;">✏️</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminare questo template?')">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
                                <button class="btn btn-red" style="padding:4px 10px; font-size:0.8em;">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($edit): ?>
    <div class="card" style="border: 2px solid #4299e1; margin-top:20px;">
        <h2>✏️ Modifica Template: <?= Security::h($edit['codice']) ?></h2>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="update_id" value="<?= (int)$edit['id'] ?>">

            <div style="display:flex; gap:15px;">
                <div style="flex:1;">
                    <label>Codice *</label>
                    <input type="text" name="codice" value="<?= Security::h($edit['codice']) ?>" required>
                </div>
                <div style="flex:2;">
                    <label>Nome *</label>
                    <input type="text" name="nome" value="<?= Security::h($edit['nome']) ?>" required>
                </div>
            </div>

            <label>Oggetto *</label>
            <input type="text" name="oggetto" value="<?= Security::h($edit['oggetto']) ?>" required>

            <label>Corpo (HTML/testo) *</label>
            <textarea name="corpo" rows="12" required style="min-height:250px;"><?= Security::h($edit['corpo']) ?></textarea>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" name="update_template" class="btn btn-green">💾 Salva Modifiche</button>
                <a href="template_email.php" class="btn btn-gray">Annulla</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
