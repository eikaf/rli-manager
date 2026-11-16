<?php
/**
 * genera_modello_pdf.php
 * Genera il PDF del modello RLI compilato, pronto per la firma.
 * Usa FPDI per sovrapporre i dati al template PDF.
 *
 * Supporta:
 *   - Prima registrazione (tipologia 1): tutti i quadri compilati
 *   - Adempimenti successivi (2-8): solo i quadri pertinenti
 *
 * Output: download diretto con nome file per tipo adempimento.
 */
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
require_once 'config.php';
require_once 'lib/ContrattoRepository.php';
require_once 'lib/RliPdfCoordinates.php';
require_once 'lib/RliPdfHelpers.php';
require_once 'fpdf/fpdf.php';
require_once 'fpdi/src/autoload.php';

if (php_sapi_name() !== 'cli') {
    $auth->requireLogin();
    Security::requireCsrf();
}
use setasign\Fpdi\Fpdi;

$idContratto = (int)($_POST['id_contratto'] ?? $_GET['id_contratto'] ?? 0);
$idEvento    = (int)($_POST['id_evento'] ?? $_GET['id_evento'] ?? 0);

if ($idContratto <= 0) die('Contratto non specificato.');

$repo = new ContrattoRepository($pdo);
$contratto = $repo->find($idContratto);
if (!$contratto) die('Contratto non trovato.');

// --- Evento (opzionale) ---
$evento = null;
if ($idEvento > 0) {
    $stmt = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id = ? AND id_contratto = ?");
    $stmt->execute([$idEvento, $idContratto]);
    $evento = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("SELECT * FROM eventi_contratto WHERE id_contratto = ? ORDER BY data_evento DESC, id DESC LIMIT 1");
    $stmt->execute([$idContratto]);
    $evento = $stmt->fetch();
}

// --- Dati correlati ---
$locatori    = $repo->locatori($idContratto);
$conduttori  = $repo->conduttori($idContratto);
$immobili    = $repo->immobili($idContratto);
$richiedente = $repo->richiedente($idContratto);

// --- Determina tipo adempimento ---
$tipoAdempimento = (int)($contratto['tipologia_adempimento'] ?? 1);
if ($evento) {
    $map = [
        'registrazione' => 1, 'annualita' => 1, 'proroga' => 2,
        'cessione' => 3, 'risoluzione' => 4, 'conguaglio' => 5,
        'subentro' => 6, 'risoluzione_corrispettivo' => 7, 'rinegoziazione' => 8,
    ];
    $tipoAdempimento = $map[$evento['tipo']] ?? $tipoAdempimento;
}

// --- Logica condizionale: quali quadri compilare ---
$isPrimaRegistrazione = ($tipoAdempimento === 1);
$compilaQuadroB = in_array($tipoAdempimento, [1, 2, 3, 6]);
$compilaQuadroC = in_array($tipoAdempimento, [1, 2]);
$compilaQuadroD_tassazione = in_array($tipoAdempimento, [1, 2]);
$compilaQuadroD_dichiarazione = in_array($tipoAdempimento, [1, 2, 3, 6, 8]);
$compilaQuadroE = $isPrimaRegistrazione;

// Per adempimenti successivi: filtra locatori per Quadro B (solo cedolari)
// Nel Quadro D invece vanno TUTTI i locatori (per mostrare il regime)
$locatoriPerQuadroB = $locatori;
if (!$isPrimaRegistrazione && $compilaQuadroB) {
    $locatoriPerQuadroB = array_values(array_filter($locatori, function($l) {
        return !empty($l['opzione_cedolare']);
    }));
}

// --- Nome file per tipo adempimento ---
$nomiTipo = [
    1 => 'REG', 2 => 'PRO', 3 => 'CES', 4 => 'RIS',
    5 => 'CON', 6 => 'SUB', 7 => 'RIS', 8 => 'RIN',
];
$prefix = $nomiTipo[$tipoAdempimento] ?? 'RLI';

// =============================================================
// CREAZIONE PDF
// =============================================================
$pdf = new Fpdi();
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetTextColor(0, 0, 0);

$templateFile = __DIR__ . '/Modello_RLI_Blank.pdf';
if (!file_exists($templateFile)) {
    die('Template PDF non trovato: ' . $templateFile);
}
$pageCount = $pdf->setSourceFile($templateFile);

