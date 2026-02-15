type Links = {
    active: boolean;
    label: string;
    page: number | undefined;
    url: string | undefined;
};

type Paginator<T> = {
    current_page: number;
    data: T[];
    first_page_url: string;
    from: number;
    last_page: number;
    last_page_url: string;
    links: Links[];
    next_page_url: string | undefined;
    path: string;
    per_page: number;
    prev_page_url: string | undefined;
    to: number;
    total: number;
};

export {
    type Paginator
}
