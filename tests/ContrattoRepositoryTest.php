<?php
use PHPUnit\Framework\TestCase;

class ContrattoRepositoryTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        // DB test (usa SQLite in-memory per test veloci)
        self::$pdo = new PDO('sqlite::memory:');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec("
            CREATE TABLE contratti (id INTEGER PRIMARY KEY AUTOINCREMENT, id_contratto_univoco TEXT, stato TEXT, tipologia_contratto TEXT, durata_dal TEXT, durata_al TEXT);
            CREATE TABLE contratto_locatori (id INTEGER PRIMARY KEY, id_contratto INTEGER, id_locatore INTEGER, richiedente INTEGER, percentuale_possesso REAL);
            CREATE TABLE contratto_conduttori (id INTEGER PRIMARY KEY, id_contratto INTEGER, id_conduttore INTEGER);
            CREATE TABLE contratto_immobili (id INTEGER PRIMARY KEY, id_contratto INTEGER, id_immobile INTEGER, tipologia_immobile INTEGER, intero_porzione TEXT);
            CREATE TABLE eventi_contratto (id INTEGER PRIMARY KEY, id_contratto INTEGER, tipo TEXT, data_evento TEXT, dettagli_json TEXT, imposta_registro REAL, imposta_bollo REAL, sanzione_registro REAL, sanzione_bollo REAL, interessi_registro REAL, interessi_bollo REAL);
            CREATE TABLE soggetti (id INTEGER PRIMARY KEY, cognome TEXT, nome TEXT, codice_fiscale TEXT);
            CREATE TABLE immobili (id INTEGER PRIMARY KEY, identificativo_interno TEXT, comune TEXT);
        ");
    }

    public function testBuildIdContrattoGeneratesId(): void
    {
        $repo = new ContrattoRepository(self::$pdo);
        $pratica = ['id' => 1, 'durata_dal' => '2024-01-01', 'durata_al' => '2028-12-31'];
        $locatori = [['cognome' => 'Rossi', 'nome' => 'Mario']];
        $conduttori = [['cognome' => 'Bianchi']];
        $id = $repo->buildIdContratto($pratica, $locatori, $conduttori);
        $this->assertStringContainsString('ROS_M', $id);
        $this->assertLessThanOrEqual(20, strlen($id));
    }

    public function testCountModuliCalcolaCorrettamente(): void
    {
        $repo = new ContrattoRepository(self::$pdo);
        // 5 locatori → 2 moduli
        $locatori = array_fill(0, 5, ['id' => 1]);
        $moduli = $repo->countModuli($locatori, [], []);
        $this->assertEquals(2, $moduli);
    }
}
