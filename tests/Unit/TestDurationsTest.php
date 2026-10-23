<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Coverage\TestDurations;

/**
 * @param list<list<string>> $groups
 * @param array<string, float> $seconds
 * @return list<float>
 */
function groupTotals(array $groups, array $seconds): array
{
    return array_map(
        static fn (array $group): float => array_sum(array_map(static fn (string $path): float => $seconds[$path], $group)),
        $groups,
    );
}

it('splits round-robin when no duration is recorded', function (): void {
    $durations = TestDurations::load('/missing/durations.json');

    expect($durations->split(['a', 'b', 'c', 'd', 'e'], 2))->toBe([['a', 'c', 'e'], ['b', 'd']])
        ->and($durations->with(['other' => 3.0])->split(['a', 'b', 'c'], 3))->toBe([['a'], ['b'], ['c']]);
});

it('puts the longest file first and every file into the group with the smallest total', function (): void {
    $seconds = ['a' => 1.0, 'b' => 9.0, 'c' => 4.0, 'd' => 3.0, 'e' => 2.0, 'f' => 1.0];

    $groups = TestDurations::load('/missing/durations.json')->with($seconds)->split(['a', 'b', 'c', 'd', 'e', 'f'], 3);

    expect($groups)->toBe([['b'], ['c', 'a', 'f'], ['d', 'e']]);
});

/**
 * @param non-empty-array<string, float> $seconds
 */
function expectGapWithinLongestFile(array $seconds, int $groupCount): void
{
    $groups = TestDurations::load('/missing/durations.json')->with($seconds)->split(array_keys($seconds), $groupCount);
    $totals = groupTotals($groups, $seconds);
    assert($totals !== []);

    expect(max($totals) - min($totals))->toBeLessThanOrEqual(max($seconds))
        ->and(array_merge(...$groups))->toEqualCanonicalizing(array_keys($seconds))
        ->and($groups)->toHaveCount($groupCount);
}

it('keeps the gap within the longest file when one file is far slower than the rest', function (): void {
    expectGapWithinLongestFile(['a' => 167.0, 'b' => 0.5, 'c' => 0.4, 'd' => 0.9, 'e' => 0.2], 3);
});

it('keeps the gap within the longest file when two files are slow', function (): void {
    expectGapWithinLongestFile(['a' => 126.0, 'b' => 104.0, 'c' => 1.0, 'd' => 0.7, 'e' => 0.3, 'f' => 0.2, 'g' => 0.1], 4);
});

it('keeps the gap within the longest file when all files take the same time', function (): void {
    expectGapWithinLongestFile(['a' => 2.0, 'b' => 2.0, 'c' => 2.0, 'd' => 2.0, 'e' => 2.0], 2);
});

it('keeps the gap within the longest file when there are more groups than slow files', function (): void {
    expectGapWithinLongestFile(['a' => 10.0, 'b' => 9.0, 'c' => 8.0, 'd' => 0.1], 4);
});

it('puts a file without a recorded duration first with the mean duration', function (): void {
    $groups = TestDurations::load('/missing/durations.json')->with(['a' => 6.0, 'b' => 2.0, 'c' => 4.0])->split(['a', 'b', 'c', 'new'], 2);

    expect($groups)->toBe([['new', 'c'], ['a', 'b']]);
});

it('gives every group a file when the recorded durations are zero', function (): void {
    $groups = TestDurations::load('/missing/durations.json')->with(['a' => 0.0, 'b' => 0.0, 'c' => 0.0])->split(['a', 'b', 'c'], 3);

    expect($groups)->toBe([['a'], ['b'], ['c']]);
});

it('stores the durations and keeps those of files the last run did not cover', function (): void {
    $directory = temporaryDirectory();
    $path = $directory . '/nested/durations.json';

    TestDurations::load($path)->with(['a' => 1.5, 'b' => 2.0])->save($path);
    TestDurations::load($path)->with(['b' => 3.0, 'c' => 0.25])->save($path);

    expect(json_decode((string) file_get_contents($path), associative: true))->toEqual(['a' => 1.5, 'b' => 3.0, 'c' => 0.25]);

    removeDirectory($directory);
});

it('reads a broken or foreign durations file as empty', function (string $content): void {
    $directory = temporaryDirectory();
    file_put_contents($directory . '/durations.json', $content);

    expect(TestDurations::load($directory . '/durations.json')->split(['a', 'b', 'c'], 2))->toBe([['a', 'c'], ['b']]);

    removeDirectory($directory);
})->with(['not json', '"text"', '{"a": "slow", "b": -1, "c": null}', '{"a": 1e999}']);
