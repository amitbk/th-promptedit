<?php

use App\Enums\GenerationStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    // Render without SSR so no HTTP leaves the test, then prove the report itself never calls
    // Pixelforge; reconciliation is a deferred prop, tested separately.
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create(['name' => 'Operator']);
});

function reportGeneration(User $user, GenerationStatus $status, ?string $finishedAt, array $attributes = []): Generation
{
    $generation = Generation::create($attributes + [
        'user_id' => $user->id,
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => $status,
        'estimated_provider_cost' => '0.99',
        'estimated_customer_cost' => '1.2375',
    ]);

    $generation->forceFill(match ($status) {
        GenerationStatus::SUCCEEDED => ['completed_at' => $finishedAt],
        GenerationStatus::FAILED => ['failed_at' => $finishedAt],
        default => [],
    } + ['created_at' => $attributes['created_at'] ?? $finishedAt ?? now()])->save();

    return $generation;
}

function succeeded(User $user, string $completedAt, string $provider, string $customer, array $attributes = []): Generation
{
    return reportGeneration($user, GenerationStatus::SUCCEEDED, $completedAt, $attributes + [
        'actual_provider_cost' => $provider,
        'actual_customer_cost' => $customer,
    ]);
}

function reportFor(User $user, array $query = []): Assert
{
    $page = null;
    test()->actingAs($user)->get(route('admin.usage-report', $query))->assertOk()->assertInertia(function (Assert $inertia) use (&$page) {
        $page = $inertia;
    });

    return $page;
}

it('is only available to admins', function () {
    $this->get(route('admin.usage-report'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.usage-report'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.usage-report'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/UsageReport'));
});

it('shares whether the user may view admin reports', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.viewAdminReports', false));
    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.can.viewAdminReports', true));
});

it('does not let users make themselves admin through profile updates', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Me', 'email' => $user->email, 'is_admin' => true]);

    expect($user->refresh()->is_admin)->toBeFalse();
});

it('defaults to the last 30 UTC days ending today', function () {
    $this->travelTo('2026-11-17 23:30:00');

    $this->actingAs($this->admin)->get(route('admin.usage-report'))->assertInertia(fn (Assert $page) => $page
        ->where('filters.start_date', '2026-10-19')
        ->where('filters.end_date', '2026-11-17')
        ->has('report.daily', 30)
        ->where('report.daily.0.date', '2026-10-19')
        ->where('report.daily.29.date', '2026-11-17'));
});

it('sums actual costs exactly, without floats, and computes gross profit', function () {
    $user = User::factory()->create();
    // Ten generations at 0.1 would drift with floats (0.1 * 10 !== 1.0 in binary).
    foreach (range(1, 10) as $i) {
        succeeded($user, '2026-11-10 12:00:00', '0.10000000', '0.1250000000');
    }
    succeeded($user, '2026-11-10 13:00:00', '0.02050250', '0.0256281250');

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-10'])
        ->where('report.summary.customer_charges', '1.2756281250')
        ->where('report.summary.provider_costs', '1.020502500')
        ->where('report.summary.gross_profit', '0.2551256250')
        ->where('report.summary.margin_percent', '20.0')
        ->where('report.summary.billed_generations', 11);
});

it('never uses estimates and treats failed generations as free', function () {
    $user = User::factory()->create();
    succeeded($user, '2026-11-10 10:00:00', '0.30551000', '0.3818875000');
    // Failed, even with a stored cost: contributes nothing.
    reportGeneration($user, GenerationStatus::FAILED, '2026-11-10 11:00:00', ['actual_provider_cost' => '5', 'actual_customer_cost' => '6']);
    // Rejected at submission: no failed_at, dated by creation.
    reportGeneration($user, GenerationStatus::FAILED, null, ['created_at' => '2026-11-10 12:00:00']);
    // In progress: has estimates only, so it is not money yet.
    reportGeneration($user, GenerationStatus::QUEUED, null, ['created_at' => '2026-11-10 13:00:00']);
    reportGeneration($user, GenerationStatus::PROCESSING, null, ['created_at' => '2026-11-10 14:00:00']);

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-10'])
        ->where('report.summary.customer_charges', '0.3818875000')
        ->where('report.summary.provider_costs', '0.305510000')
        ->where('report.summary.billed_generations', 1)
        ->where('report.summary.failed_generations', 2)
        ->where('report.summary.created_generations', 5)
        ->where('report.summary.in_progress_generations', 2)
        ->where('report.statuses', ['queued' => 1, 'processing' => 1, 'succeeded' => 1, 'failed' => 2])
        ->where('report.daily.0.failed_generations', 2)
        ->where('report.daily.0.customer_charges', '0.3818875000');
});

it('flags succeeded generations without an actual cost instead of estimating them', function () {
    $user = User::factory()->create();
    reportGeneration($user, GenerationStatus::SUCCEEDED, '2026-11-10 10:00:00');

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-10'])
        ->where('report.summary.unpriced_generations', 1)
        ->where('report.summary.customer_charges', '0.0000000000')
        ->where('report.summary.margin_percent', null);
});

it('dates money by UTC completion day and filters the range inclusively', function () {
    $user = User::factory()->create();
    succeeded($user, '2026-11-09 23:59:59', '1', '1.25');               // before the range
    succeeded($user, '2026-11-10 00:00:00', '0.1', '0.125');            // first second of the range
    succeeded($user, '2026-11-11 23:59:59', '0.2', '0.25', ['created_at' => '2026-11-09 23:00:00']);  // created before, completed in range
    succeeded($user, '2026-11-12 00:00:00', '1', '1.25');               // after the range

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-11'])
        ->has('report.daily', 2)
        ->where('report.daily.0.date', '2026-11-10')
        ->where('report.daily.0.provider_costs', '0.100000000')
        ->where('report.daily.1.date', '2026-11-11')
        ->where('report.daily.1.provider_costs', '0.200000000')
        ->where('report.summary.provider_costs', '0.300000000')
        ->where('report.summary.customer_charges', '0.3750000000')
        // Status counts are by creation day: the one created on the 9th is not in them.
        ->where('report.statuses.succeeded', 1);
});

it('includes days without activity as zeros', function () {
    succeeded(User::factory()->create(), '2026-11-11 08:00:00', '0.1', '0.125');

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-12'])
        ->where('report.daily.0', [
            'date' => '2026-11-10',
            'billed_generations' => 0,
            'failed_generations' => 0,
            'customer_charges' => '0.0000000000',
            'provider_costs' => '0.000000000',
            'gross_profit' => '0.0000000000',
        ])
        ->where('report.daily.1.billed_generations', 1)
        ->where('report.daily.2.billed_generations', 0);
});

it('breaks totals down per user, highest charges first', function () {
    $small = User::factory()->create(['name' => 'Small', 'email' => 'small@example.com']);
    $big = User::factory()->create(['name' => 'Big', 'email' => 'big@example.com']);
    $failedOnly = User::factory()->create(['name' => 'Failed only']);
    succeeded($small, '2026-11-10 10:00:00', '0.1', '0.125');
    succeeded($big, '2026-11-10 10:00:00', '0.3', '0.375');
    succeeded($big, '2026-11-10 11:00:00', '0.2', '0.25');
    reportGeneration($failedOnly, GenerationStatus::FAILED, '2026-11-10 12:00:00');

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-10'])
        ->has('report.users', 3)
        ->where('report.users.0', [
            'id' => $big->id,
            'name' => 'Big',
            'email' => 'big@example.com',
            'billed_generations' => 2,
            'failed_generations' => 0,
            'customer_charges' => '0.6250000000',
            'provider_costs' => '0.500000000',
            'gross_profit' => '0.1250000000',
        ])
        ->where('report.users.1.name', 'Small')
        ->where('report.users.2.name', 'Failed only')
        ->where('report.users.2.failed_generations', 1)
        ->where('report.users.2.customer_charges', '0.0000000000');
});

it('counts each generation once, however many webhook events it received', function () {
    $user = User::factory()->create();
    $generation = succeeded($user, '2026-11-10 10:00:00', '0.30551', '0.3818875', ['provider_generation_id' => 'gen_1']);
    foreach (['processing', 'succeeded', 'succeeded_again'] as $suffix) {
        WebhookEvent::create([
            'provider_event_id' => "evt_{$suffix}",
            'type' => 'generation.succeeded',
            'payload' => ['data' => ['object' => ['id' => $generation->provider_generation_id]]],
            'status' => WebhookEventStatus::PROCESSED,
        ]);
    }

    reportFor($this->admin, ['start_date' => '2026-11-10', 'end_date' => '2026-11-10'])
        ->where('report.summary.billed_generations', 1)
        ->where('report.summary.provider_costs', '0.305510000');
});

it('validates the date range and returns to the default report with the errors', function (array $query, string $field) {
    $this->actingAs($this->admin)
        ->from(route('dashboard'))
        ->get(route('admin.usage-report', $query))
        ->assertRedirect(route('admin.usage-report'))
        ->assertSessionHasErrors($field);
})->with([
    'bad format' => [['start_date' => '10/11/2026'], 'start_date'],
    'end before start' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-09'], 'end_date'],
    'more than 92 days' => [['start_date' => '2026-08-01', 'end_date' => '2026-11-01'], 'end_date'],
    'start alone after today' => [['start_date' => '2099-01-01'], 'start_date'],
]);

it('accepts exactly 92 days', function () {
    reportFor($this->admin, ['start_date' => '2026-08-02', 'end_date' => '2026-11-01'])->has('report.daily', 92);
});
