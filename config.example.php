<?php
/**
 * config.example.php — Template di configurazione per RLI Manager.
 *
 * ISTRUZIONI:
 *   1. Copia questo file in `config.php` (oppure in `config.local.php` per
 *      sovrascrivere solo alcuni valori senza toccare il file principale).
 *   2. Sostituisci TUTTI i valori segnaposto (XXXXXXXXXXXXXXXX, cambiame, ecc.)
 *      con i valori reali del tuo ambiente.
 *   3. NON versionare mai `config.php` / `config.local.php` con i segreti reali.
 *
 * Nota: la connessione al database è definita separatamente in `db.php`
 * (vedi `db.example.php`). Questo file contiene costanti applicative,
 * chiavi di cifratura, parametri SMTP e funzioni di supporto.
 */

// Carica la configurazione locale (non versionata) PRIMA di definire le costanti,
// così può sovrascrivere RLI_STORAGE_PATH, RLI_APP_KEY, RLI_SMTP_*, ecc.
$__rli_local_config = __DIR__ . '/config.local.php';
if (is_file($__rli_local_config)) {
    require_once $__rli_local_config;
}
unset($__rli_local_config);

// === CREDENZIALI APPLICATIVE ===
// Password di accesso di fallback (meglio gestire gli utenti su DB).
define('RLI_PASSWORD', getenv('RLI_PASSWORD') ?: 'cambiame');
define('RLI_MAX_LOGIN_ATTEMPTS', 5);

// Codice Fiscale dell'intermediario/fornitore (segnaposto fittizio).
define('RLI_CF_INTERMEDIARIO', getenv('RLI_CF_INTERMEDIARIO') ?: 'XXXXXXXXXXXXXXXX');

// Parametri della fornitura telematica RLI12.
define('RLI_CODICE_FORNITURA', 'RLI12');
define('RLI_TIPO_FORNITORE', '10');
define('RLI_ID_SOFTWARE', '00000000000'); // ID software assegnato dall'Agenzia delle Entrate
define('RLI_SPAZIO_SERVIZIO', getenv('RLI_SPAZIO_SERVIZIO') ?: '');
define('RLI_MAX_PDF_BYTES', 5 * 1024 * 1024);
define('RLI_XSD_VERSION', 'RLI12_v1');
define('RLI_XSD_DATA', '2025-11-04');

// Token per l'esecuzione dei task cron (cambialo in produzione!).
define('RLI_CRON_TOKEN', getenv('RLI_CRON_TOKEN') ?: 'cambiami-token-segreto');

// === SICUREZZA ===
define('RLI_SESSION_LIFETIME', 3600 * 8);  // 8 ore
define('RLI_ENABLE_CSRF', true);
define('RLI_ENABLE_LOG', true);

// === AUTO-LOAD LIB ===
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Theme.php';

// Inizializza la sessione di sicurezza
Security::init();
Logger::setPdo($pdo ?? null);

// Imposta timeout sessione
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > RLI_SESSION_LIFETIME)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

/**
 * Aliquote e importi fissi dell'imposta di registro per tipologia contratto.
 * Restituisce [aliquota, importo_fisso].
 */
function rli_aliquota_registro(string $t): array {
    switch ($t) {
        case 'L1': return [0.02, null];
        case 'L2': return [0.014, null];
        case 'L3': return [null, 67.00];
        case 'L4': return [null, 200.00];
        case 'S1': return [0.02, null];
        case 'S2': return [0.01, null];
        case 'S3': return [null, 200.00];
        case 'T1': return [0.005, null];
        case 'T2': return [null, 67.00];
        case 'T3': return [0.02, null];
        case 'T4': return [null, 67.00];
        default: return [0.02, null];
    }
}

/**
 * Tasso di interesse legale per anno (per il calcolo degli interessi di mora).
 */
function rli_tasso_interesse_legale(string $data): float {
    $ts = strtotime($data);
    if (!$ts) return 0.02;
    $y = (int)date('Y', $ts);
    $map = [2008=>0.03,2009=>0.03,2010=>0.01,2011=>0.015,2012=>0.025,2013=>0.025,
            2014=>0.01,2015=>0.005,2016=>0.002,2017=>0.001,2018=>0.003,2019=>0.008,
            2020=>0.0005,2021=>0.0001,2022=>0.0125,2023=>0.05,2024=>0.025,2025=>0.02];
    return $map[$y] ?? 0.02;
}

/**
 * Elenco delle sigle delle province italiane (incluse le ex-province e 'EE').
 */
