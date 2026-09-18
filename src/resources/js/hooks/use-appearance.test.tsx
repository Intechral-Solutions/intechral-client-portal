import { act, renderHook } from '@testing-library/react';

import { useAppearance } from './use-appearance';

it('applies and persists appearance changes', () => {
    document.documentElement.dataset.theme = 'dark';
    const { result } = renderHook(() => useAppearance());

    expect(result.current.appearance).toBe('dark');
    act(() => result.current.toggleAppearance());

    expect(result.current.appearance).toBe('light');
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(localStorage.getItem('theme')).toBe('light');
});
