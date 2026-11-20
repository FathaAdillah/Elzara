<?php

declare(strict_types=1);

namespace App\Platform\Domain\Statuses;

/** Sumbu 4 dari empat, pemilik Procurement + Logistics; enumerasi ini keputusan Doc 09 §6, bukan Blueprint. */
enum ProcurementStatus: string
{
    case NotRequired = 'not_required';
    case Sourcing = 'sourcing';
    case Requisitioned = 'requisitioned';
    case PoIssued = 'po_issued';
    case SupplierConfirmed = 'supplier_confirmed';
    case InTransit = 'in_transit';
    case Customs = 'customs';
    case Received = 'received';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::NotRequired;
    }
}
