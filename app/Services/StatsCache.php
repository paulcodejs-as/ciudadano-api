<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caché de las respuestas públicas. Las claves llevan una "versión" que los
 * importadores incrementan con flush(): así se invalida todo sin necesitar
 * tags (el driver file no los soporta).
 */
class StatsCache
{
    private const VERSION_KEY = 'ciudadano:stats-version';

    public function remember(string $key, Closure $callback): mixed
    {
        $version = Cache::get(self::VERSION_KEY, 1);

        return Cache::remember(
            "ciudadano:v{$version}:{$key}",
            now()->addMinutes((int) config('ciudadano.cache_minutes', 1440)),
            $callback
        );
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
    }
}
