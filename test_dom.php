<?php
echo "<h1>Test DOM/LibXML</h1>";

// 1. Estensioni
echo "<h2>1. Estensioni</h2>";
echo "dom: " . (extension_loaded('dom') ? '✅' : '❌') . "<br>";
echo "xml: " . (extension_loaded('xml') ? '✅' : '❌') . "<br>";
echo "libxml: " . (extension_loaded('libxml') ? '✅' : '❌') . "<br>";
echo "LIBXML_VERSION: " . LIBXML_VERSION . "<br>";
echo "LIBXML_DOTTED_VERSION: " . LIBXML_DOTTED_VERSION . "<br>";

// 2. Test parsing XML minimo
echo "<h2>2. Parsing XML minimo</h2>";
$xmlMinimo = '<?xml version="1.0" encoding="UTF-8"?><root><item>ok</item></root>';
$dom = new DOMDocument();
$ok = $dom->loadXML($xmlMinimo);
echo "loadXML minimo: " . ($ok ? '✅' : '❌') . "<br>";
if ($ok) {
    echo "documentElement: " . $dom->documentElement->nodeName . "<br>";
    echo "item text: " . $dom->getElementsByTagName('item')->item(0)->textContent . "<br>";
}

// 3. Test parsing del file specifico
echo "<h2>3. Parsing file RLI specifico</h2>";
$file = '/tmp/rli_import_viuheo0gng49jdbflpsovh59a/MAROBBIOM_DESAM1925_0_RLI12.rli';
if (!file_exists($file)) {
    // prova a cercarlo
    $files = glob('/tmp/rli_import_*/*.rli');
    if (!empty($files)) {
        $file = $files[0];
        echo "File trovato: {$file}<br>";
    } else {
        echo "❌ File non trovato in /tmp/rli_import_*/<br>";
    }
}

if (file_exists($file)) {
    $content = file_get_contents($file);
    echo "Dimensione file: " . strlen($content) . " bytes<br>";
    echo "Primi 20 byte in HEX: " . bin2hex(substr($content, 0, 20)) . "<br>";
    echo "Ultimi 20 byte in HEX: " . bin2hex(substr($content, -20)) . "<br>";
    
    // Cerca caratteri di controllo non validi
    $invalidi = 0;
    for ($i = 0; $i < strlen($content); $i++) {
        $c = ord($content[$i]);
        // Caratteri XML validi: 0x09, 0x0A, 0x0D, 0x20-0xD7FF, 0xE000-0xFFFD
        if ($c !== 0x09 && $c !== 0x0A && $c !== 0x0D && ($c < 0x20 || $c === 0x7F)) {
            $invalidi++;
            if ($invalidi <= 5) {
                echo "⚠ Byte invalido alla posizione {$i}: 0x" . str_pad(dechex($c), 2, '0', STR_PAD_LEFT) . "<br>";
            }
        }
    }
    echo "Totale byte invalidi (no tab/newline/cr): {$invalidi}<br>";

    // Prova loadXML
    $dom2 = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $ok2 = $dom2->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
    $errs = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    echo "loadXML file RLI: " . ($ok2 ? '✅' : '❌') . "<br>";
    echo "Errori libxml: " . count($errs) . "<br>";
    foreach (array_slice($errs, 0, 5) as $e) {
        echo "  - [{$e->level}] {$e->message} (line {$e->line}, col {$e->column})<br>";
    }
    if ($ok2) {
        echo "Root: " . $dom2->documentElement->nodeName . "<br>";
    }
    
    // Prova con encoding diverso
    echo "<h2>4. Test con encoding ISO-8859-1</h2>";
    $content8859 = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    $dom3 = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $ok3 = $dom3->loadXML($content8859, LIBXML_NONET);
    $errs3 = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    echo "loadXML (ISO→UTF8): " . ($ok3 ? '✅' : '❌') . "<br>";
    if (!$ok3 && !empty($errs3)) {
        echo "  Primo errore: " . trim($errs3[0]->message) . "<br>";
    }
}
// === TEST FINALE: RIPRODUZIONE ESATTA DEL PARSER ===
echo "<h2>5. Riproduzione esatta del parser</h2>";
if (file_exists($file)) {
    $xml = file_get_contents($file);
    
    // FIX: rimuovi BOM
    if (substr($xml, 0, 3) === "\xEF\xBB\xBF") {
        $xml = substr($xml, 3);
    }
    $xml = ltrim($xml);
    
    echo "Lunghezza dopo fix: " . strlen($xml) . "<br>";
    
    // STEP 1: loadXML con le stesse opzioni del parser
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    
    echo "loadXML (NONET|NOBLANKS|NOCDATA): " . ($loaded ? '✅' : '❌') . "<br>";
    echo "Numero errori: " . count($errors) . "<br>";
    echo "documentElement: " . ($dom->documentElement ? $dom->documentElement->nodeName : '❌ NULL') . "<br>";
    echo "memory_get_peak_usage: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB<br>";
    echo "memory_limit: " . ini_get('memory_limit') . "<br>";
    echo "libxml limit: " . (ini_get('libxml_disable_entity_loader') ?: 'non impostato') . "<br>";
    
    // STEP 2: prova senza NOCDATA
    $dom2 = new DOMDocument('1.0', 'UTF-8');
    $dom2->preserveWhiteSpace = false;
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $loaded2 = $dom2->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    $errors2 = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    
    echo "loadXML (NONET|NOBLANKS): " . ($loaded2 ? '✅' : '❌') . "<br>";
    echo "documentElement: " . ($dom2->documentElement ? $dom2->documentElement->nodeName : '❌ NULL') . "<br>";
    
    // STEP 3: prova solo NONET
    $dom3 = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $loaded3 = $dom3->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    echo "loadXML (NONET): " . ($loaded3 ? '✅' : '❌') . "<br>";
    echo "documentElement: " . ($dom3->documentElement ? $dom3->documentElement->nodeName : '❌ NULL') . "<br>";
}
