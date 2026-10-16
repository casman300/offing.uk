<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SentryScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    public function test_an_exception_reaches_sentry_with_no_login_token_or_personal_data(): void
    {
        $this->fakeSentry();
        Route::get('_test/login/{magicLink}/{token}', fn () => throw new RuntimeException('boom'));

        // Generated, so they can't turn up via Sentry's source-code context
        // (the lines around each stack frame — here, this test file).
        $token = Str::random(40);
        $signature = Str::random(40);
        $email = Str::lower(Str::random(12)).'@example.com';
        $cookie = Str::random(32);
        $ip = '203.0.113.'.random_int(10, 250);
        $url = "_test/login/42/{$token}?signature={$signature}&expires=999";

        $this->actingAs(User::factory()->create(['email' => $email]))
            ->withHeader('Referer', "https://staging.offing.uk/{$url}")
            ->withUnencryptedCookie('offing_session', $cookie)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get($url)
            ->assertServerError();

        $this->assertCount(1, $this->sent);
        // Exactly what would go over the wire (JSON-escaped slashes undone).
        $payload = str_replace('\/', '/', (new PayloadSerializer(new Options(['dsn' => self::DSN])))->serialize($this->sent[0]));

        $this->assertStringContainsString('boom', $payload);
        $this->assertStringContainsString('/login/42/[Filtered]', $payload);
        foreach (['login token' => $token, 'signature' => $signature, 'email' => $email, 'cookie' => $cookie, 'IP' => $ip] as $what => $secret) {
            $this->assertStringNotContainsString($secret, $payload, "Sentry payload leaked the {$what}");
        }
    }

    public function test_login_tokens_are_scrubbed_from_strings_and_nested_arrays(): void
    {
        $this->assertSame(
            'https://offing.uk/login/7/[Filtered]?expires=1&signature=[Filtered]',
            SentryScrubber::scrub('https://offing.uk/login/7/abc.DEF_123?expires=1&signature=f00')
        );
        $this->assertSame(['a' => ['b' => 'token=[Filtered]']], SentryScrubber::scrub(['a' => ['b' => 'token=xyz']]));
        $this->assertSame('/login', SentryScrubber::scrub('/login'));
        $this->assertSame(42, SentryScrubber::scrub(42));
    }

    public function test_breadcrumbs_are_scrubbed(): void
    {
        $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'GET /login/1/secret', [
            'url' => 'https://offing.uk/login/1/secret?signature=s',
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
}
