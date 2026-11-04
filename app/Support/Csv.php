<?php

namespace App\Support;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads without a library: streamed row by row, UTF-8 with a byte order mark so Excel reads
 * the accents, CRLF line ends, and the separator from config/requests.php (a semicolon by default).
 *
 * Every cell that a spreadsheet could read as a formula (starting with =, +, -, @, a tab or a carriage
 * return) gets a leading apostrophe, so an exported name like "=HYPERLINK(...)" stays text.
 */
final class Csv
{
    public const FORMULA_PREFIX = "'";

    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    public static function delimiter(): string
    {
        $delimiter = config('requests.csv_delimiter');

        return is_string($delimiter) && strlen($delimiter) === 1 && ! in_array($delimiter, ['"', "\r", "\n"], true)
            ? $delimiter
            : ';';
    }

    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], self::FORMULA_STARTS, true) ? self::FORMULA_PREFIX.$value : $value;
    }

    /**
     * A streamed download. $rows yields one list of cells per line; $finished receives the number of
     * data lines written, once every line is out.
     *
     * @param  list<string>  $header
     * @param  Closure(): iterable<list<mixed>>  $rows
     * @param  Closure(int): void  $finished
     */
    public static function download(string $filename, array $header, Closure $rows, Closure $finished): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $finished) {
            // A closed tab must not stop the export halfway: the audit row needs the final count.
            ignore_user_abort(true);

            $out = fopen('php://output', 'wb');
            $delimiter = self::delimiter();
            $count = 0;

            fwrite($out, "\xEF\xBB\xBF");
            self::line($out, $header, $delimiter);

            foreach ($rows() as $row) {
                self::line($out, $row, $delimiter);
                $count++;
            }

            fclose($out);
            $finished($count);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  resource  $out
     * @param  list<mixed>  $cells
     */
    private static function line($out, array $cells, string $delimiter): void
    {
        fputcsv($out, array_map(self::cell(...), $cells), $delimiter, '"', '', "\r\n");
    }
}
