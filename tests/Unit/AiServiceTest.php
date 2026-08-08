<?php

use App\Enums\FieldType;
use App\Services\AiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The app is needed for config and the Http fake; no database is touched.
uses(TestCase::class);

beforeEach(function () {
    config([
        'formforge.ai.api_key' => 'test-key-do-not-leak',
        'formforge.ai.base_url' => 'https://api.openai.test/v1',
        'formforge.ai.model' => 'gpt-4o-mini',
    ]);

    // A stray request would mean a real provider call from the suite.
    Http::preventStrayRequests();
});

function aiReply(string $content, array $overrides = []): array
{
    return array_merge([
        'model' => 'gpt-4o-mini-2024-07-18',
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 340],
    ], $overrides);
}

function aiSchemaJson(): string
{
    return json_encode([
        'schema_version' => 1,
        'title' => 'Contact',
        'description' => '',
        'settings' => [],
        'sections' => [
            ['title' => 'Details', 'fields' => [
                ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name'],
            ]],
        ],
    ]);
}

it('posts to the configured endpoint with the bearer token and json mode', function () {
    Http::fake(['*' => Http::response(aiReply(aiSchemaJson()))]);

    app(AiService::class)->generateSchema('A contact form');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('https://api.openai.test/v1/chat/completions');
        expect($request->hasHeader('Authorization', 'Bearer test-key-do-not-leak'))->toBeTrue();
        expect($request['response_format'])->toBe(['type' => 'json_object']);
        expect($request['model'])->toBe('gpt-4o-mini');

        return true;
    });
});

it('reports the model tokens and latency', function () {
    Http::fake(['*' => Http::response(aiReply(aiSchemaJson()))]);

    $result = app(AiService::class)->generateSchema('A contact form');

    expect($result['model'])->toBe('gpt-4o-mini-2024-07-18');
    expect($result['prompt_tokens'])->toBe(120);
    expect($result['completion_tokens'])->toBe(340);
    expect($result['latency_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('describes every allowed field type and forbids the rest', function () {
    Http::fake(['*' => Http::response(aiReply(aiSchemaJson()))]);

    app(AiService::class)->generateSchema('A contact form');

    Http::assertSent(function (Request $request): bool {
        $system = $request['messages'][0]['content'];

        foreach (FieldType::values() as $type) {
            expect($system)->toContain($type);
        }

        expect($system)->toContain('Never invent a field type');

        return true;
    });
});

it('tells the model to preserve keys when editing', function () {
    Http::fake(['*' => Http::response(aiReply(aiSchemaJson()))]);

    app(AiService::class)->editSchema(['title' => 'X', 'sections' => []], 'Add a phone field');

    Http::assertSent(function (Request $request): bool {
        $system = $request['messages'][0]['content'];

        expect($system)->toContain('Key preservation is absolute');
        expect($system)->toContain('copied byte for byte');
        expect($system)->toContain('omit the original');

        return true;
    });
});

it('sends the rejection reasons back on a repair', function () {
    Http::fake(['*' => Http::response(aiReply(aiSchemaJson()))]);

    app(AiService::class)->repairSchema(
        '{"broken": true}',
        ['sections.0.fields.0.type' => ['Unsupported field type [signature].']],
        'A contact form',
    );

    Http::assertSent(function (Request $request): bool {
        $user = $request['messages'][1]['content'];

        expect($user)->toContain('sections.0.fields.0.type');
        expect($user)->toContain('Unsupported field type [signature].');
        expect($user)->toContain('A contact form');

        return true;
    });
});

it('extracts a schema wrapped in markdown fences', function () {
    Http::fake(['*' => Http::response(aiReply("```json\n".aiSchemaJson()."\n```"))]);

    $result = app(AiService::class)->generateSchema('A contact form');

    expect($result['candidate']['title'])->toBe('Contact');
});

it('extracts a schema surrounded by prose', function () {
    Http::fake(['*' => Http::response(aiReply('Sure! Here you go: '.aiSchemaJson().' Let me know.'))]);

    $result = app(AiService::class)->generateSchema('A contact form');

    expect($result['candidate']['title'])->toBe('Contact');
});

it('unwraps a schema envelope', function () {
    Http::fake(['*' => Http::response(aiReply('{"schema": '.aiSchemaJson().'}'))]);

    $result = app(AiService::class)->generateSchema('A contact form');

    expect($result['candidate']['title'])->toBe('Contact');
    expect($result['candidate'])->toHaveKey('sections');
});

it('returns a null candidate for unparseable output', function () {
    Http::fake(['*' => Http::response(aiReply('I am unable to help with that request.'))]);

    $result = app(AiService::class)->generateSchema('A contact form');

    expect($result['candidate'])->toBeNull();
    expect($result['raw'])->toBe('I am unable to help with that request.');
});

it('maps provider failures to safe messages', function (int $status, string $expected) {
    Http::fake(['*' => Http::response(['error' => ['message' => 'sk-secret leaked in body']], $status)]);

    expect(fn () => app(AiService::class)->generateSchema('A contact form'))
        ->toThrow(RuntimeException::class, $expected);
})->with([
    [401, 'The AI provider rejected the credentials for this server.'],
    [429, 'The AI provider is rate limiting this server. Try again shortly.'],
    [500, 'The AI provider is unavailable. Try again shortly.'],
    [400, 'The AI provider rejected the request.'],
]);

it('never leaks the key or the provider body in an error', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'sk-secret leaked in body']], 400)]);

    try {
        app(AiService::class)->generateSchema('A contact form');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('test-key-do-not-leak');
        expect($exception->getMessage())->not->toContain('sk-secret');

        return;
    }

    $this->fail('The provider failure did not raise an exception.');
});

it('maps a connection timeout to a safe message', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => app(AiService::class)->generateSchema('A contact form'))
        ->toThrow(RuntimeException::class, 'The AI provider did not respond in time.');
});

it('reports whether a key is configured', function () {
    expect(app(AiService::class)->isConfigured())->toBeTrue();

    config(['formforge.ai.api_key' => '']);
    expect(app(AiService::class)->isConfigured())->toBeFalse();

    config(['formforge.ai.api_key' => '   ']);
    expect(app(AiService::class)->isConfigured())->toBeFalse();
});
