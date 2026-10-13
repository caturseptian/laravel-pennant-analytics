<?php

namespace CaturSeptian\PennantAnalytics\Support;

/**
 * A single tracked occurrence, fully resolved at request time.
 *
 * Everything that depends on request state (auth user, session, Pennant scope)
 * is resolved *before* a Hit is created, so a Hit can be safely serialized
 * into a queued job and persisted later by a worker without any context.
 */
final readonly class Hit
{
    public function __construct(
        public string $feature,
        public string $variant,
        public string $action,
        public string $subject,
        public string $date,
        public bool $isImpression,
    ) {}

    public function withVariant(string $variant): self
    {
        return new self($this->feature, $variant, $this->action, $this->subject, $this->date, $this->isImpression);
    }

    public function asImpression(string $action): self
    {
        return new self($this->feature, $this->variant, $action, $this->subject, $this->date, true);
    }

    /**
     * @return array{feature: string, variant: string, action: string, subject: string, date: string, impression: bool}
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'variant' => $this->variant,
            'action' => $this->action,
            'subject' => $this->subject,
            'date' => $this->date,
            'impression' => $this->isImpression,
        ];
    }

    /**
     * @param  array{feature: string, variant: string, action: string, subject: string, date: string, impression: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['feature'], $data['variant'], $data['action'], $data['subject'], $data['date'], $data['impression']);
    }
}
