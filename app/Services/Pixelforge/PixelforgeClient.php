<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get($path, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function post(string $path, array $data = []): array
    {
        return $this->send(fn (PendingRequest $request) => $request->asJson()->post($path, $data));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    private function send(callable $call): array
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            // Deliberately do not forward the original message or request details.
            throw new PixelforgeException('Unable to reach Pixelforge (connection failed or timed out).', previous: $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $kind = $response->serverError() ? 'server error' : 'request error';

            throw new PixelforgeException(
                "Pixelforge {$kind} (HTTP {$status}).",
                $status,
                $response->json() ?? null,
            );
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('pixelforge.base_url');
        $apiKey = config('pixelforge.api_key');

        if (blank($baseUrl) || blank($apiKey)) {
            throw new PixelforgeException('Pixelforge is not configured. Set the base URL and API key.');
        }

        return Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('pixelforge.timeout', 30));
    }
}
