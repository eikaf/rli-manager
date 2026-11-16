<?php
/**
 * RliTaxCalculator — Motore fiscale unificato RLI.
 *
 * Unica fonte di calcolo per imposta di registro, imposta di bollo, sanzioni e
 * interessi di ravvedimento operoso. I tassi legali vengono caricati dalla tabella
 * `tassi_interesse_legale` (con fallback alla mappa storica di rli_tasso_interesse_legale()).
 *
 * La classe è istanziabile e testabile: è possibile iniettare un PDO oppure
 * impostare direttamente i tassi con setTassiLegali().
 */
class RliTaxCalculator
{
    private ?PDO $pdo;
    /** @var array<int,float>|null */
    private ?array $tassiLegali = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    // ---------------------------------------------------------------------
    // Tassi e aliquote
    // ---------------------------------------------------------------------

    /** @return array<int,float> */
    private function tassiLegali(): array
    {
        if ($this->tassiLegali !== null) {
            return $this->tassiLegali;
        }
        $this->tassiLegali = [];
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query("SELECT anno, tasso FROM tassi_interesse_legale");
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($rows as $anno => $tasso) {
                    $this->tassiLegali[(int)$anno] = (float)$tasso;
                }
            } catch (Throwable $e) {
                $this->tassiLegali = [];
            }
        }
        return $this->tassiLegali;
    }

    /** @param array<int|string,float> $tassi */
    public function setTassiLegali(array $tassi): void
    {
        $this->tassiLegali = [];
        foreach ($tassi as $anno => $tasso) {
            $this->tassiLegali[(int)$anno] = (float)$tasso;
        }
    }

    public function tassoInteresseLegale(string $data): float
    {
        $ts = $data !== '' ? strtotime($data) : false;
        $anno = $ts ? (int)date('Y', $ts) : (int)date('Y');

        $rates = $this->tassiLegali();
        if (isset($rates[$anno])) {
            return $rates[$anno];
        }
        // Fallback alla mappa storica globale
        if (function_exists('rli_tasso_interesse_legale')) {
            return rli_tasso_interesse_legale($data !== '' ? $data : date('Y-m-d'));
        }
        return 0.02;
    }

    /** @return array{0: ?float, 1: ?float} [aliquota, importo_fisso] */
    public function aliquotaRegistro(string $tipologia): array
    {
        if (function_exists('rli_aliquota_registro')) {
            return rli_aliquota_registro($tipologia);
        }
        $map = [
            'L1' => [0.02, null], 'L2' => [0.014, null], 'L3' => [null, 67.00], 'L4' => [null, 200.00],
            'S1' => [0.02, null], 'S2' => [0.01, null], 'S3' => [null, 200.00],
            'T1' => [0.005, null], 'T2' => [null, 67.00], 'T3' => [0.02, null], 'T4' => [null, 67.00],
        ];
        return $map[$tipologia] ?? [0.02, null];
    }

    // ---------------------------------------------------------------------
    // Imposta di registro
    // ---------------------------------------------------------------------

    public function calcolaImpostaRegistro(array $p, array $locatori = [], array $immobili = [], array $opts = []): float
    {
        $tipo = (string)($p['tipo_adempimento'] ?? $p['tipologia_adempimento'] ?? '1');
        $tipologia = strtoupper(trim((string)($p['tipologia_contratto'] ?? 'L1')));
        $canone = (float)($p['importo_canone'] ?? 0);
        if ($canone < 0) $canone = 0;

        $esenteRaw = $p['contratto_esente'] ?? null;
        $esente = false;
        if ($esenteRaw !== null && $esenteRaw !== '' && $esenteRaw !== '0' && $esenteRaw !== 0 && $esenteRaw !== false) {
            $esente = true;
        }

        $agevRaw = $p['agevolazioni'] ?? null;
        $agevolazioni = ($agevRaw == 1 || $agevRaw === true || $agevRaw === '1');

        $casi = $p['casi_particolari'] ?? null;
        if ($casi === '' || $casi === '0' || $casi === 0) $casi = null;
        $casi = $casi !== null ? (string)$casi : null;

        $interaRaw = $p['pagamento_intera_durata'] ?? null;
        $interaDurata = ($interaRaw == 1 || $interaRaw === true || $interaRaw === '1');

        // Stato cedolare: dai locatori (più affidabile) oppure dal contratto
        $ced = null;
        if (!empty($locatori)) {
            $totLocatori = count($locatori);
            $totCedolari = 0;
            foreach ($locatori as $l) {
                if (!empty($l['opzione_cedolare'])) $totCedolari++;
            }
            if ($totCedolari === $totLocatori) {
                $ced = '1';
            } elseif ($totCedolari > 0) {
                $ced = '2';
            } else {
                $ced = '3';
            }
        } else {
            $cedRaw = $p['cedolare_secca'] ?? null;
            if ($cedRaw !== null && $cedRaw !== '' && $cedRaw !== 0) {
                $ced = (string)$cedRaw;
            }
        }

        if ($esente) {
            return 0.0;
        }
        if ($ced === '1' && in_array($tipologia, ['L1', 'L2', 'S1'], true)) {
            return 0.0;
        }
        if ($agevolazioni) {
            return in_array($tipologia, ['L4', 'S3'], true) ? 200.00 : 67.00;
        }

        $quota = 1.0;
        if ($ced === '2' && count($locatori) > 0) {
            $noCed = 0.0;
            foreach ($locatori as $l) {
                if (empty($l['opzione_cedolare'])) {
                    $q = (float)($l['percentuale_possesso'] ?? 0);
                    if ($q > 0 && $q <= 100) {
                        $noCed += $q;
                    }
                }
            }
            if ($noCed <= 0) return 0.0;
            $quota = max(0.0, min(1.0, $noCed / 100.0));
        }

        if ($tipo === '1' || $tipo === '' || $tipo === '0') {
            return $this->primaRegistrazione($p, $quota, $casi, $interaDurata);
        }
        if ($tipo === '2') {
            return $this->proroga($p, $quota);
        }
        if ($tipo === '3') {
            $corr = (float)($p['corrispettivo'] ?? 0);
            $min = in_array($tipologia, ['L4', 'S3'], true) ? 200.00 : 67.00;
            if ($corr > 0) {
                $al = 0.02;
                if (in_array($tipologia, ['L4', 'S3'], true)) $al = 0.03;
                elseif (in_array($tipologia, ['T1', 'T2', 'T4'], true)) $al = 0.005;
                elseif ($tipologia === 'S2') $al = 0.01;
                return max($min, $corr * $al * $quota);
            }
            return $min;
        }
        if ($tipo === '4' || $tipo === '7') {
            if ($ced === '1') return 0.0;
            $corr = (float)($p['corrispettivo'] ?? 0);
            if ($corr > 0) {
                $al = ($tipo === '7') ? 0.005 : 0.03;
                return max(67.00, $corr * $al * $quota);
            }
            return 67.00;
        }
        if ($tipo === '5') {
            return max(0.0, (float)($p['imposta_registro'] ?? 0));
        }
        if ($tipo === '6') {
            return $agevolazioni ? 0.0 : 67.00;
        }
        if ($tipo === '8') {
            $inAum = ($p['rinegoziazione_tipo'] ?? 'aumento') === 'aumento';
            if (!$inAum && in_array($tipologia, ['L1', 'L2', 'L3', 'L4', 'S1', 'S2', 'S3'], true)) {
                return 0.0;
            }
            return 67.00;
        }

        return 67.00;
    }

    private function primaRegistrazione(array $p, float $quota, $casi, bool $interaDurata): float
    {
        $tipologia = strtoupper(trim((string)($p['tipologia_contratto'] ?? 'L1')));
        $canone = (float)($p['importo_canone'] ?? 0);

        [$al, $fisso] = $this->aliquotaRegistro($tipologia);
        if ($al === null && $fisso === null) $al = 0.02;

        if ($fisso !== null) {
            $imp = $fisso * $quota;
            if (!in_array($tipologia, ['L4', 'S3'], true)) {
                $imp = max(67.00, $imp);
            }
            return round($imp, 2);
        }

        if ($casi === '1' && $canone <= 0) {
            return 67.00;
        }

        $base = $canone * $al;

        $dal = $p['durata_dal'] ?? null;
        $alD = $p['durata_al'] ?? null;

        if ($interaDurata && $dal && $alD && in_array($tipologia, ['L1', 'L2', 'S1', 'S2'], true)) {
            $tsDal = strtotime($dal);
            $tsAlD = strtotime($alD);
            if ($tsDal && $tsAlD && $tsAlD > $tsDal) {
                $giorni = ($tsAlD - $tsDal) / 86400 + 1;
                $mesi = round($giorni / 30.4375);
                if ($mesi > 12) {
                    $impBase = ($al * $canone / 12) * $mesi;
                    $anni = max(1, floor($giorni / 365));
                    $tasso = $this->tassoInteresseLegale($dal);
                    $detrazione = $impBase * $tasso / 200 * $anni;
                    $base = $impBase - $detrazione;
                }
            }
        }

        $imp = round($base) * $quota;
        return max(67.00, round($imp, 2));
    }

    private function proroga(array $p, float $quota): float
    {
        $tipologia = strtoupper(trim((string)($p['tipologia_contratto'] ?? 'L1')));
        $canone = (float)($p['importo_canone'] ?? 0);
        [$al, $fisso] = $this->aliquotaRegistro($tipologia);

        if ($fisso !== null) {
            $imp = $fisso * $quota;
            if (!in_array($tipologia, ['L4', 'S3'], true)) {
                $imp = max(67.00, $imp);
            }
            return round($imp, 2);
        }

        if ($al === null) $al = 0.02;

        $imp = round($canone * $al) * $quota;
        return max(67.00, round($imp, 2));
    }

    // ---------------------------------------------------------------------
    // Imposta di bollo
    // ---------------------------------------------------------------------

    public function calcolaImpostaBollo(array $p): float
    {
        $tipologia = strtoupper(trim((string)($p['tipologia_contratto'] ?? 'L1')));
        $tipo = (string)($p['tipo_adempimento'] ?? $p['tipologia_adempimento'] ?? '1');

        $esenteRaw = $p['contratto_esente'] ?? null;
        $esente = ($esenteRaw !== null && $esenteRaw !== '' && $esenteRaw !== '0' && $esenteRaw !== 0);

        $agevRaw = $p['agevolazioni'] ?? null;
        $agevolazioni = ($agevRaw == 1 || $agevRaw === true || $agevRaw === '1');

        $cedRaw = $p['cedolare_secca'] ?? null;
        $ced = ($cedRaw !== null && $cedRaw !== '' && $cedRaw !== 0) ? (string)$cedRaw : null;

        if ($esente && in_array((string)$esenteRaw, ['1', '2'], true)) return 0.0;
        if ($agevolazioni) return 0.0;
        if ($tipo !== '1' && $tipo !== '8') return 0.0;
        if ($ced === '1') return 0.0;

        if (in_array($tipologia, ['L4', 'S3'], true)) return 16.00;

        $tgRaw = $p['tipo_garanzia'] ?? null;
        $tg = ($tgRaw !== null && $tgRaw !== '') ? (string)$tgRaw : null;
        if (in_array($tipologia, ['T1', 'T2'], true) && !in_array($tg, ['3', '4'], true)) {
            return 0.0;
        }

        $fogli = max(1, (int)($p['numero_pagine'] ?? 1));
        $copie = max(1, (int)($p['numero_copie'] ?? 1));

        $b = 16.00 * $fogli * $copie;
        $b += 16.00 * (int)($p['numero_allegato_a'] ?? 0) * $copie;
        $b += 2.00 * (int)($p['numero_allegato_b'] ?? 0) * $copie;
        $b += 1.00 * (int)($p['numero_allegato_c'] ?? 0) * $copie;

        return round($b, 2);
    }

    // ---------------------------------------------------------------------
    // Termine di riferimento / ravvedimento
    // ---------------------------------------------------------------------

    public function calcolaTermineRiferimento(array $p): ?string
    {
        $tipo = (string)($p['tipo_adempimento'] ?? $p['tipologia_adempimento'] ?? '1');
        $stip = $p['data_stipula'] ?? null;
        $inizio = $p['durata_dal'] ?? null;
        $fine = $p['durata_al'] ?? null;

        if ($tipo === '1' || $tipo === '' || $tipo === '0') {
            $annualita = $p['annualita'] ?? null;
            if (!empty($annualita) && preg_match('/^(18|19|20)[0-9]{2}$/', (string)$annualita)) {
                $anno = (int)$annualita;
                $base = $inizio ?: $stip;
                if (empty($base)) {
                    return sprintf('%04d-01-01', $anno);
                }
                $tsBase = strtotime($base);
                if (!$tsBase) return null;
                $giorno = (int)date('d', $tsBase);
                $mese = (int)date('m', $tsBase);
                return sprintf('%04d-%02d-%02d', $anno, $mese, $giorno);
            }

            if (!$stip) return $inizio;
            if (!$inizio) return $stip;
            return (strtotime($stip) <= strtotime($inizio)) ? $stip : $inizio;
        }

        if ($tipo === '2') {
            return $p['data_fine_proroga'] ?? $fine;
        }
        if ($tipo === '3') {
            return $p['data_cessione'] ?? null;
        }
        if ($tipo === '4' || $tipo === '7') {
            return $p['durata_al'] ?? null;
        }
        if ($tipo === '6') {
            return $p['data_subentro'] ?? null;
        }
        if ($tipo === '8') {
            $ds = $p['data_stipula'] ?? null;
            $dr = $p['data_inizio_rinegoziazione'] ?? null;
            if (!$ds) return $dr;
            if (!$dr) return $ds;
            return (strtotime($dr) < strtotime($ds)) ? $dr : $ds;
        }
        return $stip;
    }

    public function calcolaRavvedimento(array $p, float $impReg, float $impBol): array
    {
        $tipo = (string)($p['tipo_adempimento'] ?? $p['tipologia_adempimento'] ?? '1');

        $oggi = !empty($p['data_invio']) ? $p['data_invio'] : date('Y-m-d');
        $termineRif = $this->calcolaTermineRiferimento($p);

        $escludiSanzioni = false;
        $motivoEsclusione = '';

        if (in_array($tipo, ['4', '7'], true)) {
            $cedRis = (string)($p['cedolare_secca'] ?? '3');
            if ($cedRis === '1') {
                $escludiSanzioni = true;
                $motivoEsclusione = 'Risoluzione in cedolare secca 100%: sanzioni e interessi non dovuti';
            }
        } elseif ($tipo === '6') {
            $escludiSanzioni = true;
            $motivoEsclusione = 'Subentro: sanzioni e interessi non dovuti';
        } elseif ($tipo === '8' && ($p['rinegoziazione_tipo'] ?? 'aumento') === 'diminuzione') {
            $escludiSanzioni = true;
            $motivoEsclusione = 'Rinegoziazione in diminuzione: sanzioni e interessi non dovuti';
        }

        if ($tipo === '1' || $tipo === '') {
            $dal = $p['durata_dal'] ?? null;
            $al = $p['durata_al'] ?? null;
            if ($dal && $al) {
                $ggDurata = (int)floor((strtotime($al) - strtotime($dal)) / 86400);
                if ($ggDurata <= 30) {
                    $escludiSanzioni = true;
                    $motivoEsclusione = 'Contratto di durata ≤ 30 giorni: sanzioni e interessi non dovuti';
                }
            }
            $tipologia = strtoupper(trim((string)($p['tipologia_contratto'] ?? 'L1')));
            if (in_array($tipologia, ['L4', 'S3', 'T2'], true)) {
                $escludiSanzioni = true;
                $motivoEsclusione = 'Tipologia ' . $tipologia . ': sanzioni e interessi non dovuti';
            }
        }

        if ($impReg <= 0 && $impBol <= 0) {
            $escludiSanzioni = true;
            if (!$motivoEsclusione) {
                $motivoEsclusione = 'Imposta base = 0: nessuna sanzione/interesse dovuti';
            }
        }

        $giorni = 0;
        if ($termineRif) {
            $gg = (int)floor((strtotime($oggi) - strtotime($termineRif)) / 86400);
            $giorni = max(0, $gg);
        }

        if ($giorni <= 30) {
            return [
                'termine_riferimento' => $termineRif,
                'giorni_ritardo' => $giorni,
                'in_termine' => true,
                'escluso' => $escludiSanzioni,
                'motivo_esclusione' => $motivoEsclusione,
                'aliquota_sanz_registro' => 0,
                'aliquota_sanz_bollo' => 0,
                'riduzione' => null,
                'descrizione' => 'Nei termini (≤ 30 giorni)',
                'sanzione_registro' => 0,
                'sanzione_bollo' => 0,
                'interessi_registro' => 0,
                'interessi_bollo' => 0,
                'totale' => 0,
            ];
        }

        if ($escludiSanzioni) {
            return [
                'termine_riferimento' => $termineRif,
                'giorni_ritardo' => $giorni,
                'in_termine' => false,
                'escluso' => true,
                'motivo_esclusione' => $motivoEsclusione,
                'aliquota_sanz_registro' => 0,
                'aliquota_sanz_bollo' => 0,
                'riduzione' => null,
                'descrizione' => $motivoEsclusione,
                'sanzione_registro' => 0,
                'sanzione_bollo' => 0,
                'interessi_registro' => 0,
                'interessi_bollo' => 0,
                'totale' => 0,
            ];
        }

        $tsInizio = strtotime($termineRif);
        $dal_norm = strtotime('2024-09-01');
        $al_norm = strtotime('2025-06-13');

        $aliquotaSanz = 0.0;
        $aliquotaSanzBollo = 0.0;
        $riduzione = '';
        $minimo = 0.0;
        $descrizione = '';

        if ($tsInizio < $dal_norm) {
            if ($tipo === '1' || $tipo === '') {
                if ($giorni <= 90) {
                    $aliquotaSanz = 0.12; $minimo = 20.00;
                    $descrizione = 'Ravvedimento entro 90gg (1/10 di 120%)';
                } elseif ($giorni <= 365) {
                    $aliquotaSanz = 0.15;
                    $descrizione = 'Ravvedimento entro 1 anno (1/8 di 120%)';
                } elseif ($giorni <= 730) {
                    $aliquotaSanz = 0.1714;
                    $descrizione = 'Ravvedimento oltre 1 anno (1/7 di 120%)';
                } else {
                    $aliquotaSanz = 0.20;
                    $descrizione = 'Ravvedimento oltre 2 anni (1/6 di 120%)';
                }
                if ($giorni <= 90) $aliquotaSanzBollo = 0.0888;
                elseif ($giorni <= 365) $aliquotaSanzBollo = 0.10;
                else $aliquotaSanzBollo = 0.1143;
            } else {
                if ($giorni <= 30) $aliquotaSanz = 0.015;
                elseif ($giorni <= 90) $aliquotaSanz = 0.0167;
                elseif ($giorni <= 365) $aliquotaSanz = 0.0375;
                elseif ($giorni <= 730) $aliquotaSanz = 0.0429;
                else $aliquotaSanz = 0.05;
                $descrizione = 'Ravvedimento adempimento successivo';
                $aliquotaSanzBollo = 0;
            }
        } elseif ($tsInizio < $al_norm) {
            if ($tipo === '1' || $tipo === '') {
                if ($giorni <= 90) {
                    $aliquotaSanz = 0.12;
                    $descrizione = 'Ravvedimento entro 90gg (1/10 di 120%)';
                } elseif ($giorni <= 365) {
                    $aliquotaSanz = 0.15;
                    $descrizione = 'Ravvedimento entro 1 anno (1/8 di 120%)';
                } else {
                    $aliquotaSanz = 0.1714;
                    $descrizione = 'Ravvedimento oltre 1 anno (1/7 di 120%)';
                }
                if ($giorni <= 90) $aliquotaSanzBollo = 0.0888;
                elseif ($giorni <= 365) $aliquotaSanzBollo = 0.10;
                else $aliquotaSanzBollo = 0.1143;
            } else {
                if ($giorni <= 30) $aliquotaSanz = 0.0125;
                elseif ($giorni <= 90) $aliquotaSanz = 0.0139;
                elseif ($giorni <= 365) $aliquotaSanz = 0.0312;
                else $aliquotaSanz = 0.0357;
                $descrizione = 'Ravvedimento adempimento successivo';
                $aliquotaSanzBollo = 0;
            }
        } else {
            if ($tipo === '1' || $tipo === '') {
                if ($giorni <= 90) {
                    $aliquotaSanz = 0.12; $minimo = 25.00;
                    $descrizione = 'Ravvedimento entro 90gg (1/10 di 120%)';
                } elseif ($giorni <= 365) {
                    $aliquotaSanz = 0.15; $minimo = 31.25;
                    $descrizione = 'Ravvedimento entro 1 anno (1/8 di 120%)';
                } else {
                    $aliquotaSanz = 0.1714; $minimo = 35.71;
                    $descrizione = 'Ravvedimento oltre 1 anno (1/7 di 120%)';
                }
                if ($giorni <= 90) $aliquotaSanzBollo = 0.0888;
                elseif ($giorni <= 365) $aliquotaSanzBollo = 0.10;
                else $aliquotaSanzBollo = 0.1143;
            } else {
                if ($giorni <= 30) $aliquotaSanz = 0.0125;
                elseif ($giorni <= 90) $aliquotaSanz = 0.0139;
                elseif ($giorni <= 365) $aliquotaSanz = 0.0312;
                else $aliquotaSanz = 0.0357;
                $descrizione = 'Ravvedimento adempimento successivo';
                $aliquotaSanzBollo = 0;
            }
        }

        $sanzReg = round($impReg * $aliquotaSanz, 2);
        if ($minimo > 0 && $sanzReg < $minimo && $impReg > 0) {
            $sanzReg = $minimo;
        }
        $sanzBol = round($impBol * $aliquotaSanzBollo, 2);

        $intReg = 0.0;
        $intBol = 0.0;
        if ($sanzReg > 0 && $impReg > 0) {
            $intReg = $this->calcolaInteressi($impReg, $termineRif, $oggi);
        }
        if ($sanzBol > 0 && $impBol > 0) {
            $intBol = $this->calcolaInteressi($impBol, $termineRif, $oggi);
        }

        return [
            'termine_riferimento' => $termineRif,
            'giorni_ritardo' => $giorni,
            'in_termine' => false,
            'escluso' => false,
            'motivo_esclusione' => '',
            'aliquota_sanz_registro' => $aliquotaSanz,
            'aliquota_sanz_bollo' => $aliquotaSanzBollo,
            'riduzione' => $riduzione,
            'descrizione' => $descrizione,
            'sanzione_registro' => $sanzReg,
            'sanzione_bollo' => $sanzBol,
            'interessi_registro' => $intReg,
            'interessi_bollo' => $intBol,
            'totale' => $sanzReg + $sanzBol + $intReg + $intBol,
        ];
    }

    // ---------------------------------------------------------------------
    // Interessi e utilità
    // ---------------------------------------------------------------------

    public function calcolaInteressi(float $base, string $dRif, string $dTrasm): float
    {
        if ($base <= 0) return 0.0;
        $tR = strtotime($dRif);
        $tT = strtotime($dTrasm);
        if (!$tR || !$tT || $tT <= $tR) return 0.0;
        $gg = (int)floor(($tT - $tR) / 86400);
        $t = $this->tassoInteresseLegale($dTrasm);
        return round($base * $t / 365 * $gg, 2);
    }

    public function calcolaSanzioneRegistro(string $stip, ?string $inizio, string $trasm, float $impBase): float
    {
        if ($impBase <= 0) return 0.0;
        $p = [
            'tipo_adempimento' => '1',
            'data_stipula' => $stip,
            'durata_dal' => $inizio,
            'data_invio' => $trasm,
        ];
        $res = $this->calcolaRavvedimento($p, $impBase, 0);
        return $res['sanzione_registro'];
    }

    public function calcolaSanzioneBollo(string $stip, string $trasm, float $impBollo): float
    {
        if ($impBollo <= 0) return 0.0;
        $p = [
            'tipo_adempimento' => '1',
            'data_stipula' => $stip,
            'data_invio' => $trasm,
        ];
        $res = $this->calcolaRavvedimento($p, 0, $impBollo);
        return $res['sanzione_bollo'];
    }

    public function calcolaTutto(array $p, array $locatori = []): array
    {
        $impReg = $this->calcolaImpostaRegistro($p, $locatori);
        $impBol = $this->calcolaImpostaBollo($p);
        $rav = $this->calcolaRavvedimento($p, $impReg, $impBol);

        return [
            'imposta_registro' => $impReg,
            'imposta_bollo' => $impBol,
            'sanzione_registro' => $rav['sanzione_registro'],
            'sanzione_bollo' => $rav['sanzione_bollo'],
            'interessi_registro' => $rav['interessi_registro'],
            'interessi_bollo' => $rav['interessi_bollo'],
            'totale' => $impReg + $impBol + $rav['totale'],
            'ravvedimento' => $rav,
        ];
    }
}