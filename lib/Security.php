<?php
/**
 * Security — Funzioni di sicurezza centralizzate.
 *  - CSRF token generation/validation
 *  - Sanitizzazione output
 *  - Sanitizzazione input
 *  - IP e User Agent
 */
class Security
{
    private const CSRF_SESSION_KEY = '_csrf_token';
    private const CSRF_FIELD_NAME  = '_csrf';
    private const CSRF_TOKEN_BYTES = 32;

    /**
     * Inizializza la sessione di sicurezza (chiamare all'inizio di ogni pagina).
     */
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(self::CSRF_TOKEN_BYTES));
        }
    }

    /**
     * Ritorna il token CSRF corrente.
     */
    public static function csrfToken(): string
    {
        self::init();
        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * Ritorna il campo input hidden da inserire nei form.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . self::CSRF_FIELD_NAME . '" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES) . '">';
    }

    /**
     * Valida il token CSRF. Ritorna true se valido.
     * Se $_POST non è presente, prova in $_GET (utile per i link).
     */
    public static function validateCsrf(): bool
    {
        self::init();
        $token = $_POST[self::CSRF_FIELD_NAME]
              ?? $_GET[self::CSRF_FIELD_NAME]
              ?? $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? '';
        if ($token === '' || !hash_equals($_SESSION[self::CSRF_SESSION_KEY], $token)) {
            return false;
        }
        return true;
    }

    /**
     * Valida CSRF o termina con errore 403.
     * Da usare come guardia all'inizio di handler POST.
     */
    public static function requireCsrf(): void
    {
        if (!self::validateCsrf()) {
            http_response_code(403);
            if (self::isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'CSRF token non valido. Ricarica la pagina.']);
            } else {
                echo '<h1>Errore 403 — Token di sicurezza non valido</h1>';
                echo '<p>La sessione è scaduta o il form è stato inviato da una fonte non autorizzata.</p>';
                echo '<p><a href="index.php">Torna alla home</a></p>';
            }
            exit;
        }
    }

    /**
     * Ritorna true se la richiesta è AJAX (per risposte JSON).
     */
    public static function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }

    /**
     * Sanitizza output HTML. Scorciatoia per htmlspecialchars.
     */
    public static function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitizza input stringa (trim + strip control chars).
     */
    public static function clean(?string $value, int $maxLen = 1000): string
    {
        if ($value === null) return '';
        $value = trim($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if (mb_strlen($value) > $maxLen) {
            $value = mb_substr($value, 0, $maxLen);
        }
        return $value;
    }

    /**
     * Sanitizza input numerico.
     */
    public static function cleanInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') return $default;
        return (int)$value;
    }

    /**
     * Sanitizza importo (rimuove spazi, accetta virgola o punto).
     */
    public static function cleanFloat($value, float $default = 0.0): float
    {
        if ($value === null || $value === '') return $default;
        $value = str_replace([' ', ','], ['', '.'], (string)$value);
        return is_numeric($value) ? (float)$value : $default;
    }

    /**
     * Ritorna l'IP del client (considerando proxy se configurato).
     */
    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Ritorna lo User-Agent troncato.
     */
    public static function userAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }
}
