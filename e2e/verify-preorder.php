<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../apps/api/vendor/autoload.php';
$app = require __DIR__.'/../apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = json_decode(file_get_contents(__DIR__.'/test-results/po-order.json'), true, flags: JSON_THROW_ON_ERROR);
$order = DB::table('orders')->where('code', $expected['id'])->sole();
$invoice = DB::table('invoices')->where('order_id', $order->id)->sole();
$payment = DB::table('payment_intents')->where('order_id', $order->id)->sole();
$enrollment = DB::table('preorder_enrollments')->where('order_id', $order->id)->sole();
$item = DB::table('preorder_batch_items')->where('public_id', $expected['batch_item_id'])->sole();
$batch = DB::table('preorder_batches')->where('public_id', $expected['batch_id'])->sole();

if ($order->order_type !== 'preorder' || $order->order_status !== 'paid' || $order->payment_status !== 'partially_paid'
    || $order->total !== $expected['total']['amount_minor'] || $order->paid_at === null
    || $invoice->type !== 'deposit' || $invoice->status !== 'paid' || $invoice->paid_at === null
    || $invoice->code !== $expected['invoice']['id'] || $invoice->amount !== $expected['invoice']['total']['amount_minor']
    || $payment->code !== $expected['payment_intent']['id'] || $payment->provider !== 'fake' || $payment->status !== 'captured'
    || $payment->amount !== $invoice->amount || $payment->invoice_id !== $invoice->id || $payment->captured_at === null
    || $enrollment->state !== 'deposit_paid' || $enrollment->qty !== 1 || $enrollment->deposit_amount !== $invoice->amount
    || $enrollment->batch_item_id !== $item->id || $enrollment->batch_id !== $batch->id || $enrollment->user_id !== $order->user_id
    || $item->reserved_qty !== $expected['reserved_before'] + 1) {
    throw new RuntimeException('PO order, deposit invoice, Fake payment, enrollment or quota did not persist correctly.');
}

echo "PO database verified: enrollment deposit_paid, quota incremented once, deposit invoice paid, Fake payment captured.\n";
