<?php
/**
 * XsdValidator — Validazione locale XML contro XSD RLI12.
 *
 * Uso:
 *   $r = XsdValidator::validate($xmlString);
 *   if (!$r['ok']) { foreach ($r['errors'] as $e) echo $e; }
 */
class XsdValidator
{
    /**
     * Percorso assoluto dello XSD principale (fornituraRLI12_v1.xsd).
     * Ritorna null se non trovato.
     */
    public static function xsdPath(): ?string
    {
        $candidates = [
            __DIR__ . '/../xsd/RLI/xsd/fornituraRLI12_v1.xsd',
            __DIR__ . '/../xsd/RLI/fornituraRLI12_v1.xsd',
            __DIR__ . '/../xsd/fornituraRLI12_v1.xsd',
            '/var/www/html/RLI/xsd/RLI/xsd/fornituraRLI12_v1.xsd',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) return realpath($p);
        }
        return null;
    }

    /**
     * Valida una stringa XML contro lo XSD RLI12.
     *
     * @param string $xml
     * @return array{ok: bool, errors: string[], warnings: string[], xsd: ?string}
     */
    public static function validate(string $xml): array
    {
        $xsd = self::xsdPath();
        if ($xsd === null) {
            return [
                'ok' => false,
                'errors' => ['XSD non trovato: verifica la presenza di xsd/RLI/xsd/fornituraRLI12_v1.xsd'],
                'warnings' => [],
                'xsd' => null,
            ];
        }

        $errors = [];
        $warnings = [];

        // Sopprimi warning interni di libxml: catturiamo tutto manualmente
        $prevUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;

        // Parse
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        if (!$loaded) {
            foreach (libxml_get_errors() as $e) {
                $errors[] = 'XML non ben formato: ' . trim($e->message) .
                            ' (riga ' . $e->line . ')';
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
            return ['ok' => false, 'errors' => $errors, 'warnings' => [], 'xsd' => $xsd];
        }

        // Schema validate
        $valid = $dom->schemaValidate($xsd);
        if (!$valid) {
            foreach (libxml_get_errors() as $e) {
                $msg = trim($e->message);
                // Classifica: level 1=warning, 2=error, 3=fatal
                if ($e->level <= LIBXML_ERR_WARNING) {
                    $warnings[] = $msg . ($e->line ? ' (riga ' . $e->line . ')' : '');
                } else {
                    // Estrai il path XSD (es. /loc:Fornitura/loc:Documento/...)
                    $path = '';
                    if (preg_match('#Element \'([^\']+)\'#', $msg, $m)) {
                        $path = $m[1];
                    }
                    $errors[] = $msg . ($e->line ? ' (riga ' . $e->line . ', col ' . $e->column . ')' : '') .
                                ($path ? ' [path: ' . $path . ']' : '');
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        return [
            'ok' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'xsd' => $xsd,
        ];
    }

    /**
     * Ritorna l'HTML pronto per mostrare un blocco di errori (pagina 400).
     */
    public static function renderErrorsPage(array $result, int $idContratto = 0, string $tipoFile = 'XML'): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html  = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">';
        $html .= '<title>Errore validazione ' . $h($tipoFile) . '</title>';
        $html .= '<style>body{font-family:system-ui,sans-serif;background:#f7fafc;padding:40px;color:#2d3748;}';
        $html .= '.box{background:#fff;border-left:4px solid #e53e3e;padding:25px 30px;border-radius:10px;max-width:1000px;margin:0 auto;box-shadow:0 4px 12px rgba(0,0,0,0.08);}';
        $html .= 'h2{color:#742a2a;margin-top:0;}.meta{color:#718096;font-size:0.85em;margin-bottom:15px;}';
        $html .= 'ul{line-height:1.7;padding-left:20px;font-family:Consolas,monospace;font-size:0.85em;}';
        $html .= 'li{margin-bottom:8px;word-break:break-word;}';
        $html .= '.warn{border-left-color:#ed8936;margin-top:20px;}.warn h3{color:#7b341e;margin-top:0;}';
        $html .= '.btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#4299e1;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;margin-right:10px;}';
        $html .= '</style></head><body><div class="box">';
        $html .= '<h2>⚠ Impossibile generare il file ' . $h($tipoFile) . '</h2>';
        $html .= '<p>Il file generato non rispetta lo schema XSD ufficiale dell\'Agenzia delle Entrate. ';
        $html .= 'Non verrebbe accettato dal controllo telematico.</p>';

        if ($idContratto > 0) {
            $html .= '<div class="meta">Contratto #' . $idContratto . '</div>';
        }
        if (!empty($result['xsd'])) {
            $html .= '<div class="meta">XSD: <code>' . $h(basename($result['xsd'])) . '</code></div>';
        }

        $html .= '<h3>Errori (' . count($result['errors']) . ')</h3><ul>';
        foreach ($result['errors'] as $e) {
            $html .= '<li>' . $h($e) . '</li>';
        }
        $html .= '</ul>';

        if (!empty($result['warnings'])) {
            $html .= '<div class="warn"><h3>Warning (' . count($result['warnings']) . ')</h3><ul>';
            foreach ($result['warnings'] as $w) {
                $html .= '<li>' . $h($w) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ($idContratto > 0) {
            $html .= '<a class="btn" href="contract.php?id=' . $idContratto . '">← Torna al contratto</a>';
        }
        $html .= '<a class="btn" href="index.php">← Home</a>';
        $html .= '</div></body></html>';
        return $html;
    }
}
