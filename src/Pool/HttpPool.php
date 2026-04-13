<?php

declare(strict_types=1);

namespace Webpatser\Torque\Pool;

use Fledge\Async\Http\Client\HttpClient;
use Fledge\Async\Http\Client\HttpClientBuilder;
use Fledge\Async\Http\Client\Request;
use Fledge\Async\Http\Client\Response;
use Fledge\Async\Sync\LocalSemaphore;
use Fledge\Async\Sync\Semaphore;

/**
 * Concurrency-limited wrapper around amphp/http-client.
 *
 * amphp/http-client already manages its own connection pool internally
 * (keep-alive, HTTP/2 multiplexing, etc.), so we don't need to pool
 * HttpClient instances. Instead, this class acts as a concurrency limiter:
 * at most $maxConcurrent requests may be in-flight at the same time.
 *
 * When all slots are taken, `request()` suspends the calling Fiber until
 * a slot opens — no busy-waiting, no exceptions.
 *
 * Usage:
 *
 *     $http = new HttpPool(maxConcurrent: 10);
 *
 *     // Simple GET
 *     $response = $http->get('https://api.example.com/status');
 *
 *     // POST with JSON body
 *     $response = $http->post('https://api.example.com/webhooks', $payload);
 *
 *     // Full control with a Request object
 *     $request = new Request('https://api.example.com/upload', 'PUT');
 *     $request->setBody($fileContents);
 *     $response = $http->request($request);
 *
 *     // Scoped access to the underlying client
 *     $http->use(fn (HttpClient $client) => $client->request(new Request($uri)));
 *
 * @requires amphp/http-client ^5.0
 */
final class HttpPool
{
    private HttpClient $client;

    private Semaphore $semaphore;

    /**
     * @param int $maxConcurrent Maximum number of in-flight HTTP requests.
     */
    public function __construct(
        public private(set) int $maxConcurrent = 15,
    ) {
        $this->client = HttpClientBuilder::buildDefault();
        $this->semaphore = new LocalSemaphore($this->maxConcurrent);
    }

    /**
     * Send an HTTP request, respecting the concurrency limit.
     *
     * Suspends the Fiber if all slots are in use.
     */
    #[\NoDiscard]
    public function request(Request $request): Response
    {
        $lock = $this->semaphore->acquire();

        try {
            return $this->client->request($request);
        } finally {
            $lock->release();
        }
    }

    /**
     * Convenience: send a GET request.
     *
     *     $response = $http->get('https://api.example.com/health');
     *     $body = $response->getBody()->buffer();
     */
    #[\NoDiscard]
    public function get(string $uri): Response
    {
        return $this->request(new Request($uri));
    }

    /**
     * Convenience: send a POST request with a body.
     *
     *     $response = $http->post(
     *         'https://api.example.com/webhooks',
     *         json_encode(['event' => 'job.completed']),
     *     );
     *
     * @param string $uri         Target URI.
     * @param string $body        Request body content.
     * @param string $contentType Content-Type header value.
     */
    #[\NoDiscard]
    public function post(string $uri, string $body, string $contentType = 'application/json'): Response
    {
        $request = new Request($uri, 'POST');
        $request->setHeader('Content-Type', $contentType);
        $request->setBody($body);

        return $this->request($request);
    }

    /**
     * Execute a callback with scoped access to the underlying HttpClient.
     *
     * The callback still runs within the semaphore, so the concurrency
     * limit is respected even for custom request flows.
     *
     *     $http->use(function (HttpClient $client) {
     *         $response = $client->request(new Request($uri, 'DELETE'));
     *         // process response...
     *     });
     *
     * @template T
     *
     * @param \Closure(HttpClient): T $callback  Receives the amphp HttpClient.
     *
     * @return T
     */
    public function use(\Closure $callback): mixed
    {
        $lock = $this->semaphore->acquire();

        try {
            return $callback($this->client);
        } finally {
            $lock->release();
        }
    }
}
