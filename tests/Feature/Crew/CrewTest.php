<?php

use App\Features\Crews;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewInvite;
use App\Models\CrewMember;
use App\Models\Person;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function sailor(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Feature::for($user)->activate(Crews::class);

    return $user;
}

/** A crew with $captain aboard as captain and any $crew aboard as crew. */
function crewOf(User $captain, User ...$crew): Crew
{
    test()->actingAs($captain)->post('/crew', ['name' => 'Home']);
    $home = Crew::latest('id')->first();

    foreach ($crew as $user) {
        $home->members()->create(['user_id' => $user->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);
    }

    return $home;
}

describe('access', function () {
    it('is behind the crews flag', function () {
        $this->actingAs(User::factory()->create())->get('/crew')->assertNotFound();
        $this->actingAs(sailor())->get('/crew')->assertOk();
    });

    it('404s a crew you are not aboard, and 403s captain-only actions for crew', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs(sailor())->get("/crew/{$home->id}")->assertNotFound();
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertOk();
        $this->actingAs($mate)->post("/crew/{$home->id}/invites", ['email' => 'x@example.com', 'role' => 'crew'])->assertForbidden();
        $this->actingAs($mate)->patch("/crew/{$home->id}", ['name' => 'Mine now'])->assertForbidden();
    });
});

describe('starting a crew', function () {
    it('makes you its captain, audited', function () {
        $me = sailor();

        $this->actingAs($me)->post('/crew', ['name' => '  Home '])->assertRedirect();

        $home = Crew::sole();
        expect($home->name)->toBe('Home')
            ->and($home->membershipOf($me)->role)->toBe(CrewMember::CAPTAIN)
            ->and(AuditLog::where('action', 'crew.created')->where('subject_id', $home->id)->exists())->toBeTrue();
    });

    it('lists only your crews', function () {
        $me = sailor();
        crewOf($me);
        crewOf(sailor());

        $this->actingAs($me)->get('/crew')->assertInertia(fn (Assert $page) => $page
            ->component('crew/index')
            ->has('crews', 1)
            ->where('crews.0.role', 'captain')
            ->where('crews.0.crew_count', 1));
    });
});

describe('invites', function () {
    it('says the same thing whether or not the email has an account', function () {
        $captain = sailor();
        $home = crewOf($captain);
        sailor(['email' => 'sam@example.com']);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'Sam@Example.com', 'role' => 'crew'])
            ->assertSessionHas('status', 'Invited sam@example.com. It shows up on their Crew page when they sign in.');
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'nobody@example.com', 'role' => 'crew'])
            ->assertSessionHas('status', 'Invited nobody@example.com. It shows up on their Crew page when they sign in.');

        expect(CrewInvite::count())->toBe(2);
    });

    it('is accepted by the account with that email, joining with the offered role', function () {
        $captain = sailor(['name' => 'Charlie']);
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'captain']);
        $invite = CrewInvite::sole();

        $this->actingAs($sam)->get('/crew')->assertInertia(fn (Assert $page) => $page
            ->has('invites', 1)
            ->where('invites.0.crew', 'Home')
            ->where('invites.0.invited_by', 'Charlie'));

        $this->actingAs($sam)->post("/crew/invites/{$invite->id}/accept")->assertRedirect("/crew/{$home->id}");

        expect($home->membershipOf($sam)->role)->toBe(CrewMember::CAPTAIN)
            ->and($invite->fresh()->accepted_at)->not->toBeNull()
            ->and(AuditLog::where('action', 'crew_invite.accepted')->where('actor_id', $sam->id)->exists())->toBeTrue();
    });

    it("can't be accepted by anyone else, or once expired, withdrawn or declined", function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);
        $invite = CrewInvite::sole();

        $this->actingAs(sailor())->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        $invite->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($sam)->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        $invite->update(['expires_at' => now()->addDay()]);
        $this->actingAs($captain)->delete("/crew/{$home->id}/invites/{$invite->id}")->assertRedirect();
        $this->actingAs($sam)->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        expect($home->membershipOf($sam))->toBeNull();
    });

    it('replaces an open invite to the same address, and refuses someone already aboard', function () {
        $captain = sailor(['email' => 'cap@example.com']);
        $home = crewOf($captain);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'captain']);

        expect(CrewInvite::query()->pending()->sole()->role)->toBe('captain');

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'CAP@example.com', 'role' => 'crew'])
            ->assertSessionHasErrors('email');
    });

    it('can be declined', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);

        $this->actingAs($sam)->post('/crew/invites/'.CrewInvite::sole()->id.'/decline')->assertRedirect();

        expect(CrewInvite::sole()->declined_at)->not->toBeNull()
            ->and($home->membershipOf($sam))->toBeNull();
    });
});

