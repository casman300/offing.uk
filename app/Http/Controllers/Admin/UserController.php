<?php

namespace App\Http\Controllers\Admin;

use App\Features\Features;
use App\Features\StagedFeature;
use App\Http\Controllers\Controller;
use App\Mail\MagicLinkMail;
use App\Models\AuditLog;
use App\Models\MagicLink;
use App\Models\User;
use App\Support\Users\CreateUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * /admin/users (spec §15): there's no public signup, so this is where an
 * admin adds someone — a partner, say — switches on the flags they'll
 * need (an Admins-stage feature is otherwise invisible to a non-admin) and
 * optionally sends their first sign-in link. Same rules as `user:create`.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $features = Features::all();
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->withCount('passkeys')
            ->addSelect(['last_login_at' => AuditLog::query()
                ->select('created_at')
                ->where('action', 'auth.login')
                ->where('actor_type', (new User)->getMorphClass())
                ->whereColumn('actor_id', 'users.id')
                ->latest('created_at')
                ->limit(1),
            ])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('email', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->latest('id')
            ->limit(50)
            ->get();

        $stored = Features::stored(array_keys($features), $users->all());

        return Inertia::render('admin/users', [
            'q' => $search,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'defaultTimezone' => User::DEFAULT_TIMEZONE,
            'flags' => collect($features)->map(fn (StagedFeature $feature) => [
                'name' => $feature->name,
                'description' => $feature->description,
                'stage_label' => $feature->stage->label(),
            ])->values(),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'is_admin' => $user->isAdmin(),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->getAttribute('last_login_at') ? Carbon::parse($user->getAttribute('last_login_at'))->toIso8601String() : null,
                'passkeys' => (int) $user->getAttribute('passkeys_count'),
                // What they'd get right now: a stored value, else the stage default.
                'flags_on' => collect($features)
                    ->filter(fn (StagedFeature $feature) => $stored[$feature->name][$user->id] ?? $feature->resolve($user))
                    ->keys()
                    ->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'flags' => ['array'],
            'flags.*' => ['string', Rule::in(array_keys(Features::all()))],
            'send_link' => ['boolean'],
        ]);

        $admin = $request->user();

        $user = DB::transaction(function () use ($validated, $admin, $request) {
            $user = CreateUser::create($validated, $admin, 'admin', $request->ip());

            foreach (array_unique($validated['flags'] ?? []) as $name) {
                $flag = Features::find($name);
                Feature::for($user)->activate($flag::class);
                AuditLog::record($admin, 'feature.activated', $user, ['feature' => $name, 'via' => 'admin'], $request->ip());
            }

            return $user;
        });

        if ($request->boolean('send_link')) {
            $this->sendSignInLink($user, $admin, $request->ip());

            return back()->with('status', "Added {$user->email} and sent them a sign-in link.");
        }

        return back()->with('status', "Added {$user->email}. They can sign in by requesting a link on the login page.");
    }

    public function sendLink(Request $request, User $user): RedirectResponse
    {
        $this->sendSignInLink($user, $request->user(), $request->ip());

        return back()->with('status', "Sent a sign-in link to {$user->email}.");
    }

    /**
     * A welcome-worded link for someone who has never signed in (a first
     * magic-link sign-in is what verifies the email), the usual one after.
     */
    private function sendSignInLink(User $user, User $admin, ?string $ip): void
    {
        $welcome = $user->email_verified_at === null;

        Mail::to($user->email)->queue(new MagicLinkMail($user, MagicLink::issue($user), $welcome, $admin->name));

        AuditLog::record($admin, 'auth.magic_link.sent', $user, ['via' => 'admin', 'welcome' => $welcome], $ip);
    }
}
