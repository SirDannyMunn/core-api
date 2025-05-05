<?php

namespace Fleetbase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogRequestMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Prepare cURL command components
        $method = $request->method();
        $url = $request->fullUrl();
        $headers = $request->headers->all();
        $body = $request->getContent();

        // Build cURL command
        $curlCommand = "curl -X {$method} \\\n";

        // Add headers
        foreach ($headers as $key => $values) {
            foreach ($values as $value) {
                // Skip host header as it's implicit in the URL
                if (strtolower($key) !== 'host') {
                    $curlCommand .= "  -H \"{$key}: {$value}\" \\\n";
                }
            }
        }

        // Add request body for non-GET/HEAD requests
        if (!in_array($method, ['GET', 'HEAD']) && !empty($body)) {
            // Escape quotes in the body for safe cURL command
            $escapedBody = addslashes($body);
            $curlCommand .= "  -d '{$escapedBody}' \\\n";
        }

        // Add URL
        $curlCommand .= "  '{$url}'";

        // Log the cURL command
        Log::channel('request')->info("cURL Request:\n{$curlCommand}");

        return $next($request);
    }
}
