<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\AddressData;
use App\Modules\Identity\Contracts\CustomerAddresses;
use App\Modules\Identity\Infrastructure\Models\Address;
use App\Platform\Domain\PublicId;
use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

final class AddressBook implements CustomerAddresses
{
    public function __construct(private readonly ActiveOrderAddressUsage $usage) {}

    public function owned(int $userId, string $publicId): AddressData
    {
        return $this->read($this->model($userId, $publicId));
    }

    private function model(int $userId, string $id): Address
    {
        try {
            $key = PublicId::decode($id, 'addr');
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return Address::query()->where('user_id', $userId)->where('is_archived', false)->findOrFail($key);
    }

    /** @return list<AddressData> */
    public function all(int $userId): array
    {
        return array_values(Address::query()->where('user_id', $userId)->where('is_archived', false)->orderByDesc('is_default')->orderBy('id')->get()->map(fn (Address $a): AddressData => $this->read($a))->all());
    }

    private function read(Address $a): AddressData
    {
        return new AddressData(PublicId::encode('addr', $a->id), $a->recipient, $a->phone, $a->province, $a->city, $a->district ?? '', $a->postal_code, $a->address_line, $a->is_default);
    }

    public function save(int $userId, Request $request, ?string $id = null): AddressData
    {
        $existing = $id === null ? null : $this->model($userId, $id);
        $input = $request->all();
        if (isset($input['phone']) && is_string($input['phone'])) {
            $phone = preg_replace('/[\s()-]/', '', $input['phone']);
            $input['phone'] = preg_replace('/^(?:\+62|62|0)/', '+62', $phone ?? '');
        }
        $required = $existing === null ? 'required' : 'sometimes|required';
        $validator = Validator::make($input, [
            'recipient' => $required.'|string|max:120', 'phone' => $required.'|string|regex:/^\\+628[0-9]{8,11}$/',
            'province' => $required.'|string|max:60', 'city' => $required.'|string|max:60', 'district' => $required.'|string|max:60',
            'postal_code' => $required.'|string|regex:/^[0-9]{5}$/', 'address_line' => $required.'|string|min:5|max:1000',
        ]);
        $validator->after(function (ValidationValidator $v) use ($input): void {
            foreach (array_diff(array_keys($input), ['recipient', 'phone', 'province', 'city', 'district', 'postal_code', 'address_line']) as $key) {
                $v->errors()->add($key, 'Field tidak dikenal.');
            }
        });
        if ($validator->fails()) {
            $problem = ApiProblem::make('order', 'address-invalid', 'Alamat tidak valid', 422, 'ADDRESS_INVALID');
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $problem = $problem->withError(['pointer' => '/'.$field, 'code' => 'invalid', 'detail' => $message]);
                }
            }
            $requestId = $request->attributes->get('request_id');
            throw new HttpResponseException($problem->forRequest($request, is_string($requestId) ? $requestId : '')->toResponse());
        }
        $data = [];
        foreach ($validator->validated() as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new \UnexpectedValueException('Invalid address data');
            }
            $data[$key] = $value;
        }

        return DB::transaction(function () use ($userId, $existing, $data): AddressData {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $address = $existing ?? new Address;
            $address->fill($data);
            $address->user_id = $userId;
            if ($existing === null) {
                $address->is_default = ! Address::query()->where('user_id', $userId)->where('is_archived', false)->exists();
            }
            $address->save();

            return $this->read($address);
        });
    }

    public function setDefault(int $userId, string $id): AddressData
    {
        return DB::transaction(function () use ($userId, $id): AddressData {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $address = $this->model($userId, $id);
            Address::query()->where('user_id', $userId)->whereKeyNot($address->id)->update(['is_default' => false]);
            $address->is_default = true;
            $address->save();

            return $this->read($address);
        });
    }

    public function archive(int $userId, string $id): void
    {
        DB::transaction(function () use ($userId, $id): void {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $address = $this->model($userId, $id);
            abort_if($this->usage->isUsedByActiveOrder($address->id), 409);
            $wasDefault = $address->is_default;
            $address->is_archived = true;
            $address->is_default = false;
            $address->save();
            if ($wasDefault) {
                $next = Address::query()->where('user_id', $userId)->where('is_archived', false)->orderBy('id')->first();
                if ($next !== null) {
                    $next->is_default = true;
                    $next->save();
                }
            }
        });
    }
}
