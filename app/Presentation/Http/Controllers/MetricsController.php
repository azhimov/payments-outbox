<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Infrastructure\Metrics\Metrics;
use App\Infrastructure\Metrics\StateGauges;
use Illuminate\Http\Response;
use Prometheus\RenderTextFormat;

final readonly class MetricsController
{
    public function __invoke(Metrics $metrics, StateGauges $gauges): Response
    {
        $gauges->refresh();

        return new Response($metrics->render(), 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }
}
