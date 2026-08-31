<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Enums\LicenseKeyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ключ из собственного пула.
 *
 * Код хранится зашифрованным, а уникальность держится на детерминированном
 * SHA-256: шифртекст недетерминирован, и уникальным индексом его не защитить.
 * Поэтому code_hash — не оптимизация, а единственный способ физически запретить
 * один и тот же код в двух заказах (license_keys_code_hash_uq).
 *
 * @property int $id
 * @property int $product_id
 * @property string $code_encrypted расшифровывается кастом, в логи не попадает
 * @property string $code_hash
 * @property string $code_last4
 * @property LicenseKeyStatus $status
 * @property int|null $delivery_id
 * @property Carbon|null $reserved_at
 * @property Carbon|null $reserved_until
 * @property Carbon|null $issued_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product $product
 */
final class LicenseKey extends Model
{
    protected $table = 'license_keys';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'code_encrypted',
        'code_hash',
        'code_last4',
        'status',
        'delivery_id',
        'reserved_at',
        'reserved_until',
        'issued_at',
    ];

    /**
     * Код не должен утечь ни в лог, ни в дамп модели, ни в ответ API мимо Resource.
     *
     * @var list<string>
     */
    protected $hidden = ['code_encrypted', 'code_hash'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Предикат совпадает с частичным индексом license_keys_available_idx,
     * который покрывает ровно свободные строки и не растёт вместе с историей.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', LicenseKeyStatus::Available);
    }

    /**
     * Детерминированный отпечаток кода. Именно он, а не шифртекст, ложится
     * в уникальные индексы license_keys и deliveries.
     */
    public static function fingerprint(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function last4(string $code): string
    {
        return mb_substr($code, -4);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'delivery_id' => 'integer',
            'code_encrypted' => 'encrypted',
            'status' => LicenseKeyStatus::class,
            'reserved_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
