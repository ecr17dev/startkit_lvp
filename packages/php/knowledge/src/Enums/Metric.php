<?php

namespace Starter\Knowledge\Enums;

/**
 * Distance metric of an embedding profile, with its pgvector operator and
 * operator class. Scores are similarities (higher is better), not
 * probabilities, and are only comparable within the same profile.
 */
enum Metric: string
{
    case Cosine = 'cosine';
    case L2 = 'l2';
    case InnerProduct = 'inner_product';

    public function operator(): string
    {
        return match ($this) {
            self::Cosine => '<=>',
            self::L2 => '<->',
            self::InnerProduct => '<#>',
        };
    }

    public function operatorClass(): string
    {
        return match ($this) {
            self::Cosine => 'vector_cosine_ops',
            self::L2 => 'vector_l2_ops',
            self::InnerProduct => 'vector_ip_ops',
        };
    }

    /**
     * SQL turning the distance into a similarity score.
     */
    public function scoreExpression(string $distance): string
    {
        return match ($this) {
            self::Cosine => "1 - ({$distance})",
            self::L2 => "1 / (1 + ({$distance}))",
            self::InnerProduct => "-({$distance})",
        };
    }

    public function scoreType(): string
    {
        return match ($this) {
            self::Cosine => 'cosine_similarity',
            self::L2 => 'inverse_l2',
            self::InnerProduct => 'inner_product',
        };
    }
}
