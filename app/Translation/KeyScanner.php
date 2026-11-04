<?php

namespace App\Translation;

use Symfony\Component\Finder\Finder;

/**
 * Finds the translation keys written as string literals in the calls __(), trans(),
 * trans_choice() and the @lang directive. A key built at runtime cannot be found this way, which is
 * why every dynamic label is written out as a literal in a match (see the enums and UserAgentLabel).
 */
class KeyScanner
{
    /**
     * Groups that hold keyed messages. Any other key is a whole-sentence key in the '*' group.
     */
    public const KEYED_GROUPS = ['validation', 'auth', 'passwords'];

    private const PATTERN = <<<'REGEX'
        /(?:\b__|\btrans_choice|\btrans|@lang)\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/
        REGEX;

    /**
     * Every literal key, grouped: ['*' => [...], 'validation' => [...]], sorted, without duplicates.
     *
     * @param  list<string>|null  $paths  Directories to scan, relative to the base path.
     * @return array<string, list<string>>
     */
    public static function scan(?array $paths = null): array
    {
        $paths = array_map(base_path(...), $paths ?? ['app', 'resources/views', 'routes']);
        $keys = [];

        foreach ((new Finder)->files()->in($paths)->name(['*.php']) as $file) {
            preg_match_all(trim(self::PATTERN), $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = isset($match[2]) && $match[2] !== '' ? stripcslashes($match[2]) : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
                [$group, $item] = static::split($key);
                $keys[$group][$item] = true;
            }
        }

        ksort($keys);

        return array_map(function (array $items) {
            $items = array_keys($items);
            sort($items, SORT_STRING);

            return $items;
        }, $keys);
    }

    /**
     * Split a key into its group and item: 'validation.required' is in the validation group,
     * 'Log in' is a whole-sentence key.
     *
     * @return array{string, string}
     */
    public static function split(string $key): array
    {
        if (preg_match('/^('.implode('|', self::KEYED_GROUPS).')\.([\w.-]+)$/', $key, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return ['*', $key];
    }
}
