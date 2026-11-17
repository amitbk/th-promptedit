export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type GenerationHistoryItem = {
    uuid: string;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    /** Exact decimal string in USD; the most the generation can cost. */
    estimated_customer_cost: string;
    /** Exact decimal string in USD, once the generation is final. */
    actual_customer_cost: string | null;
    failure_reason: string | null;
    /** Authenticated URL of our stored copy, or null until it is stored. */
    image_url: string | null;
    /** Succeeded, but the image is still being stored. */
    image_pending: boolean;
    created_at: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/** Laravel's paginated resource collection. */
export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLink[];
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
};
