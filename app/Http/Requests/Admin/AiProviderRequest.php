<?php

namespace App\Http\Requests\Admin;

use App\Ai\ProviderDriver;
use App\Models\AiProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AiProviderRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name') ?: $this->input('label');

        $this->merge(['name' => Str::slug((string) $name)]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var AiProvider|null $provider */
        $provider = $this->route('provider');

        return [
            'label' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('ai_providers', 'name')->ignore($provider?->id)],
            'driver' => ['required', Rule::enum(ProviderDriver::class)],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'default_model' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'import_models' => ['boolean'],
            'options' => ['array'],
            'options.region' => ['nullable', 'string', 'max:50'],
            'options.access_key_id' => ['nullable', 'string', 'max:255'],
            'options.secret_access_key' => ['nullable', 'string', 'max:255'],
            'options.session_token' => ['nullable', 'string', 'max:4000'],
            'options.api_version' => ['nullable', 'string', 'max:50'],
            'options.deployment' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Validate the fields required by the selected driver.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $driver = ProviderDriver::tryFrom((string) $this->input('driver'));

            if ($driver === null) {
                return;
            }

            /** @var AiProvider|null $provider */
            $provider = $this->route('provider');

            foreach ($driver->requiredFields() as $field) {
                $value = match ($field) {
                    'api_key' => $this->input('api_key') ?: $provider?->api_key,
                    'base_url' => $this->input('base_url'),
                    default => $this->input("options.{$field}"),
                };

                if (blank($value)) {
                    $validator->errors()->add(
                        in_array($field, ['api_key', 'base_url'], true) ? $field : "options.{$field}",
                        __('This field is required for :driver.', ['driver' => $driver->label()]),
                    );
                }
            }

            if ($driver->requiresModel() && blank($this->input('default_model'))) {
                $validator->errors()->add('default_model', __('This field is required for :driver.', ['driver' => $driver->label()]));
            }
        }];
    }
}
