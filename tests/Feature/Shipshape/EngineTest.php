<?php

use App\Models\Chore;
use App\Models\ChoreEntry;
use App\Models\ChoreList;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Models\User;
use App\Support\Shipshape\ChoreSchedule;
use App\Support\Shipshape\Logbook;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Tuesday 20 October 2026, lunchtime in London.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/London'));
    $this->me = User::factory()->create(['timezone' => 'Europe/London']);
    $this->list = ChoreList::make(['name' => 'Mine']);
    $this->list->owner()->associate($this->me)->save();
});

function chore(array $attributes): Chore
{
    $chore = test()->list->chores()->make(array_merge(['title' => 'Test', 'every' => 1, 'unit' => 'week', 'mode' => Chore::FLOATING], $attributes));
    $chore->save();

    return $chore->fresh();
}

function log_(Chore $chore, string $kind = 'done', ?string $on = null, ?CrewMember $by = null): ChoreEntry
{
    return Logbook::record($chore, test()->me, $kind, CarbonImmutable::parse($on ?? '2026-10-20'), $by, $chore->fresh()->version);
}

describe('floating chores', function () {
    it('count from the day they were done — including a backdated day', function () {
        $oven = chore(['every' => 3, 'unit' => 'month', 'due_on' => '2026-10-01']);

        log_($oven, on: '2026-10-17');

        expect($oven->fresh()->due_on->toDateString())->toBe('2027-01-17');
    });

    it('never overflow a short month', function () {
        expect(ChoreSchedule::addInterval(CarbonImmutable::parse('2027-01-31'), 1, 'month')->toDateString())->toBe('2027-02-28');
    });

    it('skip from when they were due, or today if that is later', function () {
        $late = chore(['due_on' => '2026-10-10']);
        $early = chore(['due_on' => '2026-10-25']);

        log_($late, 'skip');
        log_($early, 'skip');

        expect($late->fresh()->due_on->toDateString())->toBe('2026-10-27')
            ->and($early->fresh()->due_on->toDateString())->toBe('2026-11-01');
    });
});

describe('fixed chores', function () {
    it('stay on their pattern however late or early you are, and never pile up', function () {
        // Bins every Tuesday, anchored on a Tuesday.
        $bins = fn (string $due) => chore(['mode' => Chore::FIXED, 'rrule' => ChoreSchedule::rrule(1, 'week'), 'anchor_on' => '2026-09-01', 'due_on' => $due]);

        $early = $bins('2026-10-27');   // done a week early
        $onTime = $bins('2026-10-20');  // done on the day
        $missed = $bins('2026-09-29');  // three Tuesdays missed

        log_($early);
        log_($onTime);
        log_($missed);

        expect($early->fresh()->due_on->toDateString())->toBe('2026-11-03')
            ->and($onTime->fresh()->due_on->toDateString())->toBe('2026-10-27')
            ->and($missed->fresh()->due_on->toDateString())->toBe('2026-10-27');
    });

    it('lands yearly renewals on the same date next year', function () {
        $tax = chore(['mode' => Chore::FIXED, 'unit' => 'year', 'rrule' => ChoreSchedule::rrule(1, 'year'), 'anchor_on' => '2026-03-01', 'due_on' => '2027-03-01']);

        log_($tax);

        expect($tax->fresh()->due_on->toDateString())->toBe('2028-03-01');
    });

    it('follows RFC 5545 for a monthly 31st — months without one are skipped', function () {
        $chore = chore(['mode' => Chore::FIXED, 'unit' => 'month', 'rrule' => ChoreSchedule::rrule(1, 'month'), 'anchor_on' => '2027-01-31']);

        expect(ChoreSchedule::nextFixedAfter($chore, CarbonImmutable::parse('2027-01-31'))->toDateString())->toBe('2027-03-31');
    });

    it("can't be logged while it needs a date", function () {
        $mot = chore(['mode' => Chore::FIXED, 'unit' => 'year', 'rrule' => ChoreSchedule::rrule(1, 'year')]);

        expect(fn () => log_($mot))->toThrow(ValidationException::class);
        expect($mot->entries()->count())->toBe(0);
    });
});

