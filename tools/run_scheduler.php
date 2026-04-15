<?php
// Simple CLI runner to invoke the SchedulerService::recalculateForUser
require __DIR__ . '/../Framework/ClassLoader.php';

use App\Services\SchedulerService;

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$userId = isset($argv[1]) ? (int)$argv[1] : 1;

echo "Running scheduler for user={$userId}\n";

try {
    $s = new SchedulerService();
    $s->recalculateForUser($userId);
    echo "Scheduler run completed for user={$userId}\n";
} catch (Throwable $e) {
    echo "Scheduler error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

