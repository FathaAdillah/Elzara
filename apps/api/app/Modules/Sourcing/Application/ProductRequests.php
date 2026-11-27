<?php

declare(strict_types=1);

namespace App\Modules\Sourcing\Application;

use App\Platform\Http\CursorPagination;
use App\Platform\Http\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** @phpstan-type Fields array{title: string, quantity: int, target_price?: array{amount_minor: int}, brand?: ?string, series?: ?string, character_name?: ?string, target_url?: ?string, origin_country?: ?string, preferred_mode?: ?string, notes?: ?string} */
final class ProductRequests
{
    public const STATUSES = ['new', 'in_review', 'quoted', 'accepted', 'declined', 'expired', 'withdrawn'];

    /**
     * @param  Fields  $fields
     * @return array<string, mixed>
     */
    public function submit(int $customer, array $fields): array
    {
        $submitted = CarbonImmutable::now('UTC');
        $due = $submitted->setTimezone('Asia/Jakarta')->addWeekdays(2)->utc();
        $code = 'prq_'.Str::ulid();
        $row = [
            'code' => $code,
            'user_id' => $customer,
            'product_name_wanted' => $fields['title'],
            'desired_qty' => $fields['quantity'],
            'budget_max' => $fields['target_price']['amount_minor'] ?? null,
            'currency_code' => 'IDR',
            'brand' => $fields['brand'] ?? null,
            'series' => $fields['series'] ?? null,
            'character_name' => $fields['character_name'] ?? null,
            'target_url' => $fields['target_url'] ?? null,
            'origin_country' => $fields['origin_country'] ?? null,
            'preferred_mode' => $fields['preferred_mode'] ?? null,
            'notes' => $fields['notes'] ?? null,
            'status' => 'new',
            'requested_at' => $submitted->format('Y-m-d H:i:sP'),
            'sla_due_at' => $due->format('Y-m-d H:i:sP'),
        ];
        DB::table('product_requests')->insert($row);

        return $this->present((object) $row);
    }

    /** @return array<string, mixed> */
    public function forCustomer(Request $request, int $customer): array
    {
        return $this->page($request, $customer, null, null);
    }

    /** @return array<string, mixed> */
    public function forStaff(Request $request, ?string $status, ?string $account): array
    {
        return $this->page($request, null, $status, $account === null ? null : Str::lower($account));
    }

    /** @return array<string, mixed> */
    private function page(Request $request, ?int $customer, ?string $status, ?string $account): array
    {
        $query = DB::table('product_requests')->select('product_requests.*');
        if ($customer !== null) {
            $query->where('user_id', $customer);
        } else {
            $accounts = DB::table('users')->select('id as account_id', 'name as customer_name', 'email as customer_email');
            $query->joinSub($accounts, 'accounts', 'accounts.account_id', '=', 'product_requests.user_id')
                ->addSelect('accounts.customer_name', 'accounts.customer_email');
            if ($status !== null) {
                $query->where('product_requests.status', $status);
            }
            if ($account !== null) {
                $query->where('accounts.customer_email', $account);
            }
        }

        $page = CursorPagination::paginate($request, $query, ['requested_at' => 'desc', 'id' => 'desc'], [
            'resource' => 'product_requests', 'customer' => $customer, 'status' => $status, 'account' => $account,
        ]);
        $data = array_map(function (stdClass $row) use ($customer): array {
            $request = $this->present($row);
            if ($customer === null) {
                $request['customer_name'] = $row->customer_name;
                $request['customer_email'] = $row->customer_email;
            }

            return $request;
        }, $page['data']);

        return ['data' => $data, 'meta' => $page['meta']];
    }

    /** @return array<string, mixed> */
    private function present(stdClass $row): array
    {
        $budget = $row->budget_max;
        $submitted = $row->requested_at;
        $due = $row->sla_due_at;
        assert($budget === null || is_int($budget) || is_string($budget));
        assert(is_string($submitted) && ($due === null || is_string($due)));

        return [
            'id' => $row->code,
            'title' => $row->product_name_wanted,
            'quantity' => $row->desired_qty,
            'target_price' => $budget === null ? null : ['amount_minor' => (int) $budget, 'currency' => $row->currency_code],
            'brand' => $row->brand,
            'series' => $row->series,
            'character_name' => $row->character_name,
            'target_url' => $row->target_url,
            'origin_country' => $row->origin_country,
            'preferred_mode' => $row->preferred_mode,
            'notes' => $row->notes,
            'status' => $row->status,
            'created_at' => WireTime::format(CarbonImmutable::parse($submitted, 'UTC')),
            'sla_due_at' => $due === null ? null : WireTime::format(CarbonImmutable::parse($due, 'UTC')),
        ];
    }
}
