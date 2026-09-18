export type AuthUser = {
    id: number;
    name: string;
    email: string;
};

export type AuthProps = {
    user: AuthUser | null;
    permissions: string[];
};

export type NavigationItem = {
    key: string;
    label: string;
    href: string;
    method: 'get' | 'post';
    visit: 'inertia' | 'document';
    activePatterns: string[];
    isActive: boolean;
    children: NavigationItem[];
};

export type NavigationGroup = {
    key: string;
    label: string | null;
    items: NavigationItem[];
};

export type FlashProps = {
    success: string | null;
    error: string | null;
    status: string | null;
    warning: string | null;
};

export type SharedPageProps = {
    app: {
        name: string;
    };
    auth: AuthProps;
    navigation: NavigationGroup[];
    flash: FlashProps;
};
