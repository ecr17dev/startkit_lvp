import { t } from '@/lib/i18n';

const labels: Record<string, string> = {
    'auth.login': 'Logged in',
    'auth.registered': 'Signed up',
    'auth.password_changed': 'Changed the password',
    'owner.created': 'Owner created from the CLI',
    'owner.promoted': 'Promoted to owner from the CLI',
    'onboarding.completed': 'Completed the onboarding',
    'users.created': 'User created',
    'users.updated': 'User updated',
    'users.disabled': 'User disabled',
    'users.enabled': 'User enabled',
    'users.deleted': 'User deleted',
    'sessions.revoked': 'Sessions revoked',
    'roles.created': 'Role created',
    'roles.updated': 'Role updated',
    'roles.deleted': 'Role deleted',
    'invitations.created': 'Invitation sent',
    'invitations.resent': 'Invitation sent again',
    'invitations.revoked': 'Invitation revoked',
    'invitations.accepted': 'Invitation accepted',
    'settings.updated': 'Settings updated',
    'api_tokens.created': 'API token created',
    'api_tokens.revoked': 'API token revoked',
};

/**
 * Human readable name of an audit action (falls back to the action key).
 */
export function auditActionLabel(action: string): string {
    return labels[action] ? t(labels[action]) : action;
}

export const auditChannels = ['web', 'api', 'cli', 'chat', 'system'] as const;
