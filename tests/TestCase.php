<?php

namespace Tests;

use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Approve a request as the Manager. Room selection is mandatory, so this
     * creates as many free rooms as the request needs and approves with them.
     *
     * @param  array<string, mixed>  $data  extra form fields, e.g. remarks
     */
    protected function approveWithRooms(User $manager, BookingRequest $request, array $data = []): TestResponse
    {
        $rooms = Room::factory()->count(max(1, (int) $request->rooms_needed))->create();

        return $this->actingAs($manager)->post(
            route('manager.requests.approve', $request),
            $data + ['room_ids' => $rooms->pluck('id')->all()],
        );
    }
}
