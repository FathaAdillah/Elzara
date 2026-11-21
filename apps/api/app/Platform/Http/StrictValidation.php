<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class StrictValidation
{
    /**
     * @param  array<string, string>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        $problem = ApiProblem::make('validation', 'failed', 'Validasi gagal', 422, 'VALIDATION_FAILED');
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        foreach ($unknown as $field) {
            $problem = $problem->withError([
                'pointer' => '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $field),
                'code' => 'unknown_field',
                'detail' => 'Field tidak dikenal.',
            ]);
        }
        if ($unknown !== []) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $problem = $problem->withError(['pointer' => '/'.$field, 'code' => 'invalid', 'detail' => $message]);
                }
            }
            throw new HttpResponseException($problem->forRequest($request, AttachRequestId::getOrAssign($request))->toResponse());
        }

        $validated = [];
        foreach ($validator->validate() as $field => $value) {
            if (is_string($field)) {
                $validated[$field] = $value;
            }
        }

        return $validated;
    }
}
