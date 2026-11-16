<?php
/**
 * profilo.php — Profilo personale utente.
 */
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';
require_once 'lib/Crypto.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

$stmt = $pdo->prepare("SELECT * FROM utenti WHERE id = ?");
$stmt->execute([$auth->id()]);
$utente = $stmt->fetch();
if (!$utente) die('Utente non trovato.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();

    try {
        // AGGIORNA DATI ANAGRAFICI
        if (isset($_POST['update_anagrafica'])) {
            $nome = trim($_POST['nome'] ?? '');
            $cognome = trim($_POST['cognome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            $smtp_host = trim($_POST['smtp_host'] ?? '');
            $smtp_port = !empty($_POST['smtp_port']) ? (int)$_POST['smtp_port'] : null;
            $smtp_user = trim($_POST['smtp_user'] ?? '');
            $smtp_pass = trim($_POST['smtp_pass'] ?? '');
            $smtp_from = trim($_POST['smtp_from'] ?? '');
            $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');
            $cf_intermediario = strtoupper(trim($_POST['cf_intermediario'] ?? ''));

            $tema = $_POST['tema'] ?? 'light';
            if (!in_array($tema, ['light', 'dark', 'auto'], true)) {
                $tema = 'light';
            }

            if ($nome === '' || $cognome === '') throw new Exception('Nome e cognome obbligatori.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email non valida.');

            $stmt = $pdo->prepare("SELECT id FROM utenti WHERE email = ? AND id != ? LIMIT 1");
            $stmt->execute([$email, $auth->id()]);
            if ($stmt->fetchColumn()) throw new Exception('Email già in uso da un altro utente.');

            $stmt = $pdo->prepare("SELECT smtp_pass FROM utenti WHERE id = ?");
            $stmt->execute([$auth->id()]);
            $old_smtp_pass = $stmt->fetchColumn();
            
            if ($smtp_pass === '') {
                $smtp_pass = $old_smtp_pass;
            } else {
                // Cifratura AES-256-GCM con prefisso enc:v1:
                $smtp_pass = Crypto::encrypt($smtp_pass);
            }

            $pdo->prepare("
                UPDATE utenti SET
                    nome = ?, cognome = ?, email = ?, cf_intermediario = ?,
                    smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, smtp_from = ?, smtp_from_name = ?,
                    tema = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $nome, $cognome, $email, $cf_intermediario ?: null,
                $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_from, $smtp_from_name,
                $tema,
                $auth->id()
            ]);

            $_SESSION['utente_nome'] = $nome;
            $_SESSION['utente_cognome'] = $cognome;
            $_SESSION['utente_email'] = $email;
            $_SESSION['utente_tema'] = $tema;

            Logger::azione('profilo_update', 'utente', $auth->id(), ['nome' => $nome, 'email' => $email, 'smtp_host' => $smtp_host]);
            header('Location: profilo.php?msg=' . urlencode('Dati aggiornati'));
            exit;
        }

        // CAMBIO PASSWORD
        if (isset($_POST['change_password'])) {
            $passAttuale = $_POST['password_attuale'] ?? '';
            $passNuova = $_POST['password_nuova'] ?? '';
            $passConferma = $_POST['password_conferma'] ?? '';

            if ($passAttuale === '' || $passNuova === '' || $passConferma === '') throw new Exception('Tutti i campi password sono obbligatori.');
            if (!password_verify($passAttuale, $utente['password_hash'])) throw new Exception('Password attuale errata.');
            if ($passNuova !== $passConferma) throw new Exception('Le due nuove password non coincidono.');
            if (strlen($passNuova) < 8) throw new Exception('Nuova password: minimo 8 caratteri.');
            if (!preg_match('/[A-Z]/', $passNuova)) throw new Exception('Nuova password deve contenere almeno una lettera maiuscola.');
            if (!preg_match('/[0-9]/', $passNuova)) throw new Exception('Nuova password deve contenere almeno un numero.');
            if ($passNuova === $passAttuale) throw new Exception('La nuova password deve essere diversa da quella attuale.');

            $nuovoHash = password_hash($passNuova, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE utenti SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$nuovoHash, $auth->id()]);

            Logger::azione('profilo_password_change', 'utente', $auth->id());
            header('Location: profilo.php?msg=' . urlencode('Password cambiata con successo'));
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

function labelRuolo(string $ruolo): string {
    return ['admin' => '👑 Admin', 'operator' => '👤 Operatore', 'viewer' => '👁 Viewer'][$ruolo] ?? $ruolo;
}
?>
<!DOCTYPE html>
<html lang="it"<?= Theme::htmlAttr() ?>>
<head>
    <base href="<?= url('/') ?>">
    <meta charset="UTF-8">
    <title>Il mio profilo — RLI Manager</title>
    <link rel="stylesheet" href="assets/uppercase.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f7fafc; margin: 0; padding: 0; color: #2d3748; }
        .container { max-width: 800px; margin: 0 auto; padding: 25px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); margin-bottom: 20px; }
        h2 { color: #2b6cb0; margin-top: 0; }
        h3 { color: #4a5568; margin-top: 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; font-size: 1.1em; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9em; color: #4a5568; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; }
        input:focus, select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        select.theme-select { margin-bottom: 16px; }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        .info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9em; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #edf2f7; font-size: 0.9em; }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: #718096; }
        .info-row .val { color: #2d3748; font-weight: 600; }
        .hint { color: #718096; font-size: 0.8em; margin-top: -8px; margin-bottom: 10px; display: block; }
        .pwd-wrapper { position: relative; }
        .pwd-wrapper input { padding-right: 40px; }
        .pwd-toggle { position: absolute; right: 10px; top: 10px; background: none; border: none; cursor: pointer; font-size: 1.1em; color: #a0aec0; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if (isset($error)) echo "<div class='alert alert-error'>⚠ " . Security::h($error) . "</div>"; ?>

    <h2>👤 Il mio profilo</h2>

    <!-- INFO ACCOUNT -->
    <div class="card">
        <h3>📋 Informazioni account</h3>
        <div class="info-row"><span class="lbl">Username</span><span class="val"><?= Security::h($utente['username']) ?></span></div>
        <div class="info-row"><span class="lbl">Ruolo</span><span class="val"><?= Security::h(labelRuolo($utente['ruolo'])) ?></span></div>
        <div class="info-row"><span class="lbl">Ultimo accesso</span><span class="val"><?= $utente['ultimo_accesso'] ? date('d/m/Y H:i', strtotime($utente['ultimo_accesso'])) : 'mai' ?></span></div>
        <div class="info-row"><span class="lbl">Account creato il</span><span class="val"><?= date('d/m/Y H:i', strtotime($utente['created_at'])) ?></span></div>
    </div>

    <!-- DATI ANAGRAFICI -->
    <div class="card">
        <h3>✏️ Dati personali</h3>
        <form method="POST" autocomplete="off">
            <?= Security::csrfField() ?>

            <div class="row">
                <div><label>Nome *</label><input type="text" name="nome" required value="<?= Security::h($utente['nome']) ?>"></div>
                <div><label>Cognome *</label><input type="text" name="cognome" required value="<?= Security::h($utente['cognome']) ?>"></div>
            </div>

            <label>Email *</label>
            <input type="email" name="email" required value="<?= Security::h($utente['email']) ?>">

            <label>CF Intermediario (opzionale)</label>
            <input type="text" name="cf_intermediario" maxlength="16" class="no-uppercase"
                   value="<?= Security::h($utente['cf_intermediario'] ?? '') ?>"
                   placeholder="es. RSSMRA80A01H501U">
            <span class="hint">Se compilato, questo CF verrà usato nell'XML RLI per l'Impegno a Trasmettere. Se vuoto, verrà usato il CF di default (config.php).</span>

            <h4 style="margin-top:30px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">📧 Configurazione Email (SMTP)</h4>
            <p style="font-size:0.85em; color:#718096; margin-bottom: 15px;">Compila questi campi se vuoi inviare email dal tuo indirizzo personale. Se lasci vuoto, verranno usate le credenziali di sistema.</p>

            <div class="row">
                <div style="flex: 2;">
                    <label>Server SMTP (es. smtp.gmail.com)</label>
                    <input type="text" name="smtp_host" class="no-uppercase" value="<?= Security::h($utente['smtp_host'] ?? '') ?>">
                </div>
                <div>
                    <label>Porta (es. 587, 465, 25)</label>
                    <input type="number" name="smtp_port" class="no-uppercase" value="<?= Security::h($utente['smtp_port'] ?? '587') ?>">
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Username SMTP</label>
                    <input type="text" name="smtp_user" class="no-uppercase" value="<?= Security::h($utente['smtp_user'] ?? '') ?>">
                </div>
                <div>
                    <label>Password SMTP (lascia vuoto per non cambiare)</label>
                    <div class="pwd-wrapper">
                        <input type="password" name="smtp_pass" id="smtp_pass" class="no-uppercase" placeholder="<?= !empty($utente['smtp_pass']) ? '••••••••' : '' ?>">
                        <button type="button" class="pwd-toggle" onclick="togglePassword('smtp_pass', this)">👁</button>
                    </div>
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Indirizzo "Da" (From)</label>
                    <input type="email" name="smtp_from" class="no-uppercase" value="<?= Security::h($utente['smtp_from'] ?? '') ?>">
                </div>
                <div>
                    <label>Nome "Da" (es. Studio Rossi)</label>
                    <input type="text" name="smtp_from_name" value="<?= Security::h($utente['smtp_from_name'] ?? '') ?>">
                </div>
            </div>

            <h4 style="margin-top:30px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">🎨 Aspetto</h4>
            <label>Tema dell'interfaccia</label>
            <select name="tema" class="theme-select">
                <?php foreach (['light', 'dark', 'auto'] as $t): ?>
                    <option value="<?= $t ?>" <?= (($utente['tema'] ?? 'light') === $t) ? 'selected' : '' ?>><?= Theme::label($t) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint">"Automatico" segue l'impostazione chiara/scura del sistema operativo.</span>

            <button type="submit" name="update_anagrafica" value="1" class="btn btn-green">💾 Salva modifiche</button>
        </form>
    </div>

    <!-- CAMBIO PASSWORD -->
    <div class="card">
        <h3>🔒 Cambio password</h3>

        <div class="info-box">
            <strong>Requisiti password:</strong>
            <ul style="margin: 8px 0 0 20px; padding: 0;">
                <li>Minimo 8 caratteri</li>
                <li>Almeno una <strong>lettera maiuscola</strong></li>
                <li>Almeno un <strong>numero</strong></li>
            </ul>
        </div>

        <form method="POST" autocomplete="off">
            <?= Security::csrfField() ?>

            <label>Password attuale *</label>
            <div class="pwd-wrapper">
                <input type="password" name="password_attuale" id="pwd_attuale" required autocomplete="current-password">
                <button type="button" class="pwd-toggle" onclick="togglePassword('pwd_attuale', this)">👁</button>
            </div>

            <div class="row">
                <div>
                    <label>Nuova password *</label>
                    <div class="pwd-wrapper">
                        <input type="password" name="password_nuova" id="pwd_nuova" required minlength="8" autocomplete="new-password">
                        <button type="button" class="pwd-toggle" onclick="togglePassword('pwd_nuova', this)">👁</button>
                    </div>
                    <span class="hint">Minimo 8 caratteri, 1 maiuscola, 1 numero.</span>
                </div>
                <div>
                    <label>Conferma nuova password *</label>
                    <div class="pwd-wrapper">
                        <input type="password" name="password_conferma" id="pwd_conferma" required minlength="8" autocomplete="new-password">
                        <button type="button" class="pwd-toggle" onclick="togglePassword('pwd_conferma', this)">👁</button>
                    </div>
                </div>
            </div>

            <button type="submit" name="change_password" value="1" class="btn btn-primary">🔒 Cambia password</button>
        </form>
    </div>
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
<script src="assets/uppercase.js"></script>
</body>
</html>
