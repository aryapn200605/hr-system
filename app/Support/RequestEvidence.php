<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Evidence files. The type comes from the file's content and the name is random, so
 * nothing the client says about a file reaches the disk or the database.
 */
class RequestEvidence
{
    public const TYPE_MESSAGE = 'Evidence must really be a PDF, JPG or PNG file.';

    /**
     * The accepted types, by what the content really is.
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'file',
            'max:'.config('requests.evidence_max_kb'),
            'mimetypes:'.implode(',', array_keys(self::EXTENSIONS)),
        ];
    }

    /**
     * Get the accept list for a file input. Only a hint: the server decides from the content.
     */
    public static function accept(): string
    {
        return '.'.implode(',.', self::EXTENSIONS);
    }

    /**
     * Store a validated file and return the columns that describe it.
     *
     * @return array{evidence_path: string, evidence_mime: string, evidence_size: int}
     */
    public static function store(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $name = Str::random(40).'.'.self::EXTENSIONS[$mime];

        static::disk()->putFileAs('', $file, $name);

        return ['evidence_path' => $name, 'evidence_mime' => $mime, 'evidence_size' => $file->getSize()];
    }

    /**
     * Remove a stored file whose request did not make it.
     */
    public static function discard(?string $path): void
    {
        if ($path !== null) {
            static::disk()->delete($path);
        }
    }

    /**
     * Get the name a download carries. The name given at upload is not kept.
     */
    public static function downloadName(Model $request): string
    {
        return 'evidence-'.$request->getKey().'.'.self::EXTENSIONS[$request->evidence_mime];
    }

    /**
     * Audit an upload with its type and size, and never its original name.
     */
    public static function audit(Model $request, User $user): void
    {
        AuditLog::record(AuditEvent::RequestEvidenceUploaded, $user, $request, [
            'mime' => $request->evidence_mime,
            'size' => $request->evidence_size,
        ]);
    }

    public static function disk(): mixed
    {
        return Storage::disk(config('requests.evidence_disk'));
    }
}
