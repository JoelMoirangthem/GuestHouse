{{--
  Live notification bell.

  Updates without a page refresh by polling the JSON feed on a timer. Three
  details make that cheap rather than wasteful:

    1. polling stops entirely while the tab is hidden (Page Visibility API), so a
       forgotten tab costs nothing
    2. it fetches immediately on becoming visible again, so returning to the tab
       feels instant rather than waiting out the interval
    3. the response is ten rows from an indexed query

  A brand-new notification also raises a toast, so a decision arriving while the
  user is mid-task is noticed rather than sitting silently under the bell.
--}}

<div
    x-data="{
        open: false,
        unread: 0,
        items: [],
        lastSeenId: 0,

        /*
         * Distinguishes 'the very first poll of this page load' from 'a user who
         * genuinely has no notifications yet'.
         *
         * Without this flag, a check of lastSeenId !== 0 suppresses the toast for
         * a user's FIRST EVER notification — the one they are most likely to be
         * waiting for. Priming on the first response and toasting only on later
         * increases handles both cases correctly.
         */
        primed: false,
        toast: null,
        timer: null,
        interval: {{ (int) config('gh.notify.poll_seconds', 10) * 1000 }},
        failures: 0,

        init() {
            this.fetchFeed();
            this.start();

            // Pause while hidden; resume and refresh at once when visible again.
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) { this.stop(); } else { this.fetchFeed(); this.start(); }
            });
        },

        start() {
            this.stop();
            this.timer = setInterval(() => this.fetchFeed(), this.interval);
        },

        stop() {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
        },

        async fetchFeed() {
            try {
                const res = await fetch('{{ route('notifications.feed') }}', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                // A redirect to the login page means the session expired. Stop
                // polling rather than hammering the server with 302s.
                if (res.redirected || res.status === 401 || res.status === 419) { this.stop(); return; }
                if (!res.ok) throw new Error('HTTP ' + res.status);

                const data = await res.json();
                this.failures = 0;

                // Toast only for arrivals after this page has been primed, so a
                // reload does not re-announce everything already in the list.
                const isNew = this.primed && data.latest_id > this.lastSeenId;

                this.unread = data.unread;
                this.items = data.items;

                if (isNew) {
                    const top = data.items.find(i => !i.read) || data.items[0];
                    if (top) this.showToast(top);
                }

                this.lastSeenId = Math.max(this.lastSeenId, data.latest_id);
                this.primed = true;
            } catch (e) {
                // Back off after repeated failures so a server restart does not
                // produce a console full of errors.
                if (++this.failures >= 3) { this.interval = Math.min(this.interval * 2, 120000); this.start(); }
            }
        },

        showToast(item) {
            this.toast = item;
            setTimeout(() => { this.toast = null; }, 6000);
        },

        async markRead(item) {
            if (item.read) return;
            item.read = true;
            this.unread = Math.max(0, this.unread - 1);
            try {
                await fetch('/notifications/' + item.id + '/read', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
            } catch (e) { /* the next poll will reconcile */ }
        },

        async markAllRead() {
            this.items.forEach(i => i.read = true);
            this.unread = 0;
            try {
                await fetch('{{ route('notifications.readAll') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
            } catch (e) { /* the next poll will reconcile */ }
        },
    }"
    @keydown.escape="open = false"
    class="relative"
>
    {{-- Bell trigger --}}
    <button type="button" @click="open = !open"
            class="gh-btn gh-btn-ghost relative px-2"
            :aria-expanded="open.toString()"
            aria-haspopup="true"
            :aria-label="unread > 0 ? unread + ' unread notifications' : 'Notifications'">
        <svg class="h-[1.125rem] w-[1.125rem]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
        </svg>

        {{-- Count badge. aria-live so a screen reader announces an arrival. --}}
        <span x-show="unread > 0" x-cloak
              x-text="unread > 9 ? '9+' : unread"
              aria-live="polite"
              class="absolute -right-0.5 -top-0.5 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full bg-[--color-danger] px-1 text-[0.625rem] font-semibold text-white"></span>
    </button>

    {{-- Dropdown --}}
    <div x-show="open" x-cloak
         @click.outside="open = false"
         x-transition.origin.top.right.duration.150ms
         class="absolute right-0 z-50 mt-2 w-[22rem] overflow-hidden rounded-xl border border-[--color-line] bg-white shadow-[--shadow-float]"
         role="region" aria-label="Notifications">

        <div class="flex items-center justify-between gap-2 border-b border-[--color-line] px-4 py-2.5">
            <p class="text-sm font-semibold text-[--color-ink]">Notifications</p>
            <button type="button" @click="markAllRead()" x-show="unread > 0"
                    class="text-xs font-medium text-navy-700 hover:underline">
                Mark all read
            </button>
        </div>

        <div class="max-h-[22rem] overflow-y-auto">
            <template x-if="items.length === 0">
                <p class="px-4 py-8 text-center text-sm text-[--color-ink-muted]">Nothing yet.</p>
            </template>

            <template x-for="item in items" :key="item.id">
                <a :href="item.url || '#'"
                   @click="markRead(item)"
                   class="flex gap-3 border-b border-[--color-line] px-4 py-3 transition-colors last:border-b-0 hover:bg-[--color-surface-sunken]"
                   :class="!item.read && 'bg-navy-50/60'">
                    <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                          :class="item.read ? 'bg-transparent' : 'bg-[--color-danger]'"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-[--color-ink]" x-text="item.title"></span>
                        <span class="mt-0.5 block text-xs leading-snug text-[--color-ink-muted]" x-text="item.body"></span>
                        <span class="mt-1 block text-[0.6875rem] text-[--color-ink-faint]" x-text="item.age"></span>
                    </span>
                </a>
            </template>
        </div>

        <div class="border-t border-[--color-line] px-4 py-2.5">
            <a href="{{ route('notifications.index') }}" class="text-xs font-medium text-navy-700 hover:underline">
                View all notifications
            </a>
        </div>
    </div>

    {{-- Toast for arrivals while the user is mid-task.

         TELEPORTED TO BODY DELIBERATELY. The header carries backdrop-blur, and a
         backdrop-filter establishes a containing block — so a position:fixed
         element nested inside it resolves against the header instead of the
         viewport, and the toast appeared stuck under the top bar. Teleporting it
         to <body> escapes that containing block. --}}
    <template x-teleport="body">
        <div x-show="toast" x-cloak
             x-transition.opacity.duration.200ms
             class="fixed bottom-5 right-5 z-[60] w-[20rem] rounded-xl border border-[--color-line] bg-white p-4 shadow-[--shadow-float]"
             role="status" aria-live="polite">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-navy-100 text-navy-800">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-[--color-ink]" x-text="toast?.title"></p>
                    <p class="mt-0.5 text-xs leading-snug text-[--color-ink-muted]" x-text="toast?.body"></p>
                    <a :href="toast?.url" class="mt-1.5 inline-block text-xs font-medium text-navy-700 hover:underline">Open</a>
                </div>
                <button type="button" @click="toast = null"
                        class="shrink-0 text-[--color-ink-faint] hover:text-[--color-ink]" aria-label="Dismiss notification">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.3 6.3a1 1 0 011.4 0L10 8.6l2.3-2.3a1 1 0 111.4 1.4L11.4 10l2.3 2.3a1 1 0 01-1.4 1.4L10 11.4l-2.3 2.3a1 1 0 01-1.4-1.4L8.6 10 6.3 7.7a1 1 0 010-1.4z"/>
                    </svg>
                </button>
            </div>
        </div>
    </template>
</div>
