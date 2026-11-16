<?php
/**
 * RliPdfHelpers — Funzioni helper per compilare il PDF del modello RLI.
 */
class RliPdfHelpers
{
    /**
     * Scrive testo semplice in una posizione.
     */
    public static function writeText($pdf, float $x, float $y, string $text, array $opts = []): void
    {
        $text = trim($text);
        if ($text === '') return;

        if (!empty($opts['font']))  $pdf->SetFont($opts['font'][0], $opts['font'][1], $opts['font'][2]);
        if (!empty($opts['size']))  $pdf->SetFont('Arial', 'B', $opts['size']);

        $pdf->SetXY($x, $y);
        $pdf->Write(0, $text);
    }

    /**
     * Scrive testo in maiuscolo.
     */
    public static function writeUpper($pdf, float $x, float $y, string $text, array $opts = []): void
    {
        self::writeText($pdf, $x, $y, strtoupper($text), $opts);
    }

    /**
     * Scrive una data nei singoli slot (gg mm aaaa separati).
     * Formato: "2026-01-15" -> "15" "01" "2026"
     */
    public static function writeDateSlots($pdf, float $x, float $y, string $dateValue, float $step = 4.0, float $gap = 2.0, int $fontSize = 9): void
    {
        $dateValue = trim($dateValue);
        if ($dateValue === '') return;

        $ts = strtotime($dateValue);
        if (!$ts) return;

        $gg = date('d', $ts);
        $mm = date('m', $ts);
        $aaaa = date('Y', $ts);

        $pdf->SetFont('Arial', 'B', $fontSize);

        // Giorno (2 cifre)
        for ($i = 0; $i < 2; $i++) {
            $pdf->SetXY($x + ($i * $step), $y);
            $pdf->Cell($step, 0, $gg[$i], 0, 0, 'C');
        }

        // Mese (2 cifre, con gap)
        $xMese = $x + (2 * $step) + $gap;
        for ($i = 0; $i < 2; $i++) {
            $pdf->SetXY($xMese + ($i * $step), $y);
            $pdf->Cell($step, 0, $mm[$i], 0, 0, 'C');
        }

        // Anno (4 cifre, con gap)
        $xAnno = $xMese + (2 * $step) + $gap;
        for ($i = 0; $i < 4; $i++) {
            $pdf->SetXY($xAnno + ($i * $step), $y);
            $pdf->Cell($step, 0, $aaaa[$i], 0, 0, 'C');
        }
    }

    /**
     * Scrive un CF nei singoli slot (16 caratteri, uno per casella).
     */
    public static function writeCFSlots($pdf, float $x, float $y, string $cf, float $step = 5.0, int $fontSize = 9): void
    {
        $cf = strtoupper(trim($cf));
        if ($cf === '') return;

        $pdf->SetFont('Arial', 'B', $fontSize);
        $chars = str_split($cf);
        foreach ($chars as $i => $ch) {
            $pdf->SetXY($x + ($i * $step), $y);
            $pdf->Cell($step, 0, $ch, 0, 0, 'C');
        }
    }

    /**
     * Scrive un valore numerico in slot (una cifra per casella).
     * Es: "12345" -> 5 caselle
     */
    public static function writeNumberSlots($pdf, float $x, float $y, string $value, float $step = 4.8, int $fontSize = 9): void
    {
        $value = trim((string)$value);
        if ($value === '') return;

        $pdf->SetFont('Arial', 'B', $fontSize);
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $i => $ch) {
            $pdf->SetXY($x + ($i * $step), $y);
            $pdf->Cell($step, 0, $ch, 0, 0, 'C');
        }
    }

    /**
     * Scrive una "X" in una checkbox.
     */
    public static function writeCheckbox($pdf, float $x, float $y, bool $checked = true): void
    {
        if (!$checked) return;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($x, $y);
        $pdf->Write(0, 'X');
    }

    /**
     * Scrive una lettera in un campo "Sesso" (M/F).
     */
    public static function writeSexChar($pdf, float $x, float $y, string $char): void
    {
        $char = strtoupper(trim($char));
        if ($char === '') return;

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($x - 2.5, $y - 2.5);
        $pdf->Cell(5, 5, $char, 0, 0, 'C');
    }

    /**
     * Scrive un importo formattato (es. "1234,56" o "12000,00").
     * Il PDF ha già la virgola pre-stampata, quindi passiamo solo la parte intera e decimale
     * (a meno che non sia tutto su una riga).
     */
    public static function writeImporto($pdf, float $x, float $y, $importo, int $fontSize = 9): void
    {
        $val = (float)$importo;
        if ($val == 0) return;

        $formatted = number_format($val, 2, ',', '.');
        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->SetXY($x, $y);
        $pdf->Write(0, $formatted);
    }

    /**
     * Spezza un indirizzo in "toponimo" + "resto".
     * Es: "VIA ROMA" -> ["VIA", "ROMA"]
     *     "PIAZZA GARIBALDI 10" -> ["PIAZZA", "GARIBALDI 10"]
     *     "ROMA" -> ["", "ROMA"]
     */
    public static function splitToponimo(string $indirizzo): array
    {
        $indirizzo = strtoupper(trim($indirizzo));
        if ($indirizzo === '') return ['', ''];

        $toponimiNoti = [
            'VIA', 'VIALE', 'PIAZZA', 'PIAZZALE', 'P.ZZA', 'PZZA',
            'CORSO', 'LARGO', 'VICO', 'VICOLO', 'STRADA', 'CONTRADA',
            'TRAVERSA', 'SALITA', 'DISCESA', 'BORGO', 'LOCALITA', 'LOCALITÀ',
            'FRAZIONE', 'ROTONDA', 'STRADA PROVINCIALE', 'STRADA STATALE',
            'STRADA REGIONALE', 'S.S.', 'S.P.', 'S.R.',
        ];

        $tokens = preg_split('/\s+/', $indirizzo, 2);
        $first = $tokens[0] ?? '';
        $rest = $tokens[1] ?? '';

        if (in_array($first, $toponimiNoti, true)) {
            return [$first, $rest];
        }

        // Controlla anche "P.ZZA" o "P.ZA" come primi due token
        if (count($tokens) === 2 && in_array($first, ['P.ZA', 'P.ZZA'], true)) {
            return [$first, $rest];
        }

        return ['', $indirizzo];
    }

    /**
     * Formatta la rendita per il PDF (con virgola).
     */
    public static function formatRendita($val): string
    {
        $val = trim((string)$val);
        if ($val === '') return '';
        return str_replace('.', ',', $val);
    }

    /**
     * Importa una pagina del template.
     */
    public static function importTemplatePage($pdf, int $pageNo): void
    {
        $tpl = $pdf->importPage($pageNo);
        $pdf->AddPage();
        $pdf->useTemplate($tpl);
    }
}
