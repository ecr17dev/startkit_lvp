<?php

namespace App\Actions\Mail;

use App\Mail\Automation\MailAutomations;
use App\Models\MailAutomation;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Administration\Mail\MailTemplates;

/**
 * Create or update a mail automation. Only the settings of its trigger are
 * kept: delay (welcome), days and role (inactivity), frequency, date or
 * day, time, time zone and role (scheduled), event and recipients (system
 * events).
 */
class SaveMailAutomation
{
    /**
     * Settings each trigger uses.
     *
     * @var array<string, list<string>>
     */
    public const SETTINGS = [
        'user_created' => ['delay_minutes'],
        'inactivity' => ['days', 'role'],
        'scheduled' => ['frequency', 'date', 'weekday', 'day', 'time', 'timezone', 'role'],
        'event' => ['event', 'recipients', 'role'],
    ];

    public const FREQUENCIES = ['once', 'weekly', 'monthly'];

    /**
     * @param  array<string, mixed>  $input  used to pick the rules of the trigger
     * @return array<string, mixed>
     */
    public static function rules(array $input = []): array
    {
        $trigger = $input['trigger'] ?? null;
        $settings = (array) ($input['settings'] ?? []);
        $role = ['nullable', 'string', Rule::exists('roles', 'name')];

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(MailAutomation::TRIGGERS)],
            'template_key' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! app(MailTemplates::class)->exists($value)) {
                    $fail(__('Choose an existing template.'));
                }
            }],
            'is_active' => ['boolean'],
            'settings' => ['array'],
        ];

        return [...$rules, ...match ($trigger) {
            'user_created' => [
                'settings.delay_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            ],
            'inactivity' => [
                'settings.days' => ['required', 'integer', 'min:1', 'max:365'],
                'settings.role' => $role,
            ],
            'scheduled' => [
                'settings.frequency' => ['required', Rule::in(self::FREQUENCIES)],
                'settings.date' => [Rule::requiredIf(($settings['frequency'] ?? null) === 'once'), 'nullable', 'date_format:Y-m-d'],
                'settings.weekday' => [Rule::requiredIf(($settings['frequency'] ?? null) === 'weekly'), 'nullable', 'integer', 'min:1', 'max:7'],
                'settings.day' => [Rule::requiredIf(($settings['frequency'] ?? null) === 'monthly'), 'nullable', 'integer', 'min:1', 'max:28'],
                'settings.time' => ['required', 'date_format:H:i'],
                'settings.timezone' => ['nullable', 'timezone:all'],
                'settings.role' => $role,
            ],
            'event' => [
                'settings.event' => ['required', Rule::in(array_keys(MailAutomations::EVENTS))],
                'settings.recipients' => ['required', Rule::in(['person', 'role']), function (string $attribute, mixed $value, Closure $fail) use ($settings) {
                    if ($value === 'person' && (MailAutomations::EVENTS[$settings['event'] ?? '']['person'] ?? null) === null) {
                        $fail(__('This event is not about a person: send it to a role.'));
                    }
                }],
                'settings.role' => [Rule::requiredIf(($settings['recipients'] ?? null) === 'role'), ...$role],
            ],
            default => [],
        }];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, ?MailAutomation $automation, array $input): MailAutomation
    {
        Gate::forUser($actor)->authorize('mail.manage');

        $data = Validator::make($input, self::rules($input))->validate();
        $settings = $this->settings($data['trigger'], (array) ($data['settings'] ?? []));

        $automation ??= new MailAutomation(['created_by' => $actor->id]);
        $automation->fill([
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'template_key' => $data['template_key'],
            'settings' => $settings,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        audit('mail.automation_saved', null, [
            'automation' => $automation->id,
            'name' => $automation->name,
            'trigger' => $automation->trigger,
            'template' => $automation->template_key,
            'active' => $automation->is_active,
        ], $actor);

        return $automation;
    }

    /**
     * The settings of the trigger, typed; the others are dropped.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function settings(string $trigger, array $settings): array
    {
        $settings = Arr::only($settings, self::SETTINGS[$trigger]);

        if ($trigger === 'scheduled') {
            $settings = Arr::except($settings, array_diff(['date', 'weekday', 'day'], [match ($settings['frequency']) {
                'once' => 'date',
                'weekly' => 'weekday',
                default => 'day',
            }]));
        }

        if ($trigger === 'event' && ($settings['recipients'] ?? null) === 'person') {
            unset($settings['role']);
        }

        foreach (['delay_minutes', 'days', 'weekday', 'day'] as $integer) {
            if (isset($settings[$integer])) {
                $settings[$integer] = (int) $settings[$integer];
            }
        }

        return array_filter($settings, fn (mixed $value) => $value !== null && $value !== '');
    }
}
