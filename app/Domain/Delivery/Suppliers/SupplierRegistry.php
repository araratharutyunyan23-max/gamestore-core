<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\Enums\SupplierName;
use App\Support\Cfg;

/**
 * Сборка клиентов поставщиков. A и B отличаются только адресом, поэтому
 * отдельных классов на каждого нет — это было бы дублирование ради симметрии.
 */
final class SupplierRegistry
{
    /** @var array<string, SupplierGateway> */
    private array $gateways = [];

    public function get(SupplierName $name): SupplierGateway
    {
        return $this->gateways[$name->value] ??= new RetryingSupplier(
            new HttpSupplierClient(
                $name,
                rtrim(Cfg::supplierUrl($name->value), '/'),
                Cfg::supplierConnectTimeout(),
                Cfg::supplierIssueTimeout(),
            ),
        );
    }
}
