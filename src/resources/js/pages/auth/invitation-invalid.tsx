import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { AuthLayout } from '@/layouts/auth-layout';
import { login } from '@/routes';

export default function InvitationInvalidPage() {
    return (
        <>
            <Head title="Invitation unavailable" />
            <AuthLayout
                title="Invitation unavailable"
                subtitle="This invitation has expired, was already accepted, or is not valid."
            >
                <Button asChild className="w-full">
                    <Link href={login.url()}>Return to sign in</Link>
                </Button>
            </AuthLayout>
        </>
    );
}
