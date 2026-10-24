export type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    is_owner?: boolean;
    locale: string | null;
    email_verified_at: string | null;
    two_factor_enabled: boolean;
    has_password: boolean;
    last_login_at: string | null;
    disabled_at: string | null;
    roles?: string[];
    created_at: string;
    updated_at: string;
};

export type AdminRole = {
    id: number;
    name: string;
    is_system: boolean;
    permissions?: string[];
    users_count?: number;
    created_at: string;
};

export type PermissionGroup = {
    group: string;
    permissions: { name: string; description: string }[];
};

export type InvitationStatus = 'pending' | 'accepted' | 'revoked' | 'expired';

export type Invitation = {
    id: number;
    email: string;
    role: string;
    status: InvitationStatus;
    invited_by: string | null;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    last_sent_at: string | null;
    sends: number;
    created_at: string;
};

export type BrowserSession = {
    id: string;
    ip_address: string | null;
    browser: string;
    platform: string;
    last_active_at: string;
    current: boolean;
    user?: { id: number; name: string; email: string };
};

export type AuditEvent = {
    id: number;
    action: string;
    channel: 'web' | 'api' | 'cli' | 'chat' | 'system';
    actor: { id: number; name: string | null } | null;
    subject_type: string | null;
    subject_id: string | null;
    properties: Record<string, unknown>;
    ip_address: string | null;
    request_id: string | null;
    created_at: string;
};
