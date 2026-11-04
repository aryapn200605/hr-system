<?php

namespace App\Services\Requests;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional request evidence (DECISIONS.md section 8): pdf, jpg or png, the type detected from the
 * file's content, at most config('requests.evidence_max_kb'), stored on the private "evidence"
 * disk under a random name. Files are read back only through App\Http\Controllers\EvidenceController.
 */
class Evidence
{
    public const DISK = 'evidence';

    /**
     * Content types accepted, with the extension the stored file gets.
     */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Check the file. Run before anything is written.
     *
     * @throws ValidationException
     */
    public function validate(mixed $file): void
    {
        Validator::make(['evidence' => $file], [
            'evidence' => [
                'nullable',
                'file',
                'max:'.config('requests.evidence_max_kb'),
            ],
        ], [
            'evidence.max' => __('The evidence may be at most :size MB.', ['size' => $this->maxMegabytes()]),
        ], ['evidence' => __('evidence')])->validate();

        if ($file !== null && ! isset(self::TYPES[$this->detectType($file)])) {
            throw ValidationException::withMessages(['evidence' => __('The evidence must be a PDF, JPG or PNG file.')]);
        }
    }

    /**
     * The type read from the file's first bytes, never the name or the type the browser claimed.
     */
    public function detectType(UploadedFile $file): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
    }

    /**
     * Store a validated file and return the evidence columns, or an empty array when there is none.
     *
     * @return array{evidence_path?: string, evidence_name?: string, evidence_mime?: string, evidence_size?: int}
     */
    public function store(?UploadedFile $file): array
    {
        if ($file === null) {
            return [];
        }

        $mime = $this->detectType($file);
        $extension = self::TYPES[$mime] ?? throw new \LogicException('Evidence was stored without validation.');
        $path = Str::random(40).'.'.$extension;

        Storage::disk(self::DISK)->putFileAs('', $file, $path);

        return [
            'evidence_path' => $path,
            'evidence_name' => $this->downloadName($file->getClientOriginalName(), $extension),
            'evidence_mime' => $mime,
            'evidence_size' => (int) filesize($file->getRealPath()),
        ];
    }

    /**
     * Remove a stored file whose request was not saved.
     */
    public function discard(array $columns): void
    {
        if (isset($columns['evidence_path'])) {
            Storage::disk(self::DISK)->delete($columns['evidence_path']);
        }
    }

    public function maxMegabytes(): string
    {
        // One decimal, at least 0.1, without a trailing ".0": 5120 KB is "5", 1536 KB is "1.5".
        $megabytes = max(0.1, round(config('requests.evidence_max_kb') / 1024, 1));

        return str_replace('.0', '', number_format($megabytes, 1, '.', ''));
    }

    /**
     * The uploaded name without path, control or quote characters, with the extension of the real type.
     */
    private function downloadName(string $original, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME);
        $base = preg_replace('/[\x00-\x1F\x7F"\/\\\\:*?<>|]+/u', '', $base) ?? '';
        $base = trim(mb_substr($base, 0, 150));

        return ($base === '' ? 'evidence' : $base).'.'.$extension;
    }
}
