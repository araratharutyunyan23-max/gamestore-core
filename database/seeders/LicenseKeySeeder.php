<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\LicenseKeyStatus;
use App\Models\LicenseKey;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Пул из 50 тестовых ключей задания.
 *
 * Распределение по SKU выбрано так, чтобы состязательные сценарии
 * воспроизводились без подготовки данных руками:
 *   - KEY-EFT получает всего 2 ключа — это готовый стенд для критерия приёмки №6
 *     («пустой остаток → восстановимое состояние»): два заказа опустошают пул;
 *   - остальные SKU получают глубокий пул для проверки гонок, где важно,
 *     что 50 конкурентов заберут ровно один ключ, а не что ключи кончились.
 *
 * Счётчик остатка двигается ОДНИМ оператором вместе со вставкой ключей — это та
 * самая единая точка пополнения, мимо которой прямой INSERT запрещён
 * (CLAUDE.md §5.6): иначе счётчик занижается, и восстановление падает
 * на CHECK вместо того, чтобы довести заказ.
 */
final class LicenseKeySeeder extends Seeder
{
    /** @var list<string> */
    private const KEYS = [
        'LFXC-TNCS-BPCD', 'P3EI-W8UO-9B4K', 'FEL3-GUXN-TCCH', 'YPLV-QK2Z-IUS5', '0K9E-P1FR-BY1U',
        '5LZV-UQ48-RXCZ', 'X93K-NYAQ-GEC1', 'EIO5-CQT5-35KO', 'M58F-GIIR-VJAP', 'NU8Y-SWYB-6252',
        'OODW-CCHF-MBAF', 'DNA5-WFJM-NE49', 'QRDD-MJ3F-A8TF', 'TAT9-5ZJN-G1T2', 'LI39-4330-ISMB',
        'BKJY-8Q79-8NHI', 'HHW6-4RX2-DX62', '1RG2-L28O-O80G', 'EF63-F39X-MTEA', '8XS7-P53H-JKIV',
        'JPE6-MQV6-P7ST', 'SAPG-A2GR-0ULS', 'T2DU-IJ1S-U16P', 'WSSY-QTR7-Z57J', 'U74E-EPCI-CY26',
        'FZXF-58H8-OR93', 'FPSM-HLZA-TPAL', 'WSC9-28DJ-B2JE', 'P63J-F7UZ-DCYP', 'C7W2-D4C5-QMT7',
        'JESI-DFBH-LK1K', 'SGMA-JA0T-GR7D', '3PR4-OSY9-M3ZW', 'OMBE-C0JF-D45Y', 'KIKQ-FQJ8-9TI8',
        'LMAN-RSHS-AJDO', 'BAKI-VT1X-Z5OL', '9F0X-B46W-03FS', 'S423-V6YY-IBEM', 'D4UW-WYRA-20ST',
        'XC0J-CJ0H-09RN', 'RY1W-XCFJ-0KUA', 'CJYY-YKSQ-QE6H', '97AQ-38QJ-H8HU', 'FS8E-3S5Z-I6RA',
        'ARQK-FML4-A14E', '7Z6K-NO9V-MPJB', 'D4K7-IJSG-N853', 'W67T-ZB0Q-1XKB', '7EQM-K09J-XKUO',
    ];

    /**
     * Сколько ключей уходит каждому SKU. Сумма обязана равняться 50.
     *
     * @var array<string, int>
     */
    private const DISTRIBUTION = [
        'KEY-CS2-PRIME' => 12,
        'KEY-GTA5' => 10,
        'KEY-EFT' => 2,          // дефицитный намеренно: стенд для сценария out_of_stock
        'GIFT-PSN-1000' => 10,
        'GIFT-XBOX-1500' => 10,
        'GIFT-ROBLOX-800' => 6,
    ];

    public function run(): void
    {
        $this->assertDistributionCoversPool();

        $offset = 0;

        foreach (self::DISTRIBUTION as $sku => $count) {
            $product = Product::query()->where('sku', $sku)->firstOrFail();
            $codes = array_slice(self::KEYS, $offset, $count);
            $offset += $count;

            $inserted = $this->insertKeys($product->id, $codes);

            // Один оператор на весь пакет: счётчик не может разъехаться со
            // вставкой, потому что они в одной транзакции.
            if ($inserted > 0) {
                DB::table('product_stock')
                    ->where('product_id', $product->id)
                    ->update([
                        'available_count' => DB::raw('available_count + '.$inserted),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @param  list<string>  $codes
     * @return int сколько ключей реально добавлено (повторный прогон добавит 0)
     */
    private function insertKeys(int $productId, array $codes): int
    {
        return DB::transaction(function () use ($productId, $codes): int {
            $inserted = 0;

            foreach ($codes as $code) {
                $hash = LicenseKey::fingerprint($code);

                // Идемпотентность сидера держится на том же индексе, что и
                // защита «один код не уйдёт в два заказа», а не на отдельной проверке.
                $exists = DB::table('license_keys')->where('code_hash', $hash)->exists();

                if ($exists) {
                    continue;
                }

                LicenseKey::query()->create([
                    'product_id' => $productId,
                    'code_encrypted' => $code,
                    'code_hash' => $hash,
                    'code_last4' => LicenseKey::last4($code),
                    'status' => LicenseKeyStatus::Available,
                ]);

                $inserted++;
            }

            return $inserted;
        });
    }

    private function assertDistributionCoversPool(): void
    {
        $planned = array_sum(self::DISTRIBUTION);

        if ($planned !== count(self::KEYS)) {
            throw new RuntimeException(
                "Распределение покрывает {$planned} ключей из ".count(self::KEYS).'. '
                .'Пул из задания обязан разойтись целиком, иначе часть ключей недостижима.'
            );
        }
    }
}
