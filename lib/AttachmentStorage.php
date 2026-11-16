<?php
/**
 * AttachmentStorage — salvataggio/lettura degli allegati PDF su file system
 * (fuori dalla document root), con fallback trasparente sul vecchio BLOB.
 *
 * I percorsi memorizzati in DB sono RELATIVI a RLI_STORAGE_PATH
 * (es. "allegati/2026/11/ab12...pdf"), così lo storage può essere spostato.
 */
class AttachmentStorage
{
    /**
     * Salva un PDF su disco.
     *
     * @return array{path: string, sha256: string, size: int}
     */
    public static function save(string $data, string $originalName = 'allegato.pdf'): array
    {
        $sha    = hash('sha256', $data);
        $relDir = 'allegati/' . date('Y') . '/' . date('m');
        $absDir = self::ensureDir($relDir);
        $name   = bin2hex(random_bytes(16)) . '.pdf';
        $absPath = $absDir . '/' . $name;

        if (@file_put_contents($absPath, $data, LOCK_EX) === false) {
            throw new RuntimeException("Impossibile salvare l'allegato in {$absPath}");
        }
        @chmod($absPath, 0640);

        return [
            'path'   => $relDir . '/' . $name,
            'sha256' => $sha,
            'size'   => strlen($data),
        ];
    }

    /**
     * Lettura trasparente: usa il file su disco se `allegato_path` è valorizzato
     * e il file esiste; altrimenti fa fallback sul vecchio BLOB `allegato_pdf`.
     */
    public static function read(?string $path, ?string $blob): ?string
    {
        if (!empty($path)) {
            $abs = self::absolutePath($path);
            if (is_file($abs)) {
                $data = @file_get_contents($abs);
                if ($data !== false) {
                    return $data;
                }
            }
        }
        return ($blob !== null && $blob !== '') ? $blob : null;
    }

    public static function delete(?string $path): void
    {
        if (!empty($path)) {
            $abs = self::absolutePath($path);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    public static function exists(?string $path): bool
    {
        return !empty($path) && is_file(self::absolutePath($path));
    }

    public static function absolutePath(string $relativePath): string
    {
        $base = rtrim(self::basePath(), '/\\');
        return $base . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    private static function basePath(): string
    {
        if (defined('RLI_STORAGE_PATH')) {
            return RLI_STORAGE_PATH;
        }
        return sys_get_temp_dir() . '/rli_storage';
    }

    private static function ensureDir(string $relDir): string
    {
        $abs = self::absolutePath($relDir);
        if (!is_dir($abs)) {
            if (!@mkdir($abs, 0770, true) && !is_dir($abs)) {
                throw new RuntimeException("Impossibile creare la directory allegati: {$abs}");
            }
        }
        return $abs;
    }
}
