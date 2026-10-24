export type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    locale: string | null;
    email_verified_at: string | null;
    two_factor_enabled: boolean;
    last_login_at: string | null;
    roles?: string[];
    created_at: string;
    updated_at: string;
};

export type AdminRole = {
    id: number;
    name: string;
    permissions?: string[];
    users_count?: number;
    created_at: string;
};

export type PermissionGroup = {
    group: string;
    permissions: { name: string; description: string }[];
};
