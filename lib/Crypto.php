<?php
/**
 * Crypto — cifratura simmetrica AES-256-GCM per dati sensibili (es. utenti.smtp_pass).
 *
 * Formato del valore cifrato:  enc:v1:<base64(iv[12] + tag[16] + ciphertext)>
 * Il prefisso "enc:v1:" permette di distinguere i valori cifrati dal plaintext
 * e di migrare gradualmente eventuali password ancora in chiaro.
 */
class Crypto
{
    private const PREFIX   = 'enc:v1:';
    private const CIPHER   = 'aes-256-gcm';
    private const IV_LEN   = 12;
    private const TAG_LEN  = 16;

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Cifra una stringa. Restituisce '' per input vuoto.
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Cifratura AES-256-GCM fallita.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decifra un valore nel formato enc:v1:. Se non è cifrato, lo restituisce così com'è.
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            return '';
        }
        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /**
     * Decifra un valore che può essere:
     *  - nuovo formato enc:v1:... (AES-256-GCM)
     *  - vecchio formato base64 AES-256-CBC (encryptData/decryptData)
     *  - plaintext (password mai cifrata)
     */
    public static function decryptLegacy(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (self::isEncrypted($value)) {
            return self::decrypt($value);
        }
        // Vecchio formato AES-256-CBC
        if (function_exists('decryptData')) {
            $dec = @decryptData($value);
            if (is_string($dec) && $dec !== '') {
                return $dec;
            }
        }
        // Plaintext
        return $value;
    }

    private static function key(): string
    {
        $key = defined('RLI_APP_KEY') ? RLI_APP_KEY : '';
        if (strlen($key) !== 32) {
            $key = hash('sha256', $key !== '' ? $key : 'rli-default-key', true);
        }
        return $key;
    }
}
