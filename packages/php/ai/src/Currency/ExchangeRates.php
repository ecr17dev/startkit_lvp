<?php

namespace Starter\Ai\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Starter\Ai\Models\ExchangeRate;

/**
 * Display conversion of USD amounts. The ledger keeps USD; a converted
 * amount always travels with its rate, date and source.
 */
class ExchangeRates
{
    /**
     * Fetch the "dólar observado" published by the Central Bank of Chile
     * through mindicador.cl (public, verifiable).
     */
    public function fetchClp(): ExchangeRate
    {
        $response = Http::timeout(15)->acceptJson()->get('https://mindicador.cl/api/dolar')->throw();
        $latest = (array) ($response->json('serie.0') ?? []);

        if (! is_numeric($latest['valor'] ?? null) || ! is_string($latest['fecha'] ?? null)) {
            throw new RuntimeException('Unexpected response from mindicador.cl');
        }

        return $this->store('CLP', (string) $latest['valor'], CarbonImmutable::parse($latest['fecha'])->toDateString(), 'mindicador.cl');
    }

    public function store(string $quote, string $rate, string $date, string $source): ExchangeRate
    {
        return ExchangeRate::query()->updateOrCreate(
            ['base' => 'USD', 'quote' => strtoupper($quote), 'rate_date' => $date, 'source' => $source],
            ['rate' => (string) BigDecimal::of($rate), 'fetched_at' => now()],
        );
    }

    /**
     * @return array{currency: string, amount: string|null, rate: string|null, rate_date: string|null, source: string|null}
     */
    public function convert(?string $usd, string $currency): array
    {
        if (strtoupper($currency) === 'USD') {
            return ['currency' => 'USD', 'amount' => $usd, 'rate' => null, 'rate_date' => null, 'source' => null];
        }

        $rate = ExchangeRate::latestFor(strtoupper($currency));

        return [
            'currency' => strtoupper($currency),
            'amount' => $usd !== null && $rate !== null ? (string) BigDecimal::of($usd)->multipliedBy((string) $rate->rate)->toScale(2, RoundingMode::HalfUp) : null,
            'rate' => $rate !== null ? (string) $rate->rate : null,
            'rate_date' => $rate?->rate_date->toDateString(),
            'source' => $rate?->source,
        ];
    }
}
