<?php

namespace App\Support;

/**
 * Ámbito geográfico de la app (config ciudadano.ubigeo_prefixes).
 * Lista vacía = todo el Perú. Ej.: ['1501', '0701'] = Lima Metropolitana y Callao.
 */
final class UbigeoScope
{
    /** @return list<string> */
    public static function prefixes(): array
    {
        return array_values(array_filter((array) config('ciudadano.ubigeo_prefixes', [])));
    }

    public static function contains(string $ubigeo): bool
    {
        $prefixes = self::prefixes();
        if ($prefixes === []) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($ubigeo, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
