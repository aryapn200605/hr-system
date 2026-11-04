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
     * Tries when MySQL reports a deadlock.
     */
    private const ATTEMPTS = 5;

    public function run(): void
    {
        // One transaction for the whole run, which first locks the language rows: a second run waits here
        // until the first one commits, then inserts nothing. Inserting in parallel instead let MySQL
        // deadlock INSERT IGNORE statements on the unique index more often than retries could absorb.
        DB::transaction(function () {
            $languages = DB::table('languages')->orderBy('code')->lockForUpdate()->pluck('code')->all();
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

                // The unique index on (locale, group, key) skips rows that exist already.
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    DB::table('translations')->insertOrIgnore($chunk);
                }
            }
        }, self::ATTEMPTS);

        DatabaseLoader::flush();
    }
}
