import type { PaginationMeta } from './app';

export type DiagnosticCheck = {
    key: string;
    label: string;
    status: 'ok' | 'warning' | 'error' | 'skipped';
    message: string;
    sql?: string;
};

export type RagConnection = {
    id: number;
    name: string;
    label: string;
    kind: 'internal' | 'pgsql' | 'api';
    status: 'pending' | 'ready' | 'failed' | 'disabled';
    capabilities: string[];
    read_only: boolean;
    pgvector_version: string | null;
    diagnostics: {
        passed: boolean;
        pgvector: string | null;
        checks: DiagnosticCheck[];
        checked_at: string;
    } | null;
    checked_at: string | null;
};

export type RagConnectionDetail = RagConnection & {
    config: {
        host?: string;
        port?: number;
        database?: string;
        schema?: string;
        sslmode?: string;
        sslrootcert?: string;
        connect_timeout?: number;
        statement_timeout_ms?: number;
        read_only?: boolean;
        base_url?: string;
        timeout?: number;
        enforces_acl?: boolean;
    };
    credentials: Record<string, string | null>;
    active: boolean;
    collections: number;
    profiles: number;
};

export type RemoteCollectionOption = {
    id: string;
    name: string;
    description: string | null;
    documents: number | null;
};

export type RemoteCatalog = {
    isolated: boolean;
    collections: RemoteCollectionOption[] | null;
    error: string | null;
};

export type RemoteDocuments = {
    documents: {
        id: string;
        title: string;
        uri: string | null;
        updated_at: string | null;
    }[];
    next_page: number | null;
    page: number;
    error: string | null;
    available: boolean;
};

export type RagStatus = {
    feature: boolean;
    mode: string;
    available: boolean;
    connection: RagConnection | null;
};

export type EmbeddingProfile = {
    id: number;
    name: string;
    model_id: number | null;
    provider: string;
    model: string;
    dimensions: number;
    metric: 'cosine' | 'l2' | 'inner_product';
    normalize: boolean;
    batch_size: number;
    max_input_tokens: number;
    status: 'pending' | 'ready' | 'failed';
    error: string | null;
    storage: {
        table?: string;
        index?: string | null;
        exact?: boolean;
        pgvector?: string | null;
    } | null;
    simulated: boolean;
    collections: number;
};

export type EmbeddingModelOption = {
    id: number;
    label: string;
    connection: string;
    simulated: boolean;
};

export type Chunking = {
    target_tokens: number;
    overlap_tokens: number;
    max_tokens: number;
};

export type SearchSettings = {
    method: 'vector' | 'text' | 'hybrid';
    top_k: number;
    min_score: number | null;
    min_text_rank: number | null;
    max_context_tokens: number;
};

export type IndexVersionSummary = {
    id: number;
    number: number;
    status: 'building' | 'active' | 'superseded' | 'failed' | 'discarded';
    reason: string;
    profile: string;
    embedding_profile_id: number;
    chunking: Chunking;
    documents: number;
    chunks: number;
    tokens: number;
    cost: string | null;
    published_at: string | null;
    purged_at: string | null;
    created_at: string;
};

export type CollectionSummary = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    status: string;
    language: string;
    embedding_profile_id: number | null;
    profile: string | null;
    /** Collection id in the retrieval API (remote collections only). */
    external_id: string | null;
    remote: boolean;
    chunking: Chunking;
    search: SearchSettings;
    acl: string[];
    retention_days: number | null;
    sources: number;
    documents: number;
    documents_by_status: Record<string, number>;
    active_version: IndexVersionSummary | null;
    building_version: IndexVersionSummary | null;
    needs_reindex: boolean;
    open_jobs: number;
    connection: {
        id: number;
        label: string;
        kind: 'internal' | 'pgsql' | 'api';
        status: RagConnection['status'];
        read_only: boolean;
        capabilities: string[];
    };
    created_at: string;
};

