<?php

declare(strict_types=1);

namespace App\Infrastructure\I18n;

use IntlDateFormatter;
use NumberFormatter;

/**
 * Locale-aware number, currency, and date formatting (Req 20.4). Uses ext-intl
 * when available and degrades gracefully to portable fallbacks otherwise.
 */
final class LocaleFormatter
{
    /** Currency minor-unit digits for the currencies we display. */
    private const CURRENCY_SYMBOLS = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    public function __construct(private string $locale = 'en')
    {
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function number(float $value, int $decimals = 2): string
    {
        if (extension_loaded('intl')) {
            $fmt = new NumberFormatter($this->intlLocale(), NumberFormatter::DECIMAL);
            $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $out = $fmt->format($value);
            if ($out !== false) {
                return $out;
            }
        }
        return number_format($value, $decimals);
    }

    public function currency(float $amount, string $currency = 'INR'): string
    {
        if (extension_loaded('intl')) {
            $fmt = new NumberFormatter($this->intlLocale(), NumberFormatter::CURRENCY);
            $out = $fmt->formatCurrency($amount, $currency);
            if ($out !== false) {
                return $out;
            }
        }
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? ($currency . ' ');
        return $symbol . number_format($amount, 2);
    }

    /** Format a unix timestamp or date string for the current locale. */
    public function date(int|string $when, bool $withTime = false): string
    {
        $ts = is_int($when) ? $when : (int) strtotime($when);
        if (extension_loaded('intl')) {
            $fmt = new IntlDateFormatter(
                $this->intlLocale(),
                IntlDateFormatter::MEDIUM,
                $withTime ? IntlDateFormatter::SHORT : IntlDateFormatter::NONE,
            );
            $out = $fmt->format($ts);
            if ($out !== false) {
                return $out;
            }
        }
        return date($withTime ? 'M j, Y H:i' : 'M j, Y', $ts);
    }

    private function intlLocale(): string
    {
        return match ($this->locale) {
            'hi'    => 'hi_IN',
            'en'    => 'en_IN',
            default => $this->locale,
        };
    }
}
