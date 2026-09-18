import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark';

const storageKey = 'theme';

function currentAppearance(): Appearance {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

function applyAppearance(appearance: Appearance) {
    document.documentElement.dataset.theme = appearance;
    localStorage.setItem(storageKey, appearance);
}

export function useAppearance() {
    const [appearance, setAppearanceState] = useState<Appearance>(currentAppearance);

    useEffect(() => {
        applyAppearance(appearance);
    }, [appearance]);

    const setAppearance = useCallback((next: Appearance) => {
        setAppearanceState(next);
    }, []);

    const toggleAppearance = useCallback(() => {
        setAppearanceState((value) => (value === 'dark' ? 'light' : 'dark'));
    }, []);

    return { appearance, setAppearance, toggleAppearance };
}
