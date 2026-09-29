<?php

/*
 * Child process for DoubleBookingRaceTest: one "administrator" attempting one
 * allotment, in its own PHP process with its own MySQL connection.
 *
 * Usage: php race_allot.php <requestId> <adminId> <roomId> <readyFile> <goFile>
 *
 * Boots the application, signals readiness, waits at the barrier until the
 * parent releases every child at once, then calls AllotmentService::allot and
 * prints a single JSON line describing the outcome.
 */

declare(strict_types=1);

use App\Application\Services\AllotmentService;
use App\Models\BookingRequest;
use App\Models\User;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $requestId, $adminId, $roomId, $readyFile, $goFile] = $argv;

$request = BookingRequest::findOrFail((int) $requestId);
$admin = User::findOrFail((int) $adminId);

// Warm the connection before the barrier so connect time does not stagger the start.
Illuminate\Support\Facades\DB::select('SELECT 1');

file_put_contents($readyFile, (string) getmypid());

$deadline = microtime(true) + 30;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        echo json_encode(['ok' => false, 'class' => 'Timeout', 'message' => 'barrier never released']);
        exit(2);
    }
    usleep(2000);
}

try {
    app(AllotmentService::class)->allot($request, $admin, [(int) $roomId]);
    echo json_encode(['ok' => true, 'class' => null, 'message' => null]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'class' => $e::class, 'message' => $e->getMessage()]);
}
