<?php

namespace App\Models;

use App\Translation\DatabaseLoader;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One interface text in one language. Every save or delete through the model refreshes the cache.
 */
#[Fillable(['locale', 'group', 'key', 'value'])]
class Translation extends Model
{
    /**
     * Refresh the translation cache whenever a row changes through the model.
     */
    protected static function booted(): void
    {
        static::saved(fn () => DatabaseLoader::flush());
        static::deleted(fn () => DatabaseLoader::flush());
    }
}
