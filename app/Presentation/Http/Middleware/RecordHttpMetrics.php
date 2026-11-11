<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Infrastructure\Metrics\Metrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecordHttpMetrics
{
    public function __construct(private Metrics $metrics) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        $route = $request->route();
        // The route template, not the URL: one series per endpoint, not per order id.
        $template = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : 'unmatched';

        $this->metrics->httpRequest($request->method(), $template, $response->getStatusCode(), microtime(true) - $started);

        return $response;
    }
}