function rli_province_italiane(): array {
    return ['AG','AL','AN','AO','AP','AQ','AR','AT','AV','BA','BG','BI','BL','BN','BO','BR','BS','BT','BZ',
            'CA','CB','CE','CH','CI','CL','CN','CO','CR','CS','CT','CZ','EN','FC','FE','FG','FI','FM','FR',
            'GE','GO','GR','IM','IS','KR','LC','LE','LI','LO','LT','LU','MB','MC','ME','MI','MN','MO','MS',
            'MT','NA','NO','NU','OG','OR','OT','PA','PC','PD','PE','PG','PI','PN','PO','PR','PT','PU','PV',
            'PZ','RA','RC','RE','RG','RI','RM','RN','RO','SA','SI','SO','SP','SR','SS','SU','SV','TA','TE',
            'TN','TO','TP','TR','TS','TV','UD','VA','VB','VC','VE','VI','VR','VS','VT','VV',
            'EE','FU','PL','ZA'];
}

/**
 * Tipologie di contratto gestite.
 */
function rli_tipologie_contratto(): array {
    return ['L1','L2','L3','L4','S1','S2','S3','T1','T2','T3','T4'];
}

/**
 * Tipologie di immobile (principale / pertinenze).
 */
function rli_tipologie_immobile(): array {
    return [1=>'Principale', 2=>'Pertinenza congiunta', 3=>'Pertinenza disgiunta', 4=>'Pertinenza atto separato'];
}

/**
 * Mappa dei tipi di evento successivo.
 */
function rli_eventi_tipo(): array {
    return ['registrazione'=>1, 'proroga'=>2, 'cessione'=>3, 'risoluzione'=>4,
            'conguaglio'=>5, 'subentro'=>6, 'risoluzione_corrispettivo'=>7, 'rinegoziazione'=>8];
}

/**
 * Etichetta leggibile per un tipo di evento.
 */
function rli_evento_label(string $tipo): string {
    return [
        'registrazione' => '📝 Prima Registrazione',
        'proroga' => '🔄 Proroga',
        'cessione' => '🔀 Cessione',
        'risoluzione' => '❌ Risoluzione',
        'conguaglio' => '💰 Conguaglio d\'imposta',
        'subentro' => '👤 Subentro',
        'risoluzione_corrispettivo' => '❌ Risoluzione con corrispettivo',
        'rinegoziazione' => '💱 Rinegoziazione canone',
        'annualita' => '📅 Annualità successiva',
    ][$tipo] ?? $tipo;
}

// === AUTH ===
require_once __DIR__ . '/lib/Auth.php';
$auth = new Auth($pdo);

// === CONTROLLO MANUTENZIONE ===
// Se la manutenzione è attiva, blocca tutti gli utenti non-admin (tranne la pagina manutenzione_attiva.php)
if (isset($pdo) && !defined('RLI_SKIP_MAINTENANCE')) {
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $pagineConsentite = ['manutenzione_attiva.php', 'manutenzione.php'];

    if (!in_array($currentPage, $pagineConsentite, true)) {
        try {
            $stmtMaint = $pdo->prepare("SELECT valore FROM impostazioni WHERE chiave = 'manutenzione'");
            $stmtMaint->execute();
            $manutenzioneAttiva = ($stmtMaint->fetchColumn() === '1');

            if ($manutenzioneAttiva) {
                // Se l'utente non è loggato o non è admin, reindirizza alla pagina di manutenzione
                $isAdmin = $auth->isLoggedIn() && $auth->isAdmin();
                if (!$isAdmin) {
                    header('Location: ' . (function_exists('url') ? url('manutenzione_attiva.php') : 'manutenzione_attiva.php'));
                    exit;
                }
            }
        } catch (Throwable $e) {
            // Se la tabella impostazioni non esiste, ignora il controllo
        }
    }
}

// === STUDIO / AZIENDA ===
define('RLI_STUDIO_NOME', getenv('RLI_STUDIO_NOME') ?: 'Studio Professionale');

