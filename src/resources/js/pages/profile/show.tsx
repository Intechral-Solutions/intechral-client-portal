import { Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    Laptop,
    Link2,
    RefreshCw,
    ShieldCheck,
    ShieldOff,
    Unlink,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { PageHeader } from '@/components/page-header';
import { SectionPanel } from '@/components/section-panel';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppLayout } from '@/layouts/app-layout';
import { formatTimestamp } from '@/lib/dates';
import { confirmation as passwordConfirmation } from '@/routes/password';
import { confirm as confirmProfilePassword } from '@/routes/profile/password';
import { destroy as destroySessions } from '@/routes/profile/sessions';
import { unlink } from '@/routes/profile/social';
import { redirect as connectProvider } from '@/routes/sso';
import {
    confirm as confirmTwoFactor,
    disable as disableTwoFactor,
    enable as enableTwoFactor,
    qrCode,
    recoveryCodes as recoveryCodesRoute,
    regenerateRecoveryCodes,
    secretKey,
} from '@/routes/two-factor';
import { update as updatePassword } from '@/routes/user-password';
import { update as updateProfile } from '@/routes/user-profile-information';

type ProfileData = {
    name: string;
    email: string;
    hasPassword: boolean;
};

type TwoFactorState = {
    enabled: boolean;
    confirmed: boolean;
};

type ConnectedAccount = {
    provider: 'google' | 'microsoft';
    connected: boolean;
};

type BrowserSession = {
    isCurrent: boolean;
    ipAddress: string | null;
    userAgent: string | null;
    lastActiveAt: string;
};

export type ProfilePageProps = {
    profile: ProfileData;
    twoFactor: TwoFactorState;
    connectedAccounts: ConnectedAccount[];
    sessions: BrowserSession[];
};

function fieldDescription(error: string | undefined, id: string) {
    return error ? `${id}-error` : undefined;
}

function ProfileInformationForm({ profile }: { profile: ProfileData }) {
    const form = useForm({ name: profile.name, email: profile.email });
    const nameRef = useRef<HTMLInputElement>(null);
    const emailRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(updateProfile.url(), {
            errorBag: 'updateProfileInformation',
            preserveScroll: true,
            onError: (errors) => {
                (errors.name ? nameRef : emailRef).current?.focus();
            },
        });
    }

    return (
        <form onSubmit={submit} className="max-w-xl space-y-5">
            <div className="space-y-2">
                <Label htmlFor="profile-name">Name</Label>
                <Input
                    ref={nameRef}
                    id="profile-name"
                    name="name"
                    autoComplete="name"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    aria-invalid={Boolean(form.errors.name)}
                    aria-describedby={fieldDescription(form.errors.name, 'profile-name')}
                />
                <FormFieldError id="profile-name-error" message={form.errors.name} />
            </div>
            <div className="space-y-2">
                <Label htmlFor="profile-email">Email address</Label>
                <Input
                    ref={emailRef}
                    id="profile-email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    aria-invalid={Boolean(form.errors.email)}
                    aria-describedby={fieldDescription(form.errors.email, 'profile-email')}
                />
                <FormFieldError id="profile-email-error" message={form.errors.email} />
            </div>
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Saving...' : 'Save profile'}
            </Button>
        </form>
    );
}

