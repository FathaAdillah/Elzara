<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validasi ketat: field di luar daftar aturan ditolak 422, bukan diabaikan diam-diam. */
final class CatalogInput
{
    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        $allowed = array_unique(array_map(static fn (string $key): string => explode('.', $key)[0], array_keys($rules)));
        $unknown = array_diff(array_map('strval', array_keys($input)), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_values($unknown), 'Field tidak dikenal.'));
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $rules)->validate();

        return $validated;
    }
}
