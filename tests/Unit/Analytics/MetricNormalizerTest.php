<?php

declare(strict_types=1);

use App\Enums\MetricType;
use App\Services\Analytics\MetricNormalizer;
use App\Services\Analytics\RawPayloadSanitizer;
use App\Services\Social\Data\AnalyticsBatch;
use App\Services\Social\Data\MetricSample;

uses(Tests\TestCase::class);

it('maps provider keys onto the canonical vocabulary', function () {
    $normalizer = new MetricNormalizer;

    expect($normalizer->resolveType('post_impressions_unique'))->toBe(MetricType::Reach)
        ->and($normalizer->resolveType('postImpressions'))->toBe(MetricType::Impressions)
        ->and($normalizer->resolveType('estimatedMinutesWatched'))->toBe(MetricType::WatchTime)
        ->and($normalizer->resolveType('total_engagements'))->toBe(MetricType::Engagement)
        ->and($normalizer->resolveType('retweets'))->toBe(MetricType::Shares)
        ->and($normalizer->resolveType('subscriber_count'))->toBe(MetricType::Followers);
});

it('never invents a reading for a metric the payload does not contain', function () {
    $normalizer = new MetricNormalizer;

    $normalized = $normalizer->normalizeRaw(
        App\Enums\SocialPlatform::Facebook,
        [
            'data' => [
                ['name' => 'post_impressions', 'values' => [['value' => 500, 'end_time' => '2026-09-20T00:00:00+0000']]],
            ],
        ],
    );

    expect($normalized->types())->toBe([MetricType::Impressions->value])
        ->and($normalized->accountMetrics[0]->value)->toBe(500.0)
        ->and($normalized->types())->not->toContain(MetricType::Reach->value);
});

it('keeps a reported zero because zero is a measurement', function () {
    $normalizer = new MetricNormalizer;

    $sample = $normalizer->normalizeSample(new MetricSample(metric: 'reach', value: 0));

    expect($sample)->not->toBeNull()
        ->and($sample->value)->toBe(0.0);
});

it('drops a non-numeric reading from a raw payload rather than coercing it', function () {
    $normalizer = new MetricNormalizer;

    $normalized = $normalizer->normalizeRaw(
        App\Enums\SocialPlatform::Facebook,
        ['likes' => 'n/a', 'comments' => 4, 'shares' => null],
    );

    expect($normalized->types())->toBe([MetricType::Comments->value])
        ->and($normalized->accountMetrics[0]->value)->toBe(4.0);
});

it('reports a provider key it cannot represent instead of forcing it into a bucket', function () {
    $normalizer = new MetricNormalizer;
    $unmapped = [];

    expect($normalizer->resolveType('post_reactions_by_type_total', $unmapped))->toBeNull()
        ->and($unmapped)->toContain('post_reactions_by_type_total');

    $normalized = $normalizer->normalizeBatch(
        App\Enums\SocialPlatform::Facebook,
        new AnalyticsBatch(
            provider: 'facebook',
            periodStart: new DateTimeImmutable('2026-09-20'),
            periodEnd: new DateTimeImmutable('2026-09-21'),
            accountMetrics: [
                new MetricSample(metric: 'likes', value: 3),
                new MetricSample(metric: 'completion_rate', value: 0.5),
                new MetricSample(metric: 'avg_view_duration_seconds', value: 12),
            ],
        ),
    );

    expect($normalized->types())->toBe(['likes'])
        ->and($normalized->unmapped)->toContain('completion_rate', 'avg_view_duration_seconds');
});

it('carries the provider metric subtype through unchanged', function () {
    $normalizer = new MetricNormalizer;

    $paid = $normalizer->normalizeSample(
        new MetricSample(metric: 'impressions', value: 10, dimensions: ['subtype' => 'Paid']),
    );

    $nested = $normalizer->normalizeSample(
        new MetricSample(metric: 'views', value: 10, dimensions: ['breakdown' => ['value' => 'video_views']]),
    );

    expect($paid->metricSubtype)->toBe('paid')
        ->and($nested->metricSubtype)->toBe('video_views');
});

it('keeps each reading in the period the provider reported', function () {
    $normalizer = new MetricNormalizer;

    $batch = $normalizer->normalizeBatch(
        App\Enums\SocialPlatform::YouTube,
        new AnalyticsBatch(
            provider: 'youtube',
            periodStart: new DateTimeImmutable('2026-09-01'),
            periodEnd: new DateTimeImmutable('2026-09-03'),
            accountMetrics: [
                new MetricSample(metric: 'views', value: 1, periodStart: '2026-09-01T00:00:00+00:00'),
                new MetricSample(metric: 'views', value: 2, periodStart: '2026-09-02T00:00:00+00:00'),
            ],
        ),
    );

    expect($batch->accountMetrics)->toHaveCount(2)
        ->and($batch->accountMetrics[0]->periodStart->format('Y-m-d'))->toBe('2026-09-01')
        ->and($batch->accountMetrics[1]->periodStart->format('Y-m-d'))->toBe('2026-09-02')
        ->and($batch->accountMetrics[0]->dedupeKey())->not->toBe($batch->accountMetrics[1]->dedupeKey());
});

it('redacts credentials and truncates an oversized payload', function () {
    $sanitizer = new RawPayloadSanitizer;

    $clean = $sanitizer->sanitize([
        'views' => 10,
        'access_token' => 'EAAB-secret',
        'nested' => ['refresh_token' => 'secret', 'client_secret' => 'secret'],
    ]);

    expect(json_encode($clean))
        ->not->toContain('EAAB-secret')
        ->and($clean['views'])->toBe(10)
        ->and($clean['access_token'])->toBe('[redacted]')
        ->and($clean['nested']['refresh_token'])->toBe('[redacted]')
        ->and($clean['nested']['client_secret'])->toBe('[redacted]');

    $huge = $sanitizer->sanitize(['breakdown' => array_fill(0, 500, ['value' => 1, 'note' => str_repeat('x', 400)])]);

    expect($huge)->toHaveKey('_truncated')
        ->and(strlen((string) json_encode($huge)))->toBeLessThan(RawPayloadSanitizer::MAX_BYTES);
});

it('returns nothing for an empty payload rather than an empty object', function () {
    expect((new RawPayloadSanitizer)->sanitize([]))->toBeNull()
        ->and((new RawPayloadSanitizer)->sanitize(null))->toBeNull();
});
