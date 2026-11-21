<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Http;

use App\Modules\Content\Application\Commands\FinalizeMediaUpload;
use App\Modules\Content\Application\Commands\PresignMediaUpload;
use App\Modules\Content\Application\Commands\SignMediaDownload;
use App\Modules\Content\Application\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MediaController
{
    public function __construct(private readonly MediaService $media) {}

    public function presign(Request $request): JsonResponse
    {
        /** @var array{bucket: string, mime: string, kind: string, size_bytes: int} $data */
        $data = $request->validate([
            'bucket' => 'required|string', 'mime' => 'required|string',
            'kind' => 'required|in:image,file,icon,label_pdf,proof,attachment', 'size_bytes' => 'required|integer|min:1',
        ]);

        return response()->json((new PresignMediaUpload($request->attributes->getInt('media_actor'), $data['bucket'], $data['mime'], $data['kind'], $data['size_bytes']))->handle($this->media))->header('Cache-Control', 'no-store');
    }

    public function finalize(Request $request, int $media_id): JsonResponse
    {
        return response()->json((new FinalizeMediaUpload($request->attributes->getInt('media_actor'), $media_id))->handle($this->media))->header('Cache-Control', 'no-store');
    }

    public function sign(Request $request, int $media_id): JsonResponse
    {
        return response()->json((new SignMediaDownload($request->attributes->getInt('media_actor'), $media_id))->handle($this->media))->header('Cache-Control', 'no-store');
    }
}