// --- Helper CF header ---
$cfRichiedente = $richiedente['codice_fiscale'] ?? '';
$cfContribuente = $cfRichiedente;

// =============================================================
// PAGINA 2 — QUADRO A (FRONTESPIZIO)
// =============================================================
if ($pageCount >= RliPdfCoordinates::PAGE_FRONTESPIZIO) {
    RliPdfHelpers::importTemplatePage($pdf, RliPdfCoordinates::PAGE_FRONTESPIZIO);

    // CF contribuente in alto
    RliPdfHelpers::writeCFSlots(
        $pdf,
        RliPdfCoordinates::A_CF_HEADER['x'],
        RliPdfCoordinates::A_CF_HEADER['y'],
        $cfContribuente,
        RliPdfCoordinates::A_CF_HEADER['step']
    );

    // Modulo N.
    RliPdfHelpers::writeText($pdf,
        RliPdfCoordinates::A_RIC_NUM_MODULI['x'],
        RliPdfCoordinates::A_RIC_NUM_MODULI['y'],
        (string)max(1,
            (int)ceil(count($locatori) / 4),
            (int)ceil(count($conduttori) / 4),
            (int)ceil(count($immobili) / 4)
        ),
        ['size' => 9]
    );

    // Richiedente (Sezione III — sempre compilato)
    if ($richiedente) {
        RliPdfHelpers::writeUpper($pdf,
            RliPdfCoordinates::A_RIC_COGNOME['x'],
            RliPdfCoordinates::A_RIC_COGNOME['y'],
            $richiedente['cognome'] ?? '',
            ['size' => 8]
        );
        RliPdfHelpers::writeUpper($pdf,
            RliPdfCoordinates::A_RIC_NOME['x'],
            RliPdfCoordinates::A_RIC_NOME['y'],
            $richiedente['nome'] ?? '',
            ['size' => 8]
        );
        RliPdfHelpers::writeCFSlots($pdf,
            RliPdfCoordinates::A_RIC_CF['x'],
            RliPdfCoordinates::A_RIC_CF['y'],
            $richiedente['codice_fiscale'] ?? '',
            RliPdfCoordinates::A_RIC_CF['step'],
            8
        );
    }

    // ============================================================
    // SEZIONE I + II: REGISTRAZIONE vs ADEMPIMENTI SUCCESSIVI
    // ============================================================
    if ($isPrimaRegistrazione) {
        // ============ PRIMA REGISTRAZIONE ============
        // Tipologia contratto
        RliPdfHelpers::writeText($pdf,
            RliPdfCoordinates::A_TIPOLOGIA['x'],
            RliPdfCoordinates::A_TIPOLOGIA['y'],
            $contratto['tipologia_contratto'] ?? '',
            ['size' => 9]
        );

        // Durata dal / al
        RliPdfHelpers::writeDateSlots($pdf,
            RliPdfCoordinates::A_DURATA_DAL['x'],
            RliPdfCoordinates::A_DURATA_DAL['y'],
            $contratto['durata_dal'] ?? '',
            RliPdfCoordinates::A_DURATA_DAL['step'],
            RliPdfCoordinates::A_DURATA_DAL['gap']
        );
        RliPdfHelpers::writeDateSlots($pdf,
            RliPdfCoordinates::A_DURATA_AL['x'],
            RliPdfCoordinates::A_DURATA_AL['y'],
            $contratto['durata_al'] ?? '',
            RliPdfCoordinates::A_DURATA_AL['step'],
            RliPdfCoordinates::A_DURATA_AL['gap']
        );

        // Canone annuo
        if (!empty($contratto['importo_canone']) && (float)$contratto['importo_canone'] > 0) {
            RliPdfHelpers::writeImporto($pdf,
                RliPdfCoordinates::A_CANONE['x'],
                RliPdfCoordinates::A_CANONE['y'],
                $contratto['importo_canone']
            );
        }

        // Numero pagine / copie
        RliPdfHelpers::writeText($pdf,
            RliPdfCoordinates::A_NUM_PAGINE['x'],
            RliPdfCoordinates::A_NUM_PAGINE['y'],
            (string)($contratto['numero_pagine'] ?? 1),
            ['size' => 9]
        );
        RliPdfHelpers::writeText($pdf,
            RliPdfCoordinates::A_NUM_COPIE['x'],
            RliPdfCoordinates::A_NUM_COPIE['y'],
            (string)($contratto['numero_copie'] ?? 2),
            ['size' => 9]
        );

        // Data stipula
        RliPdfHelpers::writeDateSlots($pdf,
            RliPdfCoordinates::A_DATA_STIPULA['x'],
            RliPdfCoordinates::A_DATA_STIPULA['y'],
            $contratto['data_stipula'] ?? '',
            RliPdfCoordinates::A_DATA_STIPULA['step'],
            RliPdfCoordinates::A_DATA_STIPULA['gap']
        );

        // Ufficio territoriale
        RliPdfHelpers::writeText($pdf,
            RliPdfCoordinates::A_UFFICIO['x'],
            RliPdfCoordinates::A_UFFICIO['y'],
            $contratto['ufficio_territoriale'] ?? '',
            ['size' => 8]
        );

    } else {
        // ============ ADEMPIMENTO SUCCESSIVO ============
        // Tipologia adempimento (2, 3, 4, 5, 6, 7, 8)
        RliPdfHelpers::writeText($pdf,
            RliPdfCoordinates::A2_ADEMP_TIPOLOGIA['x'],
            RliPdfCoordinates::A2_ADEMP_TIPOLOGIA['y'],
            (string)$tipoAdempimento,
            ['size' => 9]
        );

        // Dettagli per tipo
        $ademp = $evento ? (json_decode($evento['dettagli_json'] ?? '{}', true) ?: []) : [];

        // Tipologia proroga (solo tipo 2)
        if ($tipoAdempimento === 2 && !empty($ademp['tipologia_proroga'])) {
            RliPdfHelpers::writeText($pdf,
                RliPdfCoordinates::A2_TIPOLOGIA_PROROGA['x'],
                RliPdfCoordinates::A2_TIPOLOGIA_PROROGA['y'],
                (string)$ademp['tipologia_proroga'],
                ['size' => 9]
            );
        }

        // Tipologia subentro (solo tipo 6)
        if ($tipoAdempimento === 6 && !empty($ademp['tipologia_subentro'])) {
            RliPdfHelpers::writeText($pdf,
                RliPdfCoordinates::A2_TIPOLOGIA_SUBENTRO['x'],
                RliPdfCoordinates::A2_TIPOLOGIA_SUBENTRO['y'],
                (string)$ademp['tipologia_subentro'],
                ['size' => 9]
            );
        }

        // CDC (solo tipo 2 con immobili)
        if ($tipoAdempimento === 2 && count($immobili) > 0) {
            RliPdfHelpers::writeCheckbox($pdf,
                RliPdfCoordinates::A2_CDC['x'],
                RliPdfCoordinates::A2_CDC['y']
            );
        }

        // Annualità (solo tipo 5)
        if ($tipoAdempimento === 5 && !empty($ademp['annualita'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::A2_ANNUALITA['x'],
                RliPdfCoordinates::A2_ANNUALITA['y'],
                (string)$ademp['annualita'],
                RliPdfCoordinates::A2_ANNUALITA['step'],
                9
            );
        }

        // Data fine (tipo 2, 3, 4, 6, 7)
        $dataFine = null;
        if ($tipoAdempimento === 2) $dataFine = $ademp['data_fine_proroga'] ?? $contratto['data_fine_proroga'] ?? null;
        elseif ($tipoAdempimento === 3) $dataFine = $ademp['data_cessione'] ?? $contratto['data_cessione'] ?? null;
        elseif ($tipoAdempimento === 4 || $tipoAdempimento === 7) $dataFine = $contratto['durata_al'] ?? null;
        elseif ($tipoAdempimento === 6) $dataFine = $ademp['data_subentro'] ?? $contratto['data_subentro'] ?? null;

        if ($dataFine) {
            RliPdfHelpers::writeDateSlots($pdf,
                RliPdfCoordinates::A2_DATA_FINE['x'],
                RliPdfCoordinates::A2_DATA_FINE['y'],
                $dataFine,
                RliPdfCoordinates::A2_DATA_FINE['step'],
                RliPdfCoordinates::A2_DATA_FINE['gap']
            );
        }

        // Data inizio rinegoziazione (solo tipo 8)
        if ($tipoAdempimento === 8 && !empty($ademp['data_inizio_rinegoziazione'])) {
            RliPdfHelpers::writeDateSlots($pdf,
                RliPdfCoordinates::A2_DATA_INIZIO_RINEG['x'],
                RliPdfCoordinates::A2_DATA_INIZIO_RINEG['y'],
                $ademp['data_inizio_rinegoziazione'],
                RliPdfCoordinates::A2_DATA_INIZIO_RINEG['step'],
                RliPdfCoordinates::A2_DATA_INIZIO_RINEG['gap']
            );
        }

        // Codice identificativo contratto (IDTelematico AdE)
        $codiceAdE = $contratto['codice_identificativo_contratto'] ?? '';
        if (!empty($codiceAdE)) {
            RliPdfHelpers::writeCFSlots($pdf,
                RliPdfCoordinates::A2_ID_TELEMATICO['x'],
                RliPdfCoordinates::A2_ID_TELEMATICO['y'],
                $codiceAdE,
                RliPdfCoordinates::A2_ID_TELEMATICO['step'],
                9
            );
        }
    }
}

// =============================================================
// PAGINA 3 — QUADRO B (SOGGETTI) — solo se compilaQuadroB
// =============================================================
if ($compilaQuadroB && $pageCount >= RliPdfCoordinates::PAGE_SOGGETTI && (count($locatoriPerQuadroB) > 0 || count($conduttori) > 0)) {
    $numModuliLoc  = max(1, (int)ceil(count($locatoriPerQuadroB) / 4));
    $numModuliCond = max(1, (int)ceil(count($conduttori) / 4));
    $numModuliSoggetti = max($numModuliLoc, $numModuliCond);

    for ($modulo = 0; $modulo < $numModuliSoggetti; $modulo++) {
        RliPdfHelpers::importTemplatePage($pdf, RliPdfCoordinates::PAGE_SOGGETTI);

        RliPdfHelpers::writeCFSlots($pdf,
            RliPdfCoordinates::A_CF_HEADER['x'],
            RliPdfCoordinates::A_CF_HEADER['y'],
            $cfContribuente,
            RliPdfCoordinates::A_CF_HEADER['step']
        );

        $offsetLoc  = $modulo * 4;
        $offsetCond = $modulo * 4;

        // --- LOCATORI ---
        foreach (array_slice($locatoriPerQuadroB, $offsetLoc, 4) as $i => $loc) {
            if (!isset(RliPdfCoordinates::B_LOC_BASE_Y[$i])) break;
            $y = RliPdfCoordinates::B_LOC_BASE_Y[$i];
            $progressivo = $offsetLoc + $i + 1;

            RliPdfHelpers::writeText($pdf,
                RliPdfCoordinates::B_LOC_NUM['x'],
                $y + RliPdfCoordinates::B_LOC_NUM['y_offset'],
                (string)$progressivo, ['size' => 9]);
            RliPdfHelpers::writeCFSlots($pdf,
                RliPdfCoordinates::B_LOC_CF['x'],
                $y + RliPdfCoordinates::B_LOC_CF['y_offset'],
                $loc['codice_fiscale'] ?? '',
                RliPdfCoordinates::B_LOC_CF['step'], 8);
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_LOC_COGNOME['x'],
                $y + RliPdfCoordinates::B_LOC_COGNOME['y_offset'],
                $loc['cognome'] ?? '', ['size' => 8]);
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_LOC_NOME['x'],
                $y + RliPdfCoordinates::B_LOC_NOME['y_offset'],
                $loc['nome'] ?? '', ['size' => 8]);
            if (!empty($loc['data_nascita'])) {
                RliPdfHelpers::writeDateSlots($pdf,
                    RliPdfCoordinates::B_LOC_DATA['x'],
                    $y + RliPdfCoordinates::B_LOC_DATA['y_offset'],
                    $loc['data_nascita'],
                    RliPdfCoordinates::B_LOC_DATA['step'],
                    RliPdfCoordinates::B_LOC_DATA['gap'], 8);
            }
            $sesso = strtoupper($loc['sesso'] ?? '');
            if ($sesso === 'M') {
                RliPdfHelpers::writeSexChar($pdf,
                    RliPdfCoordinates::B_LOC_SESSO_M['x'],
                    $y + RliPdfCoordinates::B_LOC_SESSO_M['y_offset'], 'M');
            } elseif ($sesso === 'F') {
                RliPdfHelpers::writeSexChar($pdf,
                    RliPdfCoordinates::B_LOC_SESSO_F['x'],
                    $y + RliPdfCoordinates::B_LOC_SESSO_F['y_offset'], 'F');
            }
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_LOC_COMUNE['x'],
                $y + RliPdfCoordinates::B_LOC_COMUNE['y_offset'],
                $loc['comune_nascita'] ?? '', ['size' => 8]);
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_LOC_PROV['x'],
                $y + RliPdfCoordinates::B_LOC_PROV['y_offset'],
                $loc['prov_nascita'] ?? '', ['size' => 8]);
            if (!empty($loc['non_presente_in_atto'])) {
                RliPdfHelpers::writeCheckbox($pdf,
                    RliPdfCoordinates::B_LOC_CHECK_LOC['x'],
                    $y + RliPdfCoordinates::B_LOC_CHECK_LOC['y_offset']);
            }
            if (!empty($loc['soggettivita_iva'])) {
                RliPdfHelpers::writeCheckbox($pdf,
                    RliPdfCoordinates::B_LOC_CHECK_IVA['x'],
                    $y + RliPdfCoordinates::B_LOC_CHECK_IVA['y_offset']);
            }
            if (!empty($loc['tipo_cessione'])) {
                $tc = (string)$loc['tipo_cessione'];
                if ($tc === '1') {
                    RliPdfHelpers::writeCheckbox($pdf,
                        RliPdfCoordinates::B_LOC_CHECK_CED['x'],
                        $y + RliPdfCoordinates::B_LOC_CHECK_CED['y_offset']);
                } elseif ($tc === '2') {
                    RliPdfHelpers::writeCheckbox($pdf,
                        RliPdfCoordinates::B_LOC_CHECK_CES['x'],
                        $y + RliPdfCoordinates::B_LOC_CHECK_CES['y_offset']);
                }
            }
        }

        // --- CONDUTTORI ---
        foreach (array_slice($conduttori, $offsetCond, 4) as $i => $cond) {
            if (!isset(RliPdfCoordinates::B_COND_BASE_Y[$i])) break;
            $y = RliPdfCoordinates::B_COND_BASE_Y[$i];
            $progressivo = $offsetCond + $i + 1;
            $numShift = [0, -3.5, -4.5, -4.5][$i] ?? 0;

            RliPdfHelpers::writeText($pdf,
                RliPdfCoordinates::B_COND_NUM['x'],
                $y + RliPdfCoordinates::B_COND_NUM['y_offset'] + $numShift,
                (string)$progressivo, ['size' => 9]);
            RliPdfHelpers::writeCFSlots($pdf,
                RliPdfCoordinates::B_COND_CF['x'],
                $y + RliPdfCoordinates::B_COND_CF['y_offset'],
                $cond['codice_fiscale'] ?? '',
                RliPdfCoordinates::B_COND_CF['step'], 8);
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_COND_COGNOME['x'],
                $y + RliPdfCoordinates::B_COND_COGNOME['y_offset'],
                $cond['cognome'] ?? '', ['size' => 8]);
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_COND_NOME['x'],
                $y + RliPdfCoordinates::B_COND_NOME['y_offset'],
                $cond['nome'] ?? '', ['size' => 8]);
            if (!empty($cond['data_nascita'])) {
                RliPdfHelpers::writeDateSlots($pdf,
                    RliPdfCoordinates::B_COND_DATA['x'],
                    $y + RliPdfCoordinates::B_COND_DATA['y_offset'],
                    $cond['data_nascita'],
                    RliPdfCoordinates::B_COND_DATA['step'],
                    RliPdfCoordinates::B_COND_DATA['gap'], 8);
            }
            $sessoC = strtoupper($cond['sesso'] ?? '');
            if ($sessoC === 'M') {
                RliPdfHelpers::writeSexChar($pdf,
                    RliPdfCoordinates::B_COND_SESSO_M['x'],
                    $y + RliPdfCoordinates::B_COND_SESSO_M['y_offset'], 'M');
            } elseif ($sessoC === 'F') {
                RliPdfHelpers::writeSexChar($pdf,
                    RliPdfCoordinates::B_COND_SESSO_F['x'],
                    $y + RliPdfCoordinates::B_COND_SESSO_F['y_offset'], 'F');
            }
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_COND_COMUNE['x'],
                $y + RliPdfCoordinates::B_COND_COMUNE['y_offset'],
                $cond['comune_nascita'] ?? '', ['size' => 8]);
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::B_COND_PROV['x'],
                $y + RliPdfCoordinates::B_COND_PROV['y_offset'],
                $cond['prov_nascita'] ?? '', ['size' => 8]);
            if (!empty($cond['tipologia_conduttore'])) {
                RliPdfHelpers::writeText($pdf,
                    RliPdfCoordinates::B_COND_TIP['x'],
                    $y + RliPdfCoordinates::B_COND_TIP['y_offset'],
                    (string)$cond['tipologia_conduttore'], ['size' => 9]);
            }
        }
    }
}

