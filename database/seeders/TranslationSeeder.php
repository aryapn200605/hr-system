<?php

namespace Database\Seeders;

use App\Translation\DatabaseLoader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads database/translations/{locale}.php into the translations table.
 *
 * Only rows that do not exist yet are inserted, so it is safe to run any number of times and never
 * overwrites a text that was changed later with app:translation-set. Changing an existing base text
 * therefore needs app:translation-set or a migration of its own.
 */
class TranslationSeeder extends Seeder
{
    /**
     * Rows per insert statement.
     */
    private const CHUNK = 200;

    /**
     * Tries per chunk when MySQL reports a deadlock.
     */
    private const ATTEMPTS = 5;

    public function run(): void
    {
        $languages = DB::table('languages')->pluck('code')->all();
        $now = now();

        foreach (glob(database_path('translations/*.php')) as $file) {
            $locale = basename($file, '.php');

            if (! in_array($locale, $languages, true)) {
                throw new RuntimeException("Add the language '{$locale}' to the languages table before loading its texts.");
            }

            $rows = [];

            foreach (require $file as $group => $items) {
                foreach ($items as $key => $value) {
                    $rows[] = [
                        'locale' => $locale,
                        'group' => $group,
                        'key' => (string) $key,
                        'value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                // The unique index on (locale, group, key) skips rows that exist already or that another run
                // inserts meanwhile. Two overlapping runs can deadlock on that index, and MySQL then rolls one back:
                // the transaction retries it, which inserts nothing twice.
                DB::transaction(fn () => DB::table('translations')->insertOrIgnore($chunk), self::ATTEMPTS);
            }
        }

        DatabaseLoader::flush();
    }
}
