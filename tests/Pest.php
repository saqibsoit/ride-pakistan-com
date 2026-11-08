<?php

declare(strict_types=1);

use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| All tests boot through Tests\TestCase, which refuses to run unless the
| database name ends with "_testing". Feature tests start from a clean
| database (migrated once per run, each test wrapped in a transaction).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Browser smoke tests (Pest browser plugin + Playwright Chromium).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->in('Browser');

// Upper bound for browser waits (page loads, assertions), not a delay:
// each wait ends as soon as its condition holds. The test server runs in
// the test process, so a busy machine can need more than the default 5 s.
pest()->browser()->timeout(15_000);

// Real parallel processes need committed data: truncate instead of
// wrapping each test in a transaction the other processes can't see.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('concurrency')
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Write a solid-colour JPEG of the given size to a (faked) disk and return its path.
 */
function putTestImage(string $disk, string $path, int $width, int $height, string $format = 'jpg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 139, 90, 43));

    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 80);
    $binary = (string) ob_get_clean();

    Storage::disk($disk)->put($path, $binary);

    return $path;
}

/**
 * Decode the page's JSON-LD @graph (one script per page).
 *
 * @return list<array<string, mixed>>
 */
function jsonLdGraph(string $html): array
{
    preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $matches);

    expect($matches[1])->toHaveCount(1);

    /** @var array{'@context': string, '@graph': list<array<string, mixed>>} $data */
    $data = json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);

    expect($data['@context'])->toBe('https://schema.org');

    return $data['@graph'];
}

/**
 * Every @type used anywhere in a JSON-LD structure.
 *
 * @param  array<mixed>  $node
 * @return list<string>
 */
function jsonLdTypes(array $node): array
{
    $types = [];

    array_walk_recursive($node, function (mixed $value, string|int $key) use (&$types): void {
        if ($key === '@type' && is_string($value)) {
            $types[] = $value;
        }
    });

    return array_values(array_unique($types));
}

/** The content of <meta name="..."> or <meta property="...">. */
function metaContent(string $html, string $name): ?string
{
    $pattern = '#<meta (?:name|property)="'.preg_quote($name, '#').'" content="([^"]*)">#';

    return preg_match($pattern, $html, $m) === 1 ? html_entity_decode($m[1]) : null;
}

/**
 * A bookable slot a few days ahead (the slot factory spreads slots over
 * years, past any credit pack's expiry). $hour is local (Asia/Karachi).
 *
 * @param  array<string, mixed>  $attributes
 */
function nearSlot(int $daysAhead = 3, int $hour = 8, array $attributes = []): Slot
{
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays($daysAhead)->setTime($hour, 0);

    return Slot::factory()->at($local)->create($attributes);
}

/**
 * Query budget and N+1 guard (milestone 11): runs $work, then fails when
 * it ran more than $max queries, or the same query (same SQL, any
 * bindings) more than $maxRepeats times, which is the signature of an
 * N+1 loop. Returns the queries for further checks.
 *
 * @return list<string> the SQL of each query, in order
 */
function assertQueryBudget(Closure $work, int $max, int $maxRepeats = 3): array
{
    $queries = [];
    $recording = true;

    DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
        if ($recording) {
            $queries[] = $query->sql;
        }
    });

    $work();
    $recording = false;

    if (getenv('QUERY_BUDGET_REPORT') !== false) {
        fwrite(STDOUT, '
  queries: '.count($queries)." (budget {$max})
");
    }

    $shapes = array_count_values($queries);
    arsort($shapes);
    $top = array_slice($shapes, 0, 5, true);
    $report = implode("\n", array_map(fn (string $sql, int $n): string => $n.' × '.$sql, array_keys($top), $top));

    expect(count($queries))->toBeLessThanOrEqual($max, count($queries).' queries (budget '.$max."). Most repeated:\n".$report)
        ->and((int) reset($shapes))->toBeLessThanOrEqual($maxRepeats, "A query ran more than {$maxRepeats} times (N+1?):\n".$report);

    return $queries;
}
