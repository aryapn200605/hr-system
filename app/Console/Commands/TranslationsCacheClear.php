<?php

namespace App\Console\Commands;

use App\Translation\DatabaseLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Needed only after editing the translations table directly in the database: changes made through
 * app:translation-set or the seeder refresh the cache themselves.
 */
#[Signature('app:translations-cache-clear')]
#[Description('Refresh the translation cache after a direct database edit')]
class TranslationsCacheClear extends Command
{
    public function handle(): int
    {
        DatabaseLoader::flush();

        $this->components->info('Translation cache refreshed.');

        return self::SUCCESS;
    }
}
