<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Niveles territoriales del ubigeo INEI: departamento (DD), provincia (DDPP) y distrito (DDPPDD).
 */
final class Level
{
    public const DEPARTMENT = 'department';

    public const PROVINCE = 'province';

    public const DISTRICT = 'district';

    public const ALL = [self::DEPARTMENT, self::PROVINCE, self::DISTRICT];

    private const LENGTHS = [self::DEPARTMENT => 2, self::PROVINCE => 4, self::DISTRICT => 6];

    private const LABELS = [self::DEPARTMENT => 'departamento', self::PROVINCE => 'provincia', self::DISTRICT => 'distrito'];

    public static function length(string $level): int
    {
        return self::LENGTHS[$level] ?? throw new InvalidArgumentException("Nivel desconocido: {$level}");
    }

    public static function label(string $level): string
    {
        return self::LABELS[$level];
    }

    /** Nivel según la longitud del código (2, 4 o 6 dígitos). */
    public static function ofCode(string $code): ?string
    {
        return array_flip(self::LENGTHS)[strlen($code)] ?? null;
    }

    public static function parentOf(string $level): ?string
    {
        return [self::PROVINCE => self::DEPARTMENT, self::DISTRICT => self::PROVINCE][$level] ?? null;
    }

    public static function childOf(string $level): ?string
    {
        return [self::DEPARTMENT => self::PROVINCE, self::PROVINCE => self::DISTRICT][$level] ?? null;
    }

    /** Código del ancestro de un código en el nivel dado ("150122", province → "1501"). */
    public static function ancestor(string $code, string $level): string
    {
        return substr($code, 0, self::length($level));
    }
}
