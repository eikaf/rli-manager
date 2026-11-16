<?php
/**
 * download_xml.php — Riscarica l'XML storico salvato in eventi_contratto.xml_generato.
 * Protetto da ContrattoRepository::canAccess().
 */
require_once __DIR__ . '/bootstrap.php';

$auth->requireLogin();

$idContratto = (int)($_GET['id_contratto'] ?? 0);
$idEvento    = (int)($_GET['id_evento'] ?? 0);

if ($idContratto <= 0 || $idEvento <= 0) {
    http_response_code(400);
    die('Parametri mancanti.');
}

$repo = new ContrattoRepository($pdo);
if (!$repo->canAccess($idContratto, $auth)) {
    http_response_code(403);
    die('Non hai i permessi per scaricare questo XML.');
}

$stmt = $pdo->prepare("SELECT tipo, data_evento, xml_generato FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
$stmt->execute([$idEvento, $idContratto]);
$ev = $stmt->fetch();

if (!$ev || empty($ev['xml_generato'])) {
    http_response_code(404);
    die('Nessun XML storico salvato per questo evento.');
}

$dataPerNome = $ev['data_evento'] ? date('Ymd', strtotime($ev['data_evento'])) : date('Ymd');
$nomeFile = 'RLI_' . preg_replace('/[^a-z0-9_]/i', '_', (string)$ev['tipo']) . '_' . $dataPerNome . '.xml';

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
header('Content-Length: ' . strlen($ev['xml_generato']));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $ev['xml_generato'];
exit;