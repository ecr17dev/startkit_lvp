export type DriverOption = {
    value: string;
    label: string;
    options: string[];
    credentials: string[];
    requires_api_key: boolean;
    requires_base_url: boolean;
    default_url: string | null;
    discovery: boolean;
};

export type AiConnection = {
    id: number;
    name: string;
    label: string;
    driver: string;
    driver_label: string;
    base_url: string | null;
    endpoint: string | null;
    credential_mode: 'stored' | 'env';
    secret_ref: string | null;
    masked_credentials: Record<string, string | null>;
    has_api_key: boolean;
    options: Record<string, string | null>;
    is_active: boolean;
    is_default: boolean;
    models_count: number | null;
    last_tested_at: string | null;
    last_test_status: string | null;
    last_test_message: string | null;
    credentials_rotated_at: string | null;
    discovery: boolean;
};

export type CapabilityState = {
    status: 'supported' | 'unsupported' | 'unknown';
    source: string | null;
    verified_at: string | null;
};

export type CurrentPrice = {
    amount: string;
    unit: string;
    source: string | null;
    effective_from: string;
};

export type AiCatalogModel = {
    id: number;
    model: string;
    label: string | null;
    name: string;
    provider: string | null;
    connection: { id: number; label: string; driver: string } | null;
    functions: string[] | null;
    input_modalities: string[] | null;
    output_modalities: string[] | null;
    context_window: number | null;
    max_output_tokens: number | null;
    status: 'unknown' | 'available' | 'unavailable' | 'deprecated';
    source: string;
    source_url: string | null;
    discovered_at: string | null;
    verified_at: string | null;
    last_error: string | null;
    is_enabled: boolean;
    is_default: boolean;
    capabilities: Record<string, CapabilityState>;
    prices: Record<string, CurrentPrice> | null;
};

export type ModelPriceRow = {
    id: number;
    component: string;
    unit: string;
    amount: string;
    currency: string;
    tier: string;
    source: { key: string; name: string; kind: string } | null;
    effective_from: string;
    effective_to: string | null;
    retrieved_at: string | null;
    notes: string | null;
    model?: { id: number; name: string; connection: string };
};

export type LedgerRequest = {
    id: string;
    created_at: string;
    feature: string;
    status: string;
    user: { id: number; name: string } | null;
    connection: string | null;
    provider: string | null;
    model: string | null;
    agent: string | null;
    input_tokens: number;
    output_tokens: number;
    cost: string | null;
    estimated_cost: string | null;
    reconciled_cost: string | null;
    cost_status:
        | 'pending'
        | 'estimated'
        | 'calculated'
        | 'unknown'
        | 'reconciled';
    attempts: number;
    latency_ms: number | null;
    error_type: string | null;
};

export type BudgetStatus = {
    period_key: string;
    spent: string;
    reserved: string;
    limit: string;
    percent: number;
    unknown_cost_requests: number;
};

export type Breakdown = {
    key: string | null;
    label: string;
    requests: number;
    tokens: number;
    cost: string;
    unknown: number;
    errors: number;
};
