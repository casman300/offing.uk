<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewInvite;
use App\Models\CrewMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Crews (spec §7): /crew lists yours and any invites waiting for you;
 * /crew/{crew} is one crew's members, invites and passengers.
 *
 * A crew you're not aboard is a 404, never a 403. Captains manage members,
 * roles and invites; any crewmate can add or re-add passengers. Every
 * change is audited.
 */
class CrewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $memberships = $user->crewMemberships()
            ->whereHas('crew')
            ->with(['crew' => fn ($q) => $q->withCount([
                'activeMembers as crew_count' => fn ($q) => $q->whereNotNull('user_id'),
                'activeMembers as passenger_count' => fn ($q) => $q->whereNotNull('person_id'),
            ])])
            ->get();

        return Inertia::render('crew/index', [
            'crews' => $memberships->map(fn (CrewMember $m) => [
                'id' => $m->crew->id,
                'name' => $m->crew->name,
                'role' => $m->role,
                'crew_count' => (int) $m->crew->getAttribute('crew_count'),
                'passenger_count' => (int) $m->crew->getAttribute('passenger_count'),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'invites' => CrewInvite::query()->for($user)->pending()
                ->whereHas('crew')
                ->with(['crew:id,name', 'invitedBy:id,name'])
                ->latest()
                ->get()
                ->map(fn (CrewInvite $invite) => [
                    'id' => $invite->id,
                    'crew' => $invite->crew->name,
                    'role' => $invite->role,
                    'invited_by' => $invite->invitedBy?->name,
                    'expires_at' => $invite->expires_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $user = $request->user();

        $crew = DB::transaction(function () use ($data, $user) {
            $crew = Crew::create(['name' => trim($data['name']), 'created_by_user_id' => $user->id]);
            $crew->members()->create(['user_id' => $user->id, 'role' => CrewMember::CAPTAIN, 'joined_at' => now()]);

            return $crew;
        });

        AuditLog::record($user, 'crew.created', $crew, ['name' => $crew->name], $request->ip());

        return to_route('crew.show', $crew)->with('status', "{$crew->name} is afloat — you're its captain.");
    }

    public function show(Request $request, int $crew): Response
    {
        $user = $request->user();
        [$crew, $me] = $this->aboard($user, $crew);

        $members = $crew->members()->with(['user:id,name,email', 'person' => fn ($q) => $q->select('id', 'name')])->orderBy('joined_at')->orderBy('id')->get();

        $row = fn (CrewMember $m) => [
            'id' => $m->id,
            'name' => $m->displayName(),
            'email' => $m->user?->email,
            'role' => $m->role,
            'is_me' => $m->user_id === $user->id,
            'is_account' => $m->user_id !== null,
        ];

        return Inertia::render('crew/show', [
            'crew' => ['id' => $crew->id, 'name' => $crew->name],
            'me' => ['id' => $me->id, 'role' => $me->role],
            'members' => $members->whereNull('left_at')->map($row)->values(),
            'formerPassengers' => $members->whereNotNull('left_at')->whereNotNull('person_id')->map($row)->values(),
            'captainCount' => $crew->captainCount(),
            // Your own People for the passenger picker — names only, and only
            // ever yours; whether each is already aboard comes from the copies.
            'myPeople' => $user->people()->orderBy('name')->get(['id', 'name', 'birth_day', 'birth_month'])
                ->map(fn ($person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'has_birthday' => $person->birth_day !== null && $person->birth_month !== null,
                    'aboard' => $members->whereNull('left_at')->contains('added_from_person_id', $person->id),
                ])->values(),
            'invites' => $me->isCaptain()
                ? $crew->invites()->pending()->latest()->get()->map(fn (CrewInvite $i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'role' => $i->role,
                    'expires_at' => $i->expires_at->toIso8601String(),
                ])->values()
                : [],
        ]);
    }

    public function update(Request $request, int $crew): RedirectResponse
    {
        [$crew] = $this->aboardAsCaptain($request->user(), $crew);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $from = $crew->name;
        $crew->update(['name' => trim($data['name'])]);

        AuditLog::record($request->user(), 'crew.renamed', $crew, ['from' => $from, 'to' => $crew->name], $request->ip());

        return back()->with('status', 'Renamed.');
    }

    /**
     * Captain only, confirmed by typing the name. Soft for 30 days. The
     * crew's people move to the captain's own People rather than being lost
     * (spec §6: membership changes never delete a person).
     */
    public function destroy(Request $request, int $crew): RedirectResponse
    {
        $user = $request->user();
        [$crew] = $this->aboardAsCaptain($user, $crew);

        $request->validate(['confirm' => ['required', 'string']]);

        if (Str::lower(trim($request->string('confirm'))) !== Str::lower(trim($crew->name))) {
            throw ValidationException::withMessages(['confirm' => "Type “{$crew->name}” to confirm."]);
        }

        $moved = DB::transaction(function () use ($crew, $user) {
            $moved = $crew->people()->withTrashed()->update([
                'owner_type' => $user->getMorphClass(),
                'owner_id' => $user->id,
            ]);
            $crew->delete();

            return $moved;
        });

        AuditLog::record($user, 'crew.deleted', $crew, ['name' => $crew->name, 'people_moved' => $moved], $request->ip());

        $note = $moved > 0 ? ' Its passengers are in your People now.' : '';

        return to_route('crew.index')->with('status', "{$crew->name} is gone.{$note}");
    }

    public function invite(Request $request, int $crew): RedirectResponse
    {
        $user = $request->user();
        [$crew] = $this->aboardAsCaptain($user, $crew);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(CrewMember::ACCOUNT_ROLES)],
        ]);
        $email = Str::lower(trim($data['email']));

        $alreadyAboard = $crew->activeMembers()->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))->exists();

        if ($alreadyAboard) {
            throw ValidationException::withMessages(['email' => "They're already aboard."]);
        }

        $invite = DB::transaction(function () use ($crew, $email, $data, $user) {
            // A fresh invite replaces any still-open one to the same address.
            $crew->invites()->pending()->where('email', $email)->update(['revoked_at' => now()]);

            return $crew->invites()->create([
                'email' => $email,
                'role' => $data['role'],
                'invited_by_user_id' => $user->id,
                'expires_at' => now()->addDays(CrewInvite::EXPIRES_AFTER_DAYS),
            ]);
        });

        AuditLog::record($user, 'crew_invite.sent', $crew, ['invite_id' => $invite->id, 'email' => $email, 'role' => $invite->role], $request->ip());

        // Same message whether or not that email has an account.
        return back()->with('status', "Invited {$email}. It shows up on their Crew page when they sign in.");
    }

    public function revokeInvite(Request $request, int $crew, int $invite): RedirectResponse
    {
        [$crew] = $this->aboardAsCaptain($request->user(), $crew);
        $model = $crew->invites()->pending()->findOrFail($invite);

        $model->forceFill(['revoked_at' => now()])->save();
        AuditLog::record($request->user(), 'crew_invite.revoked', $crew, ['invite_id' => $model->id, 'email' => $model->email], $request->ip());

        return back()->with('status', "Withdrew the invite to {$model->email}.");
    }

    public function acceptInvite(Request $request, int $invite): RedirectResponse
    {
        $user = $request->user();
        $model = CrewInvite::query()->for($user)->pending()->whereHas('crew')->findOrFail($invite);
        $crew = $model->crew;

        DB::transaction(function () use ($model, $crew, $user) {
            $membership = $crew->members()->firstOrNew(['user_id' => $user->id]);

            if ($membership->exists && $membership->left_at === null) {
                // Already aboard — accepting changes nothing but the invite.
            } else {
                $membership->fill(['role' => $model->role, 'joined_at' => now(), 'left_at' => null])->save();
            }

            $model->forceFill(['accepted_at' => now()])->save();
        });

        AuditLog::record($user, 'crew_invite.accepted', $crew, ['invite_id' => $model->id, 'role' => $model->role], $request->ip());

        return to_route('crew.show', $crew)->with('status', "Welcome aboard {$crew->name}.");
    }

    public function declineInvite(Request $request, int $invite): RedirectResponse
    {
        $user = $request->user();
        $model = CrewInvite::query()->for($user)->pending()->findOrFail($invite);

        $model->forceFill(['declined_at' => now()])->save();
        AuditLog::record($user, 'crew_invite.declined', $model->crew()->withTrashed()->first(), ['invite_id' => $model->id], $request->ip());

        return back()->with('status', 'Invite declined.');
    }

    /**
     * Any crewmate can add a passenger: a crew-owned person with no account
     * (spec §6) — a child, a lodger, a club member who'll never sign up.
     */
    public function addPassenger(Request $request, int $crew): RedirectResponse
    {
        $user = $request->user();
        [$crew] = $this->aboard($user, $crew);
        $data = $request->validate([
            'person_id' => ['nullable', 'integer'],
            'name' => ['required_without:person_id', 'nullable', 'string', 'max:100'],
            'share_birthday' => ['boolean'],
        ]);

        if (! empty($data['person_id'])) {
            return $this->addPassengerFromPeople($request, $crew, (int) $data['person_id'], (bool) ($data['share_birthday'] ?? false));
        }

        $member = DB::transaction(function () use ($crew, $data) {
            $person = $crew->people()->create(['name' => trim($data['name'])]);

            return $crew->members()->create(['person_id' => $person->id, 'role' => CrewMember::PASSENGER, 'joined_at' => now()]);
        });

        AuditLog::record($user, 'crew.passenger_added', $crew, ['member_id' => $member->id, 'person_id' => $member->person_id], $request->ip());

        return back()->with('status', "{$member->displayName()} is aboard as a passenger.");
    }

    /**
     * From your own People: the crew gets a copy with the name — and the
     * birthday only if you tick it — never notes or anything else (spec §6).
     * Your person stays private and untouched. Picking someone who's already
     * aboard is refused; a former passenger from the same person is re-added.
     */
    private function addPassengerFromPeople(Request $request, Crew $crew, int $personId, bool $shareBirthday): RedirectResponse
    {
        $user = $request->user();
        $source = $user->people()->findOrFail($personId);
        $existing = $crew->members()->where('added_from_person_id', $source->id)->first();

        if ($existing !== null && $existing->left_at === null) {
            throw ValidationException::withMessages(['person_id' => "{$existing->displayName()} is already aboard."]);
        }

        if ($existing !== null) {
            $existing->forceFill(['left_at' => null, 'joined_at' => now()])->save();
            AuditLog::record($user, 'crew.passenger_readded', $crew, ['member_id' => $existing->id], $request->ip());

            return back()->with('status', "{$existing->displayName()} is back aboard.");
        }

        $member = DB::transaction(function () use ($crew, $source, $shareBirthday) {
            $copy = $crew->people()->create([
                'name' => $source->name,
                ...($shareBirthday ? $source->only(['birth_day', 'birth_month', 'birth_year']) : []),
            ]);

            return $crew->members()->create([
                'person_id' => $copy->id,
                'added_from_person_id' => $source->id,
                'role' => CrewMember::PASSENGER,
                'joined_at' => now(),
            ]);
        });

        AuditLog::record($user, 'crew.passenger_added', $crew, [
            'member_id' => $member->id,
            'person_id' => $member->person_id,
            'from_people' => true,
            'birthday_shared' => $shareBirthday,
        ], $request->ip());

        return back()->with('status', "{$member->displayName()} is aboard as a passenger.");
    }

    /**
     * Re-add a former passenger — their person was kept when they left.
     */
    public function readd(Request $request, int $crew, int $member): RedirectResponse
    {
        $user = $request->user();
        [$crew] = $this->aboard($user, $crew);
        $model = $crew->members()->whereNotNull('left_at')->whereNotNull('person_id')->findOrFail($member);

        $model->forceFill(['left_at' => null, 'joined_at' => now()])->save();
        AuditLog::record($user, 'crew.passenger_readded', $crew, ['member_id' => $model->id], $request->ip());

        return back()->with('status', "{$model->displayName()} is back aboard.");
    }

    public function changeRole(Request $request, int $crew, int $member): RedirectResponse
    {
        $user = $request->user();
        [$crew] = $this->aboardAsCaptain($user, $crew);
        $model = $crew->activeMembers()->whereNotNull('user_id')->findOrFail($member);
        $data = $request->validate(['role' => ['required', Rule::in(CrewMember::ACCOUNT_ROLES)]]);

        if ($model->isCaptain() && $data['role'] !== CrewMember::CAPTAIN && $crew->captainCount() === 1) {
            throw ValidationException::withMessages(['role' => 'Every crew needs a captain — make someone else captain first.']);
        }

        $from = $model->role;
        $model->update(['role' => $data['role']]);
        AuditLog::record($user, 'crew.member_role_changed', $crew, ['member_id' => $model->id, 'from' => $from, 'to' => $model->role], $request->ip());

        return back()->with('status', "{$model->displayName()} is now ".($model->isCaptain() ? 'a captain' : 'crew').'.');
    }

    /**
     * Captain removes someone: a crewmate loses access at once; a passenger
     * becomes a former passenger and their person stays with the crew.
     */
    public function removeMember(Request $request, int $crew, int $member): RedirectResponse
    {
        $user = $request->user();
        [$crew, $me] = $this->aboardAsCaptain($user, $crew);
        $model = $crew->activeMembers()->findOrFail($member);

        if ($model->is($me)) {
            throw ValidationException::withMessages(['member' => 'To step off yourself, use “Leave crew”.']);
        }

        $model->leave();
        AuditLog::record($user, $model->isPassenger() ? 'crew.passenger_removed' : 'crew.member_removed', $crew, ['member_id' => $model->id], $request->ip());

        return back()->with('status', "{$model->displayName()} is no longer aboard.");
    }

    public function leave(Request $request, int $crew): RedirectResponse
    {
        $user = $request->user();
        [$crew, $me] = $this->aboard($user, $crew);

        $otherAccounts = $crew->activeMembers()->whereNotNull('user_id')->where('user_id', '!=', $user->id)->count();

        if ($otherAccounts === 0) {
            throw ValidationException::withMessages(['leave' => "You're the only one aboard — delete the crew instead."]);
        }

        if ($me->isCaptain() && $crew->captainCount() === 1) {
            throw ValidationException::withMessages(['leave' => "You're the only captain — make someone else captain first."]);
        }

        $me->leave();
        AuditLog::record($user, 'crew.member_left', $crew, ['member_id' => $me->id], $request->ip());

        return to_route('crew.index')->with('status', "You've left {$crew->name}.");
    }

    /**
     * @return array{0: Crew, 1: CrewMember}
     */
    private function aboard(User $user, int $crewId): array
    {
        $crew = Crew::query()->findOrFail($crewId);
        $me = $crew->membershipOf($user);
        abort_if($me === null, 404);

        return [$crew, $me];
    }

    /**
     * @return array{0: Crew, 1: CrewMember}
     */
    private function aboardAsCaptain(User $user, int $crewId): array
    {
        [$crew, $me] = $this->aboard($user, $crewId);
        abort_unless($me->isCaptain(), 403);

        return [$crew, $me];
    }
}