describe('logging rules', function () {
    it('refuses a stale tick, naming who got there first', function () {
        $chore = chore(['due_on' => '2026-10-20']);
        $version = $chore->version;
        log_($chore);

        expect(fn () => Logbook::record($chore, $this->me, 'done', CarbonImmutable::parse('2026-10-20'), null, $version))
            ->toThrow(ValidationException::class, 'just updated');
        expect($chore->entries()->count())->toBe(1);
    });

    it('refuses a date in the future (in the logger’s timezone)', function () {
        $chore = chore(['due_on' => '2026-10-20']);

        expect(fn () => log_($chore, on: '2026-10-21'))->toThrow(ValidationException::class);
    });

    it("uses the logger's own today, even when it's already tomorrow in UTC", function () {
        // 11:00 UTC on the 20th is midnight on the 21st in Auckland.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00', 'UTC'));
        $this->me->update(['timezone' => 'Pacific/Auckland']);
        $chore = chore(['due_on' => '2026-10-21']);

        log_($chore, on: '2026-10-21');

        expect($chore->fresh()->due_on->toDateString())->toBe('2026-10-28');
    });

    it('snapshots the title and name so history survives renames', function () {
        $chore = chore(['title' => 'Hoover', 'due_on' => '2026-10-20']);
        $entry = log_($chore);
        $chore->update(['title' => 'Hoover downstairs']);

        expect($entry->fresh()->chore_title)->toBe('Hoover')
            ->and($entry->done_by_name)->toBe($this->me->name);
    });
});

describe('rotas', function () {
    beforeEach(function () {
        $this->crew = Crew::create(['name' => 'Home']);
        $this->you = $this->crew->members()->create(['user_id' => $this->me->id, 'role' => 'captain', 'joined_at' => now()]);
        $this->sam = $this->crew->members()->create(['user_id' => User::factory()->create(['name' => 'Sam'])->id, 'role' => 'crew', 'joined_at' => now()]);
        $alfie = $this->crew->people()->create(['name' => 'Alfie']);
        $this->alfie = $this->crew->members()->create(['person_id' => $alfie->id, 'role' => 'passenger', 'joined_at' => now()]);

        $this->rota = chore(['assign' => Chore::ROTA, 'due_on' => '2026-10-20']);
        foreach ([$this->you, $this->sam, $this->alfie] as $i => $m) {
            $this->rota->rotaMembers()->attach($m->id, ['position' => $i]);
        }
    });

    it('advances on a completion, not a skip', function () {
        log_($this->rota, 'skip');
        expect($this->rota->fresh()->rotaTurn()->is($this->you))->toBeTrue();

        log_($this->rota->fresh());
        expect($this->rota->fresh()->rotaTurn()->is($this->sam))->toBeTrue();
    });

    it('keeps the order when someone covers — it moves on from whose turn it was', function () {
        // Your turn, Alfie does it.
        log_($this->rota, by: $this->alfie);

        $entry = $this->rota->entries()->sole();
        expect($entry->done_by_name)->toBe('Alfie')
            ->and($this->rota->fresh()->rotaTurn()->is($this->sam))->toBeTrue();
    });

    it('wraps round', function () {
        foreach (range(1, 3) as $_) {
            log_($this->rota->fresh());
        }

        expect($this->rota->fresh()->rotaTurn()->is($this->you))->toBeTrue();
    });

    it('passes the turn on when the person whose turn it is leaves', function () {
        $this->rota->update(['rota_position' => 1]); // Sam's turn
        $this->sam->leave();

        $chore = $this->rota->fresh();
        expect($chore->rotaMembers->pluck('id')->all())->toBe([$this->you->id, $this->alfie->id])
            ->and($chore->rotaTurn()->is($this->alfie))->toBeTrue();
    });

    it('keeps the turn with the same person when someone earlier leaves', function () {
        $this->rota->update(['rota_position' => 2]); // Alfie's turn
        $this->you->leave();

        expect($this->rota->fresh()->rotaTurn()->is($this->alfie))->toBeTrue();
    });

    it('makes chores pinned to a leaver "anyone"', function () {
        $pinned = chore(['assign' => Chore::MEMBER, 'assignee_member_id' => $this->sam->id, 'due_on' => '2026-10-20']);

        $this->sam->leave();

        expect($pinned->fresh()->assign)->toBe(Chore::ANYONE)
            ->and($pinned->fresh()->assignee_member_id)->toBeNull();
    });
});

describe('voiding', function () {
    it('puts the chore back when the latest entry is voided', function () {
        $chore = chore(['due_on' => '2026-10-18']);
        $entry = log_($chore);

        Logbook::void($entry, $this->me, 'undo');

        expect($chore->fresh()->due_on->toDateString())->toBe('2026-10-18')
            ->and($entry->fresh()->voided_at)->not->toBeNull()
            ->and($entry->fresh()->void_reason)->toBe('undo');
    });

    it('only strikes an older entry from history', function () {
        $chore = chore(['due_on' => '2026-10-10']);
        $first = log_($chore, on: '2026-10-12');
        log_($chore->fresh());
        $dueNow = $chore->fresh()->due_on->toDateString();

        Logbook::void($first, $this->me);

        expect($chore->fresh()->due_on->toDateString())->toBe($dueNow);
    });

    it('is idempotent', function () {
        $chore = chore(['due_on' => '2026-10-18']);
        $entry = log_($chore);
        Logbook::void($entry, $this->me);
        $version = $chore->fresh()->version;

        Logbook::void($entry, $this->me);

        expect($chore->fresh()->version)->toBe($version);
    });
});
