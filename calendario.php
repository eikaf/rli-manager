<?php
session_start();
include 'db.php';
require_once 'config.php';
require_once 'lib/NotificaRepository.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth->requireLogin();

// === FILTRO VISIBILITÀ ===
$visFilter = '';
$visParams = [];
if (!$auth->isAdmin()) {
    $visFilter = " AND (
        c.id_utente_creatore = :vis_id_utente
        OR EXISTS (SELECT 1 FROM contratti_condivisioni ccv WHERE ccv.id_contratto = c.id AND ccv.id_utente = :vis_id_utente2)
    )";
    $visParams[':vis_id_utente'] = $auth->id();
    $visParams[':vis_id_utente2'] = $auth->id();
}

$notifRepo = new NotificaRepository($pdo);
$notifPendenti = $notifRepo->countPendenti($auth);

// Mese di visualizzazione
$anno = isset($_GET['anno']) ? (int)$_GET['anno'] : (int)date('Y');
$mese = isset($_GET['mese']) ? (int)$_GET['mese'] : (int)date('n');
$anno = max(2020, min(2100, $anno));
$mese = max(1, min(12, $mese));

$primoDelMese = sprintf('%04d-%02d-01', $anno, $mese);
$ultimoDelMese = date('Y-m-t', strtotime($primoDelMese));

// Eventi del mese (contratti con scadenza + eventi registrati + notifiche)
$eventi = [];

