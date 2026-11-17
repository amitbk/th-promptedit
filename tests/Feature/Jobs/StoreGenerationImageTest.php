<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const IMAGE_URL = 'https://pixelforge.test/images/gen_1.png?expires=1762351409&signature=abc';

function pngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

function succeededGenerationRecord(array $attributes = []): Generation
{
    return Generation::create($attributes + [
        'user_id' => User::factory()->create()->id,
        'provider_generation_id' => 'gen_1',
        'prompt' => 'A lighthouse',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => GenerationStatus::SUCCEEDED,
        'image_url' => IMAGE_URL,
        'actual_provider_cost' => '0.30551',
        'actual_customer_cost' => '0.3818875',
    ]);
}

/** Runs the job with a queue job attached so permanent failures (`fail()`) can be observed. */
function runImageJob(Generation $generation, bool $expectPermanentFailure = false): void
{
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('fail')->times($expectPermanentFailure ? 1 : 0);

    $job = new StoreGenerationImage($generation->id);
    $job->setJob($queueJob);
    $job->handle();
}

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    Storage::fake();
});

it('downloads the image and stores it at a predictable path', function () {
    Http::fake(['pixelforge.test/images/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png'])]);
    $generation = succeededGenerationRecord();

    runImageJob($generation);

    $path = "generations/{$generation->user_id}/{$generation->uuid}.png";

    $generation->refresh();

    expect($generation->image_path)->toBe($path)
        // The provider URL is kept for reference.
        ->and($generation->image_url)->toBe(IMAGE_URL)
        ->and(Storage::get($path))->toBe(pngBytes());

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === IMAGE_URL && ! $request->hasHeader('Authorization'));
});

it('uses the extension of the detected image type, not the response headers', function () {
    $jpeg = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($jpeg);
    $bytes = ob_get_clean();

    Http::fake(['pixelforge.test/images/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);
    $generation = succeededGenerationRecord();

    runImageJob($generation);

    expect($generation->refresh()->image_path)->toBe("generations/{$generation->user_id}/{$generation->uuid}.jpg");
});

it('does not download or store an image twice', function () {
    Http::fake(['pixelforge.test/images/*' => Http::response(pngBytes(), 200)]);
    $generation = succeededGenerationRecord();

    runImageJob($generation);
    runImageJob($generation);

    Http::assertSentCount(1);
    expect(Storage::allFiles())->toHaveCount(1);
});

it('does nothing when there is nothing to store', function (array $attributes) {
    Http::fake();
    $generation = succeededGenerationRecord($attributes);

    runImageJob($generation);

    Http::assertNothingSent();
    expect(Storage::allFiles())->toBeEmpty();
})->with([
    'not succeeded' => [['status' => GenerationStatus::FAILED]],
    'already stored' => [['image_path' => 'generations/1/existing.png']],
    'no image url' => [['image_url' => null]],
]);

it('only downloads from the configured Pixelforge host', function (string $url) {
    Http::fake();
    $generation = succeededGenerationRecord(['image_url' => $url]);

    runImageJob($generation, expectPermanentFailure: true);

    Http::assertNothingSent();
    expect($generation->refresh()->image_path)->toBeNull();
})->with([
    'other host' => ['https://evil.example.com/images/gen_1.png'],
    'allowed host as userinfo' => ['https://pixelforge.test@evil.example.com/images/gen_1.png'],
    'subdomain' => ['https://cdn.pixelforge.test/images/gen_1.png'],
    'allowed host as prefix' => ['https://pixelforge.test.evil.example.com/images/gen_1.png'],
    'allowed host as suffix' => ['https://evilpixelforge.test/images/gen_1.png'],
    'allowed host in path' => ['https://evil.example.com/pixelforge.test/images/gen_1.png'],
    'allowed host in query' => ['https://evil.example.com/images/gen_1.png?host=pixelforge.test'],
    'non-http scheme' => ['file://pixelforge.test/etc/passwd'],
    'relative url' => ['/images/gen_1.png'],
]);

it('downloads image urls in the documented format from the production host', function (string $url) {
    // The API reference serves succeeded images from the API host itself, under /images/.
    config()->set('pixelforge.base_url', 'https://pixelforge.promptedit.dev');
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response(pngBytes(), 200)]);
    $generation = succeededGenerationRecord(['image_url' => $url]);

    runImageJob($generation);

    expect($generation->refresh()->image_path)->toBe("generations/{$generation->user_id}/{$generation->uuid}.png");
    // The HTTP client normalises the host to lower case.
    Http::assertSent(fn ($request) => strcasecmp($request->url(), $url) === 0);
})->with([
    'documented example' => ['https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc123'],
    'host in a different case' => ['https://PixelForge.PromptEdit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc123'],
]);