export type RagJobSummary = {
    id: string;
    parent_id: string | null;
    type: string;
    status:
        | 'queued'
        | 'running'
        | 'retrying'
        | 'paused'
        | 'succeeded'
        | 'failed'
        | 'cancelled';
    stage: string | null;
    progress_done: number;
    progress_total: number | null;
    attempts: number;
    units: Record<string, number>;
    timings: Record<string, number>;
    estimate: {
        documents?: number;
        tokens?: number;
        cost?: string | null;
        needs_confirmation?: boolean;
    } | null;
    cost: string | null;
    max_cost: string | null;
    error: string | null;
    error_type: string | null;
    trigger: string;
    warnings: string[];
    source_id: number | null;
    document_id: number | null;
    children: number;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
};

export type SourceFile = {
    name: string;
    size: number | null;
    mime: string | null;
    uploaded_at: string | null;
};

export type SourceSummary = {
    id: number;
    collection_id: number;
    type: 'upload' | 'url' | 'sitemap' | 'adapter' | 'disk';
    name: string;
    config: {
        files?: SourceFile[];
        urls?: string[];
        url?: string;
        depth?: number;
        max_pages?: number;
        path_prefix?: string | null;
        adapter?: string;
        disk?: string;
        prefix?: string | null;
        max_files?: number;
    };
    acl: string[] | null;
    status: 'active' | 'paused' | 'deleting';
    schedule: string | null;
    next_sync_at: string | null;
    last_sync_at: string | null;
    last_error: string | null;
    documents: number;
    chunks: number;
    size: number;
    failed: number;
    cost: string | null;
    last_job: RagJobSummary | null;
    open_job: RagJobSummary | null;
};

export type DocumentSummary = {
    id: number;
    collection_id: number;
    source_id: number;
    source: string | null;
    title: string | null;
    uri: string | null;
    external_id: string;
    mime_type: string | null;
    status: string;
    content_hash: string | null;
    size: number;
    chunk_count: number;
    token_count: number;
    acl: string[] | null;
    error: string | null;
    indexed_at: string | null;
    updated_at: string;
};

export type KnowledgeOptions = {
    adapters: Record<string, string>;
    disks: string[];
    languages: string[];
    schedules: string[];
    limits: {
        max_file_mb: number;
        max_files: number;
        max_pages: number;
        max_depth: number;
    };
    automatic: boolean;
};

export type ChunkRow = {
    id: number;
    ordinal: number;
    content: string;
    token_count: number;
    metadata: {
        section?: string;
        start?: number;
        end?: number;
        page_start?: number;
        page_end?: number;
        title?: string;
        uri?: string;
        estimator?: string;
    };
};

export type DocumentDetail = DocumentSummary & {
    metadata: Record<string, unknown> | null;
    effective_acl: string[];
    etag: string | null;
    last_modified: string | null;
    collection: {
        id: number;
        name: string;
        slug: string;
        capabilities: string[];
    };
};

export type DocumentVersionDetail = {
    id: number;
    number: number;
    parser: string;
    content_hash: string;
    size: number;
    pages: { page: number; start: number; end: number }[] | null;
    warnings: string[] | null;
    has_original: boolean;
    content: string;
    truncated: boolean;
    is_current: boolean;
};

export type PaginatedDocuments = {
    data: DocumentSummary[];
    meta: PaginationMeta;
};

export type RetrievalHitRow = {
    rank: number;
    /** Absent for passages of a retrieval API (no local chunk or document). */
    chunk_id?: number;
    document_id?: number;
    external_id?: string;
    external_document_id?: string;
    collection_id: number;
    source_id: number;
    title: string;
    location?: string;
    uri?: string;
    section?: string;
    page_start?: number;
    page_end?: number;
    score: number;
    score_type: string;
    vector_score?: number;
    text_score?: number;
    token_count: number;
    content?: string;
    /** Stored trace hit (lab), for relevance judgements. */
    hit_id?: number | null;
};