function PasswordForm() {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const currentRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(updatePassword.url(), {
            errorBag: 'updatePassword',
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: (errors) => {
                if (errors.current_password) currentRef.current?.focus();
                else if (errors.password) passwordRef.current?.focus();
                else confirmationRef.current?.focus();
            },
        });
    }

    return (
        <form onSubmit={submit} className="max-w-xl space-y-5">
            <div className="space-y-2">
                <Label htmlFor="current-password">Current password</Label>
                <Input
                    ref={currentRef}
                    id="current-password"
                    name="current_password"
                    type="password"
                    autoComplete="current-password"
                    value={form.data.current_password}
                    onChange={(event) => form.setData('current_password', event.target.value)}
                    aria-invalid={Boolean(form.errors.current_password)}
                    aria-describedby={fieldDescription(
                        form.errors.current_password,
                        'current-password',
                    )}
                />
                <FormFieldError
                    id="current-password-error"
                    message={form.errors.current_password}
                />
            </div>
            <div className="space-y-2">
                <Label htmlFor="new-password">New password</Label>
                <Input
                    ref={passwordRef}
                    id="new-password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    aria-invalid={Boolean(form.errors.password)}
                    aria-describedby={fieldDescription(form.errors.password, 'new-password')}
                />
                <FormFieldError id="new-password-error" message={form.errors.password} />
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
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    aria-invalid={Boolean(form.errors.password_confirmation)}
                    aria-describedby={fieldDescription(
                        form.errors.password_confirmation,
                        'password-confirmation',
                    )}
                />
                <FormFieldError
                    id="password-confirmation-error"
                    message={form.errors.password_confirmation}
                />
            </div>
            <Button type="submit" disabled={form.processing}>
                {form.processing ? 'Updating...' : 'Update password'}
            </Button>
        </form>
    );
}

async function fetchSensitive<T>(url: string): Promise<T | null> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (response.redirected) {
        window.location.assign(response.url);
        return null;
    }

    if (!response.ok) throw new Error('Unable to retrieve the requested security details.');
    return (await response.json()) as T;
}

