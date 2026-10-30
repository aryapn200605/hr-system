<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

class DesignTokensTest extends TestCase
{
    public function test_views_use_design_tokens_and_not_the_default_palette(): void
    {
        $forbidden = '/\b(?:(?:bg|text|border|ring|divide|outline|from|to|via)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}|rounded-(?:md|lg|xl|2xl|3xl|full)|shadow-(?:sm|md|lg|xl|2xl)|bg-gradient-\S+|bg-linear-\S+|backdrop-blur\S*)\b/';

        $offenders = collect(File::allFiles(resource_path('views')))
            ->reject(fn (SplFileInfo $file) => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'mail'.DIRECTORY_SEPARATOR))
            ->filter(fn (SplFileInfo $file) => preg_match($forbidden, $file->getContents()) === 1)
            ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $offenders);
    }

    public function test_the_theme_switches_off_only_colors_radii_and_shadows(): void
    {
        preg_match_all('/--([a-z-]+)-\*:\s*initial/', File::get(resource_path('css/app.css')), $resets);

        $this->assertSame(['color', 'radius', 'shadow'], $resets[1]);
    }

    public function test_the_stylesheet_loads_no_font_or_asset_from_another_host(): void
    {
        $css = File::get(resource_path('css/app.css'));
        $vite = File::get(base_path('vite.config.js'));

        $this->assertDoesNotMatchRegularExpression('/https?:\/\//', $css);
        $this->assertStringNotContainsString('bunny', $vite);
    }
}
