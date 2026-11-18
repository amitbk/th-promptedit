<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'estimated_provider_cost' => $this->estimated_provider_cost,
            'estimated_customer_cost' => $this->estimated_customer_cost,
            'provider_generation_id' => $this->provider_generation_id,
        ];
    }
}
