<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * x-ui.page is the only page container: it alone sets the width, the centring and the padding.
 */
class PageContainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_centres_a_wide_container_with_padding_by_default(): void
    {
        $html = Blade::render('<x-ui.page title="Requests">Body</x-ui.page>');

        $this->assertMatchesRegularExpression('/<div\s+data-page\s+data-width="default"\s+class="[^"]*\bmx-auto\b[^"]*"/', $html);
        $this->assertStringContainsString('max-w-7xl', $html);
        $this->assertStringContainsString('w-full', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bpx-4\b[^"]*\bsm:px-6\b/', $html);
        $this->assertStringContainsString('Body', $html);
    }

    public function test_the_narrow_and_full_widths(): void
    {
        $narrow = Blade::render('<x-ui.page width="narrow" title="Change password">Form</x-ui.page>');
        $full = Blade::render('<x-ui.page width="full">Wide</x-ui.page>');

        $this->assertStringContainsString('max-w-lg', $narrow);
        $this->assertStringNotContainsString('max-w-7xl', $narrow);
        $this->assertStringContainsString('max-w-none', $full);
        $this->assertStringContainsString('mx-auto', $narrow);
        $this->assertStringContainsString('mx-auto', $full);
    }

    public function test_it_renders_the_title_subtitle_and_actions(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.page title="Departments" subtitle="Departments that can be given to employees.">
                <x-slot:actions><button type="button">Add department</button></x-slot:actions>
                <p>Table</p>
            </x-ui.page>
            BLADE);

        $this->assertMatchesRegularExpression('/<h1[^>]*>Departments<\/h1>/', $html);
        $this->assertMatchesRegularExpression('/data-page-subtitle>Departments that can be given to employees\.</', $html);
        $this->assertMatchesRegularExpression('/data-page-actions><button type="button">Add department<\/button>/', $html);
        $this->assertLessThan(strpos($html, '<p>Table</p>'), strpos($html, '<h1'));
    }

    public function test_title_and_subtitle_can_be_slots_with_markup(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.page title="Rina">
                <x-slot:subtitle><span data-badge>HR Admin</span></x-slot:subtitle>
            </x-ui.page>
            BLADE);

        $this->assertStringContainsString('<span data-badge>HR Admin</span>', $html);
    }

    public function test_without_a_title_there_is_no_header_and_empty_actions_are_left_out(): void
    {
        $bare = Blade::render('<x-ui.page>Only content</x-ui.page>');
        $emptyActions = Blade::render('<x-ui.page title="Recap"><x-slot:actions></x-slot:actions></x-ui.page>');

        $this->assertStringNotContainsString('<header', $bare);
        $this->assertStringNotContainsString('<h1', $bare);
        $this->assertStringNotContainsString('data-page-actions', $emptyActions);
    }

    public function test_title_text_is_escaped(): void
    {
        $html = Blade::render('<x-ui.page :title="$title">x</x-ui.page>', ['title' => '<img src=x onerror=alert(1)>']);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function test_extra_attributes_reach_the_container(): void
    {
        $html = Blade::render('<x-ui.page x-data="{ handle: null }" data-extra>x</x-ui.page>');

        $this->assertMatchesRegularExpression('/<div\s+data-page[^>]*x-data="\{ handle: null \}"[^>]*data-extra/', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function authenticatedPages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'my requests' => ['requests.index'],
            'approvals' => ['approvals.index'],
            'history' => ['history.index'],
            'recap' => ['recap.index'],
            'users' => ['users.index'],
            'departments' => ['departments.index'],
            'holidays' => ['holidays.index'],
            'profile' => ['profile.show'],
            'sessions' => ['profile.sessions'],
            'login history' => ['profile.login-history'],
            'security' => ['security.index'],
            'confirm (controller page)' => ['security.confirm'],
        ];
    }

    #[DataProvider('authenticatedPages')]
    public function test_every_page_has_exactly_one_container_and_the_layout_sets_no_width(string $route): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create(['status' => UserStatus::Active]);

        $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/\sdata-page\s/', $html), 'one x-ui.page per page');
        $this->assertSame(1, preg_match('/<main id="main"[^>]*class="([^"]*)"/', $html, $main));
        $this->assertDoesNotMatchRegularExpression('/\b(max-w-\S+|mx-auto|px-\d+)\b/', $main[1], 'the layout leaves width, centring and padding to x-ui.page');
    }
}
