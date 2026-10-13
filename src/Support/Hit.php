<?php

namespace CaturSeptian\PennantAnalytics\Support;

/**
 * A single tracked occurrence, fully resolved at request time.
 *
 * Everything that depends on request state (auth user, session, Pennant scope,
 * the clock) is resolved *before* a Hit is created, so a Hit can be serialized
 * into a queued job and persisted later by a worker with identical results.
 */
final readonly class Hit
{
    public function __construct(
        public string $feature,
        public string $variant,
        public string $action,
        public string $subject,
        /** Wall-clock time of the hit, "Y-m-d H:i:s" in the app timezone. */
        public string $occurredAt,
        public bool $isImpression,
    ) {}

    /**
     * The daily bucket ("Y-m-d") this hit is counted in.
     */
    public function date(): string
    {
        return substr($this->occurredAt, 0, 10);
    }

    public function withVariant(string $variant): self
    {
        return new self($this->feature, $variant, $this->action, $this->subject, $this->occurredAt, $this->isImpression);
    }

    public function asImpression(string $action): self
    {
        return new self($this->feature, $this->variant, $action, $this->subject, $this->occurredAt, true);
    }

    /**
     * @return array{feature: string, variant: string, action: string, subject: string, occurred_at: string, impression: bool}
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'variant' => $this->variant,
            'action' => $this->action,
            'subject' => $this->subject,
            'occurred_at' => $this->occurredAt,
            'impression' => $this->isImpression,
        ];
    }

    /**
     * @param  array{feature: string, variant: string, action: string, subject: string, occurred_at: string, impression: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['feature'], $data['variant'], $data['action'], $data['subject'], $data['occurred_at'], $data['impression']);
    }
}
