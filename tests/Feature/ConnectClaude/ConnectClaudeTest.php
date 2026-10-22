<?php

use App\Features\ConnectClaude;
use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ConnectClaude\AccessLevel;
use App\Support\ConnectClaude\Connections;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;

const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

beforeEach(function () {
    // Real RSA keys so the token endpoint can sign; never written to disk.
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    config([
        'passport.private_key' => $private,
        'passport.public_key' => openssl_pkey_get_details($key)['key'],
    ]);
});

function connectingUser(array $attributes = []): User
{
    $user = User::factory()->create(['name' => 'Charlie', 'timezone' => 'Europe/London'] + $attributes);
    Feature::for($user)->activate(ConnectClaude::class);

    return $user;
}

function registerClaude(): Client
{
    $response = test()->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [CLAUDE_CALLBACK],
    ])->assertCreated();

    return Passport::client()->newQuery()->findOrFail($response->json('client_id'));
}

/** @return array{0: string, 1: string} [verifier, challenge] */
function pkce(): array
{
    $verifier = Str::random(64);

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

function startAuthorize(Client $client, string $challenge): TestResponse
{
    return test()->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->id,
        'redirect_uri' => CLAUDE_CALLBACK,
        'scope' => 'mcp:use',
        'state' => 'xyz',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));
}

function authTokenFrom(TestResponse $response): string
{
    return $response->viewData('page')['props']['authToken'];
}

/** Run the whole browser + token-endpoint dance; returns the access token. */
function connect(User $user, string $level = 'read', bool $private = false): string
{
    $client = registerClaude();
    [$verifier, $challenge] = pkce();

    test()->actingAs($user);
    $authToken = authTokenFrom(startAuthorize($client, $challenge)->assertOk());

    $redirect = test()->post('/connect-claude/approve', [
        'auth_token' => $authToken,
        'level' => $level,
        'include_private' => $private ? '1' : '0',
    ])->assertRedirect()->headers->get('Location');

    expect($redirect)->toStartWith(CLAUDE_CALLBACK);
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('xyz');

    $token = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'redirect_uri' => CLAUDE_CALLBACK,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json('access_token');

    return $token;
}