// Contratti in scadenza
$stmt = $pdo->prepare("
    SELECT c.id, c.id_contratto_univoco, c.durata_al, c.stato,
           (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
            FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
            WHERE cl.id_contratto = c.id ORDER BY cl.richiedente DESC LIMIT 1) AS locatore,
           (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
            FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
            WHERE cc.id_contratto = c.id LIMIT 1) AS conduttore
    FROM contratti c
    WHERE c.durata_al BETWEEN :dal AND :al $visFilter
");
$paramsScadenze = array_merge([':dal' => $primoDelMese, ':al' => $ultimoDelMese], $visParams);
$stmt->execute($paramsScadenze);
foreach ($stmt->fetchAll() as $r) {
    $eventi[$r['durata_al']][] = [
        'tipo'  => 'scadenza',
        'label' => 'Scadenza contratto #' . $r['id'],
        'desc'  => ($r['locatore'] ?: '?') . ' / ' . ($r['conduttore'] ?: '?'),
        'id'    => $r['id'],
        'stato' => $r['stato'],
    ];
}

// Eventi registrati
$stmt = $pdo->prepare("
    SELECT e.id, e.tipo, e.data_evento, e.id_contratto, e.dettagli_json,
           c.id_contratto_univoco
    FROM eventi_contratto e
    JOIN contratti c ON c.id = e.id_contratto
    WHERE e.data_evento BETWEEN :dal AND :al $visFilter
    ORDER BY e.data_evento ASC
");
$paramsEventi = array_merge([':dal' => $primoDelMese, ':al' => $ultimoDelMese], $visParams);
$stmt->execute($paramsEventi);
foreach ($stmt->fetchAll() as $r) {
    $eventi[$r['data_evento']][] = [
        'tipo'  => 'evento',
        'label' => rli_evento_label($r['tipo']),
        'desc'  => $r['id_contratto_univoco'] ?: ('Contratto #' . $r['id_contratto']),
        'id'    => $r['id_contratto'],
        'evt'   => $r['tipo'],
    ];
}

// Notifiche
$notifiche = $notifRepo->perRange($primoDelMese, $ultimoDelMese, $auth);
foreach ($notifiche as $n) {
    if (!$n['data_scadenza']) continue;
    $eventi[$n['data_scadenza']][] = [
        'tipo'   => 'notifica',
        'label'  => '🔔 ' . mb_substr($n['messaggio'], 0, 60) . '…',
        'desc'   => $n['id_contratto_univoco'] ?: '',
        'id'     => $n['id_contratto'],
        'prio'   => $n['priorita'],
        'letta'  => $n['letta'],
    ];
}

// Calendario
$primoTs = strtotime($primoDelMese);
$giornoSettimanaIniziale = (int)date('N', $primoTs); // 1=lun ... 7=dom
$giorniNelMese = (int)date('t', $primoTs);
$mesePrec = $mese - 1; $annoPrec = $anno;
if ($mesePrec < 1) { $mesePrec = 12; $annoPrec--; }
$meseSucc = $mese + 1; $annoSucc = $anno;
if ($meseSucc > 12) { $meseSucc = 1; $annoSucc++; }

$mesiIt = [1=>'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
$oggi = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="it">
<head>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Calendario — RLI Manager</title>
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
        .btn { padding: 9px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-gray { background: #e2e8f0; color: #2d3748; }

        .cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .cal-header h2 { margin: 0; color: #2d3748; }
        .cal-nav a { padding: 8px 16px; background: #4299e1; color: #fff; border-radius: 6px; text-decoration: none; font-weight: bold; margin: 0 5px; }

        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: #e2e8f0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        .cal-dow { background: #1a202c; color: #fff; padding: 10px; text-align: center; font-weight: bold; font-size: 0.85em; text-transform: uppercase; }
        .cal-day { background: #fff; min-height: 110px; padding: 8px; position: relative; cursor: pointer; transition: background 0.15s; }
        .cal-day:hover { background: #f0f9ff; }
        .cal-day.empty { background: #f7fafc; cursor: default; }
        .cal-day.today { background: #fffbea; box-shadow: inset 0 0 0 2px #ed8936; }
        .cal-day .day-num { font-weight: bold; color: #2d3748; font-size: 1em; }
        .cal-day .dots { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 6px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot.scadenza { background: #ed8936; }
        .dot.evento { background: #4299e1; }
        .dot.notifica { background: #48bb78; }
        .dot.critical { background: #f56565; }
        .dot.scaduto { background: #742a2a; }
        .day-events { margin-top: 4px; font-size: 0.72em; color: #4a5568; line-height: 1.25; max-height: 60px; overflow: hidden; }
        .day-events .evt-line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .legend { display: flex; gap: 20px; margin-top: 15px; font-size: 0.85em; color: #4a5568; }
        .legend .dot { display: inline-block; margin-right: 6px; vertical-align: middle; }

        /* Modal */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 9999; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 12px; padding: 25px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .modal h3 { margin-top: 0; color: #2b6cb0; }
        .modal-close { float: right; cursor: pointer; font-size: 1.5em; line-height: 1; color: #a0aec0; border: none; background: none; }
        .modal-close:hover { color: #f56565; }
        .evt-item { padding: 12px; border-left: 4px solid #cbd5e0; background: #f7fafc; border-radius: 6px; margin-bottom: 10px; }
        .evt-item.scadenza { border-color: #ed8936; }
        .evt-item.evento { border-color: #4299e1; }
        .evt-item.notifica { border-color: #48bb78; }
        .evt-item.critical { border-color: #f56565; background: #fff5f5; }
    </style>
</head>
<body>

<?php include 'lib/Topbar.php'; ?>

<div class="container">
    <div class="card">
        <div class="cal-header">
            <h2><?= $mesiIt[$mese] . ' ' . $anno ?></h2>
            <div class="cal-nav">
                <a href="?anno=<?= $annoPrec ?>&mese=<?= $mesePrec ?>">← Prec</a>
                <a href="?anno=<?= date('Y') ?>&mese=<?= date('n') ?>">Oggi</a>
                <a href="?anno=<?= $annoSucc ?>&mese=<?= $meseSucc ?>">Succ →</a>
            </div>
        </div>

        <div class="cal-grid">
            <div class="cal-dow">Lun</div>
            <div class="cal-dow">Mar</div>
            <div class="cal-dow">Mer</div>
            <div class="cal-dow">Gio</div>
            <div class="cal-dow">Ven</div>
            <div class="cal-dow">Sab</div>
            <div class="cal-dow">Dom</div>

            <?php
            // Celle vuote prima del primo giorno
            for ($i = 1; $i < $giornoSettimanaIniziale; $i++) {
                echo '<div class="cal-day empty"></div>';
            }
            // Giorni del mese
            for ($g = 1; $g <= $giorniNelMese; $g++) {
                $dataStr = sprintf('%04d-%02d-%02d', $anno, $mese, $g);
                $isOggi = ($dataStr === $oggi);
                $giornoEventi = $eventi[$dataStr] ?? [];
                $class = 'cal-day' . ($isOggi ? ' today' : '');
                echo '<div class="' . $class . '" data-date="' . $dataStr . '" onclick="openDay(\'' . $dataStr . '\')">';
                echo '<div class="day-num">' . $g . '</div>';

                if (!empty($giornoEventi)) {
                    // Dots
                    $seen = [];
                    echo '<div class="dots">';
                    foreach ($giornoEventi as $ev) {
                        $dotClass = $ev['tipo'];
                        if ($ev['tipo'] === 'notifica' && ($ev['prio'] ?? '') === 'critical') $dotClass = 'critical';
                        if (isset($seen[$dotClass])) continue;
                        $seen[$dotClass] = true;
                        echo '<span class="dot ' . $dotClass . '"></span>';
                    }
                    echo '</div>';

                    // Prime 2 righe di eventi
                    echo '<div class="day-events">';
                    foreach (array_slice($giornoEventi, 0, 2) as $ev) {
                        echo '<div class="evt-line">' . Security::h(mb_substr($ev['label'], 0, 25)) . '</div>';
                    }
                    if (count($giornoEventi) > 2) {
                        echo '<div class="evt-line" style="color:#a0aec0;">+' . (count($giornoEventi) - 2) . ' altri</div>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
            // Celle vuote dopo
            $totCelle = $giornoSettimanaIniziale - 1 + $giorniNelMese;
            $resto = (7 - ($totCelle % 7)) % 7;
            for ($i = 0; $i < $resto; $i++) {
                echo '<div class="cal-day empty"></div>';
            }
            ?>
        </div>

        <div class="legend">
            <span><span class="dot scadenza"></span> Scadenza contratto</span>
            <span><span class="dot evento"></span> Evento registrato</span>
            <span><span class="dot notifica"></span> Notifica</span>
            <span><span class="dot critical"></span> Notifica critica</span>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="modal" onclick="if(event.target===this) closeModal()">
    <div class="modal">
        <button class="modal-close" onclick="closeModal()">×</button>
        <h3 id="modal-title">Eventi</h3>
        <div id="modal-body"></div>
    </div>
</div>

<script>
const EVENTI = <?= json_encode($eventi, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function openDay(dateStr) {
    const evs = EVENTI[dateStr] || [];
    if (evs.length === 0) {
        alert('Nessun evento il ' + dateStr);
        return;
    }

    const [y, m, d] = dateStr.split('-');
    document.getElementById('modal-title').textContent = 'Eventi del ' + d + '/' + m + '/' + y + ' (' + evs.length + ')';

    let html = '';
    evs.forEach(ev => {
        let cls = ev.tipo;
        if (ev.tipo === 'notifica' && ev.prio === 'critical') cls = 'critical';
        html += '<div class="evt-item ' + cls + '">';
        html += '<strong>' + escapeHtml(ev.label) + '</strong>';
        if (ev.desc) html += '<div style="font-size:0.85em; color:#718096; margin-top:4px;">' + escapeHtml(ev.desc) + '</div>';
        if (ev.id) html += '<div style="margin-top:8px;"><a href="<?= url('contract.php') ?>?id=' + ev.id + '" class="btn btn-primary" style="padding:5px 12px; font-size:0.8em;">📂 Apri contratto</a></div>';
        html += '</div>';
    });

    document.getElementById('modal-body').innerHTML = html;
    document.getElementById('modal').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
<script src="assets/uppercase.js"></script>
</body>
</html>
