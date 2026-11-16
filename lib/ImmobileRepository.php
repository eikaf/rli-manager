<?php
class ImmobileRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function all(): array
    {
        return $this->pdo->query("SELECT * FROM immobili ORDER BY identificativo_interno")->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM immobili WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM immobili WHERE id IN ($ph) ORDER BY identificativo_interno");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    /**
     * Restituisce i proprietari di un immobile.
     */
    public function proprietariDiImmobile(int $idImmobile): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, p.percentuale AS quota_proprieta
            FROM proprieta_immobili p
            JOIN soggetti s ON s.id = p.id_locatore
            WHERE p.id_immobile = ?
              AND (p.data_fine IS NULL OR p.data_fine >= CURDATE())
            ORDER BY s.cognome, s.nome
        ");
        $stmt->execute([$idImmobile]);
        return $stmt->fetchAll();
    }
}