function mcp(string $token, string $method, array $params = []): TestResponse
{
    // A fresh guard per call, as in a real request: the test app otherwise
    // remembers the last bearer token's user.
    app('auth')->forgetGuards();

    return test()->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

describe('discovery and registration', function () {
    it('publishes OAuth metadata for MCP clients', function () {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    });

    it('sends unauthenticated MCP calls to the metadata', function () {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
    });

    it('registers Claude but not unknown redirect hosts', function () {
        registerClaude();

        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/callback']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
    });

    it('allows Claude Code on localhost', function () {
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:33418/callback']])->assertCreated();
    });
});

describe('consent', function () {
    it('asks for an access level and private events, defaulting to the safest', function () {
        $user = connectingUser();
        [, $challenge] = pkce();

        $this->actingAs($user);
        startAuthorize(registerClaude(), $challenge)->assertInertia(fn (Assert $page) => $page
            ->component('connect-claude/consent')
            ->where('client.name', 'Claude')
            ->where('client.host', 'claude.ai')
            ->where('level', 'read')
            ->where('includePrivate', false)
            ->has('levels', 3));
    });

    it('records the grant, audits it and hands Claude a working token', function () {
        $user = connectingUser();

        $token = connect($user, 'write-new', true);

        $grant = AiGrant::sole();
        expect($grant->user_id)->toBe($user->id)
            ->and($grant->level)->toBe(AccessLevel::WriteNew)
            ->and($grant->include_private)->toBeTrue();
        expect(AuditLog::where('action', 'connect_claude.approved')->sole()->meta)
            ->toMatchArray(['client' => 'Claude', 'level' => 'write-new', 'include_private' => true]);

        mcp($token, 'tools/call', ['name' => 'get-briefing', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertSee('Offing briefing for Charlie')
            ->assertSee('Read and add new');

        expect(AuditLog::where('action', 'connect_claude.tool')->sole()->meta)->toBe(['tool' => 'get-briefing']);
        expect($grant->fresh()->last_used_at)->not->toBeNull();
    });

    it('lets the user cancel', function () {
        $user = connectingUser();
        [, $challenge] = pkce();

        $this->actingAs($user);
        $authToken = authTokenFrom(startAuthorize(registerClaude(), $challenge));

        $location = $this->post('/connect-claude/deny', ['auth_token' => $authToken])->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith(CLAUDE_CALLBACK)->toContain('error=access_denied');
        expect(AiGrant::count())->toBe(0);
        expect(AuditLog::where('action', 'connect_claude.denied')->exists())->toBeTrue();
    });

    it('refuses a stale or forged request without recording anything', function () {
        $user = connectingUser();
        [, $challenge] = pkce();

        $this->actingAs($user);
        startAuthorize(registerClaude(), $challenge);

        $this->post('/connect-claude/approve', ['auth_token' => 'not-the-token', 'level' => 'edit'])->assertStatus(419);
        expect(AiGrant::count())->toBe(0);
    });

    it('is not available without the flag', function () {
        $user = User::factory()->create();
        [, $challenge] = pkce();

        $this->actingAs($user);
        $page = startAuthorize(registerClaude(), $challenge)->assertInertia(fn (Assert $page) => $page->component('connect-claude/unavailable'));

        expect($page->viewData('page')['props'])->not->toHaveKey('authToken');
    });

    it('sends guests to sign in first', function () {
        [, $challenge] = pkce();

        startAuthorize(registerClaude(), $challenge)->assertRedirect('/login');
    });
});

describe('the MCP endpoint', function () {
    it('stops working the moment the connection is revoked', function () {
        $user = connectingUser();
        $token = connect($user);
        mcp($token, 'tools/list')->assertOk();

        $this->actingAs($user)->delete('/settings/connect-claude/'.AiGrant::sole()->id)->assertRedirect('/settings/connect-claude');

        mcp($token, 'tools/list')->assertUnauthorized();
        expect(AuditLog::where('action', 'connect_claude.revoked')->exists())->toBeTrue();
    });

    it('stops working if the flag is switched off', function () {
        $user = connectingUser();
        $token = connect($user);

        Feature::for($user)->deactivate(ConnectClaude::class);

        mcp($token, 'tools/list')->assertForbidden();
    });

    it('lists the briefing tool as read-only', function () {
        $token = connect(connectingUser());

        mcp($token, 'tools/list')->assertOk()
            ->assertJsonPath('result.tools.0.name', 'get-briefing')
            ->assertJsonPath('result.tools.0.title', 'Get briefing')
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true);
    });
});

describe('settings', function () {
    it('lists only your own connections', function () {
        $user = connectingUser();
        connect($user);
        connect(connectingUser(['email' => 'someone@example.com']));

        $this->actingAs($user)->get('/settings/connect-claude')->assertInertia(fn (Assert $page) => $page
            ->component('settings/connect-claude')
            ->where('mcpUrl', url('/mcp'))
            ->has('connections', 1)
            ->where('connections.0.name', 'Claude')
            ->where('connections.0.level', 'read'));
    });

    it('changes access straight away, audited', function () {
        $user = connectingUser();
        $token = connect($user);
        $grant = AiGrant::sole();

        $this->actingAs($user)->patch("/settings/connect-claude/{$grant->id}", ['level' => 'edit', 'include_private' => true])->assertRedirect();

        expect($grant->fresh()->level)->toBe(AccessLevel::Edit);
        expect(AuditLog::where('action', 'connect_claude.updated')->sole()->meta)
            ->toMatchArray(['level' => 'edit', 'include_private' => true, 'before' => ['level' => 'read', 'include_private' => false]]);

        mcp($token, 'tools/call', ['name' => 'get-briefing', 'arguments' => []])->assertSee('Read, add and change')->assertSee('Private events: included.');
    });

    it("can't touch someone else's connection", function () {
        connect(connectingUser(['email' => 'someone@example.com']));
        $grant = AiGrant::sole();

        $this->actingAs(connectingUser())->patch("/settings/connect-claude/{$grant->id}", ['level' => 'edit', 'include_private' => true])->assertNotFound();
        $this->delete("/settings/connect-claude/{$grant->id}")->assertNotFound();
    });

    it('is behind the flag', function () {
        $this->actingAs(User::factory()->create())->get('/settings/connect-claude')->assertNotFound();
    });
});

describe('housekeeping', function () {
    it('prunes clients that registered but were never approved', function () {
        $abandoned = registerClaude();
        $fresh = registerClaude();
        connect(connectingUser());
        $used = AiGrant::sole()->client;

        Passport::client()->newQuery()->whereKey([$abandoned->id, $used->id])->update(['created_at' => now()->subDays(2)]);

        expect(Connections::pruneAbandonedClients())->toBe(1);
        expect(Passport::client()->newQuery()->pluck('id')->all())->toEqualCanonicalizing([$fresh->id, $used->id]);
    });

    it('forgets tokens when an account is deleted', function () {
        $user = connectingUser();
        connect($user);

        $user->delete();

        expect(Passport::token()->newQuery()->count())->toBe(0)
            ->and(AiGrant::count())->toBe(0);
    });
});
