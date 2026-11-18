import type { GenerationStatus } from './generation';

/** All money values are exact decimal strings in USD: 9 places for provider costs, 10 for customer charges. */
export type UsageTotals = {
    billed_generations: number;
    failed_generations: number;
    customer_charges: string;
    provider_costs: string;
    gross_profit: string;
};

export type UsageReport = {
    summary: UsageTotals & {
        margin_percent: string | null;
        /** Succeeded generations with no recorded actual cost; counted as zero, never estimated. */
        unpriced_generations: number;
        created_generations: number;
        in_progress_generations: number;
    };
    /** Generations created in the range, by status. */
    statuses: Record<GenerationStatus, number>;
    daily: (UsageTotals & { date: string })[];
    users: (UsageTotals & { id: number; name: string; email: string })[];
};

export type UsageReportFilters = {
    start_date: string;
    end_date: string;
    max_days: number;
};

export type ReconciliationFigures = {
    generations: number;
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    cost: string;
};

export type ReconciliationDay = {
    date: string;
    matched: boolean;
    local: ReconciliationFigures;
    provider: ReconciliationFigures;
    /** Ours minus Pixelforge's. */
    cost_difference: string;
    generation_difference: number;
};

export type DiscrepancyType =
    | 'missing_locally'
    | 'status_mismatch'
    | 'cost_mismatch'
    | 'usage_mismatch'
    | 'date_mismatch'
    | 'not_reported_by_provider'
    | 'duplicate_at_provider';

export type Discrepancy = {
    type: DiscrepancyType;
    provider_generation_id: string | null;
    generation_uuid: string | null;
    user_email: string | null;
    message: string;
    local_value: string | null;
    provider_value: string | null;
};

export type Reconciliation = {
    status: 'matched' | 'differences' | 'unavailable';
    message: string | null;
    fetched_at: string | null;
    /** False when the generation list was too long to fetch in full. */
    complete: boolean;
    days: ReconciliationDay[];
    totals: {
        local_generations: number;
        provider_generations: number;
        local_cost: string;
        provider_cost: string;
        difference: string;
    } | null;
    discrepancies: Discrepancy[];
};
