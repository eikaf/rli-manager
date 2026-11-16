<?php
/**
 * Auth — Gestione autenticazione multi-utente.
 *
 * Ruoli:
 *   - admin: accesso completo, vede tutto
 *   - operator: vede solo i propri contratti + condivisi
 *   - viewer: come operator ma solo lettura
 */
class Auth
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Tenta il login. Ritorna l'utente se OK, null altrimenti.
     */
    public function login(string $username, string $password): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM utenti WHERE username = ? AND attivo = 1 LIMIT 1");
        $stmt->execute([$username]);
        $utente = $stmt->fetch();

        if (!$utente) {
            return null;
        }

        if (!password_verify($password, $utente['password_hash'])) {
            return null;
        }

        // Aggiorna ultimo accesso
        $this->pdo->prepare("UPDATE utenti SET ultimo_accesso = NOW() WHERE id = ?")
            ->execute([$utente['id']]);

        // Salva in sessione
        $_SESSION['utente_id'] = (int)$utente['id'];
        $_SESSION['utente_username'] = $utente['username'];
        $_SESSION['utente_nome'] = $utente['nome'];
        $_SESSION['utente_cognome'] = $utente['cognome'];
        $_SESSION['utente_ruolo'] = $utente['ruolo'];
        $_SESSION['utente_email'] = $utente['email'];
        $_SESSION['utente_tema'] = in_array(($utente['tema'] ?? 'light'), ['light', 'dark', 'auto'], true)
            ? $utente['tema']
            : 'light';

        // Rigenera ID sessione (previene session fixation)
        session_regenerate_id(true);

        Logger::azione('login_ok', 'utente', (int)$utente['id'], [
            'username' => $username,
        ]);

        return $utente;
    }

    public function logout(): void
    {
        $id = $this->id();
        if ($id) {
            Logger::azione('logout', 'utente', $id);
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]);
        }
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['utente_id']);
    }

    public function id(): ?int
    {
        return $_SESSION['utente_id'] ?? null;
    }

    public function username(): ?string
    {
        return $_SESSION['utente_username'] ?? null;
    }

    public function nomeCompleto(): string
    {
        if (!$this->isLoggedIn()) return '';
        return trim(($_SESSION['utente_nome'] ?? '') . ' ' . ($_SESSION['utente_cognome'] ?? ''));
    }

    public function ruolo(): ?string
    {
        return $_SESSION['utente_ruolo'] ?? null;
    }

    public function isAdmin(): bool
    {
        return $this->ruolo() === 'admin';
    }

    public function isOperator(): bool
    {
        return in_array($this->ruolo(), ['admin', 'operator'], true);
    }

    public function isViewer(): bool
    {
        return $this->ruolo() === 'viewer';
    }

    /**
     * Richiede che l'utente sia loggato. Altrimenti redirect a index.php.
     */
    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: index.php');
            exit;
        }
    }

    /**
     * Richiede ruolo admin.
     */
    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            http_response_code(403);
            die('Accesso riservato agli amministratori.');
        }
    }

    /**
     * Verifica che l'utente possa modificare (non viewer).
     */
    public function requireOperator(): void
    {
        $this->requireLogin();
        if (!$this->isOperator()) {
            http_response_code(403);
            die('Azione non consentita al tuo ruolo.');
        }
    }

    /**
     * Cambia password.
     */
    public function cambiaPassword(int $idUtente, string $passwordAttuale, string $nuovaPassword): array
    {
        if (strlen($nuovaPassword) < 8) {
            return ['ok' => false, 'error' => 'La password deve essere di almeno 8 caratteri.'];
        }

        $stmt = $this->pdo->prepare("SELECT password_hash FROM utenti WHERE id = ?");
        $stmt->execute([$idUtente]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($passwordAttuale, $hash)) {
            return ['ok' => false, 'error' => 'Password attuale errata.'];
        }

        $nuovoHash = password_hash($nuovaPassword, PASSWORD_BCRYPT);
        $this->pdo->prepare("UPDATE utenti SET password_hash = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$nuovoHash, $idUtente]);

        Logger::azione('password_change', 'utente', $idUtente);

        return ['ok' => true];
    }

    /**
     * Crea un nuovo utente (solo admin).
     */
    public function creaUtente(string $username, string $password, string $nome, string $cognome, string $email, string $ruolo = 'operator'): array
    {
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password minimo 8 caratteri.'];
        }
        if (!in_array($ruolo, ['admin', 'operator', 'viewer'], true)) {
            return ['ok' => false, 'error' => 'Ruolo non valido.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email non valida.'];
        }

        // Verifica unicità username/email
        $stmt = $this->pdo->prepare("SELECT id FROM utenti WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'Username o email già in uso.'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->pdo->prepare("
            INSERT INTO utenti (username, password_hash, nome, cognome, email, ruolo)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$username, $hash, $nome, $cognome, $email, $ruolo]);

        $id = (int)$this->pdo->lastInsertId();
        Logger::azione('utente_create', 'utente', $id, ['username' => $username, 'ruolo' => $ruolo]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Ritorna la lista utenti (solo admin).
     */
    public function listaUtenti(): array
    {
        return $this->pdo->query("
            SELECT id, username, nome, cognome, email, ruolo, attivo, ultimo_accesso, created_at
            FROM utenti
            ORDER BY ruolo, cognome, nome
        ")->fetchAll();
    }

    /**
     * Calcola il permesso effettivo dell'utente corrente su un contratto.
     *
     * Ritorna:
     *   - 'admin'  : utente admin (permessi totali)
     *   - 'owner'  : proprietario del contratto (permessi totali)
     *   - 'edit'   : condivisione con permesso edit
     *   - 'view'   : condivisione con permesso view
     *   - null     : nessun accesso
     */
    public function permessoContratto(int $idContratto): ?string
    {
        if (!$this->isLoggedIn()) return null;

        if ($this->isAdmin()) return 'admin';

        // Verifica se è proprietario
        $stmt = $this->pdo->prepare("SELECT id_utente_creatore FROM contratti WHERE id = ?");
        $stmt->execute([$idContratto]);
        $idProprietario = $stmt->fetchColumn();

        if ($idProprietario !== false && (int)$idProprietario === $this->id()) {
            return 'owner';
        }

        // Verifica condivisione
        $stmt = $this->pdo->prepare("
            SELECT permesso FROM contratti_condivisioni
            WHERE id_contratto = ? AND id_utente = ?
            LIMIT 1
        ");
        $stmt->execute([$idContratto, $this->id()]);
        $permesso = $stmt->fetchColumn();

        return $permesso ?: null;
    }

    /**
     * Verifica se l'utente può modificare il contratto (owner, admin o edit).
     */
    public function puoModificareContratto(int $idContratto): bool
    {
        return in_array($this->permessoContratto($idContratto), ['admin', 'owner', 'edit'], true);
    }

    /**
     * Verifica se l'utente può vedere il contratto (qualsiasi permesso).
     */
    public function puoVedereContratto(int $idContratto): bool
    {
        return $this->permessoContratto($idContratto) !== null;
    }
}
