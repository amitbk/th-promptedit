<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function historyGeneration(User $user, array $attributes = []): Generation
{
    return Generation::create($attributes + [
        'user_id' => $user->id,
        'prompt' => 'A lighthouse on a cliff at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'estimated_customer_cost' => '0.46734375',
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    Storage::fake();
});

it('requires authentication', function () {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
});

it('shows only the authenticated user\'s generations, newest first', function () {
    $older = historyGeneration($this->user, ['prompt' => 'older']);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = historyGeneration($this->user, ['prompt' => 'newer']);
    historyGeneration(User::factory()->create(), ['prompt' => 'someone else']);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.uuid', $newer->uuid)
            ->where('generations.data.1.uuid', $older->uuid)
            ->where('generations.meta.total', 2)
            ->where('filters.status', null)
            ->where('statuses', ['queued', 'processing', 'succeeded', 'failed']));
});

it('provides the details shown for each generation without private fields', function () {
    $generation = historyGeneration($this->user, [
        'status' => GenerationStatus::SUCCEEDED,
        'actual_provider_cost' => '0.30551',
        'actual_customer_cost' => '0.3818875',
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_1.png?signature=secret',
        'image_path' => 'generations/1/abc.png',
        'provider_rates_snapshot' => ['output_tokens' => '40.00'],
    ]);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0', [
                'uuid' => $generation->uuid,
                'prompt' => 'A lighthouse on a cliff at dawn',
                'size' => '1536x1024',
                'quality' => 'high',
                'status' => 'succeeded',
                'estimated_customer_cost' => '0.4673437500',
                'actual_customer_cost' => '0.3818875000',
                'failure_reason' => null,
                'image_url' => route('generations.image', $generation),
                'image_pending' => false,
                'created_at' => $generation->created_at->toIso8601String(),
            ]));
});

it('marks a succeeded generation whose image is not stored yet as pending', function () {
    historyGeneration($this->user, ['status' => GenerationStatus::SUCCEEDED, 'actual_customer_cost' => '0.38']);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_pending', true));
});

it('shows the failure reason and no image for a failed generation', function () {
    historyGeneration($this->user, [
        'status' => GenerationStatus::FAILED,
        'failure_reason' => 'The model could not produce an image.',
        'actual_customer_cost' => '0',
    ]);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'failed')
            ->where('generations.data.0.failure_reason', 'The model could not produce an image.')
            ->where('generations.data.0.actual_customer_cost', '0.0000000000')
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_pending', false));
});

it('does not show a failure reason unless the generation failed', function () {
    historyGeneration($this->user, ['status' => GenerationStatus::QUEUED, 'failure_reason' => 'stale']);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.failure_reason', null)
            ->where('generations.data.0.actual_customer_cost', null)
            ->where('generations.data.0.image_pending', false));
});

it('filters by status', function () {
    historyGeneration($this->user, ['status' => GenerationStatus::SUCCEEDED]);
    $failed = historyGeneration($this->user, ['status' => GenerationStatus::FAILED]);
    historyGeneration($this->user, ['status' => GenerationStatus::QUEUED]);
    historyGeneration(User::factory()->create(), ['status' => GenerationStatus::FAILED]);

    $this->actingAs($this->user)
        ->get(route('generations.index', ['status' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.uuid', $failed->uuid)
            ->where('filters.status', 'failed'));
});

it('ignores an unknown status filter', function () {
    historyGeneration($this->user);
    historyGeneration($this->user, ['status' => GenerationStatus::FAILED]);

    $this->actingAs($this->user)
        ->get(route('generations.index', ['status' => 'bogus']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 2)
            ->where('filters.status', null));
});

it('paginates twelve per page and keeps the filter in page links', function () {
    foreach (range(1, 14) as $i) {
        historyGeneration($this->user, ['status' => GenerationStatus::SUCCEEDED, 'prompt' => "prompt {$i}"]);
    }
    historyGeneration($this->user, ['status' => GenerationStatus::FAILED]);

    $this->actingAs($this->user)
        ->get(route('generations.index', ['status' => 'succeeded']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 12)
            ->where('generations.meta.total', 14)
            ->where('generations.meta.last_page', 2)
            ->where('generations.links.next', route('generations.index', ['status' => 'succeeded', 'page' => 2])));

    $this->actingAs($this->user)
        ->get(route('generations.index', ['status' => 'succeeded', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 2)
            ->where('generations.meta.current_page', 2)
            // The two oldest, as all share a timestamp and ties are broken by id.
            ->where('generations.data.0.prompt', 'prompt 2')
            ->where('generations.data.1.prompt', 'prompt 1'));
});

it('serves a stored image to its owner', function () {
    $generation = historyGeneration($this->user, ['status' => GenerationStatus::SUCCEEDED]);
    $path = "generations/{$this->user->id}/{$generation->uuid}.png";
    Storage::put($path, 'png-bytes');
    $generation->update(['image_path' => $path]);

    $response = $this->actingAs($this->user)->get(route('generations.image', $generation));

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe('png-bytes')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toContain("{$generation->uuid}.png")
        ->and($response->headers->get('Content-Disposition'))->not->toContain('generations/');
});

it('hides other users\' images as not found', function () {
    $owner = User::factory()->create();
    $generation = historyGeneration($owner, ['status' => GenerationStatus::SUCCEEDED]);
    $path = "generations/{$owner->id}/{$generation->uuid}.png";
    Storage::put($path, 'png-bytes');
    $generation->update(['image_path' => $path]);

    $this->actingAs($this->user)->get(route('generations.image', $generation))->assertNotFound();
});

it('requires authentication for images', function () {
    $generation = historyGeneration($this->user, ['image_path' => 'generations/1/x.png']);

    $this->get(route('generations.image', $generation))->assertRedirect(route('login'));
});

it('returns not found when the image is not stored', function (?string $path) {
    $generation = historyGeneration($this->user, ['status' => GenerationStatus::SUCCEEDED, 'image_path' => $path]);

    $this->actingAs($this->user)->get(route('generations.image', $generation))->assertNotFound();
})->with([
    'not stored yet' => [null],
    'file missing from disk' => ['generations/1/missing.png'],
]);

it('returns not found for an unknown or malformed generation id', function (string $id) {
    $this->actingAs($this->user)->get("/generations/{$id}/image")->assertNotFound();
})->with(['00000000-0000-0000-0000-000000000000', '1', 'not-a-uuid']);
