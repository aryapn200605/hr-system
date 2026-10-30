<?php

namespace Tests\Feature;

use App\Actions\CreateUserWithInvitation;
use App\Actions\ResendInvitation;
use App\Enums\RoleName;
use App\Http\Middleware\StartSession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\LogsIn;
use Tests\TestCase;

/**
 * Runs the real mailers against a real log file, with the database queue on, so a
 * queued email would land in the jobs table and an unwanted log line in the file.
 */
class TokenLeakTest extends TestCase
{
    use LogsIn, RefreshDatabase;

    /**
     * Every place outside the email itself where a token must never appear.
     */
    private const TABLES = [
        'jobs',
        'failed_jobs',
        'job_batches',
        'audit_logs',
        'login_histories',
        'account_invitations',
        'password_reset_tokens',
        'cache',
        'sessions',
    ];

    private string $logFile;

    /** @var list<string> */
    private array $links = [];

    private string $logged = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->logFile = storage_path('logs/token-leak-test.log');
        File::delete($this->logFile);

        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'logging.default' => 'token-leak',
            'logging.channels.token-leak' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);
        Log::forgetChannel('token-leak');

        Event::listen(MessageSending::class, function (MessageSending $event) {
            $this->links[] = $event->data['url'];
        });
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged .= $event->message.json_encode($event->context);
        });
    }

    protected function tearDown(): void
    {
        File::delete($this->logFile);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $name = 'rina'): array
    {
        return [
            'name' => 'Rina Wijaya',
            'username' => $name,
            'email' => "$name@example.com",
            'role' => RoleName::Employee->value,
        ];
    }

    private function admin(): User
    {
        return User::factory()->twoFactorEnrolled()->withRole(RoleName::HrAdmin)->create();
    }

    private function tokenFrom(string $link): string
    {
        return Str::afterLast($link, '/');
    }

    private function assertNowhere(string $token): void
    {
        foreach (self::TABLES as $table) {
            $this->assertStringNotContainsString($token, DB::table($table)->get()->toJson(), $table);
        }

        $this->assertStringNotContainsString($token, $this->logged);
        $this->assertStringNotContainsString($token, File::exists($this->logFile) ? File::get($this->logFile) : '');
    }

    public function test_invitation_tokens_reach_no_table_and_no_log(): void
    {
        $admin = $this->admin();

        $user = app(CreateUserWithInvitation::class)->handle($this->input(), $admin)->user;
        app(ResendInvitation::class)->handle($user, $admin);

        $this->assertCount(2, $this->links);
        $this->assertSame(0, DB::table('jobs')->count());

        foreach ($this->links as $link) {
            $this->assertNowhere($this->tokenFrom($link));
        }
    }

    public function test_the_reset_token_reaches_no_table_and_no_log(): void
    {
        $this->withoutDefer();
        $this->enrolledUser(['email' => 'rina@example.com']);

        $this->post(route('password.email'), ['email' => 'rina@example.com']);

        $this->assertCount(1, $this->links);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNowhere($this->tokenFrom($this->links[0]));
    }

    public function test_opening_a_link_keeps_the_token_out_of_the_session(): void
    {
        $this->withoutDefer();
        app(CreateUserWithInvitation::class)->handle($this->input(), $this->admin());
        $this->enrolledUser(['email' => 'budi@example.com']);
        $this->post(route('password.email'), ['email' => 'budi@example.com']);

        // No request follows, as with a mail scanner that fetches the link and stops.
        $this->get(route('activation.start', $this->tokenFrom($this->links[0])));
        $this->assertStringNotContainsString($this->tokenFrom($this->links[0]), json_encode(session()->all()));

        $this->get(route('password.reset.start', $this->tokenFrom($this->links[1])));
        $this->assertStringNotContainsString($this->tokenFrom($this->links[1]), json_encode(session()->all()));
    }

    public function test_only_routes_that_name_their_parameter_token_carry_a_token(): void
    {
        $withToken = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route) => in_array('token', $route->parameterNames(), true))
            ->map(fn (IlluminateRoute $route) => $route->getName())
            ->values()
            ->sort()
            ->all();

        $this->assertSame(['activation.start', 'password.reset.start'], array_values($withToken));
        $this->assertContains(StartSession::class, Route::getMiddlewareGroups()['web']);
    }

    public function test_a_failed_transport_logs_no_token_and_no_message_body(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        $result = app(CreateUserWithInvitation::class)->handle($this->input(), $this->admin());

        $this->assertFalse($result->sent);
        $this->assertCount(1, $this->links);
        $this->assertStringContainsString('Mail delivery failed.', $this->logged);
        $this->assertStringNotContainsString('rina@example.com', $this->logged);
        $this->assertNowhere($this->tokenFrom($this->links[0]));
    }

    public function test_only_the_log_mailer_writes_a_token_to_the_log_and_only_as_the_email(): void
    {
        config(['mail.default' => 'log']);

        app(CreateUserWithInvitation::class)->handle($this->input(), $this->admin());

        $token = $this->tokenFrom($this->links[0]);
        $file = File::get($this->logFile);

        // This proves the checks above can see a token, and it is why this mailer is local only.
        $this->assertStringContainsString($token, str_replace("=\r\n", '', $file));
        $this->assertStringContainsString('Subject:', $file);
        $this->assertSame(1, preg_match_all('/^\[\d{4}-\d{2}-\d{2}.*$/m', $file));

        foreach (self::TABLES as $table) {
            $this->assertStringNotContainsString($token, DB::table($table)->get()->toJson(), $table);
        }
    }
}
