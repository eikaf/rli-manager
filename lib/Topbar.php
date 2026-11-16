<?php
/**
 * Topbar centralizzata per RLI Manager.
 */
if (!isset($auth)) die("Auth non disponibile per la Topbar.");
$currentPage = basename($_SERVER['PHP_SELF']);

function topbar_link($href, $label, $currentPage) {
    $isActive = (strpos($currentPage, $href) !== false || $currentPage === $href) ? ' active' : '';
    return '<a href="' . url($href) . '" class="' . trim($isActive) . '">' . $label . '</a>';
}
?>
<link rel="stylesheet" href="<?= url('assets/theme.css') ?>?v=9">
<script>
(function () {
    var t = <?= json_encode(class_exists('Theme') ? Theme::current() : ($_SESSION['utente_tema'] ?? 'light')) ?>;
    if (t === 'auto') {
        t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', t);
})();
</script>
<style>
    .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #1a202c;
        padding: 0 15px;
        height: 64px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        position: sticky;
        top: 0;
        z-index: 1000;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        width: 100%;
        box-sizing: border-box;
        overflow: visible; /* Importante per non tagliare i menu a tendina */
    }
    .topbar .brand {
        color: #fff;
        font-size: 1.1em;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
        flex-shrink: 0; /* Non si restringe mai */
    }
    .maint-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f56565;
        color: #fff;
        font-size: 0.6em;
        font-weight: bold;
        padding: 3px 8px;
        border-radius: 12px;
        letter-spacing: 0.5px;
        animation: pulse-badge 2s infinite;
        margin-left: 8px;
    }
    .maint-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        background: #fff;
        border-radius: 50%;
        animation: blink 1s infinite;
    }
    @keyframes blink {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }
    @keyframes pulse-badge {
        0%, 100% { box-shadow: 0 0 0 0 rgba(245, 101, 101, 0.7); }
        50% { box-shadow: 0 0 0 6px rgba(245, 101, 101, 0); }
    }
    .topbar nav {
        display: flex;
        align-items: center;
        height: 100%;
        gap: 0px;
        flex-wrap: nowrap;
        flex-grow: 1;
        justify-content: flex-start;
        overflow: visible; /* Non tagliare i dropdown */
    }
    .topbar nav > a, .topbar nav .dropdown > a {
        color: #a0aec0;
        text-decoration: none;
        font-size: 0.82em; /* Leggermente più piccolo per far stare tutto */
        font-weight: 500;
        padding: 0 10px; /* Padding ridotto per risparmiare spazio */
        height: 100%;
        display: flex;
        align-items: center;
        border-bottom: 3px solid transparent;
        transition: all 0.2s ease-in-out;
        white-space: nowrap;
        cursor: pointer;
        flex-shrink: 0; /* I testi non si comprimono */
    }
    .topbar nav > a:hover, .topbar nav .dropdown:hover > a {
        color: #fff;
        background: rgba(255, 255, 255, 0.05);
    }
    .topbar nav > a.active, .topbar nav .dropdown > a.active {
        color: #63b3ed;
        border-bottom-color: #63b3ed;
        font-weight: 700;
    }
    
    /* Menu a tendina generici */
    .dropdown { 
        position: relative; 
        display: flex; 
        align-items: center; 
        height: 100%; 
        z-index: 1000;
    }
    
    .dropdown-content {
        display: none;
        position: absolute;
        top: 64px; /* Altezza esatta della topbar */
        left: 0;
        background: #2d3748;
        min-width: 200px;
        box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        border-radius: 0 0 8px 8px;
        padding: 8px 0;
        z-index: 1001;
    }
    
    /* Mostra il menu al passaggio del mouse */
    .dropdown:hover .dropdown-content { 
        display: block; 
    }
    
    .dropdown-content a {
        padding: 10px 16px;
        display: block;
        color: #e2e8f0;
        text-decoration: none;
        font-size: 0.9em;
        transition: background 0.2s;
        height: auto;
        border-bottom: none;
    }
    
    .dropdown-content a:hover { 
        background: #4a5568; 
        color: #fff; 
    }
    
    .dropdown-content .menu-divider { 
        height: 1px; 
        background: #4a5568; 
        margin: 6px 0; 
    }

    /* Area utente */
    .user-dropdown {
        position: relative;
        display: flex;
        align-items: center;
        margin-left: 10px;
        padding-left: 15px;
        padding-right: 5px;
        border-left: 1px solid #4a5568;
        height: 100%; /* Assicura che l'hover copra tutta l'altezza */
        cursor: pointer;
    }
    .user-link { display: flex; align-items: center; text-decoration: none; color: #fff; gap: 8px; white-space: nowrap; }
    .user-icon { font-size: 1.4em; }
    .user-details { display: flex; flex-direction: column; line-height: 1.1; }
    .user-name { font-weight: 700; font-size: 0.9em; }
    .user-role { font-size: 0.7em; color: #a0aec0; text-transform: uppercase; letter-spacing: 0.5px; }
    .user-dropdown:hover .user-name { color: #63b3ed; }
    
    .user-menu {
        display: none;
        position: absolute;
        top: 64px; /* Altezza esatta della topbar */
        right: 0;
        background: #2d3748;
        min-width: 180px;
        box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        border-radius: 0 0 8px 8px;
        padding: 8px 0;
        z-index: 1001;
    }
    
    .user-dropdown:hover .user-menu { 
        display: block; 
    }
    
    .user-menu a { 
        padding: 10px 16px; 
        display: block; 
        color: #e2e8f0; 
        text-decoration: none; 
        font-size: 0.9em; 
        transition: background 0.2s; 
    }
    
    .user-menu a:hover { 
        background: #4a5568; 
        color: #fff; 
    }
    
    .user-menu .menu-divider { 
        height: 1px; 
        background: #4a5568; 
        margin: 6px 0; 
    }
    
    .user-menu .logout-link { 
        color: #fc8181; 
    }
    
    .user-menu .logout-link:hover { 
        background: #f56565; 
        color: #fff; 
    }
</style>

<?php
// Controlla se la manutenzione è attiva (solo per admin)
$manutenzioneTopbar = false;
if ($auth->isLoggedIn() && $auth->isAdmin()) {
    try {
        $stmtM = $pdo->prepare("SELECT valore FROM impostazioni WHERE chiave = 'manutenzione'");
        $stmtM->execute();
        $manutenzioneTopbar = ($stmtM->fetchColumn() === '1');
    } catch (Throwable $e) {}
}
?>
<div class="topbar">
    <h1 class="brand">
        🏠 RLI Manager
        <?php if ($manutenzioneTopbar): ?>
            <span class="maint-badge" title="Portale in manutenzione — solo admin possono accedere">
                <span class="maint-dot"></span> MANUTENZIONE
            </span>
        <?php endif; ?>
    </h1>
    
    <nav>
        <div class="dropdown">
            <a href="<?= url('index.php') ?>" class="<?= ($currentPage === 'index.php') ? 'active' : '' ?>">📄 Contratti ▾</a>
            <div class="dropdown-content">
                <a href="<?= url('index.php') ?>">📋 Contratti censiti</a>
                <div class="menu-divider"></div>
                <a href="<?= url('contract_new.php') ?>">➕ Nuovo Contratto</a>
            </div>
        </div>

        <!-- MENU SOGGETTI -->
        <div class="dropdown">
            <a href="<?= url('soggetti.php') ?>" class="<?= ($currentPage === 'soggetti.php') ? 'active' : '' ?>">👤 Soggetti ▾</a>
            <div class="dropdown-content">
                <a href="<?= url('soggetti.php') ?>">📋 Soggetti censiti</a>
                <div class="menu-divider"></div>
                <a href="<?= url('soggetto_new.php') ?>">➕ Nuovo Soggetto</a>
            </div>
        </div>

        <!-- MENU IMMOBILI -->
        <div class="dropdown">
            <a href="<?= url('immobili.php') ?>" class="<?= ($currentPage === 'immobili.php') ? 'active' : '' ?>">🏠 Immobili ▾</a>
            <div class="dropdown-content">
                <a href="<?= url('immobili.php') ?>">📋 Immobili censiti</a>
                <div class="menu-divider"></div>
                <a href="<?= url('immobile_new.php') ?>">➕ Nuovo Immobile</a>
            </div>
        </div>

        <?= topbar_link('proprieta.php', '🏘️ Proprietà', $currentPage) ?>
        <?= topbar_link('calendario.php', '📅 Calendario', $currentPage) ?>
        <?= topbar_link('dati.php', '📊 Dati', $currentPage) ?>
        <?= topbar_link('comunicazioni.php', '📧 Comunicazioni', $currentPage) ?>
        
        <a href="<?= url('notifiche.php') ?>" style="position:relative;" class="<?= $currentPage === 'notifiche.php' ? 'active' : '' ?>">
            🔔 Notifiche
            <?php if (isset($notifPendenti) && $notifPendenti > 0): ?>
                <span style="position:absolute; top:12px; right:0px; background:#f56565; color:#fff; font-size:0.65em; font-weight:bold; padding:2px 5px; border-radius:10px; line-height:1;"><?= $notifPendenti ?></span>
            <?php endif; ?>
        </a>

        <?php if ($auth->isAdmin()): ?>
            <div class="dropdown">
                <a href="#" style="color:#f6ad55;">⚙️ Admin ▾</a>
                <div class="dropdown-content">
                    <a href="<?= url('utenti.php') ?>">👥 Gestione Utenti</a>
                    <a href="<?= url('manutenzione.php') ?>">🔧 Manutenzione</a>
                    <div class="menu-divider"></div>
                    <a href="<?= url('template_email.php') ?>">📋 Template censiti</a>
                    <a href="<?= url('template_email_new.php') ?>">➕ Nuovo Template</a>
                </div>
            </div>
        <?php endif; ?>
    </nav>

    <!-- Area Utente -->
    <div class="user-dropdown">
        <div class="user-link">
            <span class="user-icon">👤</span>
            <div class="user-details">
                <span class="user-name"><?= Security::h($auth->nomeCompleto()) ?></span>
                <span class="user-role"><?= Security::h($auth->ruolo()) ?></span>
            </div>
            <span style="color:#a0aec0; font-size:0.8em; margin-left:5px;">▼</span>
        </div>
        <div class="user-menu">
            <a href="<?= url('profilo.php') ?>">👤 Il mio Profilo</a>
            <div class="menu-divider"></div>
            <a href="<?= url('?logout=1') ?>" class="logout-link">🚪 Esci</a>
        </div>
    </div>
</div>
