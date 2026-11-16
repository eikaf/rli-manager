<?php
/**
 * Theme — Gestione del tema dell'interfaccia (chiaro/scuro/automatico).
 *
 * La preferenza è salvata in utenti.tema e copiata in $_SESSION['utente_tema']
 * al login e al salvataggio del profilo.
 */
class Theme
{
    public const OPTIONS = ['light', 'dark', 'auto'];

    /**
     * Tema corrente dell'utente (default: light).
     */
    public static function current(): string
    {
        $t = $_SESSION['utente_tema'] ?? 'light';
        return in_array($t, self::OPTIONS, true) ? $t : 'light';
    }

    public static function isDark(): bool
    {
        return self::current() === 'dark';
    }

    /**
     * Attributo da inserire nel tag <html> (es. data-theme="dark").
     */
    public static function htmlAttr(): string
    {
        return ' data-theme="' . htmlspecialchars(self::current(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Etichetta leggibile per il selettore del profilo.
     */
    public static function label(string $t): string
    {
        $labels = [
            'light' => '☀️ Chiaro',
            'dark'  => '🌙 Scuro',
            'auto'  => '🖥️ Automatico (sistema)',
        ];
        return $labels[$t] ?? $t;
    }
}
