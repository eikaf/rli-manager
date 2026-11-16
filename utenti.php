<?php
/**
 * utenti.php — Gestione utenti (solo admin).
 *
 * Funzionalità:
 *   - Lista utenti con ruolo, stato, ultimo accesso
 *   - Crea nuovo utente
 *   - Modifica utente (nome, cognome, email, ruolo, stato)
 *   - Reset password (admin imposta nuova password)
 *   - Attiva/disattiva utente (soft delete)
 *
 * Sicurezza:
 *   - Solo admin
 *   - CSRF su tutte le POST
 *   - Self-protection: admin non può disattivarsi/eliminarsi
 *   - Impedito declassamento dell'ultimo admin
 */
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireAdmin();

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

// === POST ACTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();

    try {
        // ------------------------------------------------------------
        // SALVA UTENTE (create o update)
        // ------------------------------------------------------------
        if (isset($_POST['save_utente'])) {
            $idUtente = (int)($_POST['id_utente'] ?? 0);
            $isUpdate = $idUtente > 0;

            $username = strtolower(trim($_POST['username'] ?? ''));
            $nome = trim($_POST['nome'] ?? '');
            $cognome = trim($_POST['cognome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $ruolo = $_POST['ruolo'] ?? 'operator';
            $attivo = !empty($_POST['attivo']) ? 1 : 0;
            $password = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            // Validazioni
            if ($username === '' || strlen($username) > 10 || !preg_match('/^[a-z0-9]+$/', $username)) {
                throw new Exception('Username non valido: solo lettere minuscole e numeri, max 10 caratteri.');
            }
            if ($nome === '' || $cognome === '') {
                throw new Exception('Nome e cognome obbligatori.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email non valida.');
            }
            if (!in_array($ruolo, ['admin', 'operator', 'viewer'], true)) {
                throw new Exception('Ruolo non valido.');
            }

            // Verifica unicità username/email (escludendo l'utente corrente in update)
            $stmt = $pdo->prepare("
                SELECT id, username, email FROM utenti 
                WHERE (username = ? OR email = ?) AND id != ?
                LIMIT 1
            ");
            $stmt->execute([$username, $email, $idUtente]);
            $conflitto = $stmt->fetch();
            if ($conflitto) {
                if (strcasecmp($conflitto['username'], $username) === 0) {
                    throw new Exception("Username già in uso da un altro utente (ID #{$conflitto['id']}).");
                }
                throw new Exception("Email già in uso da un altro utente (ID #{$conflitto['id']}).");
            }

            if ($isUpdate) {
                // Recupera utente esistente
                $stmt = $pdo->prepare("SELECT * FROM utenti WHERE id = ?");
                $stmt->execute([$idUtente]);
                $utenteOld = $stmt->fetch();
                if (!$utenteOld) throw new Exception('Utente non trovato.');

                // Self-protection: non può disattivarsi da solo
                if ($idUtente === $auth->id() && $attivo === 0) {
                    throw new Exception('Non puoi disattivare il tuo stesso account.');
                }

                // Se sta cambiando ruolo da admin a non-admin: verifica che non sia l'ultimo admin
                if ($utenteOld['ruolo'] === 'admin' && $ruolo !== 'admin') {
                    $numAdmin = (int)$pdo->query("SELECT COUNT(*) FROM utenti WHERE ruolo = 'admin' AND attivo = 1")->fetchColumn();
                    if ($numAdmin <= 1) {
                        throw new Exception('Non puoi declassare l\'ultimo admin attivo.');
                    }
                }

                // Se sta disattivando un admin: verifica che non sia l'ultimo admin attivo
                if ($utenteOld['ruolo'] === 'admin' && $attivo === 0 && $utenteOld['attivo'] == 1) {
                    $numAdmin = (int)$pdo->query("SELECT COUNT(*) FROM utenti WHERE ruolo = 'admin' AND attivo = 1")->fetchColumn();
                    if ($numAdmin <= 1) {
                        throw new Exception('Non puoi disattivare l\'ultimo admin attivo.');
                    }
                }

                // Se nuova password: valida e aggiorna
                if ($password !== '') {
                    if ($password !== $password2) throw new Exception('Le due password non coincidono.');
                    if (strlen($password) < 8) throw new Exception('Password minimo 8 caratteri.');
                    if (!preg_match('/[A-Z]/', $password)) throw new Exception('Password deve contenere almeno una lettera maiuscola.');
                    if (!preg_match('/[0-9]/', $password)) throw new Exception('Password deve contenere almeno un numero.');

                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE utenti SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$hash, $idUtente]);
                }

                $pdo->prepare("
                    UPDATE utenti SET
                        username = ?, nome = ?, cognome = ?, email = ?, ruolo = ?, attivo = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$username, $nome, $cognome, $email, $ruolo, $attivo, $idUtente]);

                Logger::azione('utente_update', 'utente', $idUtente, [
                    'username' => $username,
                    'ruolo' => $ruolo,
                    'attivo' => $attivo,
                    'password_changed' => $password !== '',
                ]);

                header('Location: utenti.php?msg=' . urlencode('Utente aggiornato'));
                exit;

            } else {
                // Creazione
                if ($password === '' || $password2 === '') {
                    throw new Exception('Password obbligatoria in creazione.');
                }
                if ($password !== $password2) throw new Exception('Le due password non coincidono.');
                if (strlen($password) < 8) throw new Exception('Password minimo 8 caratteri.');
                if (!preg_match('/[A-Z]/', $password)) throw new Exception('Password deve contenere almeno una lettera maiuscola.');
                if (!preg_match('/[0-9]/', $password)) throw new Exception('Password deve contenere almeno un numero.');

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("
                    INSERT INTO utenti (username, password_hash, nome, cognome, email, ruolo, attivo)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$username, $hash, $nome, $cognome, $email, $ruolo, $attivo]);

                $newId = (int)$pdo->lastInsertId();
                Logger::azione('utente_create', 'utente', $newId, [
                    'username' => $username,
                    'ruolo' => $ruolo,
                ]);

                header('Location: utenti.php?msg=' . urlencode('Utente creato'));
                exit;
            }
        }

        // ------------------------------------------------------------
        // RESET PASSWORD (admin imposta nuova password)
        // ------------------------------------------------------------
        if (isset($_POST['reset_password'])) {
            $idUtente = (int)$_POST['reset_password'];
            $nuovaPassword = $_POST['nuova_password'] ?? '';
            $nuovaPassword2 = $_POST['nuova_password2'] ?? '';

            if ($nuovaPassword !== $nuovaPassword2) throw new Exception('Le due password non coincidono.');
            if (strlen($nuovaPassword) < 8) throw new Exception('Password minimo 8 caratteri.');
            if (!preg_match('/[A-Z]/', $nuovaPassword)) throw new Exception('Password deve contenere almeno una lettera maiuscola.');
            if (!preg_match('/[0-9]/', $nuovaPassword)) throw new Exception('Password deve contenere almeno un numero.');

            $stmt = $pdo->prepare("SELECT username FROM utenti WHERE id = ?");
            $stmt->execute([$idUtente]);
            $username = $stmt->fetchColumn();
            if (!$username) throw new Exception('Utente non trovato.');

            $hash = password_hash($nuovaPassword, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE utenti SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$hash, $idUtente]);

            Logger::azione('password_reset_admin', 'utente', $idUtente, ['username' => $username]);

            header('Location: utenti.php?msg=' . urlencode("Password resettata per $username"));
            exit;
        }

        // ------------------------------------------------------------
        // TOGGLE ATTIVO
        // ------------------------------------------------------------
        if (isset($_POST['toggle_attivo'])) {
            $idUtente = (int)$_POST['toggle_attivo'];

            // Self-protection
            if ($idUtente === $auth->id()) {
                throw new Exception('Non puoi disattivare il tuo stesso account.');
            }

            $stmt = $pdo->prepare("SELECT username, ruolo, attivo FROM utenti WHERE id = ?");
            $stmt->execute([$idUtente]);
            $utente = $stmt->fetch();
            if (!$utente) throw new Exception('Utente non trovato.');

            $nuovoAttivo = $utente['attivo'] ? 0 : 1;

            // Se sta disattivando un admin: verifica che non sia l'ultimo
            if ($utente['ruolo'] === 'admin' && $nuovoAttivo === 0) {
                $numAdmin = (int)$pdo->query("SELECT COUNT(*) FROM utenti WHERE ruolo = 'admin' AND attivo = 1")->fetchColumn();
                if ($numAdmin <= 1) {
                    throw new Exception('Non puoi disattivare l\'ultimo admin attivo.');
                }
            }

            $pdo->prepare("UPDATE utenti SET attivo = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$nuovoAttivo, $idUtente]);

            Logger::azione($nuovoAttivo ? 'utente_activate' : 'utente_deactivate', 'utente', $idUtente, [
                'username' => $utente['username'],
            ]);

            header('Location: utenti.php?msg=' . urlencode($nuovoAttivo ? 'Utente attivato' : 'Utente disattivato'));
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// === CARICA UTENTE PER MODIFICA ===
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM utenti WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

// === LISTA UTENTI ===
$utenti = $pdo->query("
    SELECT u.*,
        (SELECT COUNT(*) FROM contratti WHERE id_utente_creatore = u.id) AS num_contratti
    FROM utenti u
    ORDER BY u.attivo DESC, u.ruolo, u.cognome, u.nome
")->fetchAll();

/**
 * Restituisce un colore per il badge del ruolo.
 */
function badgeRuolo(string $ruolo): string {
    return [
        'admin' => 'background:#fef3c7; color:#744210;',
        'operator' => 'background:#bee3f8; color:#2c5282;',
        'viewer' => 'background:#e2e8f0; color:#4a5568;',
    ][$ruolo] ?? 'background:#e2e8f0; color:#4a5568;';
}

function labelRuolo(string $ruolo): string {
    return [
        'admin' => '👑 Admin',
        'operator' => '👤 Operatore',
        'viewer' => '👁 Viewer',
    ][$ruolo] ?? $ruolo;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Utenti — RLI Manager</title>
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
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 8px; margin-bottom: 12px; font-size: 0.95em; }
        input:focus, select:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-green { background: #48bb78; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }
        .btn-red { background: #f56565; color: #fff; }
        .btn-orange { background: #ed8936; color: #fff; }
        .btn-sm { padding: 5px 12px; font-size: 0.85em; }
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #c6f6d5; color: #22543d; }
        .alert-error { background: #fed7d7; color: #742a2a; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #edf2f7; }
        th { color: #718096; font-size: 0.8em; text-transform: uppercase; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .check-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-weight: normal; }
        .check-row input { width: auto; margin: 0; }
        .hint { color: #718096; font-size: 0.8em; margin-top: -8px; margin-bottom: 10px; display: block; }
        .inactive-row { opacity: 0.55; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <?php if (isset($_GET['msg'])) echo "<div class='alert alert-success'>✓ " . Security::h($_GET['msg']) . "</div>"; ?>
    <?php if (isset($error)) echo "<div class='alert alert-error'>⚠ " . Security::h($error) . "</div>"; ?>

    <div class="card">
        <h2><?= $edit ? '✏️ Modifica utente' : '➕ Nuovo utente' ?></h2>
        <form method="POST" autocomplete="off">
            <?= Security::csrfField() ?>
            <?php if ($edit): ?>
                <input type="hidden" name="id_utente" value="<?= (int)$edit['id'] ?>">
            <?php endif; ?>

            <div class="row">
                <div>
                    <label>Username *</label>
                    <input type="text" name="username" id="username_field" required maxlength="10"
                           pattern="[a-z0-9]+"
                           class="no-uppercase"
                           value="<?= Security::h($edit['username'] ?? '') ?>"
                           placeholder="es. mario1"
                           autocomplete="off"
                           data-no-uppercase="1"
                           oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9]/g, '')">
                    <span class="hint">Solo lettere minuscole e numeri, max 10 caratteri.</span>
                </div>
                <div>
                    <label>Ruolo *</label>
                    <select name="ruolo" required>
                        <option value="operator" <?= ($edit['ruolo'] ?? 'operator') === 'operator' ? 'selected' : '' ?>>👤 Operatore</option>
                        <option value="admin" <?= ($edit['ruolo'] ?? '') === 'admin' ? 'selected' : '' ?>>👑 Admin</option>
                        <option value="viewer" <?= ($edit['ruolo'] ?? '') === 'viewer' ? 'selected' : '' ?>>👁 Viewer</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div>
                    <label>Nome *</label>
                    <input type="text" name="nome" required value="<?= Security::h($edit['nome'] ?? '') ?>">
                </div>
                <div>
                    <label>Cognome *</label>
                    <input type="text" name="cognome" required value="<?= Security::h($edit['cognome'] ?? '') ?>">
                </div>
            </div>

            <label>Email *</label>
            <input type="email" name="email" required value="<?= Security::h($edit['email'] ?? '') ?>">

            <div class="row">
                <div>
                    <label><?= $edit ? 'Nuova password (lascia vuoto per non cambiare)' : 'Password *' ?></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="pwd1" <?= $edit ? '' : 'required' ?> minlength="8" autocomplete="new-password" style="padding-right: 40px;">
                        <button type="button" onclick="togglePassword('pwd1', this)" style="position:absolute; right:10px; top:10px; background:none; border:none; cursor:pointer; font-size:1.1em; color:#a0aec0;">👁</button>
                    </div>
                    <span class="hint">Minimo 8 caratteri, almeno 1 maiuscola e 1 numero.</span>
                </div>
                <div>
                    <label><?= $edit ? 'Conferma nuova password' : 'Conferma password *' ?></label>
                    <div style="position:relative;">
                        <input type="password" name="password2" id="pwd2" <?= $edit ? '' : 'required' ?> minlength="8" autocomplete="new-password" style="padding-right: 40px;">
                        <button type="button" onclick="togglePassword('pwd2', this)" style="position:absolute; right:10px; top:10px; background:none; border:none; cursor:pointer; font-size:1.1em; color:#a0aec0;">👁</button>
                    </div>
                </div>
            </div>

            <div class="check-row">
                <input type="checkbox" name="attivo" id="attivo" value="1"
                    <?= (!$edit || $edit['attivo']) ? 'checked' : '' ?>>
                <label for="attivo" style="margin:0; cursor:pointer; font-weight:normal;">Utente attivo (può accedere)</label>
            </div>

            <div style="display:flex; gap:10px; margin-top:15px;">
                <button type="submit" name="save_utente" value="1" class="btn btn-green">
                    <?= $edit ? '💾 Salva Modifiche' : '➕ Crea Utente' ?>
                </button>
                <?php if ($edit): ?>
                    <a href="<?= url('utenti.php') ?>" class="btn btn-gray">Annulla</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Utenti registrati (<?= count($utenti) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Ruolo</th>
                    <th>Contratti</th>
                    <th>Ultimo accesso</th>
                    <th>Stato</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($utenti as $u): ?>
                    <tr class="<?= $u['attivo'] ? '' : 'inactive-row' ?>">
                        <td><strong><?= Security::h($u['username']) ?></strong>
                            <?php if ($u['id'] === $auth->id()): ?>
                                <span class="badge" style="background:#c6f6d5; color:#22543d;">TU</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Security::h($u['nome'] . ' ' . $u['cognome']) ?></td>
                        <td style="font-size:0.85em;"><?= Security::h($u['email']) ?></td>
                        <td><span class="badge" style="<?= badgeRuolo($u['ruolo']) ?>"><?= Security::h(labelRuolo($u['ruolo'])) ?></span></td>
                        <td><?= (int)$u['num_contratti'] ?></td>
                        <td style="font-size:0.85em;">
                            <?= $u['ultimo_accesso'] ? date('d/m/Y H:i', strtotime($u['ultimo_accesso'])) : '<span style="color:#a0aec0;">mai</span>' ?>
                        </td>
                        <td>
                            <?php if ($u['attivo']): ?>
                                <span class="badge" style="background:#c6f6d5; color:#22543d;">Attivo</span>
                            <?php else: ?>
                                <span class="badge" style="background:#fed7d7; color:#742a2a;">Disattivato</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="?edit=<?= (int)$u['id'] ?>" class="btn btn-primary btn-sm" title="Modifica">✏️</a>

                            <?php if ($u['id'] !== $auth->id()): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $u['attivo'] ? 'Disattivare' : 'Attivare' ?> questo utente?')">
                                    <?= Security::csrfField() ?>
                                    <button type="submit" name="toggle_attivo" value="<?= (int)$u['id'] ?>"
                                            class="btn <?= $u['attivo'] ? 'btn-orange' : 'btn-green' ?> btn-sm"
                                            title="<?= $u['attivo'] ? 'Disattiva' : 'Attiva' ?>">
                                        <?= $u['attivo'] ? '🚫' : '✓' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
