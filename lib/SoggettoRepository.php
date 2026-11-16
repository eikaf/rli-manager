<?php
class SoggettoRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM soggetti ORDER BY cognome, nome")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM soggetti WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findLocatori(): array
    {
        return $this->pdo->query("SELECT * FROM soggetti WHERE tipologia_default IN ('LOCATORE','ENTRAMBI') ORDER BY cognome, nome")->fetchAll();
    }

    public function findConduttori(): array
    {
        return $this->pdo->query("SELECT * FROM soggetti WHERE tipologia_default IN ('CONDUTTORE','ENTRAMBI') ORDER BY cognome, nome")->fetchAll();
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM soggetti WHERE id IN ($ph) ORDER BY cognome, nome");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    /**
     * Restituisce gli immobili di proprietà di un locatore (con quota).
     */
    public function immobiliDiLocatore(int $idLocatore): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*, p.percentuale AS quota_proprieta
            FROM proprieta_immobili p
            JOIN immobili i ON i.id = p.id_immobile
            WHERE p.id_locatore = ?
              AND (p.data_fine IS NULL OR p.data_fine >= CURDATE())
            ORDER BY i.identificativo_interno
        ");
        $stmt->execute([$idLocatore]);
        return $stmt->fetchAll();
    }

    /**
     * Restituisce gli immobili comuni a TUTTI i locatori specificati.
     * Utile per: dato un set di locatori, quali immobili possono essere locati insieme?
     */
    public function immobiliComuni(array $idLocatori): array
    {
        if (empty($idLocatori)) return [];
        $ph = implode(',', array_fill(0, count($idLocatori), '?'));
        $stmt = $this->pdo->prepare("
            SELECT i.*, GROUP_CONCAT(CONCAT(s.cognome,' ',s.nome,' (',p.percentuale,'%)') SEPARATOR ' + ') AS proprietari,
                   SUM(p.percentuale) AS somma_quote
            FROM immobili i
            JOIN proprieta_immobili p ON p.id_immobile = i.id
            JOIN soggetti s ON s.id = p.id_locatore
            WHERE p.id_locatore IN ($ph)
              AND (p.data_fine IS NULL OR p.data_fine >= CURDATE())
            GROUP BY i.id
            HAVING COUNT(DISTINCT p.id_locatore) = ?
            ORDER BY i.identificativo_interno
        ");
        $stmt->execute(array_merge($idLocatori, [count($idLocatori)]));
        return $stmt->fetchAll();
    }
}