// =============================================================
// PAGINA 4 — QUADRO C (IMMOBILI) — solo se compilaQuadroC
// =============================================================
if ($compilaQuadroC && $pageCount >= RliPdfCoordinates::PAGE_IMMOBILI && count($immobili) > 0) {
    RliPdfHelpers::importTemplatePage($pdf, RliPdfCoordinates::PAGE_IMMOBILI);

    RliPdfHelpers::writeCFSlots($pdf,
        RliPdfCoordinates::A_CF_HEADER['x'],
        RliPdfCoordinates::A_CF_HEADER['y'],
        $cfContribuente,
        RliPdfCoordinates::A_CF_HEADER['step']
    );

    foreach (array_slice($immobili, 0, 4) as $i => $imm) {
        if (!isset(RliPdfCoordinates::C_BASE_Y[$i])) break;
        $y = RliPdfCoordinates::C_BASE_Y[$i];

        RliPdfHelpers::writeText($pdf,
            RliPdfCoordinates::C_NUM['x'],
            $y + RliPdfCoordinates::C_NUM['y_offset'],
            (string)($i + 1), ['size' => 9]);

        $tipImm = (int)($imm['tipologia_immobile'] ?? 1);
        RliPdfHelpers::writeNumberSlots($pdf,
            RliPdfCoordinates::C_TIPOLOGIA['x'],
            $y + RliPdfCoordinates::C_TIPOLOGIA['y_offset'],
            (string)$tipImm,
            RliPdfCoordinates::C_TIPOLOGIA['step'] ?? 5.0, 9);

        if (!empty($imm['codice_comune'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_COMUNE_CAT['x'],
                $y + RliPdfCoordinates::C_COMUNE_CAT['y_offset'],
                strtoupper($imm['codice_comune']),
                RliPdfCoordinates::C_COMUNE_CAT['step'] ?? 5.13, 9);
        }
        if (!empty($imm['tipo_catasto'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_TU['x'],
                $y + RliPdfCoordinates::C_TU['y_offset'],
                strtoupper($imm['tipo_catasto']),
                RliPdfCoordinates::C_TU['step'] ?? 5.0, 9);
        }
        if (!empty($imm['intero_porzione'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_IP['x'],
                $y + RliPdfCoordinates::C_IP['y_offset'],
                strtoupper($imm['intero_porzione']),
                RliPdfCoordinates::C_IP['step'] ?? 5.0, 9);
        }
        if (!empty($imm['sezione'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_SEZIONE['x'],
                $y + RliPdfCoordinates::C_SEZIONE['y_offset'],
                strtoupper($imm['sezione']),
                RliPdfCoordinates::C_SEZIONE['step'] ?? 5.0, 9);
        }
        if (!empty($imm['foglio'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_FOGLIO['x'],
                $y + RliPdfCoordinates::C_FOGLIO['y_offset'],
                (string)$imm['foglio'],
                RliPdfCoordinates::C_FOGLIO['step'] ?? 5.1, 9);
        }
        if (!empty($imm['particella'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_PARTICELLA['x'],
                $y + RliPdfCoordinates::C_PARTICELLA['y_offset'],
                (string)$imm['particella'],
                RliPdfCoordinates::C_PARTICELLA['step'] ?? 4.94, 9);
        }
        if (!empty($imm['subalterno'])) {
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_SUBALTERNO['x'],
                $y + RliPdfCoordinates::C_SUBALTERNO['y_offset'],
                (string)$imm['subalterno'],
                RliPdfCoordinates::C_SUBALTERNO['step'] ?? 4.94, 9);
        }
        RliPdfHelpers::writeUpper($pdf,
            RliPdfCoordinates::C_COMUNE['x'],
            $y + RliPdfCoordinates::C_COMUNE['y_offset'],
            $imm['comune'] ?? '', ['size' => 8]);
        RliPdfHelpers::writeUpper($pdf,
            RliPdfCoordinates::C_PROVINCIA['x'],
            $y + RliPdfCoordinates::C_PROVINCIA['y_offset'],
            $imm['provincia'] ?? '', ['size' => 8]);
        if (!empty($imm['categoria'])) {
            $cat = strtoupper(str_replace(['/', ' '], '', $imm['categoria']));
            RliPdfHelpers::writeNumberSlots($pdf,
                RliPdfCoordinates::C_CATEGORIA['x'],
                $y + RliPdfCoordinates::C_CATEGORIA['y_offset'],
                $cat,
                RliPdfCoordinates::C_CATEGORIA['step'] ?? 5.1, 9);
        }
        if (!empty($imm['rendita'])) {
            RliPdfHelpers::writeText($pdf,
                RliPdfCoordinates::C_RENDITA['x'],
                $y + RliPdfCoordinates::C_RENDITA['y_offset'],
                RliPdfHelpers::formatRendita($imm['rendita']), ['size' => 8]);
        }
        if (!empty($imm['toponimo'])) {
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::C_TOPONIMO['x'],
                $y + RliPdfCoordinates::C_TOPONIMO['y_offset'],
                $imm['toponimo'], ['size' => 8]);
        }
        if (!empty($imm['indirizzo'])) {
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::C_INDIRIZZO['x'],
                $y + RliPdfCoordinates::C_INDIRIZZO['y_offset'],
                $imm['indirizzo'], ['size' => 8]);
        }
        if (!empty($imm['civico'])) {
            RliPdfHelpers::writeUpper($pdf,
                RliPdfCoordinates::C_CIVICO['x'],
                $y + RliPdfCoordinates::C_CIVICO['y_offset'],
                $imm['civico'], ['size' => 8]);
        }
        if (!empty($imm['in_via_di_accatastamento'])) {
            RliPdfHelpers::writeCheckbox($pdf,
                RliPdfCoordinates::C_CHECK_ACCATASTAMENTO['x'],
                $y + RliPdfCoordinates::C_CHECK_ACCATASTAMENTO['y_offset']);
        }
    }
}

// =============================================================
// QUADRO D — Regime di tassazione (solo tipo 1) + Dichiarazione (tipi 1,2,3,6,8)
// =============================================================
if (($compilaQuadroD_tassazione || $compilaQuadroD_dichiarazione) && $pageCount >= RliPdfCoordinates::PAGE_IMMOBILI) {
    // Se il Quadro C non è stato emesso, importa la pagina 4 standalone
    if (!$compilaQuadroC || count($immobili) === 0) {
        RliPdfHelpers::importTemplatePage($pdf, RliPdfCoordinates::PAGE_IMMOBILI);
        RliPdfHelpers::writeCFSlots($pdf,
            RliPdfCoordinates::A_CF_HEADER['x'],
            RliPdfCoordinates::A_CF_HEADER['y'],
            $cfContribuente,
            RliPdfCoordinates::A_CF_HEADER['step']);
    }

    // Regime di tassazione (solo tipo 1)
    if ($compilaQuadroD_tassazione) {
        // LOGICA CORRETTA: Il Quadro D va compilato per ogni locatore (non incrociando immobili).
        // Prendiamo il primo immobile come riferimento visivo e iteriamo i locatori.
        $righeD = [];
        foreach ($locatori as $lIdx => $loc) {
            $righeD[] = [
                'num_immobile' => 1, // Di norma si indica l'immobile 1, o quello principale
                'num_locatore' => $lIdx + 1,
                'quota'        => (float)($loc['percentuale_possesso'] ?? 100),
                'cedolare'     => !empty($loc['opzione_cedolare']),
            ];
        }

        foreach ($righeD as $idx => $r) {
            if ($idx >= 10) break;
            $isSx = $idx < 5;
            $col = $isSx ? RliPdfCoordinates::D_COL_SX : RliPdfCoordinates::D_COL_DX;
            $riga = $isSx ? $idx : ($idx - 5);
            $yBase = $isSx ? RliPdfCoordinates::D_BASE_Y_SX[$riga] : RliPdfCoordinates::D_BASE_Y_DX[$riga];

            RliPdfHelpers::writeText($pdf,
                $col + RliPdfCoordinates::D_OFF_IMM,
                $yBase, (string)$r['num_immobile'], ['size' => 9]);
            RliPdfHelpers::writeText($pdf,
                $col + RliPdfCoordinates::D_OFF_LOC,
                $yBase, (string)$r['num_locatore'], ['size' => 9]);
            RliPdfHelpers::writeText($pdf,
                $col + RliPdfCoordinates::D_OFF_QUOTA,
                $yBase, number_format($r['quota'], 2, ',', ''), ['size' => 8]);

            if ($r['cedolare']) {
                RliPdfHelpers::writeCheckbox($pdf,
                    $col + RliPdfCoordinates::D_OFF_CED_SI,
                    $yBase + 1.5);
            } else {
                RliPdfHelpers::writeCheckbox($pdf,
                    $col + RliPdfCoordinates::D_OFF_CED_NO,
                    $yBase + 1.5);
            }
        }
    }

    // Dichiarazione locatori (tipo 1, 2, 3, 6, 8)
    if ($compilaQuadroD_dichiarazione) {
        $locatoriCed = array_values(array_filter($locatori, function($l) {
            return !empty($l['opzione_cedolare']);
        }));
        $locatoriCed = array_slice($locatoriCed, 0, 4);
        foreach ($locatoriCed as $i => $loc) {
            if (!isset(RliPdfCoordinates::D_DICH_BASE_Y[$i])) break;
            $y = RliPdfCoordinates::D_DICH_BASE_Y[$i];
            RliPdfHelpers::writeCFSlots($pdf,
                RliPdfCoordinates::D_DICH_CF['x'],
                $y,
                $loc['codice_fiscale'] ?? '',
                RliPdfCoordinates::D_DICH_CF['step'],
                8);
        }
    }
}

// =============================================================
// PAGINA 5 — QUADRO E (CANONI VARIABILI) — solo tipo 1
// =============================================================
$canoniVariabili = [];
if (!empty($contratto['canoni_variabili'])) {
    $canoniVariabili = json_decode($contratto['canoni_variabili'], true) ?: [];
}

if ($compilaQuadroE && $pageCount >= RliPdfCoordinates::PAGE_CANONI && count($canoniVariabili) > 0) {
    RliPdfHelpers::importTemplatePage($pdf, RliPdfCoordinates::PAGE_CANONI);

    RliPdfHelpers::writeCFSlots($pdf,
        RliPdfCoordinates::A_CF_HEADER['x'],
        RliPdfCoordinates::A_CF_HEADER['y'],
        $cfContribuente,
        RliPdfCoordinates::A_CF_HEADER['step']
    );

    foreach ($canoniVariabili as $idx => $canone) {
        if ($idx >= 8) break;
        $isSx = ($idx % 2 === 0);
        $riga = (int)floor($idx / 2);
        $yBase = $isSx
            ? RliPdfCoordinates::E_BASE_Y_SX[$riga]
            : RliPdfCoordinates::E_BASE_Y_DX[$riga];
        $xImporto = $isSx
            ? RliPdfCoordinates::E_IMPORTO_X_SX
            : RliPdfCoordinates::E_IMPORTO_X_DX;

        $importo = is_array($canone) ? ($canone['importo'] ?? 0) : (float)$canone;
        if ($importo > 0) {
            RliPdfHelpers::writeImporto($pdf,
                $xImporto,
                $yBase + RliPdfCoordinates::E_IMPORTO_Y_OFFSET,
                $importo);
        }
    }
}

// =============================================================
// OUTPUT
// =============================================================
if (ob_get_length()) ob_clean();

// Nome file parlante: TIPO_COGNOME_LOC_COGNOME_COND_ANNO_ID.pdf
$annoEvento = $evento && !empty($evento['data_evento'])
    ? date('Y', strtotime($evento['data_evento']))
    : date('Y');

$cognomeLoc = !empty($locatori) ? strtoupper(preg_replace('/[^A-Za-z]/', '', $locatori[0]['cognome'] ?? '')) : 'NOLOC';
$cognomeCond = !empty($conduttori) ? strtoupper(preg_replace('/[^A-Za-z]/', '', $conduttori[0]['cognome'] ?? '')) : 'NOCOND';

$cognomeLoc = substr($cognomeLoc, 0, 15);
$cognomeCond = substr($cognomeCond, 0, 15);

$nomeFile = sprintf('%s_%s_%s_%s_%d.pdf',
    $prefix,
    $cognomeLoc,
    $cognomeCond,
    $annoEvento,
    $idContratto
);

if (php_sapi_name() === 'cli' && !empty($GLOBALS['cli_output_file'])) {
    $pdf->Output('F', $GLOBALS['cli_output_file']);
} else {
    if (ob_get_length()) ob_clean();
    $pdf->Output('D', $nomeFile);
}
exit;
