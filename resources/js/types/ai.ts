export type ProviderDriverOption = {
    value: string;
    label: string;
    fields: string[];
    required: string[];
    default_url: string | null;
    requires_model: boolean;
};

export type AiProviderData = {
    id: number;
    name: string;
    label: string;
    driver: string;
    driver_label: string;
    base_url: string | null;
    default_model: string | null;
    is_active: boolean;
    is_default: boolean;
    api_key_masked: string | null;
    options: Record<string, string | boolean | null>;
    models_count?: number;
    month_cost?: number;
    month_calls?: number;
};

export type AiModelData = {
    id: number;
    model: string;
    label: string | null;
    input_price: string | null;
    output_price: string | null;
    cache_read_price: string | null;
    cache_write_price: string | null;
    context_window: number | null;
    is_active: boolean;
    is_priced: boolean;
};

export type SuggestedModel = Omit<
    AiModelData,
    | 'id'
    | 'is_active'
    | 'is_priced'
    | 'input_price'
    | 'output_price'
    | 'cache_read_price'
    | 'cache_write_price'
> & {
    input_price: number | null;
    output_price: number | null;
    cache_read_price: number | null;
    cache_write_price: number | null;
};
