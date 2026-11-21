<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Illuminate\Http\Response;

final class MetricsController
{
    public function __invoke(MetricsExporter $exporter): Response
    {
        return new Response($exporter->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
