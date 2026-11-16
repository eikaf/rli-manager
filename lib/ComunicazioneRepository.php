<?php
/**
 * ComunicazioneRepository — Gestione registro comunicazioni.
 */
class ComunicazioneRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO comunicazioni (
                id_contratto, id_utente, tipo, data_comunicazione,
                oggetto, corpo, destinatari_json, allegati_json,
                inviata, data_invio, errore_invio
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['id_contratto'] ?? null,
            $data['id_utente'] ?? null,
            $data['tipo'] ?? 'email',
            $data['data_comunicazione'] ?? date('Y-m-d H:i:s'),
            $data['oggetto'] ?? null,
            $data['corpo'] ?? null,
            isset($data['destinatari']) ? json_encode($data['destinatari']) : null,
            isset($data['allegati']) ? json_encode($data['allegati']) : null,
            $data['inviata'] ?? 0,
            $data['data_invio'] ?? null,
            $data['errore_invio'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function all(?int $idContratto = null, ?string $tipo = null, int $limit = 200): array
    {
        $where = [];
        $params = [];

        if ($idContratto !== null) {
            $where[] = 'cm.id_contratto = ?';
            $params[] = $idContratto;
        }
        if ($tipo !== null) {
            $where[] = 'cm.tipo = ?';
            $params[] = $tipo;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("
            SELECT cm.*, u.username, u.nome AS utente_nome, u.cognome AS utente_cognome,
                   c.id_contratto_univoco
            FROM comunicazioni cm
            LEFT JOIN utenti u ON u.id = cm.id_utente
            LEFT JOIN contratti c ON c.id = cm.id_contratto
            $whereClause
            ORDER BY cm.data_comunicazione DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT cm.*, u.username, c.id_contratto_univoco
            FROM comunicazioni cm
            LEFT JOIN utenti u ON u.id = cm.id_utente
            LEFT JOIN contratti c ON c.id = cm.id_contratto
            WHERE cm.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM comunicazioni WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function markAsSent(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE comunicazioni SET inviata = 1, data_invio = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function setError(int $id, string $error): bool
    {
        $stmt = $this->pdo->prepare("UPDATE comunicazioni SET errore_invio = ? WHERE id = ?");
        return $stmt->execute([$error, $id]);
    }

    public function countByContratto(int $idContratto): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM comunicazioni WHERE id_contratto = ?");
        $stmt->execute([$idContratto]);
        return (int)$stmt->fetchColumn();
    }

    // === TEMPLATE ===
    public function listaTemplate(bool $soloAttivi = true): array
    {
        $where = $soloAttivi ? 'WHERE attivo = 1' : '';
        return $this->pdo->query("SELECT * FROM template_email $where ORDER BY nome")->fetchAll();
    }

    public function findTemplate(string $codice): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM template_email WHERE codice = ? LIMIT 1");
        $stmt->execute([$codice]);
        return $stmt->fetch() ?: null;
    }

    public function saveTemplate(array $data): int
    {
        if (!empty($data['id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE template_email SET nome = ?, oggetto = ?, corpo = ?, attivo = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$data['nome'], $data['oggetto'], $data['corpo'], $data['attivo'] ?? 1, $data['id']]);
            return (int)$data['id'];
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO template_email (codice, nome, oggetto, corpo, attivo)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$data['codice'], $data['nome'], $data['oggetto'], $data['corpo'], $data['attivo'] ?? 1]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Sostituisce le variabili nel template con i dati del contratto.
     */
    public function renderTemplate(string $testo, int $idContratto): string
    {
        // Carica dati contratto
        $stmt = $this->pdo->prepare("
            SELECT c.*,
                c.codice_identificativo_contratto AS codice_ade,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome, ' ', COALESCE(s.nome,'')) SEPARATOR ', ')
                 FROM contratto_conduttori cc
                 JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id) AS conduttori_list,
                (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
                 FROM contratto_locatori cl
                 JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id ORDER BY cl.richiedente DESC LIMIT 1) AS locatore_nome,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome, ' ', COALESCE(s.nome,'')) ORDER BY cl.richiedente DESC SEPARATOR ' / ')
                 FROM contratto_locatori cl
                 JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id) AS locatori_list,
                (SELECT s.cognome FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id ORDER BY cl.richiedente DESC LIMIT 1) AS locatore_cognome,
                (SELECT s.email FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                 WHERE cl.id_contratto = c.id ORDER BY cl.richiedente DESC LIMIT 1) AS locatore_email,
                (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
                 FROM contratto_conduttori cc
                 JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id LIMIT 1) AS conduttore_nome,
                (SELECT GROUP_CONCAT(CONCAT(s.cognome, ' ', COALESCE(s.nome,'')) SEPARATOR ', ')
                 FROM contratto_conduttori cc
                 JOIN soggetti s ON s.id = cc.id_conduttore
                 WHERE cc.id_contratto = c.id) AS conduttori_nomi,
                (SELECT CONCAT(TRIM(COALESCE(i.toponimo,'')), ' ', COALESCE(i.indirizzo,''), ' ', COALESCE(i.civico,''))
                 FROM contratto_immobili ci
                 JOIN immobili i ON i.id = ci.id_immobile
                 WHERE ci.id_contratto = c.id LIMIT 1) AS immobile_indirizzo,
                (SELECT i.comune FROM contratto_immobili ci JOIN immobili i ON i.id = ci.id_immobile
                 WHERE ci.id_contratto = c.id LIMIT 1) AS immobile_comune
            FROM contratti c
            WHERE c.id = ?
        ");
        $stmt->execute([$idContratto]);
        $c = $stmt->fetch();
        if (!$c) return $testo;

        // Nominativi di TUTTI i locatori del contratto (supporto multi-locatore).
        // Se la query non restituisce nulla, fallback sul locatore richiedente.
        $locatoriList = trim((string)($c['locatori_list'] ?? ''));
        if ($locatoriList === '') {
            $locatoriList = trim((string)($c['locatore_nome'] ?? ''));
        }

        $vars = [
            '{id_contratto}' => $c['id_contratto_univoco'] ?: ('#' . $c['id']),
            '{codice_ade}' => $c['codice_ade'] ?: 'Non ancora assegnato',
            '{conduttori_list}' => $c['conduttori_list'] ?: 'Nessun conduttore',
            '{data_stipula}' => $c['data_stipula'] ? date('d/m/Y', strtotime($c['data_stipula'])) : '',
            '{data_scadenza}' => $c['durata_al'] ? date('d/m/Y', strtotime($c['durata_al'])) : '',
            '{canone}' => $c['importo_canone'] ? '€ ' . number_format((float)$c['importo_canone'], 2, ',', '') : '',
            // Locatori: {locatore_nome} e alias puntano all'elenco completo (multi-locatore).
            '{locatore_nome}' => $locatoriList,
            '{nome_locatore}' => $locatoriList,
            '{locatori}' => $locatoriList,
            '{locatori_list}' => $locatoriList,
            '{locatore_cognome}' => $c['locatore_cognome'] ?? '',
            '{locatore_email}' => $c['locatore_email'] ?? '',
            '{conduttore_nome}' => $c['conduttore_nome'] ?? '',
            '{conduttori_nomi}' => $c['conduttori_nomi'] ?? '',
            '{immobile_indirizzo}' => trim($c['immobile_indirizzo'] ?? ''),
            '{immobile_comune}' => $c['immobile_comune'] ?? '',
            // Cerca il nome dello studio nelle impostazioni dell'utente, altrimenti usa il fallback
            '{studio_nome}' => (function() {
                if (isset($_SESSION['utente_id'])) {
                    $stmtU = $this->pdo->prepare("SELECT smtp_from_name FROM utenti WHERE id = ? LIMIT 1");
                    $stmtU->execute([$_SESSION['utente_id']]);
                    $nome = $stmtU->fetchColumn();
                    if ($nome) return $nome;
                }
                return defined('RLI_STUDIO_NOME') ? RLI_STUDIO_NOME : 'RLI Manager';
            })(),
            '{data_oggi}' => date('d/m/Y'),
        ];

        // Sostituzione CASE-INSENSITIVE: gestisce sia {locatore_nome} sia {LOCATORE_NOME}.
        $testo = str_ireplace(array_keys($vars), array_values($vars), $testo);

        // Rete di sicurezza: se restano segnaposto non riconosciuti (es. {FOO}),
        // li rimuove per non inviare testo grezzo e li registra nel log per la bonifica.
        if (preg_match_all('/\{[A-Za-z0-9_]+\}/', $testo, $m)) {
            error_log(
                'ComunicazioneRepository::renderTemplate — segnaposto non risolti per contratto #' .
                $idContratto . ': ' . implode(', ', array_unique($m[0]))
            );
            $testo = preg_replace('/\{[A-Za-z0-9_]+\}/', '', $testo);
        }

        return $testo;
    }
}