export type RetrievalResultData = {
    run_id: string | null;
    method: string;
    hits: RetrievalHitRow[];
    latency_ms: number;
    embedding_ms: number | null;
    search_ms: number;
    cost: string | null;
    context_tokens: number;
    diagnostics: {
        candidates?: Record<string, number>;
        exact?: Record<string, boolean>;
    };
};

export type LabCollectionOption = {
    id: number;
    name: string;
    language: string;
    search: SearchSettings;
};

export type RetrievalRunSummary = {
    id: string;
    query: string;
    method: string;
    hits: number;
    latency_ms: number;
    cost: string | null;
    created_at: string | null;
};

export type EvaluationMetrics = {
    questions: number;
    recall_at_k: number;
    mrr: number;
    hit_rate: number;
    no_result_rate: number;
    avg_latency_ms: number;
    p95_latency_ms: number;
    cost: string | null;
};

export type EvaluationRunSummary = {
    id: string;
    label: string | null;
    settings: {
        method?: 'vector' | 'text' | 'hybrid';
        top_k?: number;
        min_score?: number;
        max_context_tokens?: number;
    };
    status: 'queued' | 'running' | 'completed' | 'failed';
    metrics: EvaluationMetrics | null;
    error: string | null;
    created_at: string;
    finished_at: string | null;
};

export type EvaluationSetSummary = {
    id: number;
    name: string;
    description: string | null;
    items: number;
    collections: string[];
    latest: EvaluationRunSummary | null;
};

export type DocumentRef = { id: number; title: string };

export type EvaluationItemRow = {
    id: number;
    question: string;
    expected_answer: string | null;
    expected: DocumentRef[];
};

export type EvaluationItemResult = {
    retrieved: DocumentRef[];
    first_relevant_rank: number | null;
    recall: number;
    reciprocal_rank: number;
    latency_ms: number;
};

export type KnowledgeReport = {
    window: { from: string; to: string; days: number };
    totals: {
        collections: number;
        sources: number;
        documents: number;
        processed: number;
        indexed: number;
        chunks: number;
        bytes: number;
        queries: number;
        avg_latency_ms: number;
        p95_latency_ms: number;
        no_result_rate: number;
        abstention_rate: number;
        embedding_cost: string;
        query_cost: string;
        unknown_cost_calls: number;
    };
    documents_by_status: {
        source_id: number;
        source: string;
        collection: string;
        counts: Record<string, number>;
        total: number;
    }[];
    ingestion_daily: { day: string; indexed: number; versions: number }[];
    collections: {
        id: number;
        name: string;
        documents: number;
        chunks: number;
        tokens: number;
        bytes: number;
    }[];
    queue: {
        by_status: Record<string, number>;
        open: number;
        stages: { stage: string; avg_ms: number; jobs: number }[];
    };
    errors: {
        by_type: { label: string; total: number }[];
        by_job: { label: string; total: number }[];
        by_provider: { label: string; total: number }[];
    };
    costs_daily: {
        day: string;
        embedding: number;
        rag: number;
        unknown: number;
    }[];
    queries_daily: {
        day: string;
        queries: number;
        avg_latency_ms: number;
        p95_latency_ms: number;
        empty: number;
        abstained: number;
    }[];
    outcomes: Record<string, number>;
    unanswered: { query: string; total: number; last_at: string }[];
    agents: {
        agent: string;
        answers: number;
        avg_citations: number;
        invalid: number;
        unsupported: number;
        abstained: number;
        positive: number;
        negative: number;
    }[];
    freshness: {
        source: string;
        collection: string;
        type: string;
        schedule: string | null;
        last_sync_at: string | null;
        age_hours: number | null;
        changed: number;
        deleted: number;
        unchanged: number;
    }[];
    evaluations: {
        set: string;
        set_id: number;
        label: string | null;
        metrics: EvaluationMetrics | null;
        finished_at: string | null;
    }[];
};
