<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Support\SentryScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class SentryTest extends TestCase
{
    use RefreshDatabase;

    private const DSN = 'https://public@o0.ingest.sentry.io/1';

    /** @var list<Event> */
    private array $sent = [];

    // Boot with a DSN, as staging/production do: the SDK only hooks into
    // requests (URL, headers, IP) when one is set at boot.
    protected function setUp(): void
    {
        self::setDsnEnv(self::DSN);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::setDsnEnv(null);
    }

    private static function setDsnEnv(?string $dsn): void
    {
        if ($dsn === null) {
            putenv('SENTRY_LARAVEL_DSN');
            unset($_ENV['SENTRY_LARAVEL_DSN'], $_SERVER['SENTRY_LARAVEL_DSN']);
        } else {
            putenv("SENTRY_LARAVEL_DSN={$dsn}");
            $_ENV['SENTRY_LARAVEL_DSN'] = $_SERVER['SENTRY_LARAVEL_DSN'] = $dsn;
        }
    }

    /**
     * Swap the real client for one built from config/sentry.php (so
     * before_send etc. are the real ones) but with a transport that keeps
     * events here instead of sending them.
     */
    private function fakeSentry(): void
    {
        $transport = new class($this->sent) implements TransportInterface
        {
            public function __construct(private array &$sent) {}

            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        SentrySdk::getCurrentHub()->bindClient(
            app(ClientBuilder::class)->setTransport($transport)->getClient()
        );
    }

    public function test_an_exception_reaches_sentry_with_no_secrets_or_personal_data(): void
    {
        $this->fakeSentry();
        Route::get('_test/boom/{path}', fn () => throw new RuntimeException('boom'))->where('path', '.*');

        // Every kind of secret-bearing URL Offing mints, built by the real
        // routes. Tokens are generated so they can't turn up via Sentry's
        // source-code context (the lines around each stack frame — here,
        // this file). Add new token-bearing routes here.
        $urls = [
            route('login.consume', ['magicLink' => 7, 'token' => Str::random(64)]),
            URL::temporarySignedRoute('email-change.confirm', now()->addHour(), ['magicLink' => 7, 'token' => Str::random(64)]),
            URL::temporarySignedRoute('email-change.revert', now()->addHour(), ['magicLink' => 7, 'token' => Str::random(64)]),
            URL::temporarySignedRoute('crew.join', now()->addWeek(), ['invite' => 7, 'token' => Str::random(64)]),
            route('feed.show', ['token' => Str::random(40)]),
            URL::signedRoute('reminders.unsubscribe', ['user' => 7]),
            URL::signedRoute('barometer.check-ins.show', ['user' => 7]),
            url('oauth/callback').'?code='.Str::random(40).'&state='.Str::random(40),
        ];
        $email = Str::lower(Str::random(12)).'@example.com';
        $cookie = Str::random(32);
        $bearer = Str::random(80);
        $ip = '203.0.113.'.random_int(10, 250);
        $user = User::factory()->create(['email' => $email]);

        foreach ($urls as $url) {
            $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $query = parse_url($url, PHP_URL_QUERY);
            $this->sent = [];

            $this->actingAs($user)
                ->withHeader('Referer', $url)
                ->withHeader('Authorization', "Bearer {$bearer}")
                ->withUnencryptedCookie('offing_session', $cookie)
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->get("_test/boom/{$path}".($query ? "?{$query}" : ''))
                ->assertServerError();

            $this->assertCount(1, $this->sent, $url);
            // Exactly what would go over the wire (JSON-escaped slashes undone).
            $payload = str_replace('\/', '/', (new PayloadSerializer(new Options(['dsn' => self::DSN])))->serialize($this->sent[0]));
            $this->assertStringContainsString('boom', $payload);
            $this->assertStringContainsString(SentryScrubber::FILTERED, $payload, $url);

            $secrets = array_filter([...self::secretsIn($url), 'email' => $email, 'cookie' => $cookie, 'bearer token' => $bearer, 'IP' => $ip]);
            foreach ($secrets as $what => $secret) {
                $this->assertStringNotContainsString($secret, $payload, "Sentry payload leaked the {$what} from {$url}");
            }
        }
    }

    /**
     * The token-looking path segments and secret query values in a URL.
     *
     * @return array<string, string>
     */
    private static function secretsIn(string $url): array
    {
        $secrets = [];

        foreach (explode('/', (string) parse_url($url, PHP_URL_PATH)) as $segment) {
            $segment = Str::beforeLast($segment, '.ics');
            if (strlen($segment) >= 32) {
                $secrets['path token'] = $segment;
            }
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['signature', 'code', 'state'] as $name) {
            if (isset($query[$name]) && is_string($query[$name])) {
                $secrets[$name] = $query[$name];
            }
        }

        return $secrets;
    }

    public function test_secrets_are_scrubbed_from_strings_and_nested_arrays(): void
    {
        $token = Str::random(64);

        $this->assertSame(
            'https://offing.uk/login/7/[Filtered]?expires=1&signature=[Filtered]',
            SentryScrubber::scrub("https://offing.uk/login/7/{$token}?expires=1&signature=f00")
        );
        $this->assertSame('/feed/[Filtered].ics', SentryScrubber::scrub('/feed/'.Str::random(40).'.ics'));
        $this->assertSame('/cb?code=[Filtered]&state=[Filtered]', SentryScrubber::scrub('/cb?code=abc&state=def'));
        $this->assertSame(['a' => ['b' => 'token=[Filtered]']], SentryScrubber::scrub(['a' => ['b' => 'token=xyz']]));
        // Ordinary paths, short ids and asset hashes are left alone.
        foreach (['/login', '/crew/join/12/confirm', '/settings/feeds/3', '/build/assets/app-BrPGVzy3.js'] as $path) {
            $this->assertSame($path, SentryScrubber::scrub($path));
        }
        $this->assertSame(42, SentryScrubber::scrub(42));
    }

    public function test_breadcrumbs_are_scrubbed(): void
    {
        $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'GET /login/1/'.str_repeat('a', 64), [
            'url' => 'https://offing.uk/login/1/'.str_repeat('a', 64).'?signature=s',
        ]);

        $scrubbed = SentryScrubber::beforeBreadcrumb($breadcrumb);

        $this->assertSame('GET /login/1/[Filtered]', $scrubbed->getMessage());
        $this->assertSame('https://offing.uk/login/1/[Filtered]?signature=[Filtered]', $scrubbed->getMetadata()['url']);
    }

    public function test_the_browser_gets_the_dsn_from_the_server(): void
    {
        config(['sentry.environment' => null, 'app.env' => 'staging']);

        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('sentry.dsn', self::DSN)
            ->where('sentry.environment', 'staging'));
    }

    public function test_the_csp_lets_the_browser_reach_sentrys_ingest_host_only_with_a_dsn(): void
    {
        config(['sentry.dsn' => 'https://public@o1.ingest.de.sentry.io/2']);
        $this->assertStringContainsString("connect-src 'self' https://o1.ingest.de.sentry.io;", SecurityHeaders::contentSecurityPolicy());

        config(['sentry.dsn' => null]);
        $this->assertStringContainsString("connect-src 'self';", SecurityHeaders::contentSecurityPolicy());
    }
}
