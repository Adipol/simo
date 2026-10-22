<?php

declare(strict_types=1);

// Narrow subprocess protocol, invoked only by ReviewServiceTest; never loads project bootstrap.
$engine = getenv('PEP_REVIEW_ENGINE');
if ($engine === false) {
    fwrite(STDOUT, "SKIP: explicit PEP_REVIEW_ENGINE=pgsql required before bootstrap.\n");
    exit(0);
}
if ($engine !== 'pgsql' || $argc !== 4 || ! in_array($argv[1], ['holder', 'contender'], true)
    || ! preg_match('/\A[1-9][0-9]{0,17}\z/', $argv[2]) || ! preg_match('/\A[1-9][0-9]{0,17}\z/', $argv[3])) {
    fwrite(STDERR, "Usage: PEP_REVIEW_ENGINE=pgsql php review_concurrency.php holder|contender EVENT_ID USER_ID\n");
    exit(2);
}
require '/work/vendor/autoload.php';

use App\Exceptions\PepDirectory\ReviewConflictException;
use App\Models\PepDirectoryEvent;
use App\Models\User;
use App\Services\PepDirectory\DTOs\ReviewDecisionData;
use App\Services\PepDirectory\ReviewService;
use Illuminate\Database\Events\QueryExecuted;
use Tests\Support\PepDirectoryReviewTestCase;

try {
    $bootstrap = new class('subprocess') extends PepDirectoryReviewTestCase {};
    $app = $bootstrap->createApplication(); // Exact pinned config + guard BEFORE connector.
    $db = $app['db']->connection();
    $eventId = (int) $argv[2];
    $actor = User::findOrFail((int) $argv[3]);
    $event = PepDirectoryEvent::with('source')->findOrFail($eventId);
    if (! str_starts_with($event->source->identity, 'synthetic-review-') || ! str_starts_with($actor->name, 'synthetic-review-')) {
        throw new RuntimeException('Concurrency helper accepts only owned synthetic fixtures.');
    }
    $pid = $db->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $send = static function (array $message): void {
        fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL);
        fflush(STDOUT);
    };
    if ($argv[1] === 'holder') {
        $db->listen(static function (QueryExecuted $query) use ($pid, $send): void {
            if (str_starts_with($query->sql, 'select * from "pep_directory_events"') && str_ends_with($query->sql, 'for update')) {
                $send(['stage' => 'locked', 'pid' => $pid]);
                $read = [STDIN];
                $write = $except = [];
                if (stream_select($read, $write, $except, 3) !== 1 || trim((string) fgets(STDIN)) !== 'continue') {
                    throw new RuntimeException('Bounded holder release synchronization failed.');
                }
            }
        });
    } else {
        $send(['stage' => 'ready', 'pid' => $pid]);
    }
    try {
        $review = (new ReviewService())->decide($eventId, $actor, ReviewDecisionData::fromArray([
            'state' => $argv[1] === 'holder' ? 'confirmed' : 'discarded', 'expected_version' => 0,
            'reason' => 'Synthetic concurrent evidence review',
        ]));
        $send(['outcome' => 'review', 'version' => $review->version]);
    } catch (ReviewConflictException $error) {
        $send(['outcome' => 'conflict', 'expected' => $error->expectedVersion, 'actual' => $error->actualVersion]);
    }
    $db->disconnect();
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['error' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
