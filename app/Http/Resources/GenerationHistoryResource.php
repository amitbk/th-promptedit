<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as shown in the user's history. Exposes customer-facing costs only, and never the
 * storage path or the provider's signed image URL.
 *
 * @mixin Generation
 */
class GenerationHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $succeeded = $this->status === GenerationStatus::SUCCEEDED;

        return [
            'uuid' => $this->uuid,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'status' => $this->status,
            'estimated_customer_cost' => $this->estimated_customer_cost,
            'actual_customer_cost' => $this->actual_customer_cost,
            'failure_reason' => $this->status === GenerationStatus::FAILED ? $this->failure_reason : null,
            'image_url' => $this->image_path !== null ? route('generations.image', $this->resource) : null,
            // Succeeded, but our own copy of the image is still being downloaded.
            'image_pending' => $succeeded && $this->image_path === null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
