<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * TOTP per RFC 6238: six digits, 30 seconds, SHA-1, as Google and Microsoft Authenticator expect.
 */
class TwoFactor
{
    /**
     * Seconds per timestep.
     */
    private const PERIOD = 30;

    /**
     * Accepted clock drift, in timesteps either side of now.
     */
    private const WINDOW = 1;

    /**
     * Base32 characters in a new secret, which gives 160 bits.
     */
    private const SECRET_LENGTH = 32;

    /**
     * Generate a new secret.
     */
    public static function generateSecret(): string
    {
        return (new Google2FA)->generateSecretKey(self::SECRET_LENGTH);
    }

    /**
     * Render the enrollment QR code locally as inline SVG.
     */
    public static function qrCodeSvg(User $user, #[SensitiveParameter] string $secret): string
    {
        $uri = (new Google2FA)->getQRCodeUrl(config('app.name'), $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return trim(Str::after($writer->writeString($uri), '?>'));
    }

    /**
     * Find the timestep a code belongs to, only if it is newer than the last accepted one.
     *
     * The library returns true instead of the timestep when the old timestep is null, so null becomes 0.
     */
    public static function matchingTimestep(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        ?int $lastTimestep = null,
    ): ?int {
        $timestep = (new Google2FA)->verifyKeyNewer(
            $secret,
            $code,
            $lastTimestep ?? 0,
            self::WINDOW,
            (int) floor(now()->getTimestamp() / self::PERIOD),
        );

        return is_int($timestep) ? $timestep : null;
    }

    /**
     * Verify a code and claim its timestep. The conditional update lets only one of two
     * concurrent requests with the same code succeed.
     */
    public static function verify(User $user, #[SensitiveParameter] string $code): bool
    {
        $timestep = static::matchingTimestep($user->two_factor_secret, $code, $user->two_factor_last_timestep);

        if ($timestep === null) {
            return false;
        }

        return User::query()
            ->whereKey($user->getKey())
            ->where(fn (Builder $query) => $query
                ->whereNull('two_factor_last_timestep')
                ->orWhere('two_factor_last_timestep', '<', $timestep))
            ->update(['two_factor_last_timestep' => $timestep]) === 1;
    }
}
