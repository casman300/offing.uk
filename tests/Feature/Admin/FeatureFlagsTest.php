<?php

use App\Features\BirthdayEngine;
use App\Features\Features;
use App\Features\Rollout;
use App\Features\StagedFeature;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['offing.admin_emails' => ['boss@example.com']]);
});

function admin(): User
{
    return User::factory()->create(['email' => 'boss@example.com']);
}

describe('who is an admin', function () {
    it('reads ADMIN_EMAILS case-insensitively', function () {
        expect(User::factory()->make(['email' => 'Boss@Example.com'])->isAdmin())->toBeTrue()
            ->and(User::factory()->make(['email' => 'someone@example.com'])->isAdmin())->toBeFalse();
    });

    it('parses the env list, ignoring blanks and spaces', function () {
        // env() reads $_SERVER/$_ENV before getenv(), so set all three.
        $saved = [$_SERVER['ADMIN_EMAILS'] ?? null, $_ENV['ADMIN_EMAILS'] ?? null, getenv('ADMIN_EMAILS')];
        $_SERVER['ADMIN_EMAILS'] = $_ENV['ADMIN_EMAILS'] = ' A@x.com, ,b@X.com ';
        putenv('ADMIN_EMAILS= A@x.com, ,b@X.com ');

        try {
            $config = require config_path('offing.php');
        } finally {
            [$server, $env, $getenv] = $saved;
            if ($server === null) {
                unset($_SERVER['ADMIN_EMAILS']);
            } else {
                $_SERVER['ADMIN_EMAILS'] = $server;
            }
            if ($env === null) {
                unset($_ENV['ADMIN_EMAILS']);
            } else {
                $_ENV['ADMIN_EMAILS'] = $env;
            }
            putenv($getenv === false ? 'ADMIN_EMAILS' : "ADMIN_EMAILS={$getenv}");
        }

        expect($config['admin_emails'])->toBe(['a@x.com', 'b@x.com']);
    });

    it('treats an empty ADMIN_EMAILS as no admins', function () {
        config(['offing.admin_emails' => []]);

        expect(User::factory()->make(['email' => 'boss@example.com'])->isAdmin())->toBeFalse();
    });
});

describe('admin area access', function () {
    it('404s for guests and non-admins, on every route', function () {
        $user = User::factory()->create();

        $this->get('/admin/features')->assertRedirect('/login');
        $this->actingAs($user)->get('/admin')->assertNotFound();
        $this->actingAs($user)->get('/admin/features')->assertNotFound();
        $this->actingAs($user)->post("/admin/features/birthday-engine/users/{$user->id}", ['value' => 'on'])->assertNotFound();
        $this->actingAs($user)->post('/admin/features/birthday-engine/reset')->assertNotFound();

        expect(DB::table('features')->count())->toBe(0);
    });

    it('shares isAdmin with the frontend', function () {
        $this->actingAs(admin())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', true));

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', false));
    });
});

describe('rollout stages', function () {
    it('resolves each stage', function (Rollout $stage, bool $forAdmin, bool $forUser) {
        $feature = new class extends StagedFeature
        {
            public string $name = 'test-flag';

            public string $description = '';

            public Rollout $stage = Rollout::Off;
        };
        $feature->stage = $stage;

        expect($feature->resolve(User::factory()->make(['email' => 'boss@example.com'])))->toBe($forAdmin)
            ->and($feature->resolve(User::factory()->make(['id' => 1])))->toBe($forUser);
    })->with([
        'off' => [Rollout::Off, false, false],
        'admins' => [Rollout::Admins, true, false],
        'everyone' => [Rollout::Everyone, true, true],
    ]);

    it('gives a stable, roughly proportional slice for a percentage', function () {
        $feature = new class extends StagedFeature
        {
            public string $name = 'test-flag';

            public string $description = '';

            public Rollout $stage = Rollout::Percentage;

            public int $percentage = 30;
        };

        $on = collect(range(1, 1000))->filter(fn ($id) => $feature->resolve(User::factory()->make(['id' => $id])))->count();
        $again = collect(range(1, 1000))->filter(fn ($id) => $feature->resolve(User::factory()->make(['id' => $id])))->count();

        expect($on)->toBeGreaterThan(220)->toBeLessThan(380)
            ->and($again)->toBe($on);

        $feature->percentage = 0;
        expect($feature->resolve(User::factory()->make(['id' => 5])))->toBeFalse()
            ->and($feature->resolve(User::factory()->make(['email' => 'boss@example.com'])))->toBeTrue();
    });

    it('ships birthday-engine to admins only', function () {
        expect(Feature::for(admin())->active(BirthdayEngine::class))->toBeTrue()
            ->and(Feature::for(User::factory()->create())->active(BirthdayEngine::class))->toBeFalse();
    });

    it('registers every feature with a unique stored name', function () {
        $names = array_map(fn ($class) => (new $class)->name, Features::classes());

        expect($names)->toBe(array_unique($names))
            ->and(Features::find('birthday-engine'))->toBeInstanceOf(BirthdayEngine::class)
            ->and(Features::find('nope'))->toBeNull();
    });
});

