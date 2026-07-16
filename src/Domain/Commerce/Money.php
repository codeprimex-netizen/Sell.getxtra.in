<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use InvalidArgumentException;

/**
 * Money value object stored as integer minor units (paise/cents) to avoid
 * floating-point drift in pricing and the ledger. Arithmetic is exact; only
 * conversion to/from decimal crosses the float boundary (with rounding).
 */
final class Money
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
    }

    public static function of(int $minor, string $currency = 'INR'): self
    {
        return new self($minor, strtoupper($currency));
    }

    /** Build from a decimal amount (e.g. 499.00) with correct rounding. */
    public static function fromDecimal(float|int|string $amount, string $currency = 'INR'): self
    {
        $minor = (int) round(((float) $amount) * 100);
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'INR'): self
    {
        return new self(0, strtoupper($currency));
    }

    public function decimal(): float
    {
        return $this->minor / 100;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiply by a scalar quantity (integer-safe). */
    public function times(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    /** Take a percentage of this amount, rounded to the nearest minor unit. */
    public function percentage(float $percent): self
    {
        return new self((int) round($this->minor * $percent / 100), $this->currency);
    }

    /** Clamp to zero if negative (e.g. a discount larger than the subtotal). */
    public function clampNonNegative(): self
    {
        return new self(max(0, $this->minor), $this->currency);
    }

    public function min(Money $other): self
    {
        $this->assertSameCurrency($other);
        return $this->minor <= $other->minor ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function greaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->minor > $other->minor;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function format(): string
    {
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[$this->currency] ?? ($this->currency . ' ');
        return $symbol . number_format($this->decimal(), 2);
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}."
            );
        }
    }
}
