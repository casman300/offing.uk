import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import connectClaude from '@/routes/connect-claude';

interface Level {
    value: string;
    label: string;
    description: string;
}

interface ConsentProps {
    client: { name: string; host: string | null };
    authToken: string;
    csrfToken: string;
    levels: Level[];
    level: string;
    includePrivate: boolean;
    /** Only users with Barometer get its switch (health data: a separate opt-in, spec §12). */
    barometerAvailable: boolean;
    barometer: boolean;
}

/**
 * The consent screen for any AI assistant (spec §12). Plain HTML forms, not
 * Inertia visits: approving ends in a redirect back to Claude, which an XHR
 * can't follow.
 */
export default function Consent({
    client,
    authToken,
    csrfToken,
    levels,
    level: initialLevel,
    includePrivate: initialPrivate,
    barometerAvailable,
    barometer: initialBarometer,
}: ConsentProps) {
    const [level, setLevel] = useState(initialLevel);
    const [includePrivate, setIncludePrivate] = useState(initialPrivate);
    const [barometer, setBarometer] = useState(initialBarometer);

    return (
        <AuthLayout
            title={`Connect ${client.name} to Offing`}
            description="Choose what it can do. You can change this or disconnect at any time in Settings."
        >
            <Head title={`Connect ${client.name}`} />

            <form method="post" action={connectClaude.approve.url()} className="flex flex-col gap-6">
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="auth_token" value={authToken} />
                <input type="hidden" name="include_private" value={includePrivate ? '1' : '0'} />
                <input type="hidden" name="barometer" value={barometerAvailable && barometer ? '1' : '0'} />

                {client.host && (
                    <p className="text-muted-foreground text-center text-sm">
                        It will send you back to <span className="text-foreground font-medium">{client.host}</span>.
                    </p>
                )}

                <fieldset className="space-y-2">
                    <legend className="mb-2 text-sm font-medium">What can it do?</legend>
                    {levels.map((option) => (
                        <label
                            key={option.value}
                            className="has-[:checked]:border-primary has-[:checked]:bg-accent flex cursor-pointer items-start gap-3 rounded-lg border-2 p-3"
                        >
                            <input
                                type="radio"
                                name="level"
                                value={option.value}
                                className="mt-1"
                                checked={level === option.value}
                                onChange={() => setLevel(option.value)}
                            />
                            <span>
                                <span className="block text-sm font-semibold">{option.label}</span>
                                <span className="text-muted-foreground block text-sm">{option.description}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>

                <div className="flex items-start space-x-3">
                    <Checkbox id="include-private" checked={includePrivate} onCheckedChange={(checked) => setIncludePrivate(checked === true)} />
                    <div className="grid gap-1">
                        <Label htmlFor="include-private">Include my private events</Label>
                        <p className="text-muted-foreground text-sm">Off: it sees them only as “busy”, like your partner would.</p>
                    </div>
                </div>

                {barometerAvailable && (
                    <div className="flex items-start space-x-3">
                        <Checkbox id="barometer" checked={barometer} onCheckedChange={(checked) => setBarometer(checked === true)} />
                        <div className="grid gap-1">
                            <Label htmlFor="barometer">Barometer: let it add readings and see summary numbers</Label>
                            <p className="text-muted-foreground text-sm">
                                It can log a reading you tell it about, and see counts and averages. It never sees your readings or what you wrote.
                            </p>
                        </div>
                    </div>
                )}

                <p className="text-muted-foreground text-sm">
                    It sees what you can see in Offing. Anything it reads is sent to the company that runs it, under their privacy policy.
                </p>

                <div className="flex flex-col gap-3">
                    <Button type="submit" className="w-full">
                        Allow
                    </Button>
                    <Button type="submit" variant="outline" className="w-full" formAction={connectClaude.deny.url()}>
                        Cancel
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
