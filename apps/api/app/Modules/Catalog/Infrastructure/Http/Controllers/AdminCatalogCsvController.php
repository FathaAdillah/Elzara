<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Http\Controllers;

use App\Modules\Catalog\Application\AdminContext;
use App\Modules\Catalog\Application\ProductCsv;
use App\Platform\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminCatalogCsvController
{
    public function __construct(private readonly ProductCsv $csv) {}

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
            'dry_run' => ['sometimes', 'in:true,false,1,0'],
            'reason' => ['sometimes', 'string', 'min:5', 'max:320'],
        ]);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Berkas CSV wajib.']);
        }
        $handle = fopen($file->getRealPath(), 'rb') ?: throw new RuntimeException('Berkas unggahan tidak dapat dibaca.');
        $dryRun = in_array($request->input('dry_run'), ['true', '1'], true);
        try {
            $result = $this->csv->import($handle, $dryRun, AdminContext::fromRequest($request, 'Import CSV katalog'));
        } finally {
            fclose($handle);
        }
        if ($result['errors'] !== []) {
            $problem = ApiProblem::make('validation', 'failed', 'Validasi gagal', 422, 'VALIDATION_FAILED', detail: 'Tidak ada baris yang disimpan; perbaiki semua galat lalu unggah ulang.');
            foreach (array_slice($result['errors'], 0, 500) as $error) {
                $problem = $problem->withError(['pointer' => "/rows/{$error['row']}/{$error['column']}", 'code' => $error['code'], 'detail' => $error['detail']]);
            }

            return $problem->forRequest($request, $request->attributes->getString('request_id'))->toResponse();
        }

        return response()->json(['data' => ['dry_run' => $dryRun, ...$result['summary']]], $dryRun ? 200 : 201);
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb') ?: throw new RuntimeException('Output CSV tidak tersedia.');
            $this->csv->export($output);
            fclose($output);
        }, 'catalog-products-'.Carbon::now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
