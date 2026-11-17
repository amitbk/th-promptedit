<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeGeneration(User $user, array $attributes = []): Generation
{
    return Generation::create($attributes + [
        'user_id' => $user->id,
        'prompt' => 'A lighthouse on a cliff at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
    ]);
}

it('creates the generations table with the expected columns', function () {
    expect(Schema::getColumnListing('generations'))->toEqualCanonicalizing([
        'id', 'uuid', 'user_id', 'provider_generation_id', 'prompt', 'size', 'quality',
        'reference_image_urls', 'status', 'estimated_provider_cost', 'estimated_customer_cost',
        'actual_provider_cost', 'actual_customer_cost', 'token_usage', 'provider_rates_snapshot',
        'image_url', 'image_path', 'failure_reason', 'completed_at', 'failed_at', 'created_at', 'updated_at',
    ]);
});

it('creates a generation with a uuid and database defaults', function () {
    $generation = makeGeneration(User::factory()->create())->refresh();

    expect(Str::isUuid($generation->uuid))->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->estimated_provider_cost)->toBe('0.00000000')
        ->and($generation->estimated_customer_cost)->toBe('0.0000000000')
        ->and($generation->actual_provider_cost)->toBeNull()
        ->and($generation->actual_customer_cost)->toBeNull()
        ->and($generation->provider_generation_id)->toBeNull();
});

it('casts json, decimal and status attributes', function () {
    $generation = makeGeneration(User::factory()->create(), [
        'reference_image_urls' => ['https://example.com/a.png'],
        'token_usage' => ['output_tokens' => 6450],
        'provider_rates_snapshot' => ['output_tokens' => '40.00'],
        'status' => GenerationStatus::SUCCEEDED,
        'estimated_provider_cost' => '0.373875',
        'actual_provider_cost' => '0.30551',
        'actual_customer_cost' => '0.3818875',
    ])->refresh();

    expect($generation->reference_image_urls)->toBe(['https://example.com/a.png'])
        ->and($generation->token_usage)->toBe(['output_tokens' => 6450])
        ->and($generation->provider_rates_snapshot)->toBe(['output_tokens' => '40.00'])
        ->and($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->estimated_provider_cost)->toBe('0.37387500')
        ->and($generation->actual_provider_cost)->toBe('0.30551000')
        ->and($generation->actual_customer_cost)->toBe('0.3818875000')
        ->and($generation->created_at)->toBeInstanceOf(DateTimeInterface::class);
});

it('belongs to a user', function () {
    $user = User::factory()->create();

    expect(makeGeneration($user)->user->is($user))->toBeTrue();
});

it('enforces unique provider generation ids', function () {
    $user = User::factory()->create();
    makeGeneration($user, ['provider_generation_id' => 'gen_1']);

    expect(fn () => makeGeneration($user, ['provider_generation_id' => 'gen_1']))
        ->toThrow(QueryException::class);
});

it('deletes generations when the user is deleted', function () {
    $user = User::factory()->create();
    makeGeneration($user);

    $user->delete();

    expect(Generation::count())->toBe(0);
});
