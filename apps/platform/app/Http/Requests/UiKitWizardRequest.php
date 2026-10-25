<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Demo wizard of the UI kit catalog. The email rule only exists on the
 * server, so the catalog shows how a server error brings the wizard back
 * to the step of the field.
 */
class UiKitWizardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A date without time is a day of the workspace calendar, not of the
        // server clock (UTC): at night in America, UTC is already tomorrow...
        $today = now((string) (settings('general.timezone') ?: config('app.timezone')))->toDateString();

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'not_regex:/@example\.(com|org|net)$/i'],
            'company' => ['nullable', 'string', 'max:100'],
            'plan' => ['required', Rule::in(['starter', 'team', 'enterprise'])],
            'seats' => ['required', 'integer', 'min:1', 'max:500'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'channels' => ['array'],
            'channels.*' => [Rule::in(['email', 'chat', 'phone'])],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.not_regex' => __('Use a real address: example.com is reserved (rule checked only on the server).'),
        ];
    }
}
