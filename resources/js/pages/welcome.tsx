import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="What's in the offing">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
            </Head>
            <div className="bg-background text-foreground flex min-h-screen flex-col">
                <header className="flex items-center justify-between p-6 lg:p-8">
                    <span className="text-primary text-lg font-semibold tracking-tight">offing.uk</span>

                    {auth.user ? (
                        <Button asChild size="sm">
                            <Link href={dashboard.url()}>Go to dashboard</Link>
                        </Button>
                    ) : (
                        <Button asChild size="sm" variant="outline">
                            <Link href={login.url()}>Log in</Link>
                        </Button>
                    )}
                </header>

                <main className="flex flex-1 flex-col items-center justify-center px-6 pb-24 text-center">
                    <p className="text-muted-foreground mb-3 text-sm font-medium tracking-wide uppercase">Coming soon</p>
                    <h1 className="mb-4 max-w-2xl text-4xl font-semibold text-balance lg:text-6xl">What's in the offing.</h1>
                    <p className="text-muted-foreground mb-10 max-w-md text-base lg:text-lg">
                        A shared calendar for the people in your life — partners who see everything, friends who see what they're tagged in, and clubs
                        that don't need an account at all. We're building it in the open.
                    </p>

                    {auth.user ? (
                        <Button asChild size="lg">
                            <Link href={dashboard.url()}>Go to dashboard</Link>
                        </Button>
                    ) : (
                        <div className="flex flex-col items-center gap-3">
                            <Button asChild size="lg">
                                <Link href={login.url()}>Log in</Link>
                            </Button>
                            <p className="text-muted-foreground text-sm">Existing admins &amp; beta users only, for now.</p>
                        </div>
                    )}
                </main>

                <footer className="text-muted-foreground p-6 text-center text-xs">
                    offing.uk is open source and{' '}
                    <a href="https://github.com/casman300/offing.uk" target="_blank" rel="noreferrer" className="underline">
                        built in the open
                    </a>
                    .
                </footer>
            </div>
        </>
    );
}
