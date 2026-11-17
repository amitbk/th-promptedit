<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
    'timeout' => (int) env('PIXELFORGE_TIMEOUT', 30),
    // Options accepted by POST /v1/generations, per the Pixelforge API reference.
    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
    'qualities' => ['low', 'medium', 'high'],
    'max_prompt_length' => 4000,
    'max_reference_images' => 4,
    // Seconds to reuse fetched pricing for estimates. Billing uses the rates on each generation, not these.
    'pricing_cache_seconds' => 60,
    // Percentage added on top of Pixelforge's cost when charging users.
    'markup_percent' => 25,
];
