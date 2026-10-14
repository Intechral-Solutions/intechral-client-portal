import { Head, Link } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { AppShell } from '@/components/shell/app-shell';
import { buttonVariants } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { show } from '@/routes/profile';

/**
 * The dashboard a customer receives while the portal is closed to them (Release 0.9, R0.9-CS1). It takes no
 * props: the server serializes no business data for this page, so there is nothing here to show or to hide.
 * Account management and sign-out stay reachable from the shell.
 */
function CustomerUnavailablePage() {
    return (
        <>
            <Head title="Home" />

            <PageFrame width="canvas">
                <PageHeader title="Home" />

                <div className="mt-8">
                    <EmptyState
                        icon={Hourglass}
                        title="The portal is not open to your account yet."
                        description="We will let you know when it is ready. You can still manage your account in the meantime."
                        action={
                            <Link
                                href={show.url()}
                                className={buttonVariants({ variant: 'secondary' })}
                            >
                                Manage your account
                            </Link>
                        }
                    />
                </div>
            </PageFrame>
        </>
    );
}

CustomerUnavailablePage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default CustomerUnavailablePage;
