<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../apps/api/vendor/autoload.php';
$app = require __DIR__.'/../apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = json_decode(file_get_contents(__DIR__.'/test-results/rts-order.json'), true, flags: JSON_THROW_ON_ERROR);
$order = DB::table('orders')->where('code', $expected['id'])->sole();
$payment = DB::table('payment_intents')->where('order_id', $order->id)->sole();
$invoice = DB::table('invoices')->where('order_id', $order->id)->sole();
$reservations = DB::table('stock_reservations')->where('order_id', $order->id)->get();

if ($order->order_status !== 'paid' || $order->payment_status !== 'paid' || $order->paid_at === null
    || $order->total !== $expected['total']['amount_minor']
    || $payment->code !== $expected['payment_intent']['id'] || $payment->status !== 'captured'
    || $payment->provider !== 'fake' || $payment->captured_at === null || $payment->amount !== $order->total
    || $invoice->code !== $expected['invoice']['id'] || $invoice->status !== 'paid' || $invoice->paid_at === null
    || $invoice->amount !== $order->total || $payment->invoice_id !== $invoice->id
    || $reservations->isEmpty() || $reservations->contains(fn ($row) => $row->status !== 'committed' || $row->committed_at === null)) {
    throw new RuntimeException('RTS order, invoice, payment or stock reservation did not persist the successful Fake payment.');
}

echo "RTS database verified: order paid, invoice paid, Fake payment captured, stock reservations committed.\n";