it('rejects responses that are not valid images and retries', function (string $body) {
    Http::fake(['pixelforge.test/images/*' => Http::response($body, 200, ['Content-Type' => 'image/png'])]);
    $generation = succeededGenerationRecord();

    expect(fn () => runImageJob($generation))->toThrow(RuntimeException::class, 'not a PNG, JPEG or WebP');

    expect($generation->refresh()->image_path)->toBeNull()
        ->and(Storage::allFiles())->toBeEmpty();
})->with([
    'html error page' => ['<html>Service unavailable</html>'],
    'empty body' => [''],
    'truncated image' => [substr(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 0, 10)],
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
]);

it('gives up on an oversized image without retrying', function () {
    Http::fake(['pixelforge.test/images/*' => Http::response(pngBytes(), 200, ['Content-Length' => (string) (11 * 1024 * 1024)])]);
    $generation = succeededGenerationRecord();

    runImageJob($generation, expectPermanentFailure: true);

    expect($generation->refresh()->image_path)->toBeNull()
        ->and(Storage::allFiles())->toBeEmpty();
});

it('retries transient download failures', function (string $failure) {
    Http::fake(['pixelforge.test/images/*' => match ($failure) {
        'server_error' => Http::response('boom', 500),
        'rate_limited' => Http::response('slow down', 429),
        'timeout' => fn () => throw new ConnectionException('cURL error 28: timed out'),
    }]);
    $generation = succeededGenerationRecord();

    expect(fn () => runImageJob($generation))->toThrow(RuntimeException::class);

    expect($generation->refresh()->image_path)->toBeNull();
})->with(['server_error', 'rate_limited', 'timeout']);

it('gives up without retrying when the url is no longer valid', function (int $status) {
    Http::fake(['pixelforge.test/images/*' => Http::response('nope', $status)]);
    $generation = succeededGenerationRecord();

    runImageJob($generation, expectPermanentFailure: true);

    expect($generation->refresh()->image_path)->toBeNull();
})->with([403, 404, 302]);

it('stores the image on a later attempt after a failure, leaving billing untouched', function () {
    Http::fake(['pixelforge.test/images/*' => Http::sequence()
        ->push('boom', 500)
        ->push(pngBytes(), 200)]);
    $generation = succeededGenerationRecord();

    expect(fn () => runImageJob($generation))->toThrow(RuntimeException::class);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->actual_provider_cost)->toBe('0.30551000')
        ->and($generation->actual_customer_cost)->toBe('0.3818875000')
        ->and($generation->image_path)->toBeNull();

    runImageJob($generation);

    $generation->refresh();
    expect($generation->image_path)->not->toBeNull()
        ->and($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->actual_provider_cost)->toBe('0.30551000')
        ->and($generation->actual_customer_cost)->toBe('0.3818875000');

    Http::assertSentCount(2);
});

it('does not leak the signed image url into the thrown exception', function () {
    Http::fake(['pixelforge.test/images/*' => fn () => throw new ConnectionException('failed for '.IMAGE_URL)]);
    $generation = succeededGenerationRecord();

    try {
        runImageJob($generation);
        $this->fail('Expected the job to throw.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('signature')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('is configured to retry with a backoff and to be unique per generation', function () {
    $job = new StoreGenerationImage(7);

    expect($job->tries)->toBe(5)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff())->toBe([10, 60, 300, 900])
        ->and($job->uniqueId())->toBe('7')
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->toBeInstanceOf(ShouldQueue::class);
});
