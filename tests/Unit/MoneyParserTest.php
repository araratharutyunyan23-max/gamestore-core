<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Payments\Exceptions\UnparsableAmount;
use App\Domain\Payments\Support\MoneyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Единственное место, где сумма из внешнего мира превращается в копейки.
 *
 * Ошибка здесь стоит расхождения в 100 раз и блокирует все выдачи по
 * amount_mismatch, поэтому разбор покрыт отдельно от всего остального.
 */
final class MoneyParserTest extends TestCase
{
    #[Test]
    #[DataProvider('validAmounts')]
    public function it_converts_major_units_to_minor(mixed $raw, int $expected): void
    {
        self::assertSame($expected, MoneyParser::majorToMinor($raw));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function validAmounts(): array
    {
        return [
            'целое из контракта' => [500, 50000],
            'ноль' => [0, 0],
            'строка без дробной части' => ['1290', 129000],
            'строка с копейками' => ['299.99', 29999],
            'строка с одним знаком' => ['10.5', 1050],
            'отрицательная' => ['-100.25', -10025],
        ];
    }

    #[Test]
    public function it_refuses_a_float_outright(): void
    {
        // Деньги во float недопустимы ни в каком виде: 0.1 + 0.2 != 0.3,
        // и расхождение всплывёт не здесь, а в сверке через неделю.
        $this->expectException(UnparsableAmount::class);
        $this->expectExceptionMessageMatches('/float/');

        MoneyParser::majorToMinor(500.0);
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function it_refuses_anything_it_cannot_parse_exactly(mixed $raw): void
    {
        $this->expectException(UnparsableAmount::class);

        MoneyParser::majorToMinor($raw);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'null' => [null],
            'массив' => [[500]],
            'булево' => [true],
            'мусор' => ['пятьсот'],
            'пустая строка' => [''],
            'три знака после точки' => ['1.005'],
            'с пробелом' => [' 500'],
        ];
    }
}
