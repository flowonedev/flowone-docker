<?php

declare(strict_types=1);

namespace Webmail\Utils;

/**
 * Deterministic-ish Hungarian display names from a seed string.
 */
final class HungarianNameGenerator
{
    /** @var list<string> */
    private const FIRST = [
        'Bence', 'Anna', 'Márton', 'Réka', 'Dániel', 'Eszter', 'Gábor', 'Katalin',
        'László', 'Noémi', 'Péter', 'Zsófia', 'Ádám', 'Bea', 'Csaba', 'Dóra',
        'Ferenc', 'Hanna', 'Imre', 'Júlia', 'Krisztián', 'Levente', 'Márta', 'Norbert',
        'Orsolya', 'Patrik', 'Roland', 'Szilvia', 'Tamás', 'Viktor',
    ];

    /** @var list<string> */
    private const LAST = [
        'Kovács', 'Nagy', 'Szabó', 'Tóth', 'Horváth', 'Varga', 'Kiss', 'Molnár',
        'Németh', 'Farkas', 'Balogh', 'Papp', 'Takács', 'Juhász', 'Rácz', 'Fekete',
        'Oláh', 'Simon', 'Lakatos', 'Fodor', 'Bíró', 'Orosz', 'Mészáros', 'Gulyás',
        'Kocsis', 'Vass', 'Halász', 'Szűcs', 'Boros', 'Somogyi',
    ];

    public static function displayName(int $index, string $runId): string
    {
        $h = self::hashSeed($runId . ':' . $index);
        $f = self::FIRST[$h % count(self::FIRST)];
        // intdiv keeps the result an int — plain "/" returns a float on PHP 8+ and triggers
        // an implicit float-to-int deprecation when used as an array index.
        $l = self::LAST[intdiv($h, 31) % count(self::LAST)];
        return $f . ' ' . $l;
    }

    /**
     * ASCII slug for plus-addressing: "Bence Kovács" -> "bence.kovacs"
     */
    public static function slug(int $index, string $runId): string
    {
        $name = self::displayName($index, $runId);
        $ascii = self::stripDiacritics($name);
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '.', $ascii) ?? '';
        return trim($ascii, '.');
    }

    private static function stripDiacritics(string $s): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O',
            'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
        ];
        return strtr($s, $map);
    }

    private static function hashSeed(string $s): int
    {
        $u = unpack('N', hash('crc32b', $s, true));
        return (int) ($u[1] & 0x7fffffff);
    }
}
