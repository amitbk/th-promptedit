<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\Generations\GenerationEstimateRequest;
use App\Http\Requests\Generations\GenerationRequest;
use App\Http\Resources\GenerationEstimateResource;
use App\Http\Resources\GenerationHistoryResource;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationController extends Controller
{
    /**
     * Show the authenticated user's generation history.
     */
    public function index(Request $request, GenerationService $generations): Response
    {
        // An unknown status is ignored rather than rejected, so a stale link still shows the history.
        $status = $request->enum('status', GenerationStatus::class);

        return Inertia::render('generations/Index', [
            'generations' => GenerationHistoryResource::collection(
                $generations->paginateForUser($request->user(), $status),
            ),
            'filters' => ['status' => $status],
            'statuses' => array_column(GenerationStatus::cases(), 'value'),
        ]);
    }

    /**
     * Show the page for generating an image.
     */
    public function create(): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
            'limits' => [
                'prompt_length' => config('pixelforge.max_prompt_length'),
                'reference_images' => config('pixelforge.max_reference_images'),
            ],
            'markupPercent' => config('pixelforge.markup_percent'),
        ]);
    }

    /**
     * Estimate the customer cost of a generation before it is submitted.
     */
    public function estimate(GenerationEstimateRequest $request, GenerationService $generations): GenerationEstimateResource
    {
        return new GenerationEstimateResource($generations->estimate(
            (string) $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            (int) $request->validated('reference_image_count'),
        ));
    }

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

    /**
     * Serve the stored copy of a generation's image to its owner.
     */
    public function image(Generation $generation): StreamedResponse
    {
        Gate::authorize('view', $generation);

        abort_if($generation->image_path === null || ! Storage::exists($generation->image_path), 404);

        return Storage::response($generation->image_path, "{$generation->uuid}.".pathinfo($generation->image_path, PATHINFO_EXTENSION), [
            // Private to the signed-in user; the stored file never changes once written.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
