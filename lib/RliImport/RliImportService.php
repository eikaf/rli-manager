<?php
/**
 * RliImportService — Import di un file RLI12 nel database.
 *
 * Supporta:
 *   - Prima registrazione (tipologia 1): import completo (contratto + soggetti + immobili + proprietà)
 *   - Adempimenti successivi (tipologie 2, 3, 4, 6, 7, 8): import come evento su contratto esistente
 *
 * Regole cessione/subentro:
 *   - Crea il nuovo soggetto se manca
 *   - Sostituisce il conduttore cedente con il cessionario sul contratto
 *   - NON modifica la durata_al del contratto
 *   - Lo stato resta ATTIVO
 */
class RliImportService
{
    /** @var PDO */
    private $pdo;

    /** @var RliParser */
    private $parser;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->parser = new RliParser();
    }

    // ============================================================
    // ANALISI / ANTEPRIMA
    // ============================================================
    public function analizza(string $filePath): array
    {
        $parsed = $this->parser->parseFile($filePath);

        if (empty($parsed['documenti'])) {
            throw new RuntimeException("Il file non contiene documenti.");
        }

        $documento = $parsed['documenti'][0];
        $front = $documento['frontespizio'];
        $ademp = $front['ademp_succ'] ?? [];

        $tipoAdemp = (int)($ademp['tipologia_adempimento'] ?? 1);
        $codAde    = trim($ademp['id_telematico'] ?? '');
        $idU       = trim($front['id_contratto'] ?? '');

        $anteprima = [
            'id_contratto_univoco' => $idU,
            'codice_ade'           => $codAde,
            'tipologia_contratto'  => $front['tipologia_contratto'] ?? '',
            'data_stipula'         => $front['registrazione']['data_stipula'] ?? null,
            'durata_dal'           => $front['data_inizio'] ?? null,
            'durata_al'            => $front['data_fine'] ?? null,
            'importo_canone'       => $front['importo_canone'] ?? 0,
            'tipo_adempimento'     => $tipoAdemp,
            'tipo_adempimento_label' => $this->labelTipoAdempimento($tipoAdemp),
            'modo_import'          => 'skip', // skip | insert_completo | insert_evento | errore
            'motivo_skip'          => null,
            'contratto_originale_id' => null,
            'contratto_originale_univoco' => null,
            'evento_da_importare'  => null,
            'locatori'             => [],
            'conduttori'           => [],
            'immobili'             => [],
            'proprieta'            => [],
            'relazioni'            => $documento['relazioni'] ?? [],
        ];

        // === RICERCA CONTRATTO ESISTENTE ===
        $contrattoOriginale = null;

        // Prima cerco per codice AdE (IDTelematico), che è la chiave per gli adempimenti successivi
        if ($codAde !== '') {
            $stmt = $this->pdo->prepare("SELECT id, id_contratto_univoco, codice_identificativo_contratto, stato FROM contratti WHERE codice_identificativo_contratto = ? LIMIT 1");
            $stmt->execute([$codAde]);
            $contrattoOriginale = $stmt->fetch() ?: null;
        }

        // Poi per ID interno (id_contratto_univoco)
        if (!$contrattoOriginale && $idU !== '') {
            $stmt = $this->pdo->prepare("SELECT id, id_contratto_univoco, codice_identificativo_contratto, stato FROM contratti WHERE id_contratto_univoco = ? LIMIT 1");
            $stmt->execute([$idU]);
            $contrattoOriginale = $stmt->fetch() ?: null;
        }

        if ($contrattoOriginale) {
            $anteprima['contratto_originale_id'] = (int)$contrattoOriginale['id'];
            $anteprima['contratto_originale_univoco'] = $contrattoOriginale['id_contratto_univoco'];
        }

        // === DETERMINA IL MODO DI IMPORT ===
        if ($tipoAdemp === 1) {
            // Prima registrazione
            if ($contrattoOriginale) {
                $anteprima['modo_import'] = 'skip';
                $anteprima['motivo_skip'] = "Contratto già presente (contratto #{$contrattoOriginale['id']}).";
            } else {
                $anteprima['modo_import'] = 'insert_completo';
            }
        } else {
            // Adempimento successivo (2,3,4,6,7,8)
            if (!$contrattoOriginale) {
                $anteprima['modo_import'] = 'errore';
                $anteprima['motivo_skip'] = "Contratto originale con codice AdE '{$codAde}' non trovato. Importa prima la prima registrazione.";
            } else {
                // Verifica duplicato evento
                $tipoEvento = $this->mappaTipoEvento($tipoAdemp);
                $dataEvento = $ademp['data_fine'] ?? $ademp['data_stipula'] ?? null;

                if ($tipoEvento && $dataEvento) {
                    $stmtDup = $this->pdo->prepare("
                        SELECT id FROM eventi_contratto
                        WHERE id_contratto = ? AND tipo = ? AND data_evento = ?
                        LIMIT 1
                    ");
                    $stmtDup->execute([(int)$contrattoOriginale['id'], $tipoEvento, $dataEvento]);
                    if ($stmtDup->fetchColumn()) {
                        $anteprima['modo_import'] = 'skip';
                        $anteprima['motivo_skip'] = "Evento '{$tipoEvento}' del {$dataEvento} già presente sul contratto #{$contrattoOriginale['id']}.";
                    } else {
                        $anteprima['modo_import'] = 'insert_evento';
                        $anteprima['evento_da_importare'] = [
                            'tipo' => $tipoEvento,
                            'data_evento' => $dataEvento,
                            'data_invio' => $front['data_invio'] ?? null,
                            'tipologia_proroga' => $ademp['tipologia_proroga'] ?? null,
                            'tipologia_subentro' => $ademp['tipologia_subentro'] ?? null,
                            'corrispettivo' => $ademp['corrispettivo'] ?? null,
                            'data_inizio_rineg' => $ademp['data_inizio_rineg'] ?? null,
                            'data_stipula' => $ademp['data_stipula'] ?? null,
                            'importi' => $front['importi'] ?? [],
                        ];
                    }
                } else {
                    $anteprima['modo_import'] = 'errore';
                    $anteprima['motivo_skip'] = "Impossibile determinare tipo evento o data.";
                }
            }
        }

        // === SOGGETTI / IMMOBILI (per anteprima) ===
        foreach ($documento['soggetti']['locatori'] as $loc) {
            $cf = $loc['codice_fiscale'];
            $found = $this->findSoggettoByCf($cf);
            $loc['esistente'] = $found !== null;
            $loc['id_esistente'] = $found ? (int)$found['id'] : null;
            $loc['tipologia_default'] = 'LOCATORE';
            $anteprima['locatori'][] = $loc;
        }

        foreach ($documento['soggetti']['conduttori'] as $cond) {
            $cf = $cond['codice_fiscale'];
            $found = $this->findSoggettoByCf($cf);
            $cond['esistente'] = $found !== null;
            $cond['id_esistente'] = $found ? (int)$found['id'] : null;
            $cond['tipologia_default'] = 'CONDUTTORE';
            $anteprima['conduttori'][] = $cond;
        }

        foreach ($documento['immobili'] as $imm) {
            $found = $this->findImmobile($imm);
            $imm['esistente'] = $found !== null;
            $imm['id_esistente'] = $found ? (int)$found['id'] : null;
            $anteprima['immobili'][] = $imm;
        }

        // === PROPRIETÀ (solo per prima registrazione) ===
        if ($tipoAdemp === 1) {
            $quotaPerLocatore = [];
            foreach ($documento['relazioni']['locazioni'] as $rel) {
                $locNum = (int)$rel['locatore_numero'];
                $quota = (float)$rel['percentuale_possesso'];
                if (!isset($quotaPerLocatore[$locNum]) || $quota > $quotaPerLocatore[$locNum]) {
                    $quotaPerLocatore[$locNum] = $quota;
                }
            }

            foreach ($documento['soggetti']['locatori'] as $locIdx => $loc) {
                $progressivoLoc = $locIdx + 1;
                $quota = $quotaPerLocatore[$progressivoLoc] ?? 100.0;

                foreach ($documento['immobili'] as $immIdx => $imm) {
                    $idLoc = $loc['id_esistente'] ?? null;
                    $idImm = $imm['id_esistente'] ?? null;
                    $esiste = false;

                    if ($idLoc !== null && $idImm !== null) {
                        $stmtProp = $this->pdo->prepare("SELECT id FROM proprieta_immobili WHERE id_locatore = ? AND id_immobile = ? LIMIT 1");
                        $stmtProp->execute([$idLoc, $idImm]);
                        $esiste = (bool)$stmtProp->fetchColumn();
                    }

                    $anteprima['proprieta'][] = [
                        'locatore_cf'     => $loc['codice_fiscale'],
                        'locatore_nome'   => trim(($loc['cognome'] ?? '') . ' ' . ($loc['nome'] ?? '')),
                        'immobile_chiave' => $this->chiaveImmobile($imm),
                        'quota'           => $quota,
                        'esiste'          => $esiste,
                    ];
                }
            }
        }

        return [
            'parsed'    => $parsed,
            'anteprima' => $anteprima,
        ];
    }

    // ============================================================
    // IMPORT
    // ============================================================
    public function importa(array $parsed, array $opzioni = []): array
    {
        $opzioni = array_merge([
            'crea_soggetti' => true,
            'crea_immobili' => true,
        ], $opzioni);

        if (empty($parsed['documenti'])) {
            throw new RuntimeException("Nessun documento da importare.");
        }

        $documento = $parsed['documenti'][0];
        $front = $documento['frontespizio'];
        $ademp = $front['ademp_succ'] ?? [];
        $tipoAdemp = (int)($ademp['tipologia_adempimento'] ?? 1);
        $codAde = trim($ademp['id_telematico'] ?? '');
        $idU = trim($front['id_contratto'] ?? '');

        if ($tipoAdemp === 1) {
            // Prima registrazione → import completo
            return $this->importaRegistrazione($parsed, $opzioni);
        }

        // Adempimento successivo → import evento
        return $this->importaEventoSuccessivo($parsed, $opzioni);
    }

    // ============================================================
    // IMPORT: PRIMA REGISTRAZIONE
    // ============================================================
    private function importaRegistrazione(array $parsed, array $opzioni): array
    {
        $documento = $parsed['documenti'][0];
        $front = $documento['frontespizio'];
        $idU = trim($front['id_contratto'] ?? '');
        $codAde = trim($front['ademp_succ']['id_telematico'] ?? '');

        // Ricontrollo esistenza
        if ($idU !== '') {
            $stmt = $this->pdo->prepare("SELECT id FROM contratti WHERE id_contratto_univoco = ? LIMIT 1");
            $stmt->execute([$idU]);
            if ($stmt->fetchColumn()) throw new RuntimeException("Contratto '{$idU}' già presente. Import annullato.");
        }
        if ($codAde !== '') {
            $stmt = $this->pdo->prepare("SELECT id FROM contratti WHERE codice_identificativo_contratto = ? LIMIT 1");
            $stmt->execute([$codAde]);
            if ($stmt->fetchColumn()) throw new RuntimeException("Contratto con codice AdE '{$codAde}' già presente. Import annullato.");
        }

        $this->pdo->beginTransaction();
        try {
            $creati = [];
            $collegati = [];

            // Locatori
            $idLocatori = [];
            foreach ($documento['soggetti']['locatori'] as $loc) {
                $cf = $loc['codice_fiscale'];
                if ($cf === '') continue;
                $found = $this->findSoggettoByCf($cf);
                if ($found) {
                    $idLocatori[] = (int)$found['id'];
                    $collegati[] = ['tipo' => 'locatore', 'cf' => $cf, 'id' => (int)$found['id']];
                } elseif ($opzioni['crea_soggetti']) {
                    $newId = $this->insertSoggetto($loc, 'LOCATORE');
                    $idLocatori[] = $newId;
                    $creati[] = ['tipo' => 'locatore', 'cf' => $cf, 'id' => $newId];
                } else {
                    throw new RuntimeException("Locatore CF {$cf} non presente e creazione disabilitata.");
                }
            }

            // Conduttori
            $idConduttori = [];
            foreach ($documento['soggetti']['conduttori'] as $cond) {
                $cf = $cond['codice_fiscale'];
                if ($cf === '') continue;
                $found = $this->findSoggettoByCf($cf);
                if ($found) {
                    $idConduttori[] = (int)$found['id'];
                    $collegati[] = ['tipo' => 'conduttore', 'cf' => $cf, 'id' => (int)$found['id']];
                } elseif ($opzioni['crea_soggetti']) {
                    $newId = $this->insertSoggetto($cond, 'CONDUTTORE');
                    $idConduttori[] = $newId;
                    $creati[] = ['tipo' => 'conduttore', 'cf' => $cf, 'id' => $newId];
                } else {
                    throw new RuntimeException("Conduttore CF {$cf} non presente e creazione disabilitata.");
                }
            }

            // Immobili
            $idImmobili = [];
            foreach ($documento['immobili'] as $imm) {
                $found = $this->findImmobile($imm);
                if ($found) {
                    $idImmobili[] = (int)$found['id'];
                    $collegati[] = ['tipo' => 'immobile', 'chiave' => $this->chiaveImmobile($imm), 'id' => (int)$found['id']];
                } elseif ($opzioni['crea_immobili']) {
                    $newId = $this->insertImmobile($imm);
                    $idImmobili[] = $newId;
                    $creati[] = ['tipo' => 'immobile', 'chiave' => $this->chiaveImmobile($imm), 'id' => $newId];
                } else {
                    throw new RuntimeException("Immobile {$this->chiaveImmobile($imm)} non presente e creazione disabilitata.");
                }
            }

            // Contratto
            $idContratto = $this->insertContratto($front, $documento);
            $creati[] = ['tipo' => 'contratto', 'id' => $idContratto];

            // Collegamenti
            foreach ($idLocatori as $idx => $idLoc) {
                $loc = $documento['soggetti']['locatori'][$idx] ?? [];
                $progressivo = $idx + 1;
                $quota = 100.0;
                $cedolare = 0;
                foreach ($documento['relazioni']['locazioni'] as $rel) {
                    if ((int)$rel['locatore_numero'] === $progressivo) {
                        $quota = $rel['percentuale_possesso'];
                        $cedolare = ($rel['cedolare'] === 'S') ? 1 : 0;
                        break;
                    }
                }
                $isRichiedente = (!empty($front['richiedente']['codice_fiscale'])
                                  && $front['richiedente']['codice_fiscale'] === $loc['codice_fiscale']) ? 1 : 0;

                $this->pdo->prepare("
                    INSERT INTO contratto_locatori (id_contratto, id_locatore, richiedente, opzione_cedolare, percentuale_possesso)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$idContratto, $idLoc, $isRichiedente, $cedolare, $quota]);
            }

            foreach ($idConduttori as $idCond) {
                $this->pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)")
                    ->execute([$idContratto, $idCond]);
            }

            foreach ($idImmobili as $idx => $idImm) {
                $imm = $documento['immobili'][$idx] ?? [];
                $this->pdo->prepare("
                    INSERT INTO contratto_immobili (id_contratto, id_immobile, tipologia_immobile, intero_porzione)
                    VALUES (?, ?, ?, ?)
                ")->execute([
                    $idContratto, $idImm,
                    (int)($imm['tipologia_immobile'] ?? 1),
                    ($imm['intero_porzione'] ?? 'P') === 'I' ? 'I' : 'P',
                ]);
            }

            // Proprietà
            $quotaPerLocatore = [];
            foreach ($documento['relazioni']['locazioni'] as $rel) {
                $locNum = (int)$rel['locatore_numero'];
                $quota = (float)$rel['percentuale_possesso'];
                if (!isset($quotaPerLocatore[$locNum]) || $quota > $quotaPerLocatore[$locNum]) {
                    $quotaPerLocatore[$locNum] = $quota;
                }
            }

            foreach ($idLocatori as $locIdx => $idLoc) {
                $progressivoLoc = $locIdx + 1;
                $quota = $quotaPerLocatore[$progressivoLoc] ?? 100.0;
                foreach ($idImmobili as $idImm) {
                    $stmtCheck = $this->pdo->prepare("SELECT id FROM proprieta_immobili WHERE id_locatore = ? AND id_immobile = ? LIMIT 1");
                    $stmtCheck->execute([$idLoc, $idImm]);
                    if ($stmtCheck->fetchColumn()) {
                        $collegati[] = ['tipo' => 'proprieta', 'id_locatore' => $idLoc, 'id_immobile' => $idImm];
                        continue;
                    }
                    $this->pdo->prepare("INSERT INTO proprieta_immobili (id_locatore, id_immobile, percentuale) VALUES (?, ?, ?)")
                        ->execute([$idLoc, $idImm, $quota]);
                    $creati[] = ['tipo' => 'proprieta', 'id_locatore' => $idLoc, 'id_immobile' => $idImm, 'quota' => $quota];
                }
            }

            // Evento registrazione
            $dataStipula = $front['registrazione']['data_stipula'] ?? $front['data_inizio'] ?? date('Y-m-d');
            $dataInvio = $front['data_invio'] ?? date('Y-m-d');
            $importi = $front['importi'] ?? [];

            $this->pdo->prepare("
                INSERT INTO eventi_contratto
                    (id_contratto, tipo, data_evento, data_invio, dettagli_json,
                     imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo,
                     interessi_registro, interessi_bollo)
                VALUES (?, 'registrazione', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $idContratto, $dataStipula, $dataInvio,
                json_encode(['importato_da_rli' => true, 'note' => 'Importato da file RLI']),
                $importi['imposta_registro'] ?? 0,
                $importi['imposta_bollo'] ?? 0,
                $importi['sanzione_registro'] ?? 0,
                $importi['sanzione_bollo'] ?? 0,
                $importi['interessi_registro'] ?? 0,
                $importi['interessi_bollo'] ?? 0,
            ]);

            $this->pdo->commit();

            return [
                'ok'           => true,
                'id_contratto' => $idContratto,
                'modo'         => 'insert_completo',
                'creati'       => $creati,
                'collegati'    => $collegati,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    // ============================================================
    // IMPORT: ADEMPIMENTO SUCCESSIVO
    // ============================================================
    private function importaEventoSuccessivo(array $parsed, array $opzioni): array
    {
        $documento = $parsed['documenti'][0];
        $front = $documento['frontespizio'];
        $ademp = $front['ademp_succ'] ?? [];
        $tipoAdemp = (int)($ademp['tipologia_adempimento'] ?? 0);
        $codAde = trim($ademp['id_telematico'] ?? '');

        // Trova contratto originale
        $contrattoOrig = null;
        if ($codAde !== '') {
            $stmt = $this->pdo->prepare("SELECT id, id_contratto_univoco, stato FROM contratti WHERE codice_identificativo_contratto = ? LIMIT 1");
            $stmt->execute([$codAde]);
            $contrattoOrig = $stmt->fetch() ?: null;
        }
        if (!$contrattoOrig) {
            throw new RuntimeException("Contratto originale con codice AdE '{$codAde}' non trovato. Importa prima la prima registrazione.");
        }

        $idContratto = (int)$contrattoOrig['id'];
        $tipoEvento = $this->mappaTipoEvento($tipoAdemp);
        if (!$tipoEvento) {
            throw new RuntimeException("Tipo adempimento {$tipoAdemp} non supportato.");
        }

        $dataEvento = $ademp['data_fine'] ?? $ademp['data_stipula'] ?? null;
        if (!$dataEvento) {
            throw new RuntimeException("Data evento non trovata nel file.");
        }

        // Verifica duplicato
        $stmtDup = $this->pdo->prepare("SELECT id FROM eventi_contratto WHERE id_contratto = ? AND tipo = ? AND data_evento = ? LIMIT 1");
        $stmtDup->execute([$idContratto, $tipoEvento, $dataEvento]);
        if ($stmtDup->fetchColumn()) {
            throw new RuntimeException("Evento '{$tipoEvento}' del {$dataEvento} già presente sul contratto #{$idContratto}.");
        }

        $this->pdo->beginTransaction();
        try {
            $creati = [];
            $collegati = [];

            $dettagli = [
                'importato_da_rli' => true,
                'note' => 'Importato da file RLI (adempimento successivo)',
            ];

            // === CESSIONE / SUBENTRO: gestione sostituzione conduttore ===
            if ($tipoEvento === 'cessione' || $tipoEvento === 'subentro') {
                $this->gestisciSostituzioneConduttore($idContratto, $documento, $tipoEvento, $dettagli, $creati, $collegati);
            }

            // Popola dettagli per tipo
            if ($tipoEvento === 'proroga') {
                $dettagli['data_fine_proroga'] = $ademp['data_fine'];
                $dettagli['tipologia_proroga'] = $ademp['tipologia_proroga'] ?? null;
            } elseif ($tipoEvento === 'cessione') {
                $dettagli['data_cessione'] = $ademp['data_fine'];
            } elseif ($tipoEvento === 'risoluzione' || $tipoEvento === 'risoluzione_corrispettivo') {
                $dettagli['durata_al'] = $ademp['data_fine'];
                if (!empty($ademp['corrispettivo'])) $dettagli['corrispettivo'] = $ademp['corrispettivo'];
            } elseif ($tipoEvento === 'subentro') {
                $dettagli['data_subentro'] = $ademp['data_fine'];
                $dettagli['tipologia_subentro'] = $ademp['tipologia_subentro'] ?? null;
            } elseif ($tipoEvento === 'rinegoziazione') {
                $dettagli['data_stipula'] = $ademp['data_stipula'] ?? null;
                $dettagli['data_inizio_rinegoziazione'] = $ademp['data_inizio_rineg'] ?? null;
                $dettagli['corrispettivo'] = $ademp['corrispettivo'] ?? null;
            }

            $importi = $front['importi'] ?? [];

            // Inserisci evento
            $this->pdo->prepare("
                INSERT INTO eventi_contratto
                    (id_contratto, tipo, data_evento, data_invio, dettagli_json,
                     imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo,
                     interessi_registro, interessi_bollo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $idContratto, $tipoEvento, $dataEvento,
                $front['data_invio'] ?? date('Y-m-d'),
                json_encode($dettagli),
                $importi['imposta_registro'] ?? 0,
                $importi['imposta_bollo'] ?? 0,
                $importi['sanzione_registro'] ?? 0,
                $importi['sanzione_bollo'] ?? 0,
                $importi['interessi_registro'] ?? 0,
                $importi['interessi_bollo'] ?? 0,
            ]);

            $creati[] = ['tipo' => 'evento', 'sottotipo' => $tipoEvento, 'id_contratto' => $idContratto, 'data' => $dataEvento];

            // Aggiorna contratto in base al tipo
            if ($tipoEvento === 'proroga') {
                $this->pdo->prepare("
                    UPDATE contratti SET stato = 'PROROGATO', data_fine_proroga = ?, tipologia_proroga = ?, tipologia_adempimento = '2', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$ademp['data_fine'], $ademp['tipologia_proroga'] ?? null, $idContratto]);
            } elseif ($tipoEvento === 'cessione') {
                // Stato resta ATTIVO
                $this->pdo->prepare("
                    UPDATE contratti SET data_cessione = ?, tipologia_adempimento = '3', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$ademp['data_fine'], $idContratto]);
            } elseif ($tipoEvento === 'risoluzione' || $tipoEvento === 'risoluzione_corrispettivo') {
                $tipoAd = ($tipoEvento === 'risoluzione') ? '4' : '7';
                $this->pdo->prepare("
                    UPDATE contratti SET stato = 'RISOLTO', durata_al = ?, tipologia_adempimento = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$ademp['data_fine'], $tipoAd, $idContratto]);
            } elseif ($tipoEvento === 'subentro') {
                $this->pdo->prepare("
                    UPDATE contratti SET data_subentro = ?, tipologia_subentro = ?, tipologia_adempimento = '6', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$ademp['data_fine'], $ademp['tipologia_subentro'] ?? null, $idContratto]);
            } elseif ($tipoEvento === 'rinegoziazione') {
                $this->pdo->prepare("
                    UPDATE contratti SET corrispettivo = ?, data_stipula = ?, tipologia_adempimento = '8', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$ademp['corrispettivo'] ?? null, $ademp['data_stipula'] ?? null, $idContratto]);
            }

            $this->pdo->commit();

            return [
                'ok'           => true,
                'id_contratto' => $idContratto,
                'modo'         => 'insert_evento',
                'tipo_evento'  => $tipoEvento,
                'data_evento'  => $dataEvento,
                'creati'       => $creati,
                'collegati'    => $collegati,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Cessione/Subentro: sostituisce il conduttore cedente con il cessionario.
     */
    private function gestisciSostituzioneConduttore(
        int $idContratto,
        array $documento,
        string $tipoEvento,
        array &$dettagli,
        array &$creati,
        array &$collegati
    ): void {
        // Trova cedente (Cedente=1) e cessionario (Cedente=2) tra i conduttori
        $cedente = null;
        $cessionario = null;

        foreach ($documento['soggetti']['conduttori'] as $cond) {
            $tipoCess = (string)($cond['tipo_cessione'] ?? '');
            if ($tipoCess === '1') $cedente = $cond;
            if ($tipoCess === '2') $cessionario = $cond;
        }

        if (!$cedente || !$cessionario) {
            // Se non ci sono marcatori Cedente, prendi il primo come cedente e il secondo come cessionario
            $conduttori = $documento['soggetti']['conduttori'];
            if (count($conduttori) >= 2) {
                $cedente = $conduttori[0];
                $cessionario = $conduttori[1];
            } else {
                throw new RuntimeException("Cessione/Subentro: impossibile identificare cedente e cessionario.");
            }
        }

        $dettagli['cedente_cf']     = $cedente['codice_fiscale'];
        $dettagli['cedente_nome']   = trim(($cedente['cognome'] ?? '') . ' ' . ($cedente['nome'] ?? ''));
        $dettagli['cessionario_cf'] = $cessionario['codice_fiscale'];
        $dettagli['cessionario_nome'] = trim(($cessionario['cognome'] ?? '') . ' ' . ($cessionario['nome'] ?? ''));

        // Cerca il cessionario in DB, altrimenti lo crea
        $cessionarioDb = $this->findSoggettoByCf($cessionario['codice_fiscale']);
        if ($cessionarioDb) {
            $idCessionario = (int)$cessionarioDb['id'];
            $collegati[] = ['tipo' => 'conduttore', 'cf' => $cessionario['codice_fiscale'], 'id' => $idCessionario];
        } else {
            $idCessionario = $this->insertSoggetto($cessionario, 'CONDUTTORE');
            $creati[] = ['tipo' => 'conduttore', 'cf' => $cessionario['codice_fiscale'], 'id' => $idCessionario];
        }

        // Cerca il cedente in DB
        $cedenteDb = $this->findSoggettoByCf($cedente['codice_fiscale']);
        $idCedente = $cedenteDb ? (int)$cedenteDb['id'] : null;

        // Rimuovi il cedente dal contratto (se presente)
        if ($idCedente !== null) {
            $stmt = $this->pdo->prepare("DELETE FROM contratto_conduttori WHERE id_contratto = ? AND id_conduttore = ?");
            $stmt->execute([$idContratto, $idCedente]);
        }

        // Aggiungi il cessionario al contratto
        $stmtCheck = $this->pdo->prepare("SELECT id FROM contratto_conduttori WHERE id_contratto = ? AND id_conduttore = ? LIMIT 1");
        $stmtCheck->execute([$idContratto, $idCessionario]);
        if (!$stmtCheck->fetchColumn()) {
            $this->pdo->prepare("INSERT INTO contratto_conduttori (id_contratto, id_conduttore) VALUES (?, ?)")
                ->execute([$idContratto, $idCessionario]);
            $creati[] = ['tipo' => 'contratto_conduttore', 'id_contratto' => $idContratto, 'id_conduttore' => $idCessionario];
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================
    private function labelTipoAdempimento(int $tipo): string
    {
        $mappa = [
            1 => 'Prima registrazione',
            2 => 'Proroga',
            3 => 'Cessione',
            4 => 'Risoluzione',
            5 => 'Conguaglio d\'imposta',
            6 => 'Subentro',
            7 => 'Risoluzione con corrispettivo',
            8 => 'Rinegoziazione',
        ];
        return $mappa[$tipo] ?? "Adempimento {$tipo}";
    }

    private function mappaTipoEvento(int $tipoAdemp): ?string
    {
        $mappa = [
            2 => 'proroga',
            3 => 'cessione',
            4 => 'risoluzione',
            6 => 'subentro',
            7 => 'risoluzione_corrispettivo',
            8 => 'rinegoziazione',
        ];
        return $mappa[$tipoAdemp] ?? null;
    }

    private function findSoggettoByCf(string $cf): ?array
    {
        if ($cf === '') return null;
        $stmt = $this->pdo->prepare("SELECT id, codice_fiscale, cognome, nome, tipologia_default FROM soggetti WHERE codice_fiscale = ? LIMIT 1");
        $stmt->execute([$cf]);
        return $stmt->fetch() ?: null;
    }

    private function findImmobile(array $imm): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM immobili
            WHERE codice_comune = ? AND foglio = ? AND particella = ?
              AND COALESCE(subalterno,'') = COALESCE(?, '')
            LIMIT 1
        ");
        $stmt->execute([
            $imm['codice_comune'] ?? '',
            $imm['foglio'] ?? '',
            $imm['particella'] ?? '',
            $imm['subalterno'] ?? '',
        ]);
        return $stmt->fetch() ?: null;
    }

    private function chiaveImmobile(array $imm): string
    {
        return sprintf('%s-%s-%s-%s',
            $imm['codice_comune'] ?? '?',
            $imm['foglio'] ?? '?',
            $imm['particella'] ?? '?',
            $imm['subalterno'] ?? '?'
        );
    }

    private function insertSoggetto(array $s, string $tipologia): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO soggetti (
                codice_fiscale, cognome, nome, sesso, data_nascita,
                comune_nascita, prov_nascita, tipologia_default, tipologia_conduttore,
                soggettivita_iva
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            strtoupper($s['codice_fiscale']),
            strtoupper($s['cognome']),
            strtoupper($s['nome'] ?? ''),
            $s['sesso'] ?: null,
            $s['data_nascita'] ?: null,
            strtoupper($s['comune_nascita'] ?? ''),
            strtoupper($s['prov_nascita'] ?? ''),
            $tipologia,
            $s['tipologia_conduttore'] ?: null,
            !empty($s['soggettivita_iva']) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertImmobile(array $imm): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO immobili (
                identificativo_interno, codice_comune, provincia, sezione, tipo_catasto,
                categoria, comune, foglio, particella, subalterno,
                rendita, toponimo, indirizzo, civico
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ident = sprintf('%s Fg.%s Part.%s Sub.%s',
            $imm['comune'] ?? 'IMMOBILE',
            $imm['foglio'] ?? '?',
            $imm['particella'] ?? '?',
            $imm['subalterno'] ?? '?'
        );
        $stmt->execute([
            $ident,
            strtoupper($imm['codice_comune'] ?? ''),
            strtoupper($imm['provincia'] ?? ''),
            strtoupper($imm['sezione'] ?? ''),
            strtoupper($imm['tipo_catasto'] ?? 'U'),
            strtoupper($imm['categoria'] ?? ''),
            strtoupper($imm['comune'] ?? ''),
            $imm['foglio'] ?? '',
            $imm['particella'] ?? '',
            $imm['subalterno'] ?? '',
            !empty($imm['rendita']) ? $imm['rendita'] : null,
            strtoupper($imm['toponimo'] ?? ''),
            strtoupper($imm['indirizzo'] ?? ''),
            strtoupper($imm['civico'] ?? ''),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertContratto(array $front, array $documento): int
    {
        $dataStipula = $front['registrazione']['data_stipula'] ?? null;
        $dal = $front['data_inizio'] ?? null;
        $al = $front['data_fine'] ?? null;
        $canone = (float)($front['importo_canone'] ?? 0);

        // Pagine: priorità a NumeroPagine, altrimenti NumeroFogli
        $pagine = (int)($front['registrazione']['numero_pagine'] ?? 0);
        if ($pagine <= 0) {
            $pagine = (int)($front['registrazione']['numero_fogli'] ?? 0);
        }
        if ($pagine <= 0) {
            $pagine = 1;
        }

        $copie = (int)($front['registrazione']['numero_copie'] ?? 1);
        if ($copie <= 0) $copie = 1;

        $esente = $front['contratto_esente'] ?? null;
        $codAde = $front['ademp_succ']['id_telematico'] ?? null;
        $idU = $front['id_contratto'] ?? null;
        $dataInvio = $front['data_invio'] ?? date('Y-m-d');

        $cfIntermediario = $front['impegno_trasmettere']['cf_intermediario'] ?? null;
        $dataImpegno = $front['impegno_trasmettere']['data_impegno'] ?? null;

        $casiParticolari = !empty($front['casi_particolari']) ? (int)$front['casi_particolari'] : null;
        $contrattoEsente = !empty($esente) ? (int)$esente : null;
        $agevolazioni = !empty($front['agevolazioni']) ? 1 : 0;
        $pagamentoInteraDurata = !empty($front['pagamento_intera_durata']) ? 1 : 0;
        $condSospensiva = !empty($front['condizione_sospensiva']) ? 1 : 0;
        $tempoIndet = !empty($front['contratto_tempo_indeterminato']) ? 1 : 0;
        $clausolaPenale = !empty($front['clausola_penale']) ? 1 : 0;
        $contrattoIVA = !empty($front['registrazione']['contratto_iva']) ? 1 : 0;
        $numAllegatoA = (int)($front['registrazione']['numero_allegato_a'] ?? 0);
        $numAllegatoB = (int)($front['registrazione']['numero_allegato_b'] ?? 0);
        $numAllegatoC = (int)($front['registrazione']['numero_allegato_c'] ?? 0);

        $importi = $front['importi'] ?? [];

        // IBAN dal blocco DatiGenerali (non disponibile qui, lo lasciamo null)
        $iban = $parsed['dati_generali']['iban'] ?? null;

        $canoni = $documento['canoni_variabili'] ?? [];
        $canoniJson = !empty($canoni) ? json_encode($canoni) : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO contratti (
                id_contratto_univoco, codice_identificativo_contratto, stato, tipologia_contratto,
                data_stipula, durata_dal, durata_al, importo_canone, numero_pagine, numero_copie,
                tipologia_adempimento, cedolare_secca,
                imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo,
                interessi_registro, interessi_bollo,
                iban, cf_intestatario_cc,
                cf_intermediario, data_impegno_intermediario,
                comunica_dati_catastali, contratto_non_allegato,
                casi_particolari, contratto_esente, agevolazioni, pagamento_intera_durata,
                condizione_sospensiva, contratto_tempo_indeterminato, clausola_penale_volontaria,
                contratto_soggetto_iva,
                numero_allegato_a, numero_allegato_b, numero_allegato_c,
                canoni_variabili,
                data_invio, updated_at
            ) VALUES (
                ?, ?, 'ATTIVO', ?,
                ?, ?, ?, ?, ?, ?,
                '1', ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?,
                ?, ?, ?,
                ?,
                ?, NOW()
            )
        ");
        $stmt->execute([
            $idU ?: null, $codAde, $front['tipologia_contratto'] ?? 'L1',
            $dataStipula, $dal, $al, $canone, $pagine, $copie,
            $this->calcolaCedolare($documento),
            $importi['imposta_registro'] ?? 0, $importi['imposta_bollo'] ?? 0,
            $importi['sanzione_registro'] ?? 0, $importi['sanzione_bollo'] ?? 0,
            $importi['interessi_registro'] ?? 0, $importi['interessi_bollo'] ?? 0,
            $iban, null,
            $cfIntermediario, $dataImpegno,
            0, 0,
            $casiParticolari, $contrattoEsente, $agevolazioni, $pagamentoInteraDurata,
            $condSospensiva, $tempoIndet, $clausolaPenale,
            $contrattoIVA,
            $numAllegatoA, $numAllegatoB, $numAllegatoC,
            $canoniJson,
            $dataInvio,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function calcolaCedolare(array $documento): string
    {
        $locatoriCount = count($documento['soggetti']['locatori']);
        if ($locatoriCount === 0) return '3';

        $cedolareCount = 0;
        foreach ($documento['relazioni']['locazioni'] as $rel) {
            if (($rel['cedolare'] ?? '') === 'S') $cedolareCount++;
        }
        if ($cedolareCount === 0) return '3';
        if ($cedolareCount === $locatoriCount) return '1';
        return '2';
    }
}
