<?php

namespace App\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads interface texts from the translations table, so __(), @lang and validation messages read the database.
 *
 * Each language and group is cached under a version number. flush() moves to a new version, so every
 * cached entry is abandoned at once without having to know its key.
 */
class DatabaseLoader implements Loader
{
    private const VERSION_KEY = 'translations.version';

    /**
     * Load the messages for the given locale and group. Namespaced groups are not used and load nothing.
     *
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        if ($namespace !== null && $namespace !== '*') {
            return [];
        }

        $key = 'translations.'.static::version().'.'.$locale.'.'.$group;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $rows = DB::table('translations')->where('locale', $locale)->where('group', $group)->pluck('value', 'key')->all();
        } catch (Throwable) {
            // Before the table exists (a fresh install running its first migrations) texts fall back to
            // their keys. Nothing is cached, so the real texts load once the table is there.
            return [];
        }

        $messages = $group === '*' ? $rows : Arr::undot($rows);
        Cache::forever($key, $messages);

        return $messages;
    }

    /**
     * Abandon every cached translation.
     */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, bin2hex(random_bytes(8)));
    }

    /**
     * The current cache version.
     */
    private static function version(): string
    {
        return Cache::rememberForever(self::VERSION_KEY, fn () => bin2hex(random_bytes(8)));
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint): void {}

    /**
     * @param  string  $path
     */
    public function addJsonPath($path): void {}

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return [];
    }
}
