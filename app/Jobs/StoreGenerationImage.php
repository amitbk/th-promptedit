<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Copies a succeeded generation's image from Pixelforge, whose URLs expire after 24 hours, to our own storage.
 *
 * It only ever sets `image_path`: status and costs are already final, so a failure here never touches them.
 */
class StoreGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Largest image accepted, in bytes. Pixelforge images are PNGs of at most 1536x1024. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Image types accepted, keyed by the MIME type detected from the downloaded content. */
    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public int $tries = 5;

    public int $timeout = 60;

    /** Safety net so a lost worker cannot hold the uniqueness lock forever. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $generationId) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    /**
     * @throws RuntimeException When the download should be retried.
     */
    public function handle(): void
    {
        $generation = Generation::find($this->generationId);

        // Already stored (or nothing to store): never download the same image twice.
        if ($generation === null
            || $generation->status !== GenerationStatus::SUCCEEDED
            || $generation->image_path !== null
            || blank($generation->image_url)) {
            return;
        }

        if (! $this->isPixelforgeUrl($generation->image_url)) {
            $this->fail(new RuntimeException('The image URL is not on the configured Pixelforge host.'));

            return;
        }

        $response = $this->download($generation);

        if (! $response->successful()) {
            // A client error (expired or unknown URL) will not change on retry; timeouts, rate limits and 5xx may.
            if ($this->isRetryableStatus($response->status())) {
                throw $this->retry($generation, "The image host answered HTTP {$response->status()}.");
            }

            $this->fail(new RuntimeException("The image host answered HTTP {$response->status()}."));

            return;
        }

        $body = $response->body();

        if ((int) $response->header('Content-Length') > self::MAX_BYTES || strlen($body) > self::MAX_BYTES) {
            $this->fail(new RuntimeException('The image is larger than the allowed size.'));

            return;
        }

        // Reads the real image header, so a wrong Content-Type or an HTML error page is rejected.
        $info = @getimagesizefromstring($body);
        $extension = self::EXTENSIONS[$info['mime'] ?? ''] ?? null;

        if ($extension === null) {
            throw $this->retry($generation, 'The response is not a PNG, JPEG or WebP image.');
        }

        $path = "generations/{$generation->user_id}/{$generation->uuid}.{$extension}";

        if (! Storage::put($path, $body)) {
            throw $this->retry($generation, 'The image could not be written to storage.');
        }

        $generation->update(['image_path' => $path]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Generation image could not be stored.', [
            'generation_id' => $this->generationId,
            'reason' => $exception->getMessage(),
        ]);
    }

    private function download(Generation $generation): Response
    {
        try {
            return Http::timeout((int) config('pixelforge.timeout', 30))
                // A redirect could leave the allowed host.
                ->withOptions(['allow_redirects' => false])
                ->get($generation->image_url);
        } catch (ConnectionException) {
            // The original message contains the signed URL, so it is not carried over.
            throw $this->retry($generation, 'Connection to the image host failed.');
        }
    }

    private function retry(Generation $generation, string $reason): RuntimeException
    {
        Log::warning('Generation image download failed; it will be retried.', [
            'generation_id' => $generation->id,
            'attempt' => $this->attempts(),
            'reason' => $reason,
        ]);

        return new RuntimeException($reason);
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status >= 500 || in_array($status, [408, 429], true);
    }

    private function isPixelforgeUrl(string $url): bool
    {
        $allowedHost = parse_url((string) config('pixelforge.base_url'), PHP_URL_HOST);

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && is_string($allowedHost)
            && strcasecmp((string) parse_url($url, PHP_URL_HOST), $allowedHost) === 0;
    }
}
