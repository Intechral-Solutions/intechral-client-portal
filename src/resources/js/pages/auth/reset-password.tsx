import { Head, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { update } from '@/routes/password';

type ResetPasswordProps = {
    token: string;
    email: string;
};

export default function ResetPasswordPage({ token, email }: ResetPasswordProps) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const emailRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(update.url(), {
            onError: (errors) => {
                if (errors.email || errors.token) emailRef.current?.focus();
                else if (errors.password) passwordRef.current?.focus();
                else confirmationRef.current?.focus();
            },
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    return (
        <>
            <Head title="Choose a new password" />
            <AuthLayout title="Choose a new password">
                <form onSubmit={submit} className="space-y-5">
                    <input type="hidden" name="token" value={form.data.token} />
                    <div className="space-y-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            ref={emailRef}
                            id="email"
                            name="email"
                            type="email"
                            autoComplete="username"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            aria-invalid={Boolean(form.errors.email || form.errors.token)}
                            aria-describedby={
                                form.errors.email || form.errors.token ? 'email-error' : undefined
                            }
                        />
                        <FormFieldError
                            id="email-error"
                            message={form.errors.email ?? form.errors.token}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="password">New password</Label>
                        <Input
                            ref={passwordRef}
                            id="password"
                            name="password"
                            type="password"
                            autoComplete="new-password"
                            autoFocus
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            aria-invalid={Boolean(form.errors.password)}
                            aria-describedby={form.errors.password ? 'password-error' : undefined}
                        />
                        <FormFieldError id="password-error" message={form.errors.password} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="password-confirmation">Confirm new password</Label>
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
                        {form.processing ? 'Resetting...' : 'Reset password'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
