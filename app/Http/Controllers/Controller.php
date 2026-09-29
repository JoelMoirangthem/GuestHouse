<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 12 ships this base class empty, so $this->authorize() is not
     * available until the trait is added. Policies are authorization layer 3 in
     * SECURITY.md section 4 and are used throughout, so it belongs here rather
     * than being repeated in every controller.
     */
    use AuthorizesRequests;
}
