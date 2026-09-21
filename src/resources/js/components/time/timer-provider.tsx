import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useReducer,
    useRef,
} from 'react';
import type { PropsWithChildren } from 'react';

import { description, start, stop } from '@/routes/time/timer';
import { active } from '@/routes/time/timers';
import type { ActiveTimer, TimerStartPayload } from '@/types/time';

type HydrationStatus = 'idle' | 'loading' | 'ready' | 'error';

type TimerState = {
    timers: ActiveTimer[];
    status: HydrationStatus;
    error: string | null;
    clockOffsetMs: number;
    starting: boolean;
    stopping: number[];
    updating: number[];
};

type TimerAction =
    | { type: 'loading' }
    | { type: 'hydrated'; timers: ActiveTimer[]; clockOffsetMs: number }
    | { type: 'failed'; message: string }
    | { type: 'starting'; value: boolean }
    | { type: 'started'; timer: ActiveTimer; clockOffsetMs: number }
    | { type: 'stopping'; id: number; value: boolean }
    | { type: 'stopped'; id: number }
    | { type: 'updating'; id: number; value: boolean }
    | { type: 'updated'; id: number; description: string | null };

type TimerContextValue = TimerState & {
    refreshTimers: () => Promise<void>;
    startTimer: (payload: TimerStartPayload) => Promise<ActiveTimer>;
    stopTimer: (id: number) => Promise<void>;
    updateDescription: (id: number, value: string | null) => Promise<void>;
};

const initialState: TimerState = {
    timers: [],
    status: 'idle',
    error: null,
    clockOffsetMs: 0,
    starting: false,
    stopping: [],
    updating: [],
};

const TimerContext = createContext<TimerContextValue | null>(null);

function toggleId(ids: number[], id: number, value: boolean) {
    return value ? [...new Set([...ids, id])] : ids.filter((candidate) => candidate !== id);
}

function reducer(state: TimerState, action: TimerAction): TimerState {
    switch (action.type) {
        case 'loading':
            return { ...state, status: 'loading', error: null };
        case 'hydrated':
            return {
                ...state,
                timers: action.timers,
                status: 'ready',
                error: null,
                clockOffsetMs: action.clockOffsetMs,
            };
        case 'failed':
            return { ...state, status: 'error', error: action.message };
        case 'starting':
            return { ...state, starting: action.value, error: null };
        case 'started':
            return {
                ...state,
                starting: false,
                timers: [
                    ...state.timers.filter((timer) => timer.id !== action.timer.id),
                    action.timer,
                ].sort((a, b) => Date.parse(a.started_at) - Date.parse(b.started_at)),
                clockOffsetMs: action.clockOffsetMs,
            };
        case 'stopping':
            return { ...state, stopping: toggleId(state.stopping, action.id, action.value) };
        case 'stopped':
            return {
                ...state,
                stopping: state.stopping.filter((id) => id !== action.id),
                timers: state.timers.filter((timer) => timer.id !== action.id),
            };
        case 'updating':
            return { ...state, updating: toggleId(state.updating, action.id, action.value) };
        case 'updated':
            return {
                ...state,
                updating: state.updating.filter((id) => id !== action.id),
                timers: state.timers.map((timer) =>
                    timer.id === action.id ? { ...timer, description: action.description } : timer,
                ),
            };
    }
}

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function responseMessage(response: Response) {
    const body = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    return body?.message ?? Object.values(body?.errors ?? {})[0]?.[0] ?? 'Request failed.';
}

async function requestJson<T>(url: string, init?: RequestInit): Promise<T> {
    const response = await fetch(url, {
        ...init,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
            ...(init?.method && init.method !== 'GET' ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            ...init?.headers,
        },
    });

    if (response.status === 401 || response.status === 419) {
        window.location.reload();
        throw new Error('Your session has expired.');
    }

    if (!response.ok) {
        throw new Error(await responseMessage(response));
    }

    return (await response.json()) as T;
}

