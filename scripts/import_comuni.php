<?php
/**
 * scripts/import_comuni.php
 * Import idempotente dei comuni (codice Belfiore) da JSON o CSV.
 *
 * Uso:
 *   php scripts/import_comuni.php /percorso/comuni.json
 *   php scripts/import_comuni.php /percorso/comuni.csv
 *
 * Formati supportati:
 *   JSON: [{codice_belfiore, nome, provincia, soppresso}]
 *         oppure [{nome, sigla, codiceCatastale}]  (formato comuni.json)
 *   CSV:  codice_belfiore,nome,provincia,soppresso
 *         oppure nome,sigla,codiceCatastale
 *
 * Gli Stati Esteri devono avere provincia = "EE".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Script CLI only\n");
}

require_once __DIR__ . '/../db.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Uso: php scripts/import_comuni.php <file.json|file.csv>\n");
    exit(2);
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$rows = [];

if ($ext === 'json') {
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "JSON non valido\n");
        exit(1);
    }
    foreach ($data as $r) {
        if (!is_array($r)) {
            $rows[] = null;
            continue;
        }

        // Codice Belfiore: solo stringa scalare
        $codiceRaw = $r['codice_belfiore'] ?? $r['codiceCatastale'] ?? '';
        $codice = is_string($codiceRaw) ? strtoupper(trim($codiceRaw)) : '';

        // Nome: solo stringa scalare
        $nomeRaw = $r['nome'] ?? '';
        $nome = is_string($nomeRaw) ? strtoupper(trim($nomeRaw)) : '';

        // Provincia: priorità a 'sigla' se stringa, altrimenti 'provincia' solo se stringa.
        // Nel formato comuni.json 'provincia' può essere un array associativo
        // (es. ['codice' => 'RM', 'nome' => 'Roma']): in quel caso va ignorato.
        $prov = '';
        if (isset($r['sigla']) && is_string($r['sigla'])) {
            $prov = strtoupper(trim($r['sigla']));
        } elseif (isset($r['provincia']) && is_string($r['provincia'])) {
            $prov = strtoupper(trim($r['provincia']));
        }

        $rows[] = [
            'codice_belfiore' => $codice,
            'nome'            => $nome,
            'provincia'       => $prov,
            'soppresso'       => (int)($r['soppresso'] ?? 0),
        ];
    }
} elseif ($ext === 'csv') {
    $fh = fopen($file, 'r');
    if (!$fh) {
        fwrite(STDERR, "Impossibile aprire il file\n");
        exit(1);
    }
    $header = fgetcsv($fh);
    $map = array_map('strtolower', array_map('trim', $header ?: []));
    while (($line = fgetcsv($fh)) !== false) {
        $assoc = [];
        foreach ($map as $i => $col) {
            $assoc[$col] = $line[$i] ?? '';
        }
        $rows[] = [
            'codice_belfiore' => strtoupper(trim((string)($assoc['codice_belfiore'] ?? $assoc['codicecatastale'] ?? ''))),
            'nome'            => strtoupper(trim((string)($assoc['nome'] ?? ''))),
            'provincia'       => strtoupper(trim((string)($assoc['provincia'] ?? $assoc['sigla'] ?? ''))),
            'soppresso'       => (int)($assoc['soppresso'] ?? 0),
        ];
    }
    fclose($fh);
} else {
    fwrite(STDERR, "Estensione non supportata (usa .json o .csv)\n");
    exit(2);
}

$stmt = $pdo->prepare("
    INSERT INTO comuni (codice_belfiore, nome, provincia, soppresso)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        nome = VALUES(nome),
        provincia = VALUES(provincia),
        soppresso = VALUES(soppresso)
");

$ok = 0;
$skip = 0;
$pdo->beginTransaction();
foreach ($rows as $r) {
    // Scarta record malformati: codice Belfiore non di 4 caratteri,
    // provincia non di 2 caratteri o nome vuoto.
    if (!is_array($r)
        || strlen($r['codice_belfiore']) !== 4
        || strlen($r['provincia']) !== 2
        || $r['nome'] === '') {
        $skip++;
        continue;
    }
    $stmt->execute([$r['codice_belfiore'], $r['nome'], $r['provincia'], $r['soppresso']]);
    $ok++;
}
$pdo->commit();

echo "Importati/aggiornati: {$ok}, scartati: {$skip}\n";
