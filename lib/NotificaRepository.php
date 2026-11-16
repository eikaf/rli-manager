<?php
/**
 * NotificaRepository — gestione notifiche persistenti.
 */
class NotificaRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Costruisce la clausola WHERE per filtrare le notifiche in base ai permessi dell'utente.
     */
    private function buildVisibilityFilter(?Auth $auth): array
    {
        if ($auth === null || $auth->isAdmin()) {
            return ['where' => '', 'params' => []];
        }

        $idUtente = $auth->id();
        $where = " AND (
            n.id_contratto IS NULL
            OR EXISTS (
                SELECT 1 FROM contratti c
                WHERE c.id = n.id_contratto
                  AND (
                    c.id_utente_creatore = :vis_id_utente
                    OR EXISTS (
                        SELECT 1 FROM contratti_condivisioni ccv
                        WHERE ccv.id_contratto = c.id AND ccv.id_utente = :vis_id_utente2
                    )
                  )
            )
        )";
        return [
            'where' => $where,
            'params' => [
                ':vis_id_utente' => $idUtente,
                ':vis_id_utente2' => $idUtente,
            ],
        ];
    }

    public function countTotali(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifiche")->fetchColumn();
    }

    /**
     * Crea una notifica.
     */
    public function create(
        string $tipo,
        string $messaggio,
        string $priorita = 'info',
        ?int $idContratto = null,
        ?string $dataScadenza = null,
        string $origine = 'manuale',
        ?string $chiaveUnivoca = null
    ): ?int {
        if ($chiaveUnivoca !== null) {
            $stmt = $this->pdo->prepare("SELECT id FROM notifiche WHERE chiave_univoca = ? LIMIT 1");
            $stmt->execute([$chiaveUnivoca]);
            if ($stmt->fetchColumn()) return null;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO notifiche (tipo, id_contratto, data_scadenza, messaggio, priorita, origine, chiave_univoca, letta)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            substr($tipo, 0, 50),
            $idContratto,
            $dataScadenza,
            $messaggio,
            $priorita,
            substr($origine, 0, 30),
            $chiaveUnivoca,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Notifiche non lette (per badge topbar).
     */
    public function pendenti(int $limit = 50, ?Auth $auth = null): array
    {
        $filter = $this->buildVisibilityFilter($auth);
        $sql = "
            SELECT n.*, c.id_contratto_univoco
            FROM notifiche n
            LEFT JOIN contratti c ON c.id = n.id_contratto
            WHERE n.letta = 0
        " . $filter['where'] . "
            ORDER BY
                FIELD(n.priorita, 'critical', 'warning', 'info'),
                n.data_creazione DESC
            LIMIT " . (int)$limit . "
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filter['params']);
        return $stmt->fetchAll();
    }

    /**
     * Conta solo le non lette (per badge).
     */
    public function countPendenti(?Auth $auth = null): int
    {
        $filter = $this->buildVisibilityFilter($auth);
        $sql = "SELECT COUNT(*) FROM notifiche n WHERE n.letta = 0" . $filter['where'];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filter['params']);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Tutte le notifiche.
     */
    public function all(?bool $soloNonLette = null, int $limit = 200, ?Auth $auth = null): array
    {
        $where = [];
        $params = [];

        // 1. Filtro lette/non lette
        if ($soloNonLette === true)  $where[] = 'n.letta = 0';
        if ($soloNonLette === false) $where[] = 'n.letta = 1';

        // 2. Filtro visibilità
        if ($auth !== null && !$auth->isAdmin()) {
            $where[] = "(
                n.id_contratto IS NULL
                OR EXISTS (
                    SELECT 1 FROM contratti c2
                    WHERE c2.id = n.id_contratto
                      AND (
                        c2.id_utente_creatore = :vis_id_utente
                        OR EXISTS (
                            SELECT 1 FROM contratti_condivisioni ccv
                            WHERE ccv.id_contratto = c2.id AND ccv.id_utente = :vis_id_utente2
                        )
                      )
                )
            )";
            $params[':vis_id_utente'] = $auth->id();
            $params[':vis_id_utente2'] = $auth->id();
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT n.*, c.id_contratto_univoco
            FROM notifiche n
            LEFT JOIN contratti c ON c.id = n.id_contratto
            $whereClause
            ORDER BY
                n.letta ASC,
                FIELD(n.priorita, 'critical', 'warning', 'info'),
                n.data_creazione DESC
            LIMIT " . (int)$limit . "
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Notifiche per range di date (per calendario).
     */
    public function perRange(string $dal, string $al, ?Auth $auth = null): array
    {
        $filter = $this->buildVisibilityFilter($auth);
        
        // Sostituiamo i ? con i named parameters (:dal, :al)
        $sql = "
            SELECT n.*, c.id_contratto_univoco
            FROM notifiche n
            LEFT JOIN contratti c ON c.id = n.id_contratto
            WHERE n.data_scadenza BETWEEN :dal AND :al
        " . $filter['where'] . "
            ORDER BY n.data_scadenza ASC, n.data_creazione DESC
        ";
        
        $params = array_merge(
            [':dal' => $dal, ':al' => $al],
            $filter['params']
        );
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function markAsRead(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE notifiche SET letta = 1, data_lettura = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function markAllAsRead(): int
    {
        return $this->pdo->exec("UPDATE notifiche SET letta = 1, data_lettura = NOW() WHERE letta = 0");
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM notifiche WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function cleanOld(int $giorni = 90): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM notifiche WHERE letta = 1 AND data_lettura < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$giorni]);
        return $stmt->rowCount();
    }

    public function generaDaScadenze(PDO $pdo): array
    {
        $create = [];

        $stmt = $pdo->query("
            SELECT c.id, c.id_contratto_univoco, c.durata_al, c.stato,
                   DATEDIFF(c.durata_al, CURDATE()) AS giorni_rimasti,
                   (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
                    FROM contratto_locatori cl JOIN soggetti s ON s.id = cl.id_locatore
                    WHERE cl.id_contratto = c.id ORDER BY cl.richiedente DESC LIMIT 1) AS locatore,
                   (SELECT CONCAT(s.cognome, ' ', COALESCE(s.nome,''))
                    FROM contratto_conduttori cc JOIN soggetti s ON s.id = cc.id_conduttore
                    WHERE cc.id_contratto = c.id LIMIT 1) AS conduttore
            FROM contratti c
            WHERE c.stato IN ('ATTIVO', 'PROROGATO')
              AND c.durata_al IS NOT NULL
              AND c.durata_al <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ");

        foreach ($stmt->fetchAll() as $c) {
            $gg = (int)$c['giorni_rimasti'];
            $nome = ($c['locatore'] ?: '?') . ' / ' . ($c['conduttore'] ?: '?');
            $idC = (int)$c['id'];
            $scad = $c['durata_al'];

            if ($gg < 0) {
                $chiave = "scaduto_contratto_{$idC}";
                $id = $this->create('scaduto', "Contratto #{$idC} ({$nome}) SCADUTO il " . date('d/m/Y', strtotime($scad)) . " — azione richiesta.", 'critical', $idC, $scad, 'cron', $chiave);
                if ($id) $create[] = $id;
            } elseif ($gg <= 30) {
                $chiave = "scadenza30_contratto_{$idC}";
                $id = $this->create('scadenza', "Contratto #{$idC} ({$nome}) scade tra {$gg} giorni (" . date('d/m/Y', strtotime($scad)) . ").", 'critical', $idC, $scad, 'cron', $chiave);
                if ($id) $create[] = $id;
            } elseif ($gg <= 60) {
                $chiave = "scadenza60_contratto_{$idC}";
                $id = $this->create('scadenza', "Contratto #{$idC} ({$nome}) scade tra {$gg} giorni (" . date('d/m/Y', strtotime($scad)) . ").", 'warning', $idC, $scad, 'cron', $chiave);
                if ($id) $create[] = $id;
            } elseif ($gg <= 90) {
                $chiave = "scadenza90_contratto_{$idC}";
                $id = $this->create('scadenza', "Contratto #{$idC} ({$nome}) scade tra {$gg} giorni (" . date('d/m/Y', strtotime($scad)) . ").", 'info', $idC, $scad, 'cron', $chiave);
                if ($id) $create[] = $id;
            }
        }
        return $create;
    }
}
