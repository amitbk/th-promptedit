<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generations\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;

class GenerationController extends Controller
{
    /**
     * Create a generation and submit it to Pixelforge.
     *
     * Responds 201 once Pixelforge has accepted it, or 202 when the submission outcome is still unknown.
     */
    public function store(GenerationRequest $request, GenerationService $generations): JsonResponse
    {
        $generation = $generations->create($request->user(), $request->validated());

        return (new GenerationResource($generation))
            ->response()
            ->setStatusCode($generation->provider_generation_id ? 201 : 202);
    }
}
