<?php

// Temporary verification script: renders one real allotment letter to disk.
// Runs inside a transaction that is rolled back, so the database is untouched.

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Application\Services\AllotmentLetterService;
use App\Application\Services\AllotmentService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    (new Database\Seeders\RolePermissionSeeder)->run();
    $admin = User::factory()->role(RoleSlug::ADMIN)->create();
    $mgr = User::factory()->role(RoleSlug::MANAGER)->create();
    $emp = User::factory()->role(RoleSlug::USER)->reportingTo($mgr)->create(['name' => 'Rajesh Kumar', 'designation' => 'Income Tax Officer']);
    $type = RoomType::factory()->create(['name' => 'Deluxe AC', 'default_capacity' => 2]);
    Tariff::factory()->create(['room_type_id' => $type->id, 'amount_per_night' => '1500.00']);

    $req = BookingRequest::factory()->for_($emp)->status(RequestStatus::PENDING_ALLOTMENT)->members(3)
        ->dates(today()->format('Y-m-d'), today()->addDays(2)->format('Y-m-d'))->create();
    $req->occupants()->create(['name' => 'Rajesh Kumar', 'is_primary' => true, 'age' => 40]);
    $req->occupants()->create(['name' => 'Sunita Kumar', 'relation' => 'Spouse', 'age' => 38]);
    $req->occupants()->create(['name' => 'Aarav Kumar', 'relation' => 'Son', 'age' => 10]);

    $ids = [Room::factory()->ofType($type)->number('101')->create()->id, Room::factory()->ofType($type)->number('102')->create()->id];
    $req = app(AllotmentService::class)->allot($req, $admin, $ids);

    $letter = app(AllotmentLetterService::class)->render($req);
    file_put_contents($argv[1], $letter['bytes']);
    echo Allotment::where('booking_request_id', $req->id)->orderBy('id')->value('qr_token'), "\n";
} finally {
    DB::rollBack();
}
