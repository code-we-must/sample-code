<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

final class BobGoWayBillPayloadAssembler
{
    public function __invoke(string $reference): array
    {
        return [
            'tracking_references' => $reference,
            'paper_size' => 'A4',
            'waybill_size' => 'A5',
            'waybills_per_shipment' => '1',
            'show_order_items' => 'false',
            'show_email_address' => 'false',
        ];
    }
}
