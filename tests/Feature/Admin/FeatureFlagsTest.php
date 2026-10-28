<?php

use App\Features\BirthdayEngine;
use App\Features\Features;
use App\Features\Rollout;
use App\Features\StagedFeature;
use App\Features\StageOverrides;
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
        $this->actingAs($user)->post('/admin/features/birthday-engine/stage', ['stage' => 'everyone'])->assertNotFound();

        expect(DB::table('features')->count())->toBe(0)
            ->and(DB::table('feature_stages')->count())->toBe(0);
    });

    it('shares isAdmin with the frontend', function () {
        $this->actingAs(admin())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', true));

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', false));
    });
});

describe('rollout stages', function () {
    it('resolves each stage', function (Rollout $stage, bool $forAdmin, bool $forBeta, bool $forUser) {
        $feature = new class extends StagedFeature
        {
            public string $name = 'test-flag';

            public string $description = '';

            public Rollout $stage = Rollout::Off;
        };
        $feature->stage = $stage;

        expect($feature->resolve(User::factory()->make(['email' => 'boss@example.com'])))->toBe($forAdmin)
            ->and($feature->resolve(User::factory()->make(['id' => 1, 'is_beta_tester' => true])))->toBe($forBeta)
            ->and($feature->resolve(User::factory()->make(['id' => 1])))->toBe($forUser);
    })->with([
        'off' => [Rollout::Off, false, false, false],
        'admins' => [Rollout::Admins, true, false, false],
        'beta' => [Rollout::Beta, true, true, false],
        'everyone' => [Rollout::Everyone, true, true, true],
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
            ->and($feature->resolve(User::factory()->make(['id' => 5, 'is_beta_tester' => true])))->toBeTrue()
            ->and($feature->resolve(User::factory()->make(['email' => 'boss@example.com'])))->toBeTrue();
    });

    it('stores overrides only, never a resolved default', function () {
        $user = User::factory()->create();

        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse()
            ->and(Feature::for([$user, admin()])->allAreActive([BirthdayEngine::class]))->toBeFalse()
            ->and(DB::table('features')->count())->toBe(0);

        Feature::for($user)->activate(BirthdayEngine::class);

        expect(DB::table('features')->where('scope', Feature::serializeScope($user))->value('value'))->toBe('true');
    });

    it('follows a stage chosen in the admin over the declared one', function () {
        $user = User::factory()->create();
        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse();

        app(StageOverrides::class)->set('birthday-engine', Rollout::Everyone, 0);
        Feature::flushCache();

        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeTrue()
            ->and((new BirthdayEngine)->stageOverridden())->toBeTrue();

        app(StageOverrides::class)->forget('birthday-engine');
        Feature::flushCache();

        expect(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse();
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
        User::factory()->create(['email' => 'beta@example.com', 'is_beta_tester' => true]);
        Feature::for($on)->activate(BirthdayEngine::class);
        Feature::for($off)->deactivate(BirthdayEngine::class);

        $this->actingAs($boss)->get('/admin/features')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/features')
                ->where('features.birthdayEngine', true)
                ->where('userCount', 4)
                ->has('stages', count(Rollout::cases()))
                ->where('flags.0.name', 'birthday-engine')
                ->where('flags.0.stage', 'admins')
                ->where('flags.0.stage_overridden', false)
                ->where('flags.0.overrides_on', 1)
                ->where('flags.0.overrides_off', 1)
                ->where('flags.0.enabled', 2)
                ->has('users', 4)
                ->where('users.0.email', 'beta@example.com')
                ->where('users.0.is_beta_tester', true)
                ->where('users.1.email', 'boss@example.com')
                ->where('users.1.is_admin', true)
                ->where('users.1.flags.birthday-engine', ['stored' => null, 'stage_default' => true])
                ->where('users.2.email', 'off@example.com')
                ->where('users.2.flags.birthday-engine', ['stored' => false, 'stage_default' => false])
                ->where('users.3.email', 'on@example.com')
                ->where('users.3.flags.birthday-engine', ['stored' => true, 'stage_default' => false]));

        expect(DB::table('features')->count())->toBe(2);
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

    it('clears a flag\'s overrides so the stage applies again, audited', function () {
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

describe('changing a stage', function () {
    it('moves a flag to a new stage for everyone, straight away, audited', function () {
        $boss = admin();
        $user = User::factory()->create();
        $beta = User::factory()->create(['is_beta_tester' => true]);
        expect(Feature::for($beta)->active(BirthdayEngine::class))->toBeFalse();

        $this->actingAs($boss)->post('/admin/features/birthday-engine/stage', ['stage' => 'beta'])
            ->assertRedirect()->assertSessionHas('status', 'birthday-engine is now Beta testers.');

        Feature::flushCache();
        app()->forgetScopedInstances();
        expect(Feature::for($beta)->active(BirthdayEngine::class))->toBeTrue()
            ->and(Feature::for($user)->active(BirthdayEngine::class))->toBeFalse();

        $log = AuditLog::where('action', 'feature.stage_changed')->sole();
        expect($log->actor_id)->toBe($boss->id)
            ->and($log->meta)->toBe([
                'feature' => 'birthday-engine',
                'from' => ['stage' => 'admins', 'percentage' => 0],
                'to' => ['stage' => 'beta', 'percentage' => 0],
                'via' => 'admin',
            ]);
    });

    it('takes a percentage, and goes back to the code default', function () {
        $boss = admin();

        $this->actingAs($boss)->post('/admin/features/birthday-engine/stage', ['stage' => 'percentage', 'percentage' => 40])
            ->assertSessionHas('status', 'birthday-engine is now Percentage (40%).');
        expect(DB::table('feature_stages')->where('name', 'birthday-engine')->first())
            ->stage->toBe('percentage')
            ->percentage->toBe(40);

        $this->actingAs($boss)->post('/admin/features/birthday-engine/stage', ['stage' => 'default']);
        expect(DB::table('feature_stages')->count())->toBe(0)
            ->and(AuditLog::where('action', 'feature.stage_changed')->count())->toBe(2);
    });

    it('validates the stage, the percentage and the feature', function () {
        $boss = admin();

        $this->actingAs($boss)->post('/admin/features/birthday-engine/stage', ['stage' => 'friends'])->assertSessionHasErrors('stage');
        $this->actingAs($boss)->post('/admin/features/birthday-engine/stage', ['stage' => 'percentage'])->assertSessionHasErrors('percentage');
        $this->actingAs($boss)->post('/admin/features/birthday-engine/stage', ['stage' => 'percentage', 'percentage' => 101])->assertSessionHasErrors('percentage');
        $this->actingAs($boss)->post('/admin/features/nope/stage', ['stage' => 'everyone'])->assertNotFound();

        expect(DB::table('feature_stages')->count())->toBe(0);
    });

    it('ignores a stored stage this code no longer knows', function () {
        DB::table('feature_stages')->insert(['name' => 'birthday-engine', 'stage' => 'retired', 'percentage' => 0]);

        expect((new BirthdayEngine)->currentStage())->toBe(Rollout::Admins);
    });
});

describe('cleaning up pinned defaults', function () {
    it('drops stored values that only repeat the stage, keeping real overrides', function () {
        $boss = admin();
        $pinned = User::factory()->create();
        $override = User::factory()->create();
        DB::table('features')->insert([
            ['name' => 'birthday-engine', 'scope' => Feature::serializeScope($boss), 'value' => 'true'],
            ['name' => 'birthday-engine', 'scope' => Feature::serializeScope($pinned), 'value' => 'false'],
            ['name' => 'birthday-engine', 'scope' => Feature::serializeScope($override), 'value' => 'true'],
        ]);

        (require database_path('migrations/2026_10_24_000002_drop_pinned_feature_defaults.php'))->up();

        expect(DB::table('features')->pluck('scope')->all())->toBe([Feature::serializeScope($override)]);
    });
});
