<?php
// app/helpers/CacheHelper.php
// Cache simples baseado em arquivos para queries pesadas.
// Em produção: substituir por Redis ou Memcached.

class CacheHelper {

    private static string $dir = '';

    private static function dir(): string {
        if (!self::$dir) {
            self::$dir = STORAGE_PATH . '/cache';
            if (!is_dir(self::$dir)) mkdir(self::$dir, 0755, true);
        }
        return self::$dir;
    }

    /**
     * Salva valor em cache.
     * @param int $ttl Tempo de vida em segundos
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): void {
        $file = self::dir() . '/' . md5($key) . '.cache';
        $data = serialize([
            'expires' => time() + $ttl,
            'value'   => $value,
        ]);
        file_put_contents($file, $data, LOCK_EX);
    }

    /**
     * Recupera valor do cache. Retorna null se expirado ou inexistente.
     */
    public static function get(string $key): mixed {
        $file = self::dir() . '/' . md5($key) . '.cache';
        if (!file_exists($file)) return null;

        $data = unserialize(file_get_contents($file));
        if (!$data || $data['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'];
    }

    /**
     * Remove um item do cache.
     */
    public static function delete(string $key): void {
        $file = self::dir() . '/' . md5($key) . '.cache';
        if (file_exists($file)) @unlink($file);
    }

    /**
     * Remove todos os caches com um prefixo.
     */
    public static function deleteByPrefix(string $prefix): void {
        $hash = md5($prefix);
        foreach (glob(self::dir() . '/*.cache') as $file) {
            // Como os arquivos são hasheados, invalida tudo para o prefixo
            // Em produção com Redis use: SCAN + DEL prefix:*
            @unlink($file);
        }
    }

    /**
     * Limpa todo o cache.
     */
    public static function flush(): void {
        foreach (glob(self::dir() . '/*.cache') as $file) {
            @unlink($file);
        }
    }

    /**
     * Padrão remember: retorna do cache ou executa o callback e armazena.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed {
        $cached = self::get($key);
        if ($cached !== null) return $cached;

        $value = $callback();
        self::set($key, $value, $ttl);
        return $value;
    }
}