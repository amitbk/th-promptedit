<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $provider_generation_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string>|null $reference_image_urls
 * @property GenerationStatus $status
 * @property string $estimated_provider_cost
 * @property string $estimated_customer_cost
 * @property string|null $actual_provider_cost
 * @property string|null $actual_customer_cost
 * @property array<string, int>|null $token_usage
 * @property array<string, string>|null $provider_rates_snapshot
 * @property string|null $image_url
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'provider_generation_id',
    'prompt',
    'size',
    'quality',
    'reference_image_urls',
    'status',
    'estimated_provider_cost',
    'estimated_customer_cost',
    'actual_provider_cost',
    'actual_customer_cost',
    'token_usage',
    'provider_rates_snapshot',
    'image_url',
    'failure_reason',
])]
class Generation extends Model
{
    use HasUuids;

    /**
     * Default attribute values, mirroring the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'estimated_provider_cost' => '0',
        'estimated_customer_cost' => '0',
    ];

    /**
     * Generate a UUID for the `uuid` column; the primary key stays an auto-increment id.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_image_urls' => 'array',
            'token_usage' => 'array',
            'provider_rates_snapshot' => 'array',
            'status' => GenerationStatus::class,
            'estimated_provider_cost' => 'decimal:8',
            'estimated_customer_cost' => 'decimal:8',
            'actual_provider_cost' => 'decimal:8',
            'actual_customer_cost' => 'decimal:8',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
