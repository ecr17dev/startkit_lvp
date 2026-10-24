<?php

namespace Starter\Ai\Simulated;

use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * Deterministic embeddings without network: lexical feature hashing of the
 * normalized words (and their 4-letter stems) into the requested dimensions,
 * L2 normalized. Texts sharing words are close, so a local installation can
 * index and search for real. It is NOT a semantic model and is labelled as
 * simulated everywhere.
 */
class SimulatedEmbeddingGateway implements EmbeddingGateway
{
    /**
     * @param  array<int, mixed>  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        $dimensions = $dimensions > 0 ? $dimensions : 256;
        $tokens = 0;
        $embeddings = [];

        foreach ($inputs as $input) {
            $text = is_string($input) ? $input : '';
            $tokens += (int) ceil(mb_strlen($text) / 4);
            $embeddings[] = self::vector($text, $dimensions);
        }

        return new EmbeddingsResponse($embeddings, new Usage($tokens, 0), new Meta($provider->name(), $model));
    }

    /**
     * @return list<float>
     */
    public static function vector(string $text, int $dimensions): array
    {
        $vector = array_fill(0, $dimensions, 0.0);
        $normalized = mb_strtolower(self::stripAccents($text));

        preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized, $matches);

        foreach ($matches[0] as $word) {
            foreach (array_unique([$word, mb_substr($word, 0, 4)]) as $feature) {
                $hash = crc32($feature);
                $index = $hash % $dimensions;
                $vector[$index] += ($hash & 1) === 1 ? 1.0 : -1.0;
            }
        }

        $norm = sqrt(array_sum(array_map(fn (float $value) => $value * $value, $vector)));

        if ($norm == 0.0) {
            // Empty text: a fixed unit vector (dimension 0)...
            $vector[0] = 1.0;

            return array_values($vector);
        }

        return array_values(array_map(fn (float $value) => round($value / $norm, 6), $vector));
    }

    protected static function stripAccents(string $text): string
    {
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', 'ã' => 'a', 'õ' => 'o', 'â' => 'a', 'ê' => 'e', 'ô' => 'o',
        ]);
    }
}
