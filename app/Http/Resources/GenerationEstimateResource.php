<?php

namespace App\Http\Resources;

use App\Services\Pixelforge\PixelforgeCostEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a generation may cost the customer, markup included. Provider costs are not exposed.
 *
 * @property PixelforgeCostEstimate $resource
 */
class GenerationEstimateResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer_cost_min' => $this->resource->customerCostMin,
            // The amount recorded as the generation's estimate.
            'customer_cost_max' => $this->resource->customerCostMax,
        ];
    }
}
