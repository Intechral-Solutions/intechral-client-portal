import { useEffect, useRef, useState } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { options } from '@/routes/time/context';
import type { ContextKind, ContextOption } from '@/types/time';

export type SelectedContext = {
    kind: ContextKind;
    id: number;
    label?: string;
} | null;

type Props = {
    idPrefix: string;
    value: SelectedContext;
    onChange: (value: SelectedContext) => void;
    disabled?: boolean;
    error?: string;
};

export function ContextSelector({ idPrefix, value, onChange, disabled, error }: Props) {
    const [kind, setKind] = useState<ContextKind | ''>(value?.kind ?? '');
    const [items, setItems] = useState<ContextOption[]>([]);
    const [status, setStatus] = useState<'idle' | 'loading' | 'ready' | 'error'>(
        value?.kind ? 'loading' : 'idle',
    );
    const cache = useRef(new Map<ContextKind, ContextOption[]>());

    useEffect(() => {
        if (!kind) {
            return;
        }

        const cached = cache.current.get(kind);
        if (cached) {
            return;
        }

        const controller = new AbortController();

        void fetch(options.url({ query: { type: kind } }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('Unable to load context options.');
                return (await response.json()) as ContextOption[];
            })
            .then((loaded) => {
                cache.current.set(kind, loaded);
                setItems(loaded);
                setStatus('ready');
            })
            .catch((reason: unknown) => {
                if (reason instanceof DOMException && reason.name === 'AbortError') return;
                setItems([]);
                setStatus('error');
            });

        return () => controller.abort();
    }, [kind]);

    const recordId = `${idPrefix}-context-record`;
    const errorId = `${idPrefix}-context-error`;

    return (
        <>
            <div className="space-y-2">
                <Label htmlFor={`${idPrefix}-context-type`}>Context</Label>
                <NativeSelect
                    id={`${idPrefix}-context-type`}
                    value={kind}
                    disabled={disabled}
                    className="w-full"
                    onChange={(event) => {
                        const next = event.target.value as ContextKind | '';
                        setKind(next);
                        onChange(null);

                        if (!next) {
                            setItems([]);
                            setStatus('idle');
                            return;
                        }

                        const cached = cache.current.get(next);
                        setItems(cached ?? []);
                        setStatus(cached ? 'ready' : 'loading');
                    }}
                >
                    <option value="">None</option>
                    <option value="project">Project</option>
                    <option value="task">Task</option>
                    <option value="ticket">Ticket</option>
                </NativeSelect>
            </div>

            {kind ? (
                <div className="space-y-2">
                    <Label htmlFor={recordId}>Record</Label>
                    <NativeSelect
                        id={recordId}
                        value={value?.kind === kind ? value.id : ''}
                        disabled={disabled || status === 'loading'}
                        aria-invalid={Boolean(error)}
                        aria-describedby={error ? errorId : undefined}
                        className="w-full"
                        onChange={(event) => {
                            const id = Number(event.target.value);
                            onChange(id ? { kind, id } : null);
                        }}
                    >
                        <option value="">
                            {status === 'loading'
                                ? 'Loading...'
                                : status === 'error'
                                  ? 'Unable to load options'
                                  : 'Select record'}
                        </option>
                        {items.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.label}
                            </option>
                        ))}
                        {value?.kind === kind &&
                        value.label &&
                        !items.some((item) => item.id === value.id) ? (
                            <option value={value.id}>{value.label}</option>
                        ) : null}
                    </NativeSelect>
                    {status === 'ready' && items.length === 0 ? (
                        <p className="text-xs text-muted-foreground">No available records.</p>
                    ) : null}
                    {status === 'error' ? (
                        <p className="text-xs text-destructive" role="alert">
                            Context options could not be loaded. Change the context type to retry.
                        </p>
                    ) : null}
                    <FormFieldError id={errorId} message={error} />
                </div>
            ) : null}
        </>
    );
}
