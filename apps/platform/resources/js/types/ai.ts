export type DriverOption = {
    value: string;
    label: string;
    options: string[];
    credentials: string[];
    requires_api_key: boolean;
    requires_base_url: boolean;
    default_url: string | null;
    discovery: boolean;
    simulated: boolean;
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

export type AgentSummary = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    is_active: boolean;
    is_default: boolean;
    avatar: string | null;
    published_version: {
        id: number;
        number: number;
        published_at: string | null;
    } | null;
    draft: { id: number; number: number; updated_at: string } | null;
    tools: string[];
    surfaces: string[];
    roles: string[];
};

export type AgentConfig = {
    identity: {
        avatar: string | null;
        greeting: string | null;
        language: string;
        tone: string;
    };
    instructions: { system_prompt: string; variables: string[] };
    execution: {
        model_id: number | null;
        fallback_model_ids: number[];
        timeout: number;
        retries: number;
    };
    responses: {
        max_tokens: number | null;
        temperature: number | null;
        style: string;
    };
    context: { history_messages: number; retention_days: number | null };
    multimedia: {
        images: boolean;
        documents: boolean;
        max_files: number;
        max_file_mb: number;
        mime_types: string[];
    };
    microphone: {
        enabled: boolean;
        transcription_model_id: number | null;
        language: string | null;
        max_seconds: number;
        auto_send: boolean;
    };
    tools: { allowed: string[]; max_steps: number; confirm_writes: boolean };
    rag: { mode: string };
    access: { roles: string[]; surfaces: string[] };
    limits: {
        requests_per_minute: number | null;
        concurrent_per_user: number | null;
    };
};

export type AgentCheck = { errors: string[]; warnings: string[] };

export type AgentVersionRow = {
    id: number;
    number: number;
    status: 'draft' | 'published' | 'archived';
    notes: string | null;
    created_by: string | null;
    published_by: string | null;
    published_at: string | null;
    created_at: string;
    changes: string[];
};

export type AgentToolOption = {
    name: string;
    group: string;
    label: string;
    kind: 'read' | 'write' | 'sensitive';
    permission: string | null;
};

export type AgentModelOption = {
    id: number;
    label: string;
    connection: string;
    is_default?: boolean;
    capabilities?: Record<'tools' | 'vision' | 'documents', string>;
};

export type AgentOptions = {
    models: AgentModelOption[];
    transcription_models: AgentModelOption[];
    tools: AgentToolOption[];
    roles: string[];
    variables: string[];
    languages: string[];
    tones: string[];
    styles: string[];
    surfaces: string[];
    image_types: string[];
    document_types: string[];
};

export type AgentTestResult =
    | {
          ok: true;
          version: number;
          text: string;
          provider: string | null;
          model: string | null;
          latency_ms: number | null;
          input_tokens: number;
          output_tokens: number;
          cost: string | null;
          cost_status: string;
          tools: string[];
      }
    | { ok: false; version: number; error: string };

export type RecommendationCapability = { status: string; verified: boolean };

export type RecommendationCandidate = {
    id: number;
    label: string;
    model: string;
    connection: string;
    status: string;
    context_window: number | null;
    input_price: string | null;
    output_price: string | null;
    capabilities: Record<string, RecommendationCapability>;
    score: number;
    reasons: string[];
    rank?: number;
    source?: 'deterministic' | 'evaluator';
    explanation?: string;
};

export type RecommendationResult = {
    candidates: RecommendationCandidate[];
    recommendations: RecommendationCandidate[];
    source: 'deterministic' | 'evaluator';
    summary: string | null;
    evaluator: {
        model: string;
        request_id: string | null;
        cost: string | null;
        cost_status: string | null;
        valid: boolean;
    } | null;
    excluded: number;
};

export type LabModelOption = {
    id: number;
    label: string;
    connection: string;
    is_default: boolean;
};

export type LabRunSummary = {
    id: number;
    name: string;
    status: 'queued' | 'running' | 'completed' | 'cancelled';
    prompts: string[];
    system_prompt: string | null;
    max_tokens: number | null;
    max_cost: string;
    max_concurrency: number;
    spent: string;
    reserved: string;
    counts: {
        total: number;
        pending: number;
        succeeded: number;
        failed: number;
        skipped: number;
    };
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
};

export type LabResultRow = {
    id: number;
    model_id: number | null;
    prompt_index: number;
    status: 'pending' | 'running' | 'succeeded' | 'failed' | 'skipped';
    output: string | null;
    error: string | null;
    latency_ms: number | null;
    ttft_ms: number | null;
    input_tokens: number;
    output_tokens: number;
    estimated_cost: string | null;
    cost: string | null;
    cost_status: string | null;
    request_id: string | null;
};

export type LabRunDetail = LabRunSummary & {
    models: { id: number; label: string; connection: string | null }[];
    results: LabResultRow[];
};
