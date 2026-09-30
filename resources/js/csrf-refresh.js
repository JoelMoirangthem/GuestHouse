/**
 * Keeps the CSRF token on guest pages (sign-in, password reset, the public
 * requisition form) valid for as long as the page stays open.
 *
 * Why: the server session idles out after SESSION_LIFETIME minutes. A sign-in
 * page left open longer than that — or restored from the back/forward cache —
 * still carries the old token, and submitting it fails with "Your session has
 * expired". Re-fetching the current page renews the session and hands back the
 * current token, which is copied into the page's meta tag and every form.
 *
 * Only active when the layout emits <meta name="gh-csrf-refresh">, which it does
 * for guests only: on signed-in pages the idle timeout is intentional
 * (SECURITY.md section 3) and must not be kept alive by an open tab.
 */
const REFRESH_EVERY_MS = 5 * 60 * 1000;
const CHECK_EVERY_MS = 30 * 1000;

let lastRefresh = Date.now();
let inFlight = null;

function applyToken(token) {
    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
    document.querySelectorAll('input[name="_token"]').forEach((input) => {
        input.value = token;
    });
}

function refresh() {
    if (inFlight) return inFlight;

    inFlight = fetch(window.location.href, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'text/html' },
    })
        .then((res) => (res.ok ? res.text() : null))
        .then((html) => {
            if (!html) return;
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const token = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (token) {
                applyToken(token);
                lastRefresh = Date.now();
            }
        })
        .catch(() => {
            // Offline or server restarting: try again on the next tick. The
            // server-side fallback still recovers a stale submit gracefully.
        })
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

function refreshIfStale(maxAgeMs = REFRESH_EVERY_MS) {
    if (Date.now() - lastRefresh >= maxAgeMs) refresh();
}

export function startCsrfRefresh() {
    if (!document.querySelector('meta[name="gh-csrf-refresh"]')) return;

    // A wall-clock check rather than one long timer: background tabs and
    // sleeping laptops pause timers, and this catches up on the first tick after.
    setInterval(() => refreshIfStale(), CHECK_EVERY_MS);

    // Returning to the tab after a while, or a Back navigation that restores
    // the page from the back/forward cache with its old token.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refreshIfStale(60 * 1000);
    });
    window.addEventListener('focus', () => refreshIfStale(60 * 1000));
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) refresh();
    });
}
