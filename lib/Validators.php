<?php
/**
 * Validators — Verifica pattern e business rules XSD RLI12 + IBAN.
 */
class Validators
{
    public static function cf(string $cf): bool
    {
        $cf = strtoupper(trim($cf));

        // Partita IVA (11 cifre)
        if (preg_match('/^[0-9]{11}$/', $cf)) {
            return true;
        }

        // Codice fiscale persona fisica: struttura + carattere di controllo mod-26
        if (!preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $cf)) {
            return false;
        }

        return self::cfCheckDigit($cf);
    }

    /**
     * Verifica il carattere di controllo del CF persona fisica (algoritmo mod-26).
     * Le tabelle includono già la decodifica dell'omocodia (lettere L,M,N,P,Q,R,S,T,U,V).
     */
    private static function cfCheckDigit(string $cf): bool
    {
        $dispari = [
            '0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
            'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
            'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
            'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23,
        ];
        $pari = [
            '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
            'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,
            'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,
            'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25,
        ];
        $check = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $somma = 0;
        for ($i = 0; $i < 15; $i++) {
            $ch = $cf[$i];
            if ($i % 2 === 0) {
                if (!isset($dispari[$ch])) return false;
                $somma += $dispari[$ch];
            } else {
                if (!isset($pari[$ch])) return false;
                $somma += $pari[$ch];
            }
        }

        return $cf[15] === $check[$somma % 26];
    }

    public static function dataDt(string $d): bool
    {
        $ts = strtotime($d);
        return $ts !== false && $ts <= strtotime('today');
    }

    public static function dataDn(string $d): bool
    {
        return strtotime($d) !== false;
    }

    public static function anno(string $a): bool
    {
        return (bool)preg_match('/^(18|19|20)[0-9]{2}$/', $a);
    }

    public static function provincia(string $p): bool
    {
        return in_array(strtoupper($p), rli_province_italiane(), true);
    }

    public static function tipologiaContratto(string $t): bool
    {
        return in_array(strtoupper($t), rli_tipologie_contratto(), true);
    }

    public static function tipoCatasto(string $t): bool
    {
        return in_array(strtoupper($t), ['T', 'U'], true);
    }

    public static function porzioneImmobile(string $p): bool
    {
        return in_array(strtoupper($p), ['I', 'P'], true);
    }

    public static function tipologiaImmobile($t): bool
    {
        return in_array((int)$t, [1, 2, 3, 4], true);
    }

    public static function categoriaCatastale(string $c): bool
    {
        $c = strtoupper(trim($c));
        $c = str_replace(['/', ' ', '-'], '', $c);
        $c = preg_replace('/^([A-Z])0([0-9])$/', '$1$2', $c);
        return (bool)preg_match('/^(A([1-9]|10|11)|B[1-8]|C[1-7]|D([1-9]|10)|E[1-9]|F[1-7])$/', $c);
    }

    public static function idContratto(string $id): bool
    {
        return (bool)preg_match('/^([0-9A-Z]){1}([0-9A-Z_])*$/', $id) && strlen($id) <= 20;
    }

    public static function importoVP(string $v): bool
    {
        return (bool)preg_match('/^[0-9]+,[0-9]{2}$/', $v);
    }

    public static function percentuale(string $v): bool
    {
        return (bool)preg_match('/^[0-9]?[0-9](,[0-9]{1,3})?$|^100(,0{1,3})?$/', $v);
    }

    public static function numeroNaturale(string $v): bool
    {
        return (bool)preg_match('/^[1-9]{1}[0-9]*$/', $v);
    }

    public static function codiceComuneCatastale(string $c): bool
    {
        $c = strtoupper(trim($c));
        return (bool)preg_match('/^[0-9A-Z]{4,5}$/', $c);
    }

    public static function serieRegistrazione(string $s): bool
    {
        $s = strtoupper(trim($s));
        $ammessi = ['3','3A','3B','3C','3P','3T','3V','1','1A','1B','1C','1D','1T','1V','2','2A','2B','2C','2V'];
        return in_array($s, $ammessi, true);
    }

    public static function tipologiaAdempimento(string $t): bool
    {
        return in_array($t, ['1','2','3','4','5','6','7','8'], true);
    }

    public static function cedolareSecca($v): bool
    {
        return in_array((string)$v, ['1','2','3'], true);
    }

    public static function sanificaCF(string $cf): string
    {
        return strtoupper(preg_replace('/\s+/', '', $cf));
    }

    public static function sanificaProvincia(string $p): string
    {
        $p = strtoupper(trim($p));
        return strlen($p) > 2 ? substr($p, 0, 2) : $p;
    }

    public static function sanificaData(?string $d): ?string
    {
        if (empty($d)) return null;
        $ts = strtotime($d);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Valida IBAN con algoritmo mod-97 (ISO 13616).
     */
    public static function iban(string $iban): bool
    {
        return self::ibanValidate($iban)['valid'];
    }

    /**
     * Valida IBAN e ritorna dettagli.
     */
    public static function ibanValidate(string $iban): array
    {
        if (empty($iban)) {
            return ['valid' => false, 'error' => 'IBAN vuoto', 'formatted' => '', 'country' => ''];
        }

        $clean = strtoupper(preg_replace('/\s+/', '', $iban));

        if (strlen($clean) < 15 || strlen($clean) > 34) {
            return ['valid' => false, 'error' => 'Lunghezza non valida', 'formatted' => $clean, 'country' => ''];
        }

        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $clean)) {
            return ['valid' => false, 'error' => 'Formato non valido', 'formatted' => $clean, 'country' => ''];
        }

        $country = substr($clean, 0, 2);

        $ibanLengths = [
            'AD'=>24,'AE'=>23,'AL'=>28,'AT'=>20,'AZ'=>28,'BA'=>20,'BE'=>16,'BG'=>22,
            'BH'=>22,'BR'=>29,'BY'=>28,'CH'=>21,'CR'=>22,'CY'=>28,'CZ'=>24,'DE'=>22,
            'DK'=>18,'DO'=>28,'EE'=>20,'EG'=>29,'ES'=>24,'FI'=>18,'FO'=>18,'FR'=>27,
            'GB'=>22,'GE'=>22,'GI'=>23,'GL'=>18,'GR'=>27,'GT'=>28,'HR'=>21,'HU'=>28,
            'IE'=>22,'IL'=>23,'IQ'=>23,'IS'=>26,'IT'=>27,'JO'=>30,'KW'=>30,'KZ'=>20,
            'LB'=>28,'LC'=>32,'LI'=>21,'LT'=>20,'LU'=>20,'LV'=>21,'MC'=>27,'MD'=>24,
            'ME'=>22,'MK'=>19,'MR'=>27,'MT'=>31,'MU'=>30,'NL'=>18,'NO'=>15,'PK'=>24,
            'PL'=>28,'PS'=>29,'PT'=>25,'QA'=>29,'RO'=>24,'RS'=>22,'SA'=>24,'SC'=>31,
            'SE'=>24,'SI'=>19,'SK'=>24,'SM'=>27,'ST'=>25,'SV'=>28,'TL'=>23,'TN'=>24,
            'TR'=>26,'UA'=>29,'VA'=>22,'VG'=>24,'XK'=>20,
        ];

        if (isset($ibanLengths[$country]) && strlen($clean) !== $ibanLengths[$country]) {
            return [
                'valid' => false,
                'error' => "Lunghezza errata per $country (attesi {$ibanLengths[$country]} caratteri)",
                'formatted' => $clean,
                'country' => $country
            ];
        }

        // Algoritmo mod-97
        $rearranged = substr($clean, 4) . substr($clean, 0, 4);
        $numericString = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $ch = $rearranged[$i];
            if (ctype_digit($ch)) {
                $numericString .= $ch;
            } else {
                $numericString .= (string)(ord($ch) - 55);
            }
        }

        // mod 97 su stringa (evita overflow)
        $remainder = 0;
        for ($i = 0; $i < strlen($numericString); $i++) {
            $remainder = ($remainder * 10 + (int)$numericString[$i]) % 97;
        }

        if ($remainder !== 1) {
            return ['valid' => false, 'error' => 'Checksum non valido', 'formatted' => $clean, 'country' => $country];
        }

        $formatted = implode(' ', str_split($clean, 4));
        return ['valid' => true, 'error' => null, 'formatted' => $formatted, 'country' => $country];
    }

    /**
     * Validazione completa di una pratica prima dell'invio.
     */
    public static function validaPraticaCompleta(
        array $pratica,
        array $locatori,
        array $conduttori,
        array $immobili,
        string $idContratto,
        int $numModuli
    ): array {
        $errori = [];
        $warning = [];
        $tipo = (string)($pratica['tipo_adempimento'] ?? '1');

        if (!self::idContratto($idContratto)) {
            $errori[] = "IDContratto '{$idContratto}' non rispetta il pattern XSD o supera 20 caratteri.";
        }

        if (!self::tipologiaContratto($pratica['tipologia_contratto'] ?? '')) {
            $errori[] = "Tipologia contratto non valida.";
        }

        if (!in_array($tipo, ['3','5'], true)) {
            $ced = (string)($pratica['cedolare_secca'] ?? '3');
            if (!self::cedolareSecca($ced)) {
                $errori[] = "CedolareSecca deve essere 1, 2 o 3. Trovato: {$ced}";
            }
        }

        if (!self::tipologiaAdempimento($tipo)) {
            $errori[] = "Tipologia adempimento '{$tipo}' non valida.";
        }

        if (!empty($pratica['data_stipula']) && !self::dataDt($pratica['data_stipula'])) {
            $errori[] = "DataStipula non può essere nel futuro.";
        }

        if ($tipo === '2' && empty($pratica['tipologia_proroga'])) {
            $errori[] = "TipologiaProroga obbligatoria per proroghe.";
        }

        if ($tipo === '6' && empty($pratica['tipologia_subentro'])) {
            $errori[] = "TipologiaSubentro obbligatoria.";
        }

        if (!empty($locatori) && $tipo !== '4') {
            $somma = 0;
            foreach ($locatori as $l) $somma += (float)($l['percentuale_possesso'] ?? 0);
            if (round($somma, 2) !== 100.0) {
                $errori[] = "Somma quote possesso = {$somma}%, deve essere 100%.";
            }
        }

        foreach (array_merge($locatori, $conduttori) as $s) {
            $cf = $s['codice_fiscale'] ?? '';
            if (!self::cf($cf)) {
                $errori[] = "CF non valido: {$cf} ({$s['cognome']})";
            }
        }

        foreach ($locatori as $l) {
            if (strlen($l['codice_fiscale'] ?? '') === 11 && ctype_digit($l['codice_fiscale'] ?? '') && empty($l['cf_rappresentante'])) {
                $warning[] = "Persona giuridica '{$l['cognome']}' senza rappresentante legale (rischio scarto).";
            }
        }

        foreach ($immobili as $i) {
            if (!self::tipologiaImmobile($i['tipologia_immobile'] ?? 1)) {
                $errori[] = "TipologiaImmobile non valida per {$i['identificativo_interno']}.";
            }
            if (empty($i['codice_comune']) && ($i['provincia'] ?? '') !== 'EE') {
                $warning[] = "CodiceComuneCatastale mancante per {$i['identificativo_interno']}.";
            }
            if (!empty($i['categoria']) && !self::categoriaCatastale($i['categoria'])) {
                $warning[] = "Categoria catastale dubbia: {$i['categoria']}.";
            }
            if (!empty($i['intero_porzione']) && !self::porzioneImmobile($i['intero_porzione'])) {
                $errori[] = "PorzioneImmobile deve essere I o P.";
            }
        }

        if (count($locatori) > 4 * $numModuli) {
            $errori[] = "Troppi locatori per i moduli dichiarati.";
        }
        if (count($immobili) > 4 * $numModuli) {
            $errori[] = "Troppi immobili per i moduli dichiarati.";
        }

        return [
            'ok' => count($errori) === 0,
            'errori' => $errori,
            'warning' => $warning,
        ];
    }
}
