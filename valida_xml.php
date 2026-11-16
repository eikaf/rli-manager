<?php
/**
 * valida_xml.php — Endpoint AJAX per validare l'XML del contratto contro XSD.
 * Non genera file: restituisce solo l'esito della validazione.
 */
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!$auth->isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}

if (!Security::validateCsrf()) {
    echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido. Ricarica la pagina.']);
    exit;
}

$idContratto = (int)($_POST['id_contratto'] ?? 0);
$idEvento    = (int)($_POST['id_evento'] ?? 0);

if ($idContratto <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID contratto mancante']);
    exit;
}

$repo = new ContrattoRepository($pdo);
if (!$repo->canAccess($idContratto, $auth)) {
    echo json_encode(['ok' => false, 'error' => 'Non hai accesso a questo contratto']);
    exit;
}

try {
    $contratto = $repo->find($idContratto);
    if (!$contratto) {
        throw new RuntimeException('Contratto non trovato');
    }

    if ($idEvento > 0) {
        $stmt = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
        $stmt->execute([$idEvento, $idContratto]);
        $evento = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id_contratto = ? ORDER BY data_evento DESC, id DESC LIMIT 1");
        $stmt->execute([$idContratto]);
        $evento = $stmt->fetch();
    }

    $payload = $repo->prepareRliPayload($idContratto, $evento ?: null, $auth->id());
    $pratica = $payload['pratica'];

    $builder = new XmlBuilder($payload);
    $xml = $builder->build();

    $result = XsdValidator::validate($xml);

    echo json_encode([
        'ok'       => true,
        'valid'    => $result['ok'],
        'errors'   => $result['errors'],
        'warnings' => $result['warnings'],
        'xsd'      => basename($result['xsd'] ?? ''),
        'size'     => strlen($xml),
        'tipo_adempimento' => (int)($pratica['tipologia_adempimento'] ?? 1),
    ]);

} catch (Throwable $e) {
    Logger::errore('valida_xml', $e->getMessage(), $idContratto);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
