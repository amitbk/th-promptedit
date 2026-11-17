<?php

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    config()->set('pixelforge.timeout', 5);
});

it('returns decoded JSON and sends auth headers on GET', function () {
    Http::fake(['pixelforge.test/*' => Http::response(['id' => 1], 200)]);

    $result = (new PixelforgeClient)->get('/things', ['page' => 2]);

    expect($result)->toBe(['id' => 1]);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://pixelforge.test/things')
        && $request->data() === ['page' => 2]
        && $request->hasHeader('Authorization', 'Bearer secret-key')
        && $request->hasHeader('Accept', 'application/json'));
});

it('sends a JSON body on POST', function () {
    Http::fake(['pixelforge.test/*' => Http::response(['ok' => true], 201)]);

    $result = (new PixelforgeClient)->post('/things', ['name' => 'a']);

    expect($result)->toBe(['ok' => true]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->isJson()
        && $request->data() === ['name' => 'a']
        && $request->hasHeader('Authorization', 'Bearer secret-key'));
});

it('throws a client error on unauthorized responses', function () {
    Http::fake(['pixelforge.test/*' => Http::response(['message' => 'Unauthenticated'], 401)]);

    try {
        (new PixelforgeClient)->get('/things');
        $this->fail('Expected PixelforgeException');
    } catch (PixelforgeException $e) {
        expect($e->status)->toBe(401)
            ->and($e->isClientError())->toBeTrue()
            ->and($e->payload)->toBe(['message' => 'Unauthenticated'])
            ->and($e->getMessage())->not->toContain('secret-key');
    }
});

it('throws a server error on 5xx responses', function () {
    Http::fake(['pixelforge.test/*' => Http::response('boom', 500)]);

    try {
        (new PixelforgeClient)->post('/things');
        $this->fail('Expected PixelforgeException');
    } catch (PixelforgeException $e) {
        expect($e->status)->toBe(500)
            ->and($e->isServerError())->toBeTrue();
    }
});

it('wraps connection failures without status or secrets', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out, Authorization: Bearer secret-key'));

    try {
        (new PixelforgeClient)->get('/things');
        $this->fail('Expected PixelforgeException');
    } catch (PixelforgeException $e) {
        expect($e->isConnectionError())->toBeTrue()
            ->and($e->getMessage())->not->toContain('secret-key')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('fails fast when not configured', function () {
    config()->set('pixelforge.api_key', null);
    Http::fake();

    expect(fn () => (new PixelforgeClient)->get('/things'))->toThrow(PixelforgeException::class);

    Http::assertNothingSent();
});
