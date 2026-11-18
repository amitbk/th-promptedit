<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pixelforge\PixelforgeWebhookRequest;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * Receive a signed Pixelforge event. Any 2xx counts as delivered.
     */
    public function __invoke(PixelforgeWebhookRequest $request, PixelforgeWebhookService $webhooks): Response
    {
        $webhooks->handle($request->all());

        return response()->noContent();
    }
}
