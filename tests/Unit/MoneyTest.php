<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_multiplies_a_rate_by_nights_exactly(): void
    {
        $this->assertSame('3600.00', Money::times('1200.00', 3));
        $this->assertSame('0.00', Money::times('0.00', 5));
        $this->assertSame('0.00', Money::times(null, 2));
        $this->assertSame('2500.50', Money::times('1250.25', 2));
        $this->assertSame('3.30', Money::times('1.10', 3));   // float would give 3.3000000000000003
        $this->assertSame('1500.00', Money::times(1500, 1));
        $this->assertSame('99999999.00', Money::times('99999999', 1));
    }

    #[Test]
    public function the_application_no_longer_needs_the_bcmath_extension(): void
    {
        $dir = dirname(__DIR__, 2).'/app';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bbc(add|sub|mul|div|mod|pow|sqrt|comp|scale)\s*\(/',
                    (string) file_get_contents($file->getPathname()),
                    $file->getPathname().' uses bcmath, which is not installed on every server.',
                );
            }
        }
    }
}
