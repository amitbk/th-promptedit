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

/** Customer cost range of a generation before it runs, markup included. Exact decimal strings in USD. */
export type GenerationEstimate = {
    customer_cost_min: string;
    /** The most it can cost; recorded as the generation's estimate. */
    customer_cost_max: string;
};

/** Response of POST /generations. */
export type CreatedGeneration = {
    uuid: string;
    status: GenerationStatus;
    estimated_provider_cost: string;
    estimated_customer_cost: string;
    provider_generation_id: string | null;
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