// === SMTP (opzionale) ===
// Guardati con defined() perché config.local.php (caricato sopra) può già definirli.
if (!defined('RLI_SMTP_HOST'))      define('RLI_SMTP_HOST', getenv('RLI_SMTP_HOST') ?: 'smtp.example.com');
if (!defined('RLI_SMTP_PORT'))      define('RLI_SMTP_PORT', (int)(getenv('RLI_SMTP_PORT') ?: 587));
if (!defined('RLI_SMTP_USER'))      define('RLI_SMTP_USER', getenv('RLI_SMTP_USER') ?: 'utente@example.com');
if (!defined('RLI_SMTP_PASS'))      define('RLI_SMTP_PASS', getenv('RLI_SMTP_PASS') ?: 'cambiame');
if (!defined('RLI_SMTP_FROM'))      define('RLI_SMTP_FROM', getenv('RLI_SMTP_FROM') ?: 'noreply@example.com');
if (!defined('RLI_SMTP_FROM_NAME')) define('RLI_SMTP_FROM_NAME', getenv('RLI_SMTP_FROM_NAME') ?: 'RLI Manager');

/**
 * Restituisce l'URL corretto per una risorsa, tenendo conto della sottocartella
 * in cui è installato il progetto (es. /RLI/ o /gestione/).
 */
function url(string $path = ''): string {
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($basePath === '' || $basePath === '/') $basePath = '';
    return $basePath . '/' . ltrim($path, '/');
}

// === CRITTOGRAFIA (per password SMTP e dati sensibili) ===
// Sostituisci con una chiave segreta lunga e casuale, diversa per ogni installazione.
define('RLI_CRYPT_KEY', getenv('RLI_CRYPT_KEY') ?: 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
define('RLI_CRYPT_METHOD', 'AES-256-CBC');

function encryptData($data) {
    if (empty($data)) return $data;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(RLI_CRYPT_METHOD));
    $encrypted = openssl_encrypt($data, RLI_CRYPT_METHOD, RLI_CRYPT_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptData($data) {
    if (empty($data)) return $data;
    $decoded = base64_decode($data);
    $ivLen = openssl_cipher_iv_length(RLI_CRYPT_METHOD);
    $iv = substr($decoded, 0, $ivLen);
    $encrypted = substr($decoded, $ivLen);
    return openssl_decrypt($encrypted, RLI_CRYPT_METHOD, RLI_CRYPT_KEY, 0, $iv);
}

// === STORAGE ESTERNO ALLA DOCUMENT ROOT ===
if (!defined('RLI_STORAGE_PATH')) {
    // Default: /var/lib/rli_storage in produzione (Linux); su Windows (dev) usa la temp dir.
    $__rli_default_storage = (DIRECTORY_SEPARATOR === '\\')
        ? sys_get_temp_dir() . '/rli_storage'
        : '/var/lib/rli_storage';
    define('RLI_STORAGE_PATH', getenv('RLI_STORAGE_PATH') ?: $__rli_default_storage);
    unset($__rli_default_storage);
}

// === CHIAVE APPLICATIVA (AES-256-GCM) ===
// Generare con: php -r "echo bin2hex(random_bytes(32));"
if (!defined('RLI_APP_KEY')) {
    $__rli_app_key = getenv('RLI_APP_KEY') ?: '';
    if ($__rli_app_key === '') {
        // Fallback deterministico derivato dalla chiave esistente (32 byte raw)
        $__rli_app_key = hash('sha256', defined('RLI_CRYPT_KEY') ? RLI_CRYPT_KEY : 'rli-default-key', true);
    } elseif (strlen($__rli_app_key) !== 32) {
        $__rli_app_key = hash('sha256', $__rli_app_key, true);
    }
    define('RLI_APP_KEY', $__rli_app_key);
    unset($__rli_app_key);
}

/**
 * Restituisce (e crea se necessario) un percorso dentro RLI_STORAGE_PATH.
 */
function rli_storage_path(string $sub = ''): string {
    $base = rtrim(RLI_STORAGE_PATH, '/\\');
    $path = $sub !== '' ? $base . '/' . ltrim(str_replace('\\', '/', $sub), '/') : $base;
    if (!is_dir($path)) {
        if (!@mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException("Impossibile creare la directory di storage: {$path}");
        }
    }
    return $path;
}

// === MANUTENZIONE ===
/**
 * Verifica se il portale è in manutenzione.
 */
function isManutenzioneAttiva(PDO $pdo): bool {
    try {
        $stmt = $pdo->prepare("SELECT valore FROM impostazioni WHERE chiave = 'manutenzione'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Attiva o disattiva la manutenzione.
 */
function setManutenzione(PDO $pdo, bool $attiva): void {
    $pdo->prepare("
        INSERT INTO impostazioni (chiave, valore) VALUES ('manutenzione', ?)
        ON DUPLICATE KEY UPDATE valore = VALUES(valore)
    ")->execute([$attiva ? '1' : '0']);
}
