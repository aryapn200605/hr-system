<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Translation;
use App\Translation\KeyScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Add or change one interface text. Console only: whoever can run it already controls the server,
 * so there is no application role to check. The change is audited with a null actor, and the
 * translation cache is refreshed by the model.
 */
#[Signature('app:translation-set {locale : Language code, for example id} {key : The key, for a sentence the English text} {value : The text to show} {--group= : "*" (the default) for sentence keys, or validation, auth, passwords}')]
#[Description('Add or change one translation and refresh the translation cache')]
class TranslationSet extends Command
{
    public function handle(): int
    {
        $input = [
            'locale' => $this->argument('locale'),
            'group' => $this->option('group') ?? '*',
            'key' => $this->argument('key'),
            'value' => $this->argument('value'),
        ];

        $validator = Validator::make($input, [
            'locale' => ['required', 'string', Rule::exists('languages', 'code')],
            'group' => ['required', 'string', Rule::in(['*', ...KeyScanner::KEYED_GROUPS])],
            'key' => ['required', 'string', 'max:500'],
            // No markup: every text is printed escaped, so HTML would only show up as code.
            'value' => ['required', 'string', 'max:5000', 'not_regex:/<[^>]*>|&[#A-Za-z0-9]+;/'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $base = DB::table('translations')
            ->where('locale', config('app.fallback_locale'))
            ->where('group', $input['group'])
            ->where('key', $input['key'])
            ->value('value');

        if ($base !== null && static::placeholders($base) !== static::placeholders($input['value'])) {
            $this->components->error('The text must use the same placeholders as the English text: '.(implode(', ', static::placeholders($base)) ?: 'none').'.');

            return self::FAILURE;
        }

        $translation = Translation::updateOrCreate(
            ['locale' => $input['locale'], 'group' => $input['group'], 'key' => $input['key']],
            ['value' => $input['value']],
        );

        AuditLog::record(AuditEvent::TranslationChanged, metadata: [
            'locale' => $input['locale'],
            'group' => $input['group'],
            'key' => $input['key'],
            'created' => $translation->wasRecentlyCreated,
        ]);

        $this->components->info($translation->wasRecentlyCreated ? 'Translation added.' : 'Translation updated.');

        return self::SUCCESS;
    }

    /**
     * Placeholder names in a text, lowercased and sorted (:Attribute and :attribute are the same one).
     *
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_]+)/', $text, $matches);
        $names = array_unique(array_map('strtolower', $matches[1]));
        sort($names);

        return $names;
    }
}
