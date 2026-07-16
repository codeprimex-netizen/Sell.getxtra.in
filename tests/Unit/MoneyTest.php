<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Commerce\Money;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Money value object (Req 24.1) — the foundation of all
 * financial calculations, so it is held to a high coverage bar.
 */
final class MoneyTest extends TestCase
{
    public function testFromDecimalStoresMinorUnitsWithoutFloatDrift(): void
    {
        $this->assertSame(49999, Money::fromDecimal(499.99, 'INR')->minor);
        $this->assertSame(100000, Money::fromDecimal(1000.00)->minor);
    }

    public function testAddition(): void
    {
        $sum = Money::fromDecimal(499.99)->add(Money::fromDecimal(0.01));
        $this->assertSame(50000, $sum->minor);
    }

    public function testPercentage(): void
    {
        $this->assertSame(18000, Money::fromDecimal(1000)->percentage(18)->minor);
    }

    public function testSubtractClampsToZero(): void
    {
        $this->assertTrue(
            Money::fromDecimal(100)->subtract(Money::fromDecimal(150))->clampNonNegative()->isZero()
        );
    }

    public function testFormatUsesCurrencySymbol(): void
    {
        $this->assertSame('₹1,770.00', Money::fromDecimal(1770)->format());
    }
}
