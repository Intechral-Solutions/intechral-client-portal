import { layoutPageProps } from '@/lib/inertia-layout';

it('returns the props of the page element a layout wraps', () => {
    const page = <div data-x="1" />;

    expect(layoutPageProps<{ 'data-x': string }>(page)).toEqual({ 'data-x': '1' });
});

it('returns undefined for the raw props object Inertia 3 probes a layout function with', () => {
    expect(layoutPageProps({ project: { id: 7 }, task: { title: 'x' } })).toBeUndefined();
    expect(layoutPageProps(undefined)).toBeUndefined();
    expect(layoutPageProps(null)).toBeUndefined();
});
