<?php

namespace App\Actions\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Pricing\PriceBook;

/**
 * Set (or stop) a manual price. A change creates a new version; costs
 * already recorded keep the price they were calculated with.
 */
class SetModelPrice
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'component' => ['required', Rule::enum(PriceComponent::class)],
            'unit' => ['nullable', Rule::enum(PriceUnit::class)],
            /** Price in USD per unit (e.g. per million tokens). Empty to stop applying the price. */
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'tier' => ['nullable', 'string', Rule::in(['standard', 'batch', 'priority', 'flex'])],
            /** Where the price comes from (URL of the price list, agreement...). */
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return string created|updated|unchanged|closed
     */
    public function handle(User $actor, AiModel $model, array $input): string
    {
        Gate::forUser($actor)->authorize('ai.models.manage');

        /** @var array{component: string, unit?: string|null, amount?: string|int|float|null, tier?: string|null, notes?: string|null} $data */
        $data = Validator::make($input, self::rules())->validate();

        $component = PriceComponent::from($data['component']);
        $tier = $data['tier'] ?? 'standard';
        $prices = app(PriceBook::class);

        if (! isset($data['amount']) || $data['amount'] === '') {
            $result = $prices->close($model, $component, $tier) ? 'closed' : PriceBook::UNCHANGED;
        } else {
            $result = $prices->set(
                $model,
                $component,
                (string) $data['amount'],
                PriceSource::manual(),
                isset($data['unit']) ? PriceUnit::from($data['unit']) : null,
                $tier,
                notes: $data['notes'] ?? null,
                createdBy: $actor->id,
            );
        }

        if ($result !== PriceBook::UNCHANGED) {
            audit('ai.prices.'.$result, $model, [
                'component' => $component->value,
                'amount' => isset($data['amount']) ? (string) $data['amount'] : null,
                'unit' => $data['unit'] ?? $component->defaultUnit()->value,
                'tier' => $tier,
            ], $actor);
        }

        return $result;
    }
}
