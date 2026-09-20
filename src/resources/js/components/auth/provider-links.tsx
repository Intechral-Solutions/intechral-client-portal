import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/sso';

type ProviderLinksProps = {
    invitation?: string;
};

export function ProviderLinks({ invitation }: ProviderLinksProps) {
    const options = invitation ? { query: { invitation } } : undefined;

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-3 text-xs text-muted-foreground">
                <span className="h-px flex-1 bg-border" />
                <span>or continue with</span>
                <span className="h-px flex-1 bg-border" />
            </div>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <Button asChild variant="outline">
                    <a href={redirect.url('google', options)}>Google</a>
                </Button>
                <Button asChild variant="outline">
                    <a href={redirect.url('microsoft', options)}>Microsoft</a>
                </Button>
            </div>
        </div>
    );
}
