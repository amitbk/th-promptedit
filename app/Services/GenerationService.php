<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\InvalidPixelforgePricingException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeCostEstimate;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgePricingService;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class GenerationService
{
    private const GENERATIONS_PATH = '/v1/generations';

    public function __construct(
        private readonly PixelforgePricingService $pricing,
        private readonly PixelforgeClient $client,
        private readonly PixelforgeWebhookService $webhooks,
    ) {}

    /**
     * Record a generation for the user and submit it to Pixelforge.
     *
     * The record is saved before the HTTP call so webhooks can always find it. Its UUID is the
     * Idempotency-Key, which makes resubmitting an unconfirmed request safe.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_image_urls?: array<int, string>|null}  $data
     *
     * Only one submission per user runs at a time, so a double click or a resent request cannot create
     * a second generation while the first is still being submitted.
     *
     * @throws ValidationException When Pixelforge rejects the input; the generation is marked failed.
     * @throws HttpException When Pixelforge definitively refuses the request (the generation is marked failed),
     *                       or 429 when the user already has a submission in progress.
     */
    public function create(User $user, array $data): Generation
    {
        // Covers the pricing call and the create call, each bounded by the client timeout.
        $lock = Cache::lock("generations:submitting:{$user->id}", (int) config('pixelforge.timeout', 30) * 2 + 10);

        if (! $lock->get()) {
            throw new HttpException(429, __('Your previous image is still being submitted. Please wait a moment.'));
        }

        try {
            return $this->record($user, $data);
        } finally {
            $lock->release();
        }
    }

    /**
     * Estimate what a generation will cost before it is submitted.
     *
     * @throws ValidationException When the size and quality combination is not priced.
     * @throws HttpException 503 when pricing cannot be fetched.
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount): PixelforgeCostEstimate
    {
        try {
            return $this->pricing->estimate($prompt, $size, $quality, $referenceImageCount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['size' => __('This size and quality combination is not available.')]);
        } catch (PixelforgeException|InvalidPixelforgePricingException) {
            throw new HttpException(503, __('Pricing is temporarily unavailable. Please try again shortly.'));
        }
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_image_urls?: array<int, string>|null}  $data
     */
    private function record(User $user, array $data): Generation
    {
        $urls = $data['reference_image_urls'] ?? [];
        $estimate = $this->estimate($data['prompt'], $data['size'], $data['quality'], count($urls));

        $generation = Generation::create([
            'user_id' => $user->id,
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_image_urls' => $urls ?: null,
            // The maximum, so the estimate never understates what the generation can cost.
            'estimated_provider_cost' => $estimate->providerCostMax,
            'estimated_customer_cost' => $estimate->customerCostMax,
            'provider_rates_snapshot' => $estimate->rates,
        ]);

        return $this->submit($generation, $urls);
    }

    /**
     * The user's generations, newest first, optionally limited to one status.
     *
     * @return LengthAwarePaginator<int, Generation>
     */
    public function paginateForUser(User $user, ?GenerationStatus $status, int $perPage = 12): LengthAwarePaginator
    {
        return Generation::query()
            ->whereBelongsTo($user)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            // Keeps the order stable between pages when several share a timestamp.
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<int, string>  $referenceImageUrls
     */
    private function submit(Generation $generation, array $referenceImageUrls): Generation
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            // Lets webhooks be matched back to this record if the response is never received.
            'metadata' => ['generation_uuid' => $generation->uuid],
        ];

        if ($referenceImageUrls !== []) {
            $payload['reference_images'] = $referenceImageUrls;
        }

        try {
            $response = $this->client->post(self::GENERATIONS_PATH, $payload, ['Idempotency-Key' => $generation->uuid]);
        } catch (PixelforgeException $e) {
            return $this->handleFailedSubmission($generation, $e);
        }

        $providerId = $response['id'] ?? null;

        if (! is_string($providerId) || $providerId === '') {
            return $this->leaveForReconciliation($generation, null);
        }

        // Only these columns are written: a webhook may already have advanced the status.
        $generation->update([
            'provider_generation_id' => $providerId,
            // The rates Pixelforge fixed for this generation, which actual billing must use.
            'provider_rates_snapshot' => is_array($response['rates'] ?? null) ? $response['rates'] : $generation->provider_rates_snapshot,
        ]);

        $this->applyWaitingWebhookEvents($generation);

        return $generation->refresh();
    }

    /**
     * Webhook events that arrived before the provider id was stored are kept pending; apply them now.
     * A failure here must not fail the submission: the events stay pending and Pixelforge redelivers them.
     */
    private function applyWaitingWebhookEvents(Generation $generation): void
    {
        try {
            $this->webhooks->retryPending($generation);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Pixelforge documents that rejected requests (4xx, 429 and 503 overloaded) create and bill nothing,
     * so those fail the generation. Connection failures, timeouts and other 5xx responses are ambiguous:
     * the generation may exist at Pixelforge, so it stays queued rather than risking a duplicate.
     */
    private function handleFailedSubmission(Generation $generation, PixelforgeException $e): Generation
    {
        $code = $e->payload['error']['code'] ?? null;
        $isDefinitive = $e->status !== null && ($e->status < 500 || $code === 'overloaded');

        if (! $isDefinitive) {
            return $this->leaveForReconciliation($generation, $e);
        }

        $message = $e->payload['error']['message'] ?? __('The image service rejected the request.');

        $generation->update([
            'status' => GenerationStatus::FAILED,
            'failure_reason' => $message,
        ]);

        Log::warning('Pixelforge rejected a generation submission.', [
            'generation' => $generation->uuid,
            'status' => $e->status,
            'code' => $code,
        ]);

        throw $this->exceptionFor($e->status, $code, $message, $e->payload['error']['errors'] ?? []);
    }

    private function leaveForReconciliation(Generation $generation, ?Throwable $cause): Generation
    {
        Log::warning('Pixelforge generation submission outcome is unknown; leaving it queued.', [
            'generation' => $generation->uuid,
            'status' => $cause instanceof PixelforgeException ? $cause->status : null,
        ]);

        return $generation;
    }

    /**
     * @param  array<string, array<int, string>>  $providerErrors
     */
    private function exceptionFor(int $status, ?string $code, string $message, array $providerErrors): Throwable
    {
        return match (true) {
            $code === 'content_policy_violation' => ValidationException::withMessages(['prompt' => $message]),
            $code === 'validation_failed' => ValidationException::withMessages($this->mapProviderErrors($providerErrors, $message)),
            $status === 429 => new HttpException(429, __('Too many image requests right now. Please try again shortly.')),
            $code === 'overloaded' => new HttpException(503, __('The image service is busy. Please try again.')),
            default => new HttpException(502, __('The image service is unavailable. Please try again later.')),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $providerErrors
     * @return array<string, array<int, string>|string>
     */
    private function mapProviderErrors(array $providerErrors, string $fallbackMessage): array
    {
        if ($providerErrors === []) {
            return ['prompt' => $fallbackMessage];
        }

        $mapped = [];

        foreach ($providerErrors as $field => $messages) {
            $mapped[$field === 'reference_images' ? 'reference_image_urls' : $field] = $messages;
        }

        return $mapped;
    }
}
