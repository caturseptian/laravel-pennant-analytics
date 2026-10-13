<?php

namespace CaturSeptian\PennantAnalytics\Support;

use InvalidArgumentException;

use function Illuminate\Support\enum_value;

/**
 * Typed, validated view of config/pennant-analytics.php.
 *
 * Built once when the engine is resolved, so a typo in the config file fails
 * loudly at boot instead of silently falling back to a default at runtime.
 */
final readonly class AnalyticsConfig
{
    /** Column widths from the migration; names are validated against them. */
    public const MAX_FEATURE_LENGTH = 150;

    public const MAX_ACTION_LENGTH = 100;

    /**
     * @param  list<string>  $autoImpressionFeatures  empty = every feature
     * @param  list<string>  $controlVariants
     */
    public function __construct(
        public bool $enabled,
        public Persistence $persistence,
        public ?string $queueConnection,
        public ?string $queueName,
        public string $impressionAction,
        public bool $autoImpressions,
        public array $autoImpressionFeatures,
        public float $confidence,
        public int $minSampleSize,
        public array $controlVariants,
    ) {
        if ($impressionAction === '' || mb_strlen($impressionAction) > self::MAX_ACTION_LENGTH) {
            throw new InvalidArgumentException('pennant-analytics.impression_action must be 1-'.self::MAX_ACTION_LENGTH.' characters.');
        }

        if ($confidence <= 0 || $confidence >= 1) {
            throw new InvalidArgumentException('pennant-analytics.statistics.confidence must be between 0 and 1 (exclusive).');
        }

        if ($minSampleSize < 0) {
            throw new InvalidArgumentException('pennant-analytics.statistics.min_sample_size must not be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $persistence = Persistence::tryFrom((string) ($config['persistence'] ?? 'deferred'))
            ?? throw new InvalidArgumentException('pennant-analytics.persistence must be one of: deferred, queue, sync.');

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            persistence: $persistence,
            queueConnection: self::nullableString($config['queue']['connection'] ?? null),
            queueName: self::nullableString($config['queue']['name'] ?? null),
            impressionAction: (string) ($config['impression_action'] ?? 'impression'),
            autoImpressions: (bool) ($config['auto_impressions']['enabled'] ?? false),
            autoImpressionFeatures: self::strings($config['auto_impressions']['features'] ?? []),
            confidence: (float) ($config['statistics']['confidence'] ?? 0.95),
            minSampleSize: (int) ($config['statistics']['min_sample_size'] ?? 100),
            controlVariants: self::strings($config['statistics']['control_variants'] ?? []),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_map(fn (mixed $value) => (string) enum_value($value), (array) $values));
    }
}
