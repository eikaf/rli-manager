<?php
/**
 * FiscalCalculator — wrapper statico di retrocompatibilità.
 *
 * La logica fiscale è ora centralizzata in RliTaxCalculator (unica fonte di calcolo).
 * Questo wrapper mantiene le vecchie chiamate statiche funzionanti e delega
 * al nuovo motore, usando la connessione PDO globale per caricare i tassi legali.
 */
require_once __DIR__ . '/RliTaxCalculator.php';

class FiscalCalculator
{
    private static function engine(): RliTaxCalculator
    {
        global $pdo;
        return new RliTaxCalculator(($pdo ?? null) instanceof PDO ? $pdo : null);
    }

    public static function calcolaImpostaRegistro(array $p, array $locatori = [], array $immobili = [], array $opts = []): float
    {
        return self::engine()->calcolaImpostaRegistro($p, $locatori, $immobili, $opts);
    }

    public static function calcolaImpostaBollo(array $p): float
    {
        return self::engine()->calcolaImpostaBollo($p);
    }

    public static function calcolaTermineRiferimento(array $p): ?string
    {
        return self::engine()->calcolaTermineRiferimento($p);
    }

    public static function calcolaRavvedimento(array $p, float $impReg, float $impBol): array
    {
        return self::engine()->calcolaRavvedimento($p, $impReg, $impBol);
    }

    public static function calcolaInteressi(float $base, string $dRif, string $dTrasm): float
    {
        return self::engine()->calcolaInteressi($base, $dRif, $dTrasm);
    }

    public static function calcolaSanzioneRegistro(string $stip, ?string $inizio, string $trasm, float $impBase): float
    {
        return self::engine()->calcolaSanzioneRegistro($stip, $inizio, $trasm, $impBase);
    }

    public static function calcolaSanzioneBollo(string $stip, string $trasm, float $impBollo): float
    {
        return self::engine()->calcolaSanzioneBollo($stip, $trasm, $impBollo);
    }

    public static function calcolaTutto(array $p, array $locatori = []): array
    {
        return self::engine()->calcolaTutto($p, $locatori);
    }
}
