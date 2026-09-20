import { Head, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { store } from '@/routes/password/confirm';

export default function ConfirmPasswordPage() {
    const form = useForm({ password: '' });
    const passwordRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            onError: () => passwordRef.current?.focus(),
            onFinish: () => form.reset('password'),
        });
    }

    return (
        <>
            <Head title="Confirm password" />
            <AuthLayout title="Confirm password" subtitle="Confirm your password before continuing">
                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            ref={passwordRef}
                            id="password"
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            autoFocus
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            aria-invalid={Boolean(form.errors.password)}
                            aria-describedby={form.errors.password ? 'password-error' : undefined}
                        />
                        <FormFieldError id="password-error" message={form.errors.password} />
                    </div>
                    <Button type="submit" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Confirming...' : 'Confirm password'}
                    </Button>
                </form>
            </AuthLayout>
        </>
    );
}
