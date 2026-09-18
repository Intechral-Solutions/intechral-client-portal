import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import type { ReactElement } from 'react';

import { Button } from '@/components/ui/button';
import { AppLayout } from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { smoke } from '@/routes/inertia';
import { flash } from '@/routes/inertia/smoke';
import { show as projectShow } from '@/routes/projects';

function SmokePage() {
    const parameterizedRouteProof = projectShow.url(1, { query: { source: 'foundation' } });

    return (
        <>
            <Head title="Frontend foundation" />
            <div className="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
                <header className="border-b border-border pb-6">
                    <p className="text-sm font-medium text-primary">EPIC-011A smoke proof</p>
                    <h1 className="mt-2 text-3xl font-semibold text-foreground">
                        React foundation is running
                    </h1>
                    <p className="mt-3 max-w-2xl text-sm text-muted-foreground">
                        This temporary authenticated page validates the Inertia, React, TypeScript,
                        Wayfinder, shared layout, theme, feedback, and Blade coexistence contracts.
                    </p>
                </header>

                <section className="py-8" aria-labelledby="foundation-checks">
                    <h2 id="foundation-checks" className="text-lg font-semibold">
                        Foundation checks
                    </h2>
                    <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                        {[
                            'Laravel session and shared props',
                            'Permission-aware navigation',
                            'Persistent React application layout',
                            'Dark and light appearance persistence',
                            'Typed Wayfinder routes and actions',
                            'Normal document visits to Blade pages',
                        ].map((label) => (
                            <li
                                key={label}
                                className="flex items-center gap-2 rounded-md border border-border bg-card p-3 text-sm"
                            >
                                <CheckCircle2 className="h-4 w-4 text-success" aria-hidden="true" />
                                {label}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="border-t border-border py-8" aria-labelledby="route-checks">
                    <h2 id="route-checks" className="text-lg font-semibold">
                        Coexistence and route checks
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Typed model-bound route proof:{' '}
                        <code className="font-mono">{parameterizedRouteProof}</code>
                    </p>
                    <div className="mt-5 flex flex-wrap gap-3">
                        <Button asChild>
                            <Link href={smoke.url({ query: { refreshed: true } })}>
                                Inertia visit <ArrowRight className="h-4 w-4" aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={dashboard.url({ query: { source: 'inertia-smoke' } })}>
                                Open Blade dashboard
                            </a>
                        </Button>
                        <Button
                            variant="secondary"
                            type="button"
                            onClick={() => router.post(flash.url())}
                        >
                            Test redirect flash
                        </Button>
                    </div>
                </section>
            </div>
        </>
    );
}

SmokePage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default SmokePage;
