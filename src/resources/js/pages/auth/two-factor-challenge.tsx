import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { store } from '@/routes/two-factor/login';

type ChallengeMode = 'code' | 'recovery_code';

export default function TwoFactorChallengePage() {
    const form = useForm({ code: '', recovery_code: '' });
    const [mode, setMode] = useState<ChallengeMode>('code');
    const codeRef = useRef<HTMLInputElement>(null);
    const recoveryRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        (mode === 'code' ? codeRef : recoveryRef).current?.focus();
    }, [mode]);

    function chooseMode(next: ChallengeMode) {
        form.clearErrors();
        form.setData(next === 'code' ? 'recovery_code' : 'code', '');
        setMode(next);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            code: mode === 'code' ? data.code : '',
            recovery_code: mode === 'recovery_code' ? data.recovery_code : '',
        }));
        form.post(store.url(), {
            onError: () => (mode === 'code' ? codeRef : recoveryRef).current?.focus(),
            onFinish: () => form.reset(mode),
        });
    }

    const isCode = mode === 'code';

    return (
        <>
            <Head title="Two-factor authentication" />
            <AuthLayout title="Two-factor authentication" subtitle="Verify this sign-in">
                <div className="space-y-5">
                    <div className="grid grid-cols-2 gap-2" aria-label="Verification method">
                        <Button
                            type="button"
                            variant={isCode ? 'default' : 'outline'}
                            onClick={() => chooseMode('code')}
                        >
                            Authenticator
                        </Button>
                        <Button
                            type="button"
                            variant={!isCode ? 'default' : 'outline'}
                            onClick={() => chooseMode('recovery_code')}
                        >
                            Recovery code
                        </Button>
                    </div>
                    <form onSubmit={submit} className="space-y-5">
                        {isCode ? (
                            <div className="space-y-2">
                                <Label htmlFor="code">Authentication code</Label>
                                <Input
                                    ref={codeRef}
                                    id="code"
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={form.data.code}
                                    onChange={(event) => form.setData('code', event.target.value)}
                                    aria-invalid={Boolean(form.errors.code)}
                                    aria-describedby={form.errors.code ? 'code-error' : undefined}
                                />
                                <FormFieldError id="code-error" message={form.errors.code} />
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label htmlFor="recovery-code">Recovery code</Label>
                                <Input
                                    ref={recoveryRef}
                                    id="recovery-code"
                                    name="recovery_code"
                                    autoComplete="one-time-code"
                                    value={form.data.recovery_code}
                                    onChange={(event) =>
                                        form.setData('recovery_code', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.recovery_code)}
                                    aria-describedby={
                                        form.errors.recovery_code
                                            ? 'recovery-code-error'
                                            : undefined
                                    }
                                />
                                <FormFieldError
                                    id="recovery-code-error"
                                    message={form.errors.recovery_code}
                                />
                            </div>
                        )}
                        <Button type="submit" className="w-full" disabled={form.processing}>
                            {form.processing ? 'Verifying...' : 'Verify'}
                        </Button>
                    </form>
                </div>
            </AuthLayout>
        </>
    );
}
