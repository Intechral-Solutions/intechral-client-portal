import { Head, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';

import { ProviderLinks } from '@/components/auth/provider-links';
import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { register } from '@/routes/invitation';

type InvitationRegisterProps = {
    invitation: { email: string; expiresAt: string };
    token: string;
};

export default function InvitationRegisterPage({ invitation, token }: InvitationRegisterProps) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });
    const nameRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(register.url(token), {
            onError: (errors) => {
                if (errors.name) nameRef.current?.focus();
                else if (errors.password) passwordRef.current?.focus();
                else confirmationRef.current?.focus();
            },
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    return (
        <>
            <Head title="Accept invitation" />
            <AuthLayout
                title="Accept invitation"
                subtitle={`Create an account for ${invitation.email}`}
            >
                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="invitation-email">Email address</Label>
                        <Input id="invitation-email" value={invitation.email} readOnly disabled />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            ref={nameRef}
                            id="name"
                            name="name"
                            autoComplete="name"
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            aria-invalid={Boolean(form.errors.name)}
                            aria-describedby={form.errors.name ? 'name-error' : undefined}
                        />
                        <FormFieldError id="name-error" message={form.errors.name} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            ref={passwordRef}
                            id="password"
                            name="password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            aria-invalid={Boolean(form.errors.password)}
                            aria-describedby={form.errors.password ? 'password-error' : undefined}
                        />
                        <FormFieldError id="password-error" message={form.errors.password} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="password-confirmation">Confirm password</Label>
                        <Input
                            ref={confirmationRef}
                            id="password-confirmation"
                            name="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(event) =>
                                form.setData('password_confirmation', event.target.value)
                            }
                            aria-invalid={Boolean(form.errors.password_confirmation)}
                            aria-describedby={
                                form.errors.password_confirmation
                                    ? 'password-confirmation-error'
                                    : undefined
                            }
                        />
                        <FormFieldError
                            id="password-confirmation-error"
                            message={form.errors.password_confirmation}
                        />
                    </div>
                    <Button type="submit" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Creating account...' : 'Create account'}
                    </Button>
                    <ProviderLinks invitation={token} />
                </form>
            </AuthLayout>
        </>
    );
}
