<?php

namespace Tests\Unit;

use App\Services\MoneyParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyParserTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_it_converts_dollars_to_cents(string|int|float $input, int $expected): void
    {
        $this->assertSame($expected, (new MoneyParser)->toCents($input));
    }

    public static function validAmounts(): array
    {
        return [
            'plain dollars' => ['12', 1200],
            'dollars and cents' => ['19.99', 1999],
            'thousands separator' => ['1,000.50', 100050],
            'dollar sign' => ['$12', 1200],
            'surrounding whitespace' => [' 7.1 ', 710],
            'single decimal' => ['7.1', 710],
            'leading decimal point' => ['.5', 50],
            'small cents' => ['0.05', 5],
            'millions' => ['1,000,000.99', 100000099],
            'int input' => [20, 2000],
            'float that is imprecise in binary' => [19.99, 1999],
            'float with one decimal' => [0.1, 10],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_invalid_amounts(string|int|float|null $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MoneyParser)->toCents($input);
    }

    public static function invalidAmounts(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'letters' => ['abc'],
            'negative' => ['-5'],
            'three decimals' => ['12.345'],
            'bad grouping' => ['1,00'],
            'scientific notation' => ['1e5'],
            'only a dollar sign' => ['$'],
            'only a decimal point' => ['.'],
            'too large' => ['9999999999999'],
            'float overflow' => [1.0E+25],
            'negative float' => [-1.5],
        ];
    }
}
