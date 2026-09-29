<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The in-app notification bell and its live feed.
 *
 * WHY POLLING RATHER THAN WEBSOCKETS
 * This endpoint is polled every few seconds by the header bell. WebSockets
 * (Laravel Reverb) would be the fashionable answer, but they need a second
 * long-lived server process to run and keep running, and government networks
 * routinely block or proxy-break WebSocket upgrades. For an internal system of a
 * few dozen users, a small indexed query on a ten-second interval delivers the
 * same "no refresh" experience with no extra infrastructure to operate.
 *
 * The cost is bounded deliberately: the query is covered by the idx_bell index,
 * returns at most ten rows, and the browser stops polling entirely when the tab
 * is hidden.
 */
class NotificationController extends Controller
{
    private const FEED_LIMIT = 10;

    /**
     * JSON feed for the bell. Called on a timer, so it stays deliberately small.
     */
    public function feed(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $items = Notification::query()
            ->forBell($userId)
            ->limit(self::FEED_LIMIT)
            ->get();

        $unread = Notification::query()->forBell($userId)->unread()->count();

        return response()->json([
            'unread' => $unread,
            // The client compares this against the last id it saw, so it can tell
            // a genuinely new notification from one it has already shown.
            'latest_id' => $items->first()?->id ?? 0,
            'items' => $items->map(fn (Notification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->action_url,
                'read' => $n->isRead(),
                'age' => $n->age(),
                'tone' => $n->event()?->tone() ?? 'neutral',
            ])->all(),
            'polled_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Full history page.
     */
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => Notification::query()
                ->forBell($request->user()->id)
                ->with('bookingRequest')
                ->paginate(20),
            'unread' => Notification::query()->forBell($request->user()->id)->unread()->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse|RedirectResponse
    {
        // Ownership check: a notification id is guessable, and its body can name
        // another department's applicant.
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->markRead();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        Notification::query()
            ->forBell($request->user()->id)
            ->unread()
            ->update(['read_at' => now(), 'status' => 'READ']);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'All notifications marked as read.');
    }
}
