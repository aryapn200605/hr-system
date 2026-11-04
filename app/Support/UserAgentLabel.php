<?php

namespace App\Support;

/**
 * A short, readable name for a user agent, such as "Chrome on Windows".
 *
 * The header is whatever the client sent, so this is a hint for the user and proves nothing.
 */
class UserAgentLabel
{
    /**
     * Checked in order, because Edge and Opera also say Chrome, and Chrome also says Safari.
     */
    private const BROWSERS = [
        'Edge' => '/\bEdg(e|A|iOS)?\//',
        'Opera' => '/\bOPR\//',
        'Firefox' => '/\b(Firefox|FxiOS)\//',
        'Chrome' => '/\b(Chrome|CriOS)\//',
        'Safari' => '/\bSafari\//',
    ];

    /**
     * Checked in order, because Android also says Linux, and iOS also says Mac OS X.
     */
    private const SYSTEMS = [
        'Android' => '/\bAndroid\b/',
        'iOS' => '/\b(iPhone|iPad|iPod)\b/',
        'Windows' => '/\bWindows\b/',
        'macOS' => '/\bMac OS X\b/',
        'Linux' => '/\bLinux\b/',
    ];

    /**
     * Name a user agent.
     */
    public static function for(?string $userAgent): string
    {
        $browser = static::firstMatch(self::BROWSERS, (string) $userAgent);
        $system = static::firstMatch(self::SYSTEMS, (string) $userAgent);

        return match (true) {
            $browser && $system => __(':browser on :system', ['browser' => $browser, 'system' => $system]),
            default => $browser ?? $system ?? __('Unknown device'),
        };
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function firstMatch(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
