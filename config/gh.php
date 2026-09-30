<?php

declare(strict_types=1);

/**
 * Application-specific settings for the Guest House system.
 *
 * Institution details live here rather than being hardcoded in Blade templates
 * so the same codebase can serve another guest house by changing .env only.
 */
return [

    'institution' => env('GH_INSTITUTION', 'NADT, RC, DTRTI'),
    'building' => env('GH_BUILDING', 'Pragya Bhawan'),
    'city' => env('GH_CITY', 'Lucknow, Uttar Pradesh'),
    'pin' => env('GH_PIN', '226002'),

    /**
     * Master switch for booking notifications: emails, the in-app bell and the
     * stay reminders. Off by default — the system works silently. Set
     * GH_NOTIFICATIONS_ENABLED=true in .env to turn them back on. Password
     * reset emails are not affected; they are needed to sign in.
     */
    'notifications_enabled' => (bool) env('GH_NOTIFICATIONS_ENABLED', false),

    /**
     * Reception desk, shown on the public landing page as tap-to-call links.
     * Comma-separated in .env: GH_RECEPTION_PHONES="9918143306,9415984377"
     */
    'reception_phones' => array_values(array_filter(array_map('trim', explode(',',
        (string) env('GH_RECEPTION_PHONES', '9918143306,9415984377,8477862418')
    )))),

    /**
     * "Get directions" target on the landing page. A Google Maps search URL
     * opens the map (or the Maps app on a phone) straight on the campus.
     */
    'map_url' => env('GH_MAP_URL',
        'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Pragya Bhawan NADT RC Lucknow')),

    /**
     * Blocks shown on the Manager's room board, keyed by rooms.block, with the
     * name staff know them by. A block missing from this list is hidden from
     * the board (and so cannot be held by a Manager). Block B / Hostel B is not
     * in service yet; add 'B' => 'Hostel B' here when it is.
     */
    'room_board_blocks' => [
        'A' => 'Hostel A',
    ],

    /**
     * How long uploaded ID proofs are retained after checkout.
     * SECURITY.md section 2 — retention.
     */
    'id_proof_retention_months' => (int) env('GH_ID_PROOF_RETENTION_MONTHS', 12),

    /**
     * Upload constraints for ID proofs. Enforced by MIME sniff, not extension.
     */
    'upload' => [
        'max_kb' => 5120,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /**
     * Notification channel switches (NOTIFICATIONS.md).
     * SMS is off by default because no gateway is configured locally.
     */
    'notify' => [
        'email' => (bool) env('NOTIFY_EMAIL_ENABLED', true),
        'sms' => (bool) env('NOTIFY_SMS_ENABLED', false),
        'in_app' => (bool) env('NOTIFY_INAPP_ENABLED', true),

        /**
         * How often the header bell polls for new notifications, in seconds.
         *
         * Ten seconds reads as instant to a user while costing one small indexed
         * query per active tab. Polling stops while the tab is hidden.
         */
        'poll_seconds' => (int) env('NOTIFY_POLL_SECONDS', 10),
    ],

];
