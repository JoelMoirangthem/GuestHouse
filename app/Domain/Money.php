<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Rupee amounts as 2-decimal strings ("1200.00"), multiplied exactly.
 *
 * Replaces the bcmath multiply function: the bcmath extension is not present
 * on every server (it is
 * missing from the stock php Docker image), and a missing extension crashed
 * room allotment with a 500. Working in whole paise with integers is exact for
 * any tariff this system will see, and needs no extension.
 */
final class Money
{
    /** rate × nights, e.g. times('1200.00', 3) === '3600.00' */
    public static function times(string|int|float|null $rate, int $nights): string
    {
        $paise = self::toPaise($rate) * $nights;

        return self::format($paise);
    }

    private static function toPaise(string|int|float|null $amount): int
    {
        $s = trim((string) ($amount ?? '0'));
        $negative = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        [$whole, $fraction] = array_pad(explode('.', $s, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction), 2, '0'), 0, 2);

        $paise = (int) preg_replace('/\D/', '', $whole) * 100 + (int) $fraction;

        return $negative ? -$paise : $paise;
    }

    private static function format(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);

        return sprintf('%s%d.%02d', $sign, intdiv($paise, 100), $paise % 100);
    }
}
