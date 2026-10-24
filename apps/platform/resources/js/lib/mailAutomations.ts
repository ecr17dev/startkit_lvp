import type { Component } from 'vue';
import { CalendarClock, Hourglass, PartyPopper, Zap } from '@lucide/vue';
import { currentLocale, t } from '@/lib/i18n';

export type MailTrigger = 'user_created' | 'inactivity' | 'scheduled' | 'event';

export type MailAutomationSettings = {
    delay_minutes?: number;
    days?: number;
    role?: string;
    frequency?: 'once' | 'weekly' | 'monthly';
    date?: string;
    weekday?: number;
    day?: number;
    time?: string;
    timezone?: string;
    event?: string;
    recipients?: 'person' | 'role';
};

export type MailAutomation = {
    id: number;
    name: string;
    trigger: MailTrigger;
    settings: MailAutomationSettings;
    template_key: string;
    is_active: boolean;
};

export type MailEvent = {
    key: string;
    label: string;
    person: 'subject' | 'actor' | null;
};

/** The four triggers, with the template each one starts with. */
export const triggers: {
    value: MailTrigger;
    title: string;
    description: string;
    icon: Component;
    template: string;
}[] = [
    {
        value: 'user_created',
        title: 'Welcome email',
        description: 'When an account is created, right away or after a delay.',
        icon: PartyPopper,
        template: 'automation.welcome',
    },
    {
        value: 'inactivity',
        title: 'Inactivity',
        description: 'To users who have not logged in for a number of days.',
        icon: Hourglass,
        template: 'automation.inactivity',
    },
    {
        value: 'scheduled',
        title: 'Scheduled send',
        description: 'Once, every week or every month, to a role or everyone.',
        icon: CalendarClock,
        template: 'automation.announcement',
    },
    {
        value: 'event',
        title: 'System event',
        description: 'When something happens: a registration, a role change…',
        icon: Zap,
        template: 'automation.event',
    },
];

/** Names of the days, Monday (1) to Sunday (7), in the active locale. */
export const weekdays = () => {
    const format = new Intl.DateTimeFormat(currentLocale(), {
        weekday: 'long',
        timeZone: 'UTC',
    });

    // 2024-01-01 was a Monday...
    return [1, 2, 3, 4, 5, 6, 7].map((day) =>
        format.format(new Date(Date.UTC(2024, 0, day))),
    );
};

const audience = (role?: string) =>
    role ? t('role :role', { role }) : t('all active users');

/** One line that says when an automation sends and to whom. */
export function describeAutomation(
    automation: Pick<MailAutomation, 'trigger' | 'settings'>,
    events: MailEvent[],
): string {
    const settings = automation.settings;

    switch (automation.trigger) {
        case 'user_created':
            return settings.delay_minutes
                ? t('When an account is created, after :minutes minutes', {
                      minutes: String(settings.delay_minutes),
                  })
                : t('When an account is created, right away');
        case 'inactivity':
            return `${t('After :days days without logging in', {
                days: String(settings.days ?? 30),
            })} · ${audience(settings.role)}`;
        case 'scheduled': {
            const time = settings.time ?? '09:00';
            const when =
                settings.frequency === 'once'
                    ? t('On :date at :time', {
                          date: settings.date ?? '',
                          time,
                      })
                    : settings.frequency === 'weekly'
                      ? t('Every :weekday at :time', {
                            weekday:
                                weekdays()[(settings.weekday ?? 1) - 1] ?? '',
                            time,
                        })
                      : t('Day :day of every month at :time', {
                            day: String(settings.day ?? 1),
                            time,
                        });

            return `${when}${settings.timezone ? ` (${settings.timezone})` : ''} · ${audience(settings.role)}`;
        }
        case 'event': {
            const event =
                events.find((item) => item.key === settings.event)?.label ??
                settings.event ??
                '';

            return `${t('When: :event', { event })} · ${
                settings.recipients === 'person'
                    ? t('to the person involved')
                    : audience(settings.role)
            }`;
        }
    }
}
