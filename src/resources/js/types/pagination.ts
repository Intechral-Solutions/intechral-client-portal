export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/** Laravel's length-aware paginator as serialized by `->paginate()`. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: PaginationLink[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
