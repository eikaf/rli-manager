<?php
require_once __DIR__ . '/AttachmentStorage.php';

class ContrattoRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM contratti WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM contratti WHERE id_contratto_univoco = ? LIMIT 1");
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Verifica se l'utente può accedere al contratto.
     * Admin: sempre. Altri: solo se creatore o presente in contratti_condivisioni.
     */
    public function canAccess(int $idContratto, Auth $auth): bool
    {
        if ($auth->isAdmin()) {
            return true;
        }
        $idUtente = $auth->id();
        if ($idUtente === null) {
            return false;
        }
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM contratti c
            WHERE c.id = ?
              AND (
                    c.id_utente_creatore = ?
                    OR EXISTS (
                        SELECT 1 FROM contratti_condivisioni cc
                        WHERE cc.id_contratto = c.id AND cc.id_utente = ?
                    )
              )
            LIMIT 1
        ");
        $stmt->execute([$idContratto, $idUtente, $idUtente]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Elenco esplicito delle colonne di `contratti` per le query di lista,
     * escludendo il BLOB `allegato_pdf` (pesante e non necessario alla UI).
     */
    private function contrattiSelectColumns(string $alias = 'c'): string
    {
        $cols = [
            'id', 'id_utente_creatore', 'id_contratto_univoco', 'codice_identificativo_contratto',
            'stato', 'tipologia_contratto', 'ufficio_territoriale', 'data_stipula', 'durata_dal', 'durata_al',
            'data_fine_proroga', 'data_cessione', 'data_subentro', 'data_inizio_rinegoziazione', 'rinegoziazione_tipo',
            'importo_canone', 'numero_pagine', 'numero_copie', 'tipologia_adempimento', 'cedolare_secca',
            'casi_particolari', 'agevolazioni', 'contratto_esente', 'pagamento_intera_durata', 'corrispettivo',
            'tipologia_subentro', 'tipologia_proroga', 'annualita', 'tardivita_annualita', 'condizione_sospensiva',
            'contratto_tempo_indeterminato', 'clausola_penale_volontaria', 'contratto_soggetto_iva', 'contratto_non_allegato',
            'tipo_garanzia', 'importo_garanzia', 'cf_garante', 'cf_garante2', 'garanzia_soggetta_iva',
            'numero_allegato_a', 'numero_allegato_b', 'numero_allegato_c', 'imposta_registro', 'imposta_bollo',
            'sanzione_registro', 'sanzione_bollo', 'interessi_registro', 'interessi_bollo', 'ufficio_registrazione',
            'anno_registrazione', 'serie_registrazione', 'numero_registrazione', 'sottonumero_registrazione',
            'eventi_eccezionali', 'canoni_variabili', 'comunica_dati_catastali', 'nome_allegato', 'iban',
            'cf_intestatario_cc', 'cf_intermediario', 'data_impegno_intermediario', 'data_impegno', 'data_invio',
            'created_at', 'updated_at', 'ravvedimento_accettato', 'ravvedimento_dettagli_json',
        ];
        return implode(', ', array_map(static fn($col) => "{$alias}.{$col}", $cols));
    }

    public function allWithSummary(): array
    {
        $sql = "
            SELECT " . $this->contrattiSelectColumns('c') . ",
                (SELECT CONCAT(s.cognome,' ',COALESCE(s.nome,''))
                 FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id
                 ORDER BY cl.richiedente DESC, s.id ASC LIMIT 1) AS locatore_principale,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome,' ',COALESCE(s.nome,'')) SEPARATOR ' / ')
                 FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id) AS locatori_list,
                (SELECT CONCAT(s.cognome,' ',COALESCE(s.nome,''))
                 FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id
                 ORDER BY s.id ASC LIMIT 1) AS conduttore_principale,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome,' ',COALESCE(s.nome,'')) SEPARATOR ' / ')
                 FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id) AS conduttori_list,
                (SELECT COUNT(*) FROM eventi_contratto e WHERE e.id_contratto = c.id) AS num_eventi,
                (SELECT MAX(e.data_evento) FROM eventi_contratto e WHERE e.id_contratto = c.id) AS ultimo_evento,
                (SELECT COUNT(*) FROM contratti_condivisioni cc WHERE cc.id_contratto = c.id) AS num_condivisioni
            FROM contratti c
            ORDER BY
                CASE c.stato WHEN 'ATTIVO' THEN 1 WHEN 'PROROGATO' THEN 2 WHEN 'BOZZA' THEN 3
                             WHEN 'CESSATO' THEN 4 WHEN 'SCADUTO' THEN 5 ELSE 6 END,
                c.updated_at DESC
        ";
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * Ricerca contratti con filtri e paginazione.
     * Ritorna ['data' => array contratti, 'total' => int totale senza LIMIT].
     */
    public function searchAndPaginate(array $filters = [], int $limit = 10, int $offset = 0, ?Auth $auth = null): array
    {
        $where = [];
        $params = [];

        // Filtro per stato
        if (!empty($filters['stato'])) {
            $where[] = "c.stato = :stato";
            $params[':stato'] = $filters['stato'];
        }

        // Filtro per tipologia
        if (!empty($filters['tipologia'])) {
            $where[] = "c.tipologia_contratto = :tipologia";
            $params[':tipologia'] = $filters['tipologia'];
        }

        // Filtro registrazione AdE (presenza del codice identificativo)
        if (!empty($filters['reg'])) {
            if ($filters['reg'] === 'registrati') {
                $where[] = "TRIM(COALESCE(c.codice_identificativo_contratto, '')) <> ''";
            } elseif ($filters['reg'] === 'da_registrare') {
                $where[] = "TRIM(COALESCE(c.codice_identificativo_contratto, '')) = ''";
            }
        }

        // === FILTRO RICERCA AVANZATA ===
        if (!empty($filters['search'])) {
            $rawSearch = trim($filters['search']);

            // Suddividi in parole
            $words = preg_split('/\s+/', $rawSearch);
            $words = array_filter($words, function($w) { return trim($w) !== ''; });
            $words = array_values($words);

            if (count($words) > 0) {
                // Per ogni parola, cerca in TUTTI i campi (LIKE %parola%)
                // Usa AND tra parole: tutte le parole devono matchare
                $wordConditions = [];

                foreach ($words as $idx => $word) {
                    $paramKey = ':word_' . $idx;
                    $params[$paramKey] = '%' . $word . '%';

                    // Per questa parola, cerca in: ID contratto, note, soggetti, immobili
                    $subClauses = [
                        "c.id_contratto_univoco LIKE $paramKey",
                        "c.codice_identificativo_contratto LIKE $paramKey",
                        "EXISTS (
                            SELECT 1 FROM contratto_locatori cl
                            JOIN soggetti s ON s.id = cl.id_locatore
                            WHERE cl.id_contratto = c.id AND (
                                s.cognome LIKE $paramKey
                                OR s.nome LIKE $paramKey
                                OR s.codice_fiscale LIKE $paramKey
                                OR s.email LIKE $paramKey
                                OR s.note LIKE $paramKey
                            )
                        )",
                        "EXISTS (
                            SELECT 1 FROM contratto_conduttori cc
                            JOIN soggetti s ON s.id = cc.id_conduttore
                            WHERE cc.id_contratto = c.id AND (
                                s.cognome LIKE $paramKey
                                OR s.nome LIKE $paramKey
                                OR s.codice_fiscale LIKE $paramKey
                                OR s.email LIKE $paramKey
                                OR s.note LIKE $paramKey
                            )
                        )",
                        "EXISTS (
                            SELECT 1 FROM contratto_immobili ci
                            JOIN immobili i ON i.id = ci.id_immobile
                            WHERE ci.id_contratto = c.id AND (
                                i.comune LIKE $paramKey
                                OR i.indirizzo LIKE $paramKey
                                OR i.civico LIKE $paramKey
                                OR i.identificativo_interno LIKE $paramKey
                                OR i.note LIKE $paramKey
                            )
                        )",
                        "EXISTS (
                            SELECT 1 FROM eventi_contratto ev
                            WHERE ev.id_contratto = c.id AND ev.dettagli_json LIKE $paramKey
                        )",
                    ];

                    $wordConditions[] = '(' . implode(' OR ', $subClauses) . ')';
                }

                // AND tra tutte le parole (tutte devono matchare)
                $where[] = '(' . implode(' AND ', $wordConditions) . ')';
            }
        }

        // === FILTRO VISIBILITÀ (multi-utente) ===
        // Admin vede tutto, operator/viewer vedono solo propri + condivisi
        if ($auth !== null && !$auth->isAdmin()) {
            $idUtente = $auth->id();
            $where[] = "(c.id_utente_creatore = :id_utente_creatore
                         OR EXISTS (
                            SELECT 1 FROM contratti_condivisioni ccv
                            WHERE ccv.id_contratto = c.id AND ccv.id_utente = :id_utente_condiviso
                         ))";
            $params[':id_utente_creatore'] = $idUtente;
            $params[':id_utente_condiviso'] = $idUtente;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // === ORDINAMENTO DINAMICO (whitelist, no SQL injection) ===
        // Chiave -> espressione SQL. 'nome' usa gli alias 'principale' già presenti
        // nella SELECT esterna (costruiti con ORDER BY richiedente DESC / s.id ASC),
        // quindi ordinano correttamente per cognome + nome del primo soggetto.
        $orderMap = [
            'nome'           => 'locatore_principale, conduttore_principale',
            'data_creazione' => 'c.created_at',
            'scadenza'       => 'c.durata_al',
            'canone'         => 'c.importo_canone',
            'stato'          => "CASE c.stato WHEN 'ATTIVO' THEN 1 WHEN 'PROROGATO' THEN 2 WHEN 'BOZZA' THEN 3
                                          WHEN 'CESSATO' THEN 4 WHEN 'SCADUTO' THEN 5 ELSE 6 END",
            'id'             => 'c.id',
        ];
        $orderKey = $filters['order'] ?? 'nome';
        if (!array_key_exists($orderKey, $orderMap)) {
            $orderKey = 'nome';
        }
        $orderDir = (strtoupper($filters['dir'] ?? '') === 'DESC') ? 'DESC' : 'ASC';
        // Direzione di default per criterio (se 'dir' non specificata)
        if (empty($filters['dir'])) {
            $defaultDir = [
                'nome'           => 'ASC',
                'data_creazione' => 'DESC',
                'scadenza'       => 'ASC',
                'canone'         => 'DESC',
                'stato'          => 'ASC',
                'id'             => 'DESC',
            ];
            $orderDir = $defaultDir[$orderKey] ?? 'ASC';
        }
        $orderBySql = $orderMap[$orderKey] . ' ' . $orderDir . ', c.id ' . $orderDir;

        // Conta totale risultati (per paginazione)
        $countSql = "SELECT COUNT(*) FROM contratti c $whereClause";
        $stmtCount = $this->pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalResults = (int)$stmtCount->fetchColumn();

        // Query principale
        $sql = "
            SELECT " . $this->contrattiSelectColumns('c') . ",
                (SELECT CONCAT(s.cognome,' ',COALESCE(s.nome,''))
                 FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id
                 ORDER BY cl.richiedente DESC, s.id ASC LIMIT 1) AS locatore_principale,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome,' ',COALESCE(s.nome,'')) SEPARATOR ' / ')
                 FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id) AS locatori_list,
                (SELECT CONCAT(s.cognome,' ',COALESCE(s.nome,''))
                 FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id
                 ORDER BY s.id ASC LIMIT 1) AS conduttore_principale,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome,' ',COALESCE(s.nome,'')) SEPARATOR ' / ')
                 FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id) AS conduttori_list,
                (SELECT COUNT(*) FROM eventi_contratto e WHERE e.id_contratto = c.id) AS num_eventi,
                (SELECT MAX(e.data_evento) FROM eventi_contratto e WHERE e.id_contratto = c.id) AS ultimo_evento,
                (SELECT COUNT(*) FROM contratti_condivisioni cc WHERE cc.id_contratto = c.id) AS num_condivisioni
            FROM contratti c
            $whereClause
            ORDER BY $orderBySql
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contratti = $stmt->fetchAll();

        return [
            'data'  => $contratti,
            'total' => $totalResults,
        ];
    }

    public function locatori(int $idContratto): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, cl.richiedente, cl.opzione_cedolare, cl.percentuale_possesso,
                   cl.tipo_cessione, cl.data_inizio, cl.data_fine
            FROM contratto_locatori cl
            JOIN soggetti s ON s.id = cl.id_locatore
            WHERE cl.id_contratto = ?
            ORDER BY cl.richiedente DESC, s.id ASC
        ");
        $stmt->execute([$idContratto]);
        return $stmt->fetchAll();
    }

    public function conduttori(int $idContratto): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   COALESCE(cc.tipologia_conduttore, s.tipologia_conduttore, 3) AS tipologia_conduttore,
                   cc.tipo_cessione, cc.data_inizio, cc.data_fine
            FROM contratto_conduttori cc
            JOIN soggetti s ON s.id = cc.id_conduttore
            WHERE cc.id_contratto = ?
            ORDER BY s.id ASC
        ");
        $stmt->execute([$idContratto]);
        return $stmt->fetchAll();
    }

    public function immobili(int $idContratto): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*, ci.tipologia_immobile, ci.intero_porzione
            FROM contratto_immobili ci
            JOIN immobili i ON i.id = ci.id_immobile
            WHERE ci.id_contratto = ?
            ORDER BY ci.tipologia_immobile ASC, i.id ASC
        ");
        $stmt->execute([$idContratto]);
        return $stmt->fetchAll();
    }

    public function eventi(int $idContratto): array
    {
        // Colonne esplicite: NON carica i payload pesanti (allegato_pdf, xml_generato)
        // nella timeline; espone solo flag booleani di presenza.
        $stmt = $this->pdo->prepare("
            SELECT id, id_contratto, tipo, data_evento, data_invio, dettagli_json,
                   imposta_registro, imposta_bollo, sanzione_registro, sanzione_bollo,
                   interessi_registro, interessi_bollo,
                   nome_allegato, allegato_path,
                   (allegato_pdf IS NOT NULL AND LENGTH(allegato_pdf) > 0) AS ha_allegato_blob,
                   (xml_generato IS NOT NULL AND LENGTH(xml_generato) > 0) AS ha_xml_generato
            FROM eventi_contratto
            WHERE id_contratto = ?
            ORDER BY data_evento DESC, id DESC
        ");
        $stmt->execute([$idContratto]);
        return $stmt->fetchAll();
    }

    public function richiedente(int $idContratto): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.* FROM contratto_locatori cl
            JOIN soggetti s ON s.id = cl.id_locatore
            WHERE cl.id_contratto = ? AND cl.richiedente = 1
            LIMIT 1
        ");
        $stmt->execute([$idContratto]);
        $r = $stmt->fetch();
        if ($r) return $r;
        $loc = $this->locatori($idContratto);
        return $loc[0] ?? null;
    }

    /**
     * Costruisce l'array "pratica" per XmlBuilder a partire da un contratto
     * e, opzionalmente, da un evento specifico.
     */
    public function buildPraticaArray(int $idContratto, ?array $evento = null): array
    {
        $c = $this->find($idContratto);
        if (!$c) return [];

        $dettagli = [];
        if ($evento && !empty($evento['dettagli_json'])) {
            $dettagli = json_decode($evento['dettagli_json'], true) ?: [];
        }

        // Determina tipo adempimento
        $tipo = '1';
        if ($evento) {
            $map = ['registrazione'=>1, 'annualita'=>1, 'proroga'=>2, 'cessione'=>3,
                    'risoluzione'=>4, 'conguaglio'=>5, 'subentro'=>6,
                    'risoluzione_corrispettivo'=>7, 'rinegoziazione'=>8];
            $tipo = (string)($map[$evento['tipo']] ?? $c['tipologia_adempimento'] ?? '1');
        }

        $pratica = $c;
        $pratica['tipo_adempimento'] = $tipo;
        $pratica['tipologia_adempimento'] = $tipo;

        if ($evento) {
            // === NORMALIZZAZIONE ===
            // Per gli adempimenti successivi i dati specifici del singolo evento sono
            // la fonte primaria (record eventi_contratto + dettagli_json).
            $pratica['dettagli_json'] = $evento['dettagli_json'] ?? null;
            $pratica['data_evento']   = $evento['data_evento'] ?? null;

            if (!empty($evento['data_invio'])) {
                $pratica['data_invio'] = $evento['data_invio'];
            }

            // Importi: sempre dall'evento (anche 0, l'evento è autorevole)
            $pratica['imposta_registro']   = (float)($evento['imposta_registro'] ?? 0);
            $pratica['imposta_bollo']      = (float)($evento['imposta_bollo'] ?? 0);
            $pratica['sanzione_registro']  = (float)($evento['sanzione_registro'] ?? 0);
            $pratica['sanzione_bollo']     = (float)($evento['sanzione_bollo'] ?? 0);
            $pratica['interessi_registro'] = (float)($evento['interessi_registro'] ?? 0);
            $pratica['interessi_bollo']    = (float)($evento['interessi_bollo'] ?? 0);

            // Campi specifici dell'adempimento: dettagli_json prioritario
            $campiEvento = [
                'data_fine_proroga', 'data_cessione', 'data_subentro', 'tipologia_subentro',
                'data_stipula', 'durata_dal', 'durata_al', 'importo_canone',
                'numero_pagine', 'numero_copie', 'tipologia_proroga', 'annualita',
                'tardivita_annualita', 'corrispettivo', 'rinegoziazione_tipo',
                'data_inizio_rinegoziazione', 'ufficio_registrazione', 'anno_registrazione',
                'serie_registrazione', 'numero_registrazione', 'sottonumero_registrazione',
                'codice_identificativo_contratto', 'tipologia_contratto', 'ufficio_territoriale',
            ];
            foreach ($campiEvento as $campo) {
                if (array_key_exists($campo, $dettagli) && $dettagli[$campo] !== null && $dettagli[$campo] !== '') {
                    $pratica[$campo] = $dettagli[$campo];
                }
            }
            if (empty($pratica['data_cessione']) && $evento['tipo'] === 'cessione' && !empty($evento['data_evento'])) {
                $pratica['data_cessione'] = $evento['data_evento'];
            }
            if (empty($pratica['data_subentro']) && $evento['tipo'] === 'subentro' && !empty($evento['data_evento'])) {
                $pratica['data_subentro'] = $evento['data_evento'];
            }
		          // Allegato PDF: evento corrente -> evento registrazione -> contratto.
		          // Lettura trasparente: file su disco (allegato_path) con fallback sul BLOB.
		          $attPath = $evento['allegato_path'] ?? null;
		          $attBlob = $evento['allegato_pdf'] ?? null;
		          $attName = $evento['nome_allegato'] ?? null;

		          if (empty($attPath) && empty($attBlob)) {
		              // Fallback: cerca l'evento registrazione più vecchio
		              $stmtReg = $this->pdo->prepare("SELECT allegato_pdf, allegato_path, nome_allegato FROM eventi_contratto WHERE id_contratto = ? AND tipo = 'registrazione' AND (allegato_pdf IS NOT NULL OR allegato_path IS NOT NULL) ORDER BY id ASC LIMIT 1");
		              $stmtReg->execute([$idContratto]);
		              $reg = $stmtReg->fetch();
		              if ($reg) {
		                  $attPath = $reg['allegato_path'] ?? null;
		                  $attBlob = $reg['allegato_pdf'] ?? null;
		                  $attName = $reg['nome_allegato'] ?? null;
		              }
		          }
		          if (empty($attPath) && empty($attBlob)) {
		              // Ultimo fallback: allegato a livello contratto
		              $attPath = $c['allegato_path'] ?? null;
		              $attBlob = $c['allegato_pdf'] ?? null;
		              $attName = $c['nome_allegato'] ?? null;
		          }

		          $attData = AttachmentStorage::read($attPath, $attBlob);
		          if ($attData !== null) {
		              $pratica['allegato_pdf'] = $attData;
		              $pratica['nome_allegato'] = $attName ?: 'contratto.pdf';
		          } else {
		              unset($pratica['allegato_pdf']);
		          }
            // (importi e campi specifici dell'evento già impostati sopra da dettagli_json)
        }

        return $pratica;
    }

    /**
     * Riallinea i campi denormalizzati di `contratti` (stato, tipologia_adempimento,
     * data_fine_proroga, annualita, data_cessione, data_subentro, corrispettivo, ...)
     * all'ultimo evento per data_evento/id.
     *
     * Serve a evitare che la modifica di un evento storico sovrascriva lo stato
     * corrente del contratto con dati di un adempimento precedente.
     */
    public function sincronizzaStatoDaEventi(int $idContratto): void
    {
        $stmtC = $this->pdo->prepare("SELECT stato FROM contratti WHERE id = ?");
        $stmtC->execute([$idContratto]);
        $statoAttuale = $stmtC->fetchColumn();
        if ($statoAttuale === false) {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT tipo, data_evento, dettagli_json FROM eventi_contratto WHERE id_contratto = ? ORDER BY data_evento ASC, id ASC");
        $stmt->execute([$idContratto]);
        $eventi = $stmt->fetchAll();
        if (empty($eventi)) {
            return;
        }

        $mapTipo = [
            'registrazione' => '1', 'annualita' => '1', 'proroga' => '2', 'cessione' => '3',
            'risoluzione' => '4', 'conguaglio' => '5', 'subentro' => '6',
            'risoluzione_corrispettivo' => '7', 'rinegoziazione' => '8',
        ];

        $stato = ($statoAttuale === 'BOZZA') ? 'ATTIVO' : (string)$statoAttuale;
        $tipologiaAdempimento = '1';
        $dataFineProroga = null;
        $annualita = null;
        $dataCessione = null;
        $dataSubentro = null;
        $corrispettivo = null;
        $rinegTipo = null;
        $dataInizioRineg = null;
        $dataStipula = null;

        foreach ($eventi as $ev) {
            $tipoEv = (string)$ev['tipo'];
            $tipologiaAdempimento = $mapTipo[$tipoEv] ?? $tipologiaAdempimento;

            // Solo gli eventi che cambiano davvero lo stato del contratto
            if (in_array($tipoEv, ['risoluzione', 'risoluzione_corrispettivo'], true)) {
                $stato = 'RISOLTO';
            } elseif ($tipoEv === 'proroga') {
                $stato = 'PROROGATO';
            } elseif (in_array($tipoEv, ['cessione', 'subentro'], true)) {
                $stato = 'ATTIVO';
            }

            $d = !empty($ev['dettagli_json']) ? json_decode($ev['dettagli_json'], true) : [];
            if (!is_array($d)) {
                $d = [];
            }

            switch ($tipoEv) {
                case 'proroga':
                    if (array_key_exists('data_fine_proroga', $d)) $dataFineProroga = $d['data_fine_proroga'];
                    break;
                case 'annualita':
                    if (array_key_exists('annualita', $d)) $annualita = $d['annualita'];
                    break;
                case 'cessione':
                    if (array_key_exists('data_cessione', $d)) $dataCessione = $d['data_cessione'];
                    if (array_key_exists('corrispettivo', $d)) $corrispettivo = $d['corrispettivo'];
                    break;
                case 'subentro':
                    if (array_key_exists('data_subentro', $d)) $dataSubentro = $d['data_subentro'];
                    break;
                case 'risoluzione':
                case 'risoluzione_corrispettivo':
                    if (array_key_exists('corrispettivo', $d)) $corrispettivo = $d['corrispettivo'];
                    break;
                case 'rinegoziazione':
                    if (array_key_exists('corrispettivo', $d)) $corrispettivo = $d['corrispettivo'];
                    if (array_key_exists('rinegoziazione_tipo', $d)) $rinegTipo = $d['rinegoziazione_tipo'];
                    if (array_key_exists('data_inizio_rinegoziazione', $d)) $dataInizioRineg = $d['data_inizio_rinegoziazione'];
                    if (array_key_exists('data_stipula', $d)) $dataStipula = $d['data_stipula'];
                    break;
            }
        }

        $this->pdo->prepare("
            UPDATE contratti SET
                stato = ?, tipologia_adempimento = ?,
                data_fine_proroga = COALESCE(?, data_fine_proroga),
                annualita = COALESCE(?, annualita),
                data_cessione = COALESCE(?, data_cessione),
                data_subentro = COALESCE(?, data_subentro),
                corrispettivo = COALESCE(?, corrispettivo),
                rinegoziazione_tipo = COALESCE(?, rinegoziazione_tipo),
                data_inizio_rinegoziazione = COALESCE(?, data_inizio_rinegoziazione),
                data_stipula = COALESCE(?, data_stipula)
            WHERE id = ?
        ")->execute([
            $stato, $tipologiaAdempimento,
            $dataFineProroga, $annualita, $dataCessione, $dataSubentro,
            $corrispettivo, $rinegTipo, $dataInizioRineg, $dataStipula,
            $idContratto,
        ]);
    }

    /**
     * Prepara il payload completo e "pulito" per XmlBuilder:
     *  - pratica (contratto + evento)
     *  - cf_intermediario risolto (evento/contratto -> utenti -> costante)
     *  - locatori/conduttori finali e ordinati (con cedente_ruolo per cessione/subentro)
     *  - immobili, richiedente, id contratto, lato_subentro
     */
    public function prepareRliPayload(int $idContratto, ?array $evento = null, ?int $utenteId = null): array
    {
        $pratica     = $this->buildPraticaArray($idContratto, $evento);
        $locatori    = $this->locatori($idContratto);
        $conduttori  = $this->conduttori($idContratto);
        $immobili    = $this->immobili($idContratto);
        $richiedente = $this->richiedente($idContratto);
        $idCont      = $this->buildIdContratto($pratica, $locatori, $conduttori);

        $tipo = (string)($pratica['tipo_adempimento'] ?? $pratica['tipologia_adempimento'] ?? '1');

        // a) cf_intermediario: evento/contratto -> utenti -> costante
        $cfIntermediario = $pratica['cf_intermediario'] ?? null;
        if (empty($cfIntermediario) && $utenteId !== null) {
            $stmt = $this->pdo->prepare("SELECT cf_intermediario FROM utenti WHERE id = ?");
            $stmt->execute([$utenteId]);
            $cfUtente = $stmt->fetchColumn();
            if (!empty($cfUtente)) {
                $cfIntermediario = $cfUtente;
            }
        }
        if (empty($cfIntermediario)) {
            $cfIntermediario = defined('RLI_CF_INTERMEDIARIO') ? RLI_CF_INTERMEDIARIO : '';
        }

        // b) locatori/conduttori finali per cessione (3) / subentro (6)
        $latoSubentro  = 'entrambi';
        $cedenteId     = null;
        $cessionariIds = [];

        if (in_array($tipo, ['3', '6'], true)) {
            $stmtEv = $this->pdo->prepare("SELECT dettagli_json FROM eventi_contratto WHERE id_contratto = ? AND tipo IN ('subentro','cessione') ORDER BY id DESC LIMIT 1");
            $stmtEv->execute([$idContratto]);
            $evJson = $stmtEv->fetchColumn();
            if ($evJson) {
                $dettagli = json_decode($evJson, true) ?: [];
                if (isset($dettagli['soggetto_cedente_id'])) {
                    $cedenteId = (int)$dettagli['soggetto_cedente_id'];
                }
                if (isset($dettagli['cessionari_ids'])) {
                    $cessionariIds = array_map('intval', (array)$dettagli['cessionari_ids']);
                }
                $latoKey = ($tipo === '6') ? 'lato_subentro' : 'lato_cessione';
                if (isset($dettagli[$latoKey])) {
                    $latoSubentro = $dettagli[$latoKey];
                }
            }

            [$locatori, $conduttori] = $this->resolveSoggettiCessione(
                $locatori, $conduttori, $latoSubentro, $cedenteId, $cessionariIds
            );
        }

        return [
            'pratica'          => $pratica,
            'locatori'         => $locatori,
            'conduttori'       => $conduttori,
            'immobili'         => $immobili,
            'richiedente'      => $richiedente,
            'id_contratto'     => $idCont,
            'cf_intermediario' => $cfIntermediario,
            'lato_subentro'    => $latoSubentro,
            'cedente_id'       => $cedenteId,
            'cessionari_ids'   => $cessionariIds,
        ];
    }

    /**
     * Ricostruisce gli array finali di locatori/conduttori per cessione/subentro,
     * aggiungendo il flag `cedente_ruolo` (1 = cedente, 2 = cessionario, null = invariato).
     */
    private function resolveSoggettiCessione(
        array $locatori,
        array $conduttori,
        string $lato,
        ?int $cedenteId,
        array $cessionariIds
    ): array {
        $outLoc  = [];
        $outCond = [];

        $cedenteData = null;
        if ($cedenteId !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM soggetti WHERE id = ?");
            $stmt->execute([$cedenteId]);
            $cedenteData = $stmt->fetch() ?: null;
        }

        $cessionariData = [];
        if (!empty($cessionariIds)) {
            $in = implode(',', array_fill(0, count($cessionariIds), '?'));
            $stmt = $this->pdo->prepare("SELECT * FROM soggetti WHERE id IN ($in)");
            $stmt->execute($cessionariIds);
            $cessionariData = $stmt->fetchAll();
        }

        $coinvolti = array_merge($cedenteId !== null ? [$cedenteId] : [], $cessionariIds);

        if ($cedenteData) {
            $cedenteData['cedente_ruolo'] = 1;
            if ($lato === 'conduttore') {
                $outCond[] = $cedenteData;
            } else {
                $outLoc[] = $cedenteData;
            }
        }

        foreach ($cessionariData as $c) {
            $c['cedente_ruolo'] = 2;
            if ($lato === 'conduttore') {
                $outCond[] = $c;
            } else {
                $outLoc[] = $c;
            }
        }

        foreach ($locatori as $l) {
            if (!in_array((int)$l['id'], $coinvolti, true)) {
                if ($lato !== 'conduttore') {
                    $l['cedente_ruolo'] = null;
                    $outLoc[] = $l;
                }
            }
        }
        foreach ($conduttori as $c) {
            if (!in_array((int)$c['id'], $coinvolti, true)) {
                if ($lato !== 'locatore') {
                    $c['cedente_ruolo'] = null;
                    $outCond[] = $c;
                }
            }
        }

        return [$outLoc, $outCond];
    }

    public function buildIdContratto(array $contratto, array $locatori, array $conduttori): string
    {
        if (!empty($contratto['id_contratto_univoco'])) {
            return substr($contratto['id_contratto_univoco'], 0, 20);
        }
        $loc = $locatori[0] ?? null;
        $cond = $conduttori[0] ?? null;
        $parteLoc = $loc
            ? substr(strtoupper(str_replace(' ', '', $loc['cognome'])), 0, 3) . '_' . substr(strtoupper($loc['nome'] ?? ''), 0, 1)
            : 'LOC';
        $parteCond = $cond ? strtoupper(str_replace(' ', '', $cond['cognome'])) : 'INC';
        $dal = $contratto['durata_dal'] ?? null;
        $al = $contratto['durata_al'] ?? null;
        $anni = ($dal ? date('y', strtotime($dal)) : date('y')) . ($al ? date('y', strtotime($al)) : date('y'));
        return substr($parteLoc . '_' . $parteCond . '_' . $anni, 0, 20);
    }

    public function countModuli(array $locatori, array $conduttori, array $immobili): int
    {
        $mS = max((int)ceil(count($locatori)/4), (int)ceil(count($conduttori)/4));
        $mI = (int)ceil(count($immobili)/4);
        $mR = (int)ceil((count($locatori) * count($immobili))/10);
        return max(1, $mS, $mI, $mR);
    }

    /**
     * Restituisce le statistiche (conteggi per stato, totali soggetti/immobili) 
     * filtrate in base alla visibilità dell'utente.
     */
    public function statsByVisibilita(?Auth $auth = null): array
    {
        $where = [];
        $params = [];

        if ($auth !== null && !$auth->isAdmin()) {
            $where[] = "(c.id_utente_creatore = :id_utente OR EXISTS (
                SELECT 1 FROM contratti_condivisioni cc
                WHERE cc.id_contratto = c.id AND cc.id_utente = :id_utente_cond
            ))";
            $params[':id_utente'] = $auth->id();
            $params[':id_utente_cond'] = $auth->id();
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // 1. Statistiche per stato
        $stmt = $this->pdo->prepare("
            SELECT c.stato, COUNT(*) AS n 
            FROM contratti c 
            $whereClause 
            GROUP BY c.stato
        ");
        $stmt->execute($params);
        $statsStato = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 2. Conteggio contratti in scadenza
        // Se c'è un whereClause, dobbiamo unirlo con AND
        $scadWhere = "WHERE c.stato IN ('ATTIVO', 'PROROGATO')
            AND c.durata_al IS NOT NULL
            AND c.durata_al BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
            
        if (!empty($whereClause)) {
            // Rimuoviamo "WHERE " dalla stringa esistente e la uniamo con AND
            $scadWhere = $whereClause . " AND c.stato IN ('ATTIVO', 'PROROGATO')
                AND c.durata_al IS NOT NULL
                AND c.durata_al BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM contratti c
            $scadWhere
        ");
        $stmt->execute($params);
        $totScadenze = (int)$stmt->fetchColumn();

        // 3. Breakdown degli ATTIVI (ATTIVO + PROROGATO) per presenza del codice AdE.
        //    registrati + da_registrare = totale ATTIVI (stesso insieme di contratti).
        $attiviWhere = $whereClause !== ''
            ? $whereClause . " AND c.stato IN ('ATTIVO','PROROGATO')"
            : "WHERE c.stato IN ('ATTIVO','PROROGATO')";

        $stmt = $this->pdo->prepare("
            SELECT
                SUM(CASE WHEN TRIM(COALESCE(c.codice_identificativo_contratto, '')) <> '' THEN 1 ELSE 0 END) AS registrati,
                SUM(CASE WHEN TRIM(COALESCE(c.codice_identificativo_contratto, '')) = '' THEN 1 ELSE 0 END) AS da_registrare
            FROM contratti c
            $attiviWhere
        ");
        $stmt->execute($params);
        $attiviBreakdown = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['registrati' => 0, 'da_registrare' => 0];

        return [
            'stati' => $statsStato,
            'scadenze' => $totScadenze,
            'tot_contratti' => array_sum($statsStato),
            'attivi_registrati' => (int)($attiviBreakdown['registrati'] ?? 0),
            'attivi_da_registrare' => (int)($attiviBreakdown['da_registrare'] ?? 0),
        ];
    }
}
