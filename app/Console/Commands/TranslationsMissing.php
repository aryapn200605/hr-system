<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Translation\DynamicLabels;
use App\Translation\KeyScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lists every key that an active language lacks. A key is required when the code names it as a
 * literal, when it is a dynamic label, or when the default language has it in the database.
 */
#[Signature('app:translations-missing {--path=* : Folders to scan for keys, relative to the project (default: app, resources/views, routes)}')]
#[Description('List translation keys that an active language lacks, and fail if there are any')]
class TranslationsMissing extends Command
{
    public function handle(): int
    {
        $required = KeyScanner::scan($this->option('path') ?: null);
        $required['*'] = array_merge($required['*'] ?? [], DynamicLabels::KEYS);

        foreach (DB::table('translations')->where('locale', config('app.fallback_locale'))->get(['group', 'key']) as $row) {
            $required[$row->group][] = $row->key;
        }

        $missing = [];

        foreach (Language::activeCodes() as $locale) {
            $present = [];

            foreach (DB::table('translations')->where('locale', $locale)->get(['group', 'key']) as $row) {
                $present[$row->group][$row->key] = true;
            }

            foreach ($required as $group => $keys) {
                foreach (array_unique($keys) as $key) {
                    if (! isset($present[$group][$key])) {
                        $missing[] = [$locale, $group, $key];
                    }
                }
            }
        }

        if ($missing === []) {
            $this->components->info('Every active language has every key.');

            return self::SUCCESS;
        }

        sort($missing);
        $this->table(['Locale', 'Group', 'Key'], $missing);
        $this->components->error(count($missing).' translations are missing.');

        return self::FAILURE;
    }
}
