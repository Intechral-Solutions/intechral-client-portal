import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';

import { ProviderLinks } from '@/components/auth/provider-links';
import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { store } from '@/routes/login';
import { request as forgotPassword } from '@/routes/password';

export default function LoginPage() {
    const form = useForm({ email: '', password: '', remember: false });
    const emailRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            onError: (errors) => (errors.email ? emailRef : passwordRef).current?.focus(),
            onFinish: () => form.reset('password'),
        });
    }

    return (
        <>
            <Head title="Sign in" />
            <AuthLayout title="Sign in" subtitle="Access your client portal">
                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            ref={emailRef}
                            id="email"
                            name="email"
                            type="email"
                            autoComplete="username"
                            autoFocus
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            aria-invalid={Boolean(form.errors.email)}
                            aria-describedby={form.errors.email ? 'email-error' : undefined}
                        />
                        <FormFieldError id="email-error" message={form.errors.email} />
                    </div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-4">
                            <Label htmlFor="password">Password</Label>
                            <Link
                                className="legacy-text-primary text-sm hover:underline"
                                href={forgotPassword.url()}
                            >
                                Forgot password?
                            </Link>
                        </div>
                        <Input
                            ref={passwordRef}
                            id="password"
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            aria-invalid={Boolean(form.errors.password)}
                            aria-describedby={form.errors.password ? 'password-error' : undefined}
                        />
                        <FormFieldError id="password-error" message={form.errors.password} />
                    </div>
                    <label className="flex min-h-9 items-center gap-2 text-sm">
                        <input
                            name="remember"
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(event) => form.setData('remember', event.target.checked)}
                            className="h-4 w-4 accent-accent"
                        />
                        Remember me
                    </label>
                    <Button type="submit" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Signing in...' : 'Sign in'}
                    </Button>
                    <ProviderLinks />
                </form>
            </AuthLayout>
        </>
    );
}