function offsetFrom(timer: ActiveTimer | undefined, requestStartedAt: number, receivedAt: number) {
    if (!timer) return 0;
    const serverNow = Date.parse(timer.server_now);
    if (!Number.isFinite(serverNow)) return 0;

    return serverNow - (requestStartedAt + receivedAt) / 2;
}

export function TimerProvider({ enabled, children }: PropsWithChildren<{ enabled: boolean }>) {
    const [state, dispatch] = useReducer(reducer, {
        ...initialState,
        status: enabled ? 'idle' : 'ready',
    });

    // Only the newest refresh may publish a result (refreshSeq), and a read that began
    // before a successful mutation may predate it (mutationSeq), so it is re-read instead
    // of applied. Laravel stays authoritative; nothing is merged client-side.
    const refreshSeq = useRef(0);
    const mutationSeq = useRef(0);

    const refreshTimers = useCallback(async () => {
        if (!enabled) return;
        const seq = ++refreshSeq.current;
        dispatch({ type: 'loading' });

        for (;;) {
            const mutationsAtStart = mutationSeq.current;
            const requestStartedAt = Date.now();

            try {
                const timers = await requestJson<ActiveTimer[]>(active.url());
                const receivedAt = Date.now();

                if (seq !== refreshSeq.current) return;
                if (mutationsAtStart !== mutationSeq.current) continue;

                dispatch({
                    type: 'hydrated',
                    timers,
                    clockOffsetMs: offsetFrom(timers[0], requestStartedAt, receivedAt),
                });

                return;
            } catch (error) {
                if (seq !== refreshSeq.current) return;

                dispatch({
                    type: 'failed',
                    message: error instanceof Error ? error.message : 'Timers are unavailable.',
                });

                return;
            }
        }
    }, [enabled]);

    useEffect(() => {
        void refreshTimers();
    }, [refreshTimers]);

    const startTimer = useCallback(
        async (payload: TimerStartPayload) => {
            dispatch({ type: 'starting', value: true });
            const requestStartedAt = Date.now();

            try {
                const timer = await requestJson<ActiveTimer>(start.url(), {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                const receivedAt = Date.now();
                mutationSeq.current += 1;
                dispatch({
                    type: 'started',
                    timer,
                    clockOffsetMs: offsetFrom(timer, requestStartedAt, receivedAt),
                });

                return timer;
            } catch (error) {
                dispatch({ type: 'starting', value: false });
                await refreshTimers();
                throw error;
            }
        },
        [refreshTimers],
    );

    const stopTimer = useCallback(
        async (id: number) => {
            dispatch({ type: 'stopping', id, value: true });

            try {
                await requestJson<{ id: number }>(stop.url(id), { method: 'POST' });
                mutationSeq.current += 1;
                dispatch({ type: 'stopped', id });
            } catch (error) {
                dispatch({ type: 'stopping', id, value: false });
                await refreshTimers();
                throw error;
            }
        },
        [refreshTimers],
    );

    const updateDescription = useCallback(
        async (id: number, value: string | null) => {
            dispatch({ type: 'updating', id, value: true });

            try {
                const timer = await requestJson<{ id: number; description: string | null }>(
                    description.url(id),
                    {
                        method: 'PATCH',
                        body: JSON.stringify({ description: value }),
                    },
                );
                mutationSeq.current += 1;
                dispatch({ type: 'updated', id: timer.id, description: timer.description });
            } catch (error) {
                dispatch({ type: 'updating', id, value: false });
                await refreshTimers();
                throw error;
            }
        },
        [refreshTimers],
    );

    const value = useMemo<TimerContextValue>(
        () => ({
            ...state,
            refreshTimers,
            startTimer,
            stopTimer,
            updateDescription,
        }),
        [refreshTimers, startTimer, state, stopTimer, updateDescription],
    );

    return <TimerContext.Provider value={value}>{children}</TimerContext.Provider>;
}

export function useTimers() {
    const context = useContext(TimerContext);

    if (!context) {
        throw new Error('useTimers must be used inside TimerProvider.');
    }

    return context;
}