describe('passengers', function () {
    it('are crew-owned people any crewmate can add, never in anyone’s own People', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs($mate)->post("/crew/{$home->id}/passengers", ['name' => 'Alfie'])->assertRedirect();

        $alfie = Person::sole();
        expect($alfie->owner->is($home))->toBeTrue()
            ->and($captain->people()->count())->toBe(0)
            ->and($mate->people()->count())->toBe(0)
            ->and($home->activeMembers()->where('person_id', $alfie->id)->sole()->role)->toBe(CrewMember::PASSENGER);
    });

    it('keeps the person when a passenger is removed, and can re-add them', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Alfie']);
        $member = $home->activeMembers()->whereNotNull('person_id')->sole();

        $this->actingAs($captain)->delete("/crew/{$home->id}/members/{$member->id}")->assertRedirect();

        expect(Person::where('name', 'Alfie')->exists())->toBeTrue()
            ->and($member->fresh()->left_at)->not->toBeNull();

        $this->actingAs($captain)->get("/crew/{$home->id}")->assertInertia(fn (Assert $page) => $page
            ->has('members', 1)
            ->has('formerPassengers', 1)
            ->where('formerPassengers.0.name', 'Alfie'));

        $this->actingAs($captain)->post("/crew/{$home->id}/members/{$member->id}/readd")->assertRedirect();
        expect($member->fresh()->left_at)->toBeNull();
    });
});

describe('every crew keeps a captain', function () {
    it("won't demote the last captain", function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);
        $mine = $home->membershipOf($captain);
        $theirs = $home->membershipOf($mate);

        // Can't demote yourself via the API either, if you're the last one.
        $this->actingAs($captain)->patch("/crew/{$home->id}/members/{$mine->id}", ['role' => 'crew'])->assertSessionHasErrors('role');

        $this->actingAs($captain)->patch("/crew/{$home->id}/members/{$theirs->id}", ['role' => 'captain'])->assertSessionHasNoErrors();
        $this->actingAs($captain)->patch("/crew/{$home->id}/members/{$mine->id}", ['role' => 'crew'])->assertSessionHasNoErrors();

        expect($home->captainCount())->toBe(1)
            ->and($theirs->fresh()->isCaptain())->toBeTrue()
            ->and(AuditLog::where('action', 'crew.member_role_changed')->count())->toBe(2);
    });

    it('lets crew leave, but not the last captain or the only one aboard', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs($captain)->post("/crew/{$home->id}/leave")->assertSessionHasErrors('leave');

        $this->actingAs($mate)->post("/crew/{$home->id}/leave")->assertRedirect('/crew');
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertNotFound();

        $this->actingAs($captain)->post("/crew/{$home->id}/leave")->assertSessionHasErrors('leave');
        expect($home->membershipOf($captain))->not->toBeNull();
    });

    it('cuts a removed crewmate off at once', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs($captain)->delete("/crew/{$home->id}/members/{$home->membershipOf($mate)->id}")->assertRedirect();

        $this->actingAs($mate)->get("/crew/{$home->id}")->assertNotFound();
        expect(AuditLog::where('action', 'crew.member_removed')->exists())->toBeTrue();
    });
});

describe('deleting a crew', function () {
    it('needs the name typed, then moves its people to the captain', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Alfie']);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Jo']);
        $this->actingAs($captain)->delete("/crew/{$home->id}/members/".$home->activeMembers()->whereHas('person', fn ($q) => $q->where('name', 'Jo'))->sole()->id);

        $this->actingAs($captain)->delete("/crew/{$home->id}", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        expect($home->fresh()->trashed())->toBeFalse();

        $this->actingAs($captain)->delete("/crew/{$home->id}", ['confirm' => ' home '])->assertRedirect('/crew');

        expect($home->fresh()->trashed())->toBeTrue()
            ->and($captain->people()->pluck('name')->sort()->values()->all())->toBe(['Alfie', 'Jo'])
            ->and(AuditLog::where('action', 'crew.deleted')->sole()->meta)->toMatchArray(['people_moved' => 2]);

        $this->actingAs($mate)->get('/crew')->assertInertia(fn (Assert $page) => $page->has('crews', 0));
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertNotFound();
    });

    it('is pruned for good after 30 days', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $home->delete();
        // A passenger left behind on a crew deleted some other way goes with it.
        $home->people()->create(['name' => 'Orphan']);

        $this->travel(29)->days();
        $this->artisan('model:prune', ['--model' => [Crew::class]]);
        expect(Crew::withTrashed()->count())->toBe(1);

        $this->travel(2)->days();
        $this->artisan('model:prune', ['--model' => [Crew::class]]);
        expect(Crew::withTrashed()->count())->toBe(0)
            ->and(Person::withTrashed()->where('name', 'Orphan')->exists())->toBeFalse();
    });
});

describe('deleting an account', function () {
    it('takes your own People with it', function () {
        $me = sailor();
        Person::factory()->for($me, 'owner')->create();
        Person::factory()->for($me, 'owner')->create()->delete();
        $other = Person::factory()->create();

        $me->delete();

        expect(Person::withTrashed()->pluck('id')->all())->toBe([$other->id]);
    });

    it('promotes the longest-serving crewmate when the last captain goes', function () {
        $captain = sailor();
        $first = sailor();
        $second = sailor();
        $home = crewOf($captain, $first);
        $home->members()->create(['user_id' => $second->id, 'role' => CrewMember::CREW, 'joined_at' => now()->addMinute()]);

        $captain->delete();

        expect($home->membershipOf($first)->isCaptain())->toBeTrue()
            ->and($home->membershipOf($second)->isCaptain())->toBeFalse()
            ->and(AuditLog::where('action', 'crew.captain_promoted')->exists())->toBeTrue();
    });

    it('sinks a crew nobody else is aboard', function () {
        $captain = sailor();
        $home = crewOf($captain);

        $captain->delete();

        expect($home->fresh()->trashed())->toBeTrue();
    });
});
