<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Services\NotificationDispatcher;
use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Domain\Contracts\PdfGenerator;
use App\Domain\Contracts\QrGenerator;
use App\Infrastructure\Mail\GmailApiTransport;
use App\Infrastructure\Mail\GoogleTokenStore;
use App\Infrastructure\Notifications\EmailChannel;
use App\Infrastructure\Notifications\InAppChannel;
use App\Infrastructure\Notifications\SmsChannel;
use App\Infrastructure\Pdf\DomPdfGenerator;
use App\Infrastructure\Qr\BaconQrGenerator;
use App\Infrastructure\Queries\SqlAvailabilityQuery;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Binds domain contracts to their concrete implementations.
 *
 * Only genuinely swappable collaborators are bound here (PLAN.md decision 5).
 * Plain CRUD uses Eloquent directly, because wrapping every model in a
 * repository would be abstraction for its own sake.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The availability query is isolated because it is the one place where a
        // subtle error silently corrupts the room inventory, and because tests
        // need to be able to substitute it.
        $this->app->singleton(AvailabilityQueryInterface::class, SqlAvailabilityQuery::class);

        $this->app->singleton(GoogleTokenStore::class);

        // PDF and QR adapters — PLAN.md section 5 keeps both swappable.
        $this->app->singleton(PdfGenerator::class, DomPdfGenerator::class);
        $this->app->singleton(QrGenerator::class, BaconQrGenerator::class);

        /*
         * Notification channels, in delivery-preference order.
         *
         * Tagged so NotificationDispatcher receives them all without naming any
         * one of them. Adding WhatsApp later means one class plus one line here —
         * no change to the 19 event wirings.
         */
        $this->app->bind(NotificationDispatcher::class, fn ($app) => new NotificationDispatcher([
            $app->make(InAppChannel::class),
            $app->make(EmailChannel::class),
            $app->make(SmsChannel::class),
        ]));
    }

    public function boot(): void
    {
        /*
         * Register "gmail" as a mail transport.
         *
         * Doing it this way means every Mailable, notification and password-reset
         * message in the application is delivered through the Gmail API without
         * any of them knowing. Switching to SMTP or a department relay later is a
         * one-line change to MAIL_MAILER.
         */
        Mail::extend('gmail', fn (array $config = []) => new GmailApiTransport(
            $this->app->make(GoogleTokenStore::class)
        ));
    }
}
