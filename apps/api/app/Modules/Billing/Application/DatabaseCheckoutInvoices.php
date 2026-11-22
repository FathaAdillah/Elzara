<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Contracts\CheckoutInvoices;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class DatabaseCheckoutInvoices implements CheckoutInvoices
{
    public function issue(int $order, int $amount, CarbonInterface $expires, string $type = 'balance'): array
    {
        $code = 'inv_'.Str::ulid();
        $id = DB::table('invoices')->insertGetId([
            'code' => $code, 'order_id' => $order, 'type' => $type, 'amount' => $amount,
            'due_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'code' => $code];
    }

    public function pay(int $invoice, CarbonInterface $now): void
    {
        $this->close($invoice, 'paid', 'paid_at', $now);
    }

    public function void(int $invoice, CarbonInterface $now): void
    {
        $this->close($invoice, 'void', 'voided_at', $now);
    }

    private function close(int $invoice, string $status, string $timestamp, CarbonInterface $now): void
    {
        if (DB::table('invoices')->where('id', $invoice)->where('status', 'open')
            ->update(['status' => $status, $timestamp => $now, 'updated_at' => $now]) !== 1) {
            throw new LogicException('Expected an open checkout invoice.');
        }
    }
}
