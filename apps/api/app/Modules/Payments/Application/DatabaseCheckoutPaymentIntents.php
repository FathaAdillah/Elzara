<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Contracts\CheckoutPaymentIntent;
use App\Modules\Payments\Contracts\CheckoutPaymentIntents;
use App\Modules\Payments\Contracts\PaymentProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class DatabaseCheckoutPaymentIntents implements CheckoutPaymentIntents
{
    public function __construct(private readonly PaymentProvider $provider) {}

    public function issue(int $order, int $invoice, int $amount, CarbonInterface $expires): CheckoutPaymentIntent
    {
        $code = 'pi_'.Str::ulid();
        $id = DB::table('payment_intents')->insertGetId([
            'code' => $code, 'order_id' => $order, 'invoice_id' => $invoice, 'amount' => $amount,
            'provider' => $this->provider->name(), 'provider_ref' => $this->provider->createIntent($code, $amount, 'IDR', $expires),
            'status' => 'requires_action', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return new CheckoutPaymentIntent($id, $code, $invoice, $this->provider->name(), 'requires_action', $expires->toImmutable());
    }

    public function lockForOrder(int $order): array
    {
        /** @var list<object{id:int, code:string, invoice_id:int, provider:string, status:string, expires_at:string}> $rows */
        $rows = DB::table('payment_intents')->where('order_id', $order)->orderBy('id')->lockForUpdate()->get()->all();

        return array_map(fn ($row): CheckoutPaymentIntent => new CheckoutPaymentIntent(
            $row->id, $row->code, $row->invoice_id, $row->provider, $row->status, CarbonImmutable::parse($row->expires_at),
        ), $rows);
    }

    public function capture(CheckoutPaymentIntent $intent, CarbonInterface $now): void
    {
        $this->transition($intent, ['status' => 'captured', 'captured_at' => $now, 'updated_at' => $now]);
    }

    public function expire(CheckoutPaymentIntent $intent, CarbonInterface $now): void
    {
        $this->transition($intent, ['status' => 'expired', 'updated_at' => $now]);
    }

    /** @param array<string,mixed> $values */
    private function transition(CheckoutPaymentIntent $intent, array $values): void
    {
        if (DB::table('payment_intents')->where('id', $intent->id)->where('provider', 'fake')
            ->where('status', 'requires_action')->update($values) !== 1) {
            throw new LogicException('Expected an actionable Fake payment intent.');
        }
    }
}