describe('admin features page', function () {
    it('lists flags with counts and users with their state, without storing anything', function () {
        $boss = admin();
        $on = User::factory()->create(['email' => 'on@example.com']);
        $off = User::factory()->create(['email' => 'off@example.com']);
        User::factory()->create(['email' => 'fresh@example.com']);
        Feature::for($on)->activate(BirthdayEngine::class);
        Feature::for($off)->deactivate(BirthdayEngine::class);
        $before = DB::table('features')->count();

        $this->actingAs($boss)->get('/admin/features')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/features')
                ->where('features.birthdayEngine', true)
                ->where('flags.0.name', 'birthday-engine')
                ->where('flags.0.stage', 'admins')
                ->where('flags.0.on', 1)
                ->where('flags.0.off', 1)
                ->where('flags.0.unchecked', 2)
                ->has('users', 4)
                ->where('users.0.email', 'boss@example.com')
                ->where('users.0.is_admin', true)
                ->where('users.0.flags.birthday-engine', ['stored' => null, 'stage_default' => true])
                ->where('users.2.email', 'off@example.com')
                ->where('users.2.flags.birthday-engine', ['stored' => false, 'stage_default' => false])
                ->where('users.3.email', 'on@example.com')
                ->where('users.3.flags.birthday-engine', ['stored' => true, 'stage_default' => false]));

        // Viewing must not pin anyone's value (the admin's own sidebar check aside).
        expect(DB::table('features')->where('scope', '!=', Feature::serializeScope($boss))->count())->toBe($before);
    });

    it('searches users by email or name', function () {
        $boss = admin();
        User::factory()->create(['email' => 'sue@example.com', 'name' => 'Auntie Sue']);
        User::factory()->create(['email' => 'tom@example.com', 'name' => 'Tom']);

        $this->actingAs($boss)->get('/admin/features?q=auntie')
            ->assertInertia(fn (Assert $page) => $page->where('q', 'auntie')->has('users', 1)->where('users.0.email', 'sue@example.com'));
    });
});

describe('overrides and reset', function () {
    it('switches a flag on, off and back to default for one user, audited', function () {
        $boss = admin();
        $user = User::factory()->create();

        $this->actingAs($boss)->post("/admin/features/birthday-engine/users/{$user->id}", ['value' => 'on'])
            ->assertRedirect()->assertSessionHas('status');
        Feature::flushCache();
        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeTrue();

        $this->actingAs($boss)->post("/admin/features/birthday-engine/users/{$user->id}", ['value' => 'off']);
        Feature::flushCache();
        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse();

        $this->actingAs($boss)->post("/admin/features/birthday-engine/users/{$user->id}", ['value' => 'on']);
        $this->actingAs($boss)->post("/admin/features/birthday-engine/users/{$user->id}", ['value' => 'default']);
        expect(DB::table('features')->where('scope', Feature::serializeScope($user))->exists())->toBeFalse();

        $actions = AuditLog::where('subject_id', $user->id)->orderBy('id')->pluck('action')->all();
        expect($actions)->toBe(['feature.activated', 'feature.deactivated', 'feature.activated', 'feature.reset']);

        $log = AuditLog::where('action', 'feature.reset')->sole();
        expect($log->actor_id)->toBe($boss->id)
            ->and($log->meta)->toBe(['feature' => 'birthday-engine', 'via' => 'admin']);
    });

    it('validates the value and the feature name', function () {
        $boss = admin();
        $user = User::factory()->create();

        $this->actingAs($boss)->post("/admin/features/birthday-engine/users/{$user->id}", ['value' => 'maybe'])
            ->assertSessionHasErrors('value');
        $this->actingAs($boss)->post("/admin/features/nope/users/{$user->id}", ['value' => 'on'])->assertNotFound();
        $this->actingAs($boss)->post('/admin/features/birthday-engine/users/999999', ['value' => 'on'])->assertNotFound();
    });

    it('resets a flag for everyone so the stage applies again, audited', function () {
        $boss = admin();
        $user = User::factory()->create();
        Feature::for($user)->activate(BirthdayEngine::class);

        $this->actingAs($boss)->post('/admin/features/birthday-engine/reset')->assertRedirect();

        Feature::flushCache();
        expect(DB::table('features')->where('name', 'birthday-engine')->where('scope', Feature::serializeScope($user))->exists())->toBeFalse()
            ->and(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse()
            ->and(AuditLog::where('action', 'feature.purged')->where('actor_id', $boss->id)->exists())->toBeTrue();
    });
});
