<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PixelforgeWebhookService
{
    public function __construct(private readonly PixelforgePricingService $pricing) {}

    /**
     * Persist and apply a signature-verified event.
     *
     * Delivery is at least once and unordered, so the event is stored first (its `evt_` id is unique)
     * and then applied under row locks. Re-delivered events and events for a final generation change nothing.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        // Committed on its own so the event survives even if applying it fails or cannot be matched yet.
        WebhookEvent::query()->insertOrIgnore([
            'provider_event_id' => $event['id'],
            'type' => $event['type'],
            'payload' => json_encode($event),
            'status' => WebhookEventStatus::PENDING->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->process($event['id']);
    }

    /**
     * Apply stored events that were waiting for this generation to be matchable, e.g. once its provider id is known.
     *
     * Goes through the same locked, deduplicated path as a fresh delivery, so nothing is applied twice.
     */
    public function retryPending(Generation $generation): void
    {
        WebhookEvent::query()
            ->where('status', WebhookEventStatus::PENDING)
            ->where(fn ($query) => $query
                ->where('payload->data->object->id', $generation->provider_generation_id)
                ->orWhere('payload->data->object->metadata->generation_uuid', $generation->uuid))
            ->orderBy('id')
            ->pluck('provider_event_id')
            ->each(fn (string $eventId) => $this->process($eventId));
    }

    private function process(string $providerEventId): void
    {
        DB::transaction(function () use ($providerEventId) {
            $webhookEvent = WebhookEvent::query()
                ->where('provider_event_id', $providerEventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($webhookEvent->status !== WebhookEventStatus::PENDING) {
                return;
            }

            $this->apply($webhookEvent);
        });
    }

    private function apply(WebhookEvent $webhookEvent): void
    {
        $type = $webhookEvent->payload['type'];

        if (! in_array($type, ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
            // `ping` and any event type we do not act on.
            $this->finish($webhookEvent, WebhookEventStatus::IGNORED);

            return;
        }

        $object = $webhookEvent->payload['data']['object'] ?? null;

        if (! is_array($object) || ! is_string($object['id'] ?? null)) {
            $this->finish($webhookEvent, WebhookEventStatus::FAILED, 'The event has no generation object.');

            return;
        }

        $generation = $this->findGeneration($object);

        if ($generation === null) {
            // Left pending so a redelivery can apply it once the generation can be matched.
            Log::warning('Pixelforge webhook event does not match a generation yet.', [
                'event' => $webhookEvent->provider_event_id,
                'provider_generation_id' => $object['id'],
            ]);

            return;
        }

        // Succeeded and failed are final: late or duplicate events must never move a generation back.
        if (in_array($generation->status, [GenerationStatus::SUCCEEDED, GenerationStatus::FAILED], true)) {
            $this->finish($webhookEvent, WebhookEventStatus::IGNORED);

            return;
        }

        try {
            $attributes = match ($type) {
                'generation.processing' => ['status' => GenerationStatus::PROCESSING],
                'generation.succeeded' => $this->succeededAttributes($object),
                'generation.failed' => $this->failedAttributes($object),
            };
        } catch (InvalidArgumentException $e) {
            Log::error('Pixelforge webhook event could not be applied.', [
                'event' => $webhookEvent->provider_event_id,
                'reason' => $e->getMessage(),
            ]);
            $this->finish($webhookEvent, WebhookEventStatus::FAILED, $e->getMessage());

            return;
        }

        // The event can arrive before the create call has stored the provider id.
        $generation->update($attributes + ['provider_generation_id' => $object['id']]);

        if ($type === 'generation.succeeded') {
            // Once per generation, because final generations ignore later events. The download happens in the
            // background, after the billing above is committed, and never fails the webhook.
            StoreGenerationImage::dispatch($generation->id)->afterCommit();
        }

        $this->finish($webhookEvent, WebhookEventStatus::PROCESSED);
    }

    /**
     * Match by Pixelforge's id, falling back to the generation UUID sent as metadata when the event
     * outran the create response. The generation row is locked for the rest of the transaction.
     *
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        $generation = Generation::query()
            ->where('provider_generation_id', $object['id'])
            ->lockForUpdate()
            ->first();

        $uuid = $object['metadata']['generation_uuid'] ?? null;

        if ($generation === null && is_string($uuid)) {
            $generation = Generation::query()
                ->where('uuid', $uuid)
                ->whereNull('provider_generation_id')
                ->lockForUpdate()
                ->first();
        }

        return $generation;
    }

    /**
     * Actual cost uses the usage and the rates Pixelforge fixed when the generation was created.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function succeededAttributes(array $object): array
    {
        $usage = $object['usage'] ?? null;
        $rates = $object['rates'] ?? null;
        $imageUrl = $object['image_url'] ?? null;

        foreach (PixelforgePricingService::RATE_KEYS as $key) {
            if (! is_array($usage) || ! is_int($usage[$key] ?? null) || $usage[$key] < 0) {
                throw new InvalidArgumentException("Succeeded event has invalid usage '{$key}'.");
            }

            if (! is_array($rates) || ! is_string($rates[$key] ?? null) || ! preg_match('/^\d+(\.\d+)?$/', $rates[$key])) {
                throw new InvalidArgumentException("Succeeded event has invalid rate '{$key}'.");
            }
        }

        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new InvalidArgumentException('Succeeded event has no image_url.');
        }

        $providerCost = $this->pricing->providerCost($usage, $rates);

        return [
            'status' => GenerationStatus::SUCCEEDED,
            'token_usage' => $usage,
            'provider_rates_snapshot' => $rates,
            'actual_provider_cost' => $providerCost,
            'actual_customer_cost' => $this->pricing->applyMarkup($providerCost),
            'image_url' => $imageUrl,
            'completed_at' => $this->finishedAt($object),
        ];
    }

    /**
     * Failed generations are never billed.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function failedAttributes(array $object): array
    {
        $message = $object['error']['message'] ?? null;

        return [
            'status' => GenerationStatus::FAILED,
            'failure_reason' => is_string($message) && $message !== '' ? $message : __('The generation failed.'),
            'actual_provider_cost' => '0',
            'actual_customer_cost' => '0',
            'failed_at' => $this->finishedAt($object),
        ];
    }

    /**
     * @param  array<string, mixed>  $object
     *
     * @throws InvalidArgumentException
     */
    private function finishedAt(array $object): Carbon
    {
        $completedAt = $object['completed_at'] ?? null;

        return is_string($completedAt) ? Carbon::parse($completedAt) : now();
    }

    private function finish(WebhookEvent $webhookEvent, WebhookEventStatus $status, ?string $error = null): void
    {
        $webhookEvent->update([
            'status' => $status,
            'error' => $error,
            'processed_at' => now(),
        ]);
    }
}
