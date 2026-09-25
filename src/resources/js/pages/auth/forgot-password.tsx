import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { login } from '@/routes';
import { email as sendResetLink } from '@/routes/password';

export default function ForgotPasswordPage() {
    const form = useForm({ email: '' });
    const emailRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(sendResetLink.url(), {
            onError: () => emailRef.current?.focus(),
        });
    }

    return (
        <>
            <Head title="Reset password" />
            <AuthLayout
                title="Reset password"
                subtitle="Enter your email address to request a reset link"
            >
                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            ref={emailRef}
                            id="email"
                            name="email"
                            type="email"
                            autoComplete="email"
                            autoFocus
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            aria-invalid={Boolean(form.errors.email)}
                            aria-describedby={form.errors.email ? 'email-error' : undefined}
                        />
                        <FormFieldError id="email-error" message={form.errors.email} />
                    </div>
                    <Button type="submit" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Sending...' : 'Send reset link'}
                    </Button>
                    <p className="text-center text-sm">
                        <Link className="legacy-text-primary hover:underline" href={login.url()}>
                            Back to sign in
                        </Link>
                    </p>
                </form>
            </AuthLayout>
        </>
    );
}