async function ensurePasswordConfirmed(): Promise<boolean> {
    const response = await fetch(passwordConfirmation.url(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const status = (await response.json()) as { confirmed: boolean };

    if (!status.confirmed) {
        window.location.assign(confirmProfilePassword.url());
        return false;
    }

    return true;
}

function TwoFactorSection({ state }: { state: TwoFactorState }) {
    const confirmation = useForm({ code: '' });
    const [processing, setProcessing] = useState<string | null>(null);
    const [qrSvg, setQrSvg] = useState<string | null>(null);
    const [setupKey, setSetupKey] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [requestError, setRequestError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const clearSensitive = () => {
        setQrSvg(null);
        setSetupKey(null);
        setRecoveryCodes(null);
        setCopied(false);
    };

    async function mutate(action: string, method: 'post' | 'delete', url: string) {
        if (!(await ensurePasswordConfirmed())) return;

        setProcessing(action);
        setRequestError(null);
        router[method](
            url,
            {},
            {
                preserveScroll: true,
                only: ['twoFactor', 'flash'],
                onSuccess: clearSensitive,
                onError: () => setRequestError('The security change could not be completed.'),
                onFinish: () => setProcessing(null),
            },
        );
    }

    async function loadSetup() {
        if (!(await ensurePasswordConfirmed())) return;

        setProcessing('setup');
        setRequestError(null);
        try {
            const [qr, secret] = await Promise.all([
                fetchSensitive<{ svg: string }>(qrCode.url()),
                fetchSensitive<{ secretKey: string }>(secretKey.url()),
            ]);
            if (qr && secret) {
                setQrSvg(qr.svg);
                setSetupKey(secret.secretKey);
            }
        } catch (error) {
            setRequestError(
                error instanceof Error ? error.message : 'Unable to load setup details.',
            );
        } finally {
            setProcessing(null);
        }
    }

    async function loadRecoveryCodes() {
        if (!(await ensurePasswordConfirmed())) return;

        setProcessing('recovery');
        setRequestError(null);
        try {
            const codes = await fetchSensitive<string[]>(recoveryCodesRoute.url());
            if (codes) setRecoveryCodes(codes);
        } catch (error) {
            setRequestError(
                error instanceof Error ? error.message : 'Unable to load recovery codes.',
            );
        } finally {
            setProcessing(null);
        }
    }

    async function regenerate() {
        if (!(await ensurePasswordConfirmed())) return;

        setProcessing('regenerate');
        setRequestError(null);
        router.post(
            regenerateRecoveryCodes.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: loadRecoveryCodes,
                onError: () => setRequestError('Recovery codes could not be regenerated.'),
                onFinish: () => setProcessing(null),
            },
        );
    }

    function submitConfirmation(event: FormEvent) {
        event.preventDefault();
        confirmation.post(confirmTwoFactor.url(), {
            errorBag: 'confirmTwoFactorAuthentication',
            preserveScroll: true,
            only: ['twoFactor', 'flash'],
            onSuccess: () => {
                confirmation.reset();
                clearSensitive();
            },
        });
    }

    async function copyCodes() {
        if (!recoveryCodes) return;
        await navigator.clipboard.writeText(recoveryCodes.join('\n'));
        setCopied(true);
    }

    if (!state.enabled) {
        return (
            <div className="max-w-xl">
                <div className="flex items-center gap-2">
                    <Badge variant="neutral">Disabled</Badge>
                    <span className="text-sm text-muted-foreground">
                        Sign-in requires only your current authentication method.
                    </span>
                </div>
                <Button
                    type="button"
                    className="mt-5"
                    disabled={processing !== null}
                    onClick={() => mutate('enable', 'post', enableTwoFactor.url())}
                >
                    <ShieldCheck aria-hidden="true" />
                    {processing === 'enable' ? 'Enabling...' : 'Enable two-factor authentication'}
                </Button>
                {requestError ? (
                    <p className="mt-3 text-sm text-[var(--text-danger)]">{requestError}</p>
                ) : null}
            </div>
        );
    }

    if (!state.confirmed) {
        return (
            <div className="max-w-xl space-y-5">
                <Alert className="border-[var(--border-warning)] bg-[var(--surface-warning)]">
                    <p className="font-medium text-[var(--text-warning)]">Setup is not complete</p>
                    <p className="mt-1 text-muted-foreground">
                        Connect an authenticator app, then enter its six-digit code to finish.
                    </p>
                </Alert>

                {qrSvg && setupKey ? (
                    <div className="space-y-4">
                        <div
                            className="w-fit rounded-md border border-border bg-white p-3"
                            role="img"
                            aria-label="Two-factor authenticator setup QR code"
                            dangerouslySetInnerHTML={{ __html: qrSvg }}
                        />
                        <div>
                            <p className="text-sm font-medium">Setup key</p>
                            <code className="mt-1 block overflow-x-auto rounded-md bg-muted p-3 text-sm">
                                {setupKey}
                            </code>
                        </div>
                        <Button type="button" variant="ghost" size="sm" onClick={clearSensitive}>
                            Hide setup details
                        </Button>
                    </div>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing !== null}
                        onClick={loadSetup}
                    >
                        <KeyRound aria-hidden="true" />
                        {processing === 'setup' ? 'Loading...' : 'Show setup details'}
                    </Button>
                )}

                <form onSubmit={submitConfirmation} className="space-y-3">
                    <Label htmlFor="two-factor-code">Authentication code</Label>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Input
                            id="two-factor-code"
                            className="sm:max-w-52"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="[0-9]*"
                            value={confirmation.data.code}
                            onChange={(event) => confirmation.setData('code', event.target.value)}
                            aria-invalid={Boolean(confirmation.errors.code)}
                            aria-describedby={fieldDescription(
                                confirmation.errors.code,
                                'two-factor-code',
                            )}
                        />
                        <Button type="submit" disabled={confirmation.processing}>
                            {confirmation.processing ? 'Confirming...' : 'Confirm setup'}
                        </Button>
                    </div>
                    <FormFieldError id="two-factor-code-error" message={confirmation.errors.code} />
                </form>

                <Button
                    type="button"
                    variant="ghost"
                    className="text-[var(--text-danger)]"
                    disabled={processing !== null}
                    onClick={() => mutate('disable', 'delete', disableTwoFactor.url())}
                >
                    <ShieldOff aria-hidden="true" />
                    Cancel setup
                </Button>
                {requestError ? (
                    <p className="text-sm text-[var(--text-danger)]">{requestError}</p>
                ) : null}
            </div>
        );
    }

    return (
        <div className="max-w-xl space-y-5">
            <div className="flex items-center gap-2">
                <Badge variant="success">Enabled</Badge>
                <span className="text-sm text-muted-foreground">
                    Your account requires an authenticator code at sign-in.
                </span>
            </div>

            {recoveryCodes ? (
                <Alert className="border-[var(--border-warning)] bg-[var(--surface-warning)]">
                    <p className="font-medium">Recovery codes</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Store these somewhere secure. Each code can be used once.
                    </p>
                    <ul className="mt-3 grid gap-1 font-mono text-sm sm:grid-cols-2">
                        {recoveryCodes.map((code) => (
                            <li key={code}>{code}</li>
                        ))}
                    </ul>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={copyCodes}>
                            {copied ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
                            {copied ? 'Copied' : 'Copy codes'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={processing !== null}
                            onClick={regenerate}
                        >
                            <RefreshCw aria-hidden="true" />
                            {processing === 'regenerate' ? 'Regenerating...' : 'Generate new codes'}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={clearSensitive}>
                            Hide codes
                        </Button>
                    </div>
                </Alert>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing !== null}
                    onClick={loadRecoveryCodes}
                >
                    <KeyRound aria-hidden="true" />
                    {processing === 'recovery' ? 'Loading...' : 'Show recovery codes'}
                </Button>
            )}

            <Button
                type="button"
                variant="ghost"
                className="text-[var(--text-danger)]"
                disabled={processing !== null}
                onClick={() => mutate('disable', 'delete', disableTwoFactor.url())}
            >
                <ShieldOff aria-hidden="true" />
                {processing === 'disable' ? 'Disabling...' : 'Disable two-factor authentication'}
            </Button>
            {requestError ? (
                <p className="text-sm text-[var(--text-danger)]">{requestError}</p>
            ) : null}
        </div>
    );
}

function ConnectedAccounts({ accounts }: { accounts: ConnectedAccount[] }) {
    const [confirming, setConfirming] = useState<ConnectedAccount | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function removeAccount() {
        if (!confirming) return;
        setProcessing(true);
        setError(null);
        router.delete(unlink.url(confirming.provider), {
            preserveScroll: true,
            only: ['connectedAccounts', 'flash'],
            onSuccess: () => setConfirming(null),
            onError: (errors) =>
                setError(errors.provider ?? 'The connected account could not be removed.'),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <div className="max-w-xl space-y-3">
            {accounts.map((account) => {
                const label = account.provider === 'google' ? 'Google' : 'Microsoft';
                return (
                    <div
                        key={account.provider}
                        className="flex flex-col justify-between gap-3 border-b border-border pb-3 last:border-0 sm:flex-row sm:items-center"
                    >
                        <div className="flex items-center gap-3">
                            <Link2 className="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            <div>
                                <p className="text-sm font-medium">{label}</p>
                                <p className="text-xs text-muted-foreground">
                                    {account.connected ? 'Connected' : 'Not connected'}
                                </p>
                            </div>
                        </div>
                        {account.connected ? (
                            <ConfirmationDialog
                                open={confirming?.provider === account.provider}
                                onOpenChange={(open) => setConfirming(open ? account : null)}
                                title={`Disconnect ${label}?`}
                                description={`You will no longer be able to sign in with ${label}. Other sign-in methods are not affected.`}
                                confirmLabel="Disconnect"
                                processing={processing}
                                onConfirm={removeAccount}
                            >
                                <Button type="button" variant="outline" size="sm">
                                    <Unlink aria-hidden="true" />
                                    Disconnect
                                </Button>
                            </ConfirmationDialog>
                        ) : (
                            <Button asChild variant="outline" size="sm">
                                <a href={connectProvider.url(account.provider)}>
                                    <Link2 aria-hidden="true" />
                                    Connect
                                </a>
                            </Button>
                        )}
                    </div>
                );
            })}
            {error ? (
                <p className="text-sm text-[var(--text-danger)]" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function SessionsSection({ sessions }: { sessions: BrowserSession[] }) {
    const form = useForm({ password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.delete(destroySessions.url(), {
            errorBag: 'destroySessions',
            preserveScroll: true,
            only: ['sessions', 'flash'],
            onSuccess: () => form.reset(),
        });
    }

    return (
        <div className="max-w-xl space-y-6">
            {sessions.length ? (
                <ul className="divide-y divide-border border-y border-border">
                    {sessions.map((session, index) => (
                        <li key={`${session.lastActiveAt}-${index}`} className="flex gap-3 py-4">
                            <Laptop
                                className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="text-sm font-medium">
                                        {session.userAgent ?? 'Unknown browser'}
                                    </p>
                                    {session.isCurrent ? (
                                        <Badge variant="info">Current</Badge>
                                    ) : null}
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {session.ipAddress ?? 'Unknown IP'} ·{' '}
                                    {formatTimestamp(session.lastActiveAt)}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-muted-foreground">
                    No browser session details are currently available.
                </p>
            )}

            <form onSubmit={submit} className="space-y-3">
                <div className="space-y-2">
                    <Label htmlFor="session-password">Current password</Label>
                    <Input
                        id="session-password"
                        className="max-w-sm"
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        aria-invalid={Boolean(form.errors.password)}
                        aria-describedby={fieldDescription(
                            form.errors.password,
                            'session-password',
                        )}
                    />
                    <FormFieldError id="session-password-error" message={form.errors.password} />
                </div>
                <Button type="submit" variant="outline" disabled={form.processing}>
                    {form.processing ? 'Signing out...' : 'Sign out other browser sessions'}
                </Button>
            </form>
        </div>
    );
}

function ProfilePage({ profile, twoFactor, connectedAccounts, sessions }: ProfilePageProps) {
    return (
        <>
            <Head title="Profile" />
            <div className="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Profile"
                    description="Manage your identity, sign-in methods, and account security."
                />

                <SectionPanel
                    title="Profile information"
                    description="Update the name and email address shown across the portal."
                >
                    <ProfileInformationForm profile={profile} />
                </SectionPanel>

                <SectionPanel
                    title="Password"
                    description="Use a unique password you do not use elsewhere."
                >
                    {profile.hasPassword ? (
                        <PasswordForm />
                    ) : (
                        <Alert className="max-w-xl">
                            <p className="font-medium">Local password sign-in is not configured</p>
                            <p className="mt-1 text-muted-foreground">
                                This account signs in through a connected provider. Local password
                                enrollment is not currently available.
                            </p>
                        </Alert>
                    )}
                </SectionPanel>

                <SectionPanel
                    title="Two-factor authentication"
                    description="Add an authenticator app as an extra sign-in check."
                >
                    {profile.hasPassword ? (
                        <TwoFactorSection state={twoFactor} />
                    ) : (
                        <Alert className="max-w-xl">
                            Two-factor setup changes require a local password, which is not
                            configured for this account.
                        </Alert>
                    )}
                </SectionPanel>

                <SectionPanel
                    title="Connected accounts"
                    description="Manage the external providers available for sign-in."
                >
                    <ConnectedAccounts accounts={connectedAccounts} />
                </SectionPanel>

                <SectionPanel
                    title="Browser sessions"
                    description="Review browser activity and sign out sessions on other devices."
                >
                    {profile.hasPassword ? (
                        <SessionsSection sessions={sessions} />
                    ) : (
                        <Alert className="max-w-xl">
                            Session revocation requires a local password, which is not configured
                            for this account.
                        </Alert>
                    )}
                </SectionPanel>
            </div>
        </>
    );
}

ProfilePage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default ProfilePage;
