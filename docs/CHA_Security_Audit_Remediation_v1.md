# CHA Security Audit Remediation v1

**Audit:** GitHub Copilot review of `whitelabel-heritage-trail`, 26 Aug 2026
**Brief:** `CHA_Security_Audit_Remediation_Build_Brief_ClaudeCode_v1.md`
**Branch:** `trail-grouping-and-order`
**Status:** all five items fixed and tested locally. **Nothing has been deployed** — no
`wrangler deploy`, no plugin re-upload. Awaiting Danie's approval.

Items are numbered as in the brief. They were worked in the brief's priority order
(3 → 2 → 4 → 1 → 5); they are written up here in item order for cross-reference.

---

## Summary

| # | Issue | Where | Status |
|---|-------|-------|--------|
| 1 | Voucher failures treated as successful redemptions | `app/index.html` | Fixed |
| 2 | Unlimited Paystack sessions via `/checkout` | `class-cha-paystack.php` | Fixed (+ 1 Cloudflare action for Danie) |
| 3 | Payment could start without a purchase row | `class-cha-paystack.php` | Fixed |
| 4 | Voucher stock not concurrency-safe | `class-cha-redeem.php` | Fixed |
| 5 | Inconsistent HTML escaping of WordPress feed values | `app/index.html` | Fixed |

Three files changed, plus a new regression suite under `tests/`.

---

## 1. Voucher failures are no longer treated as redemptions

**Was:** every outcome of `/redeem` — including 401 invalid token, 403 sold out,
403 expired, a 500, a dropped connection and a timeout — called `onConfirmed()`
and rendered a usable voucher screen. The rejection was reported, at most, as a
small "could not sync with server" footnote *underneath a confirmed voucher*.

**Now:** `onConfirmed()` is reachable from exactly one place — an HTTP 200 whose
JSON body carries a literal `success: true`. Everything else shows a dedicated
red error panel (`#voucherErrMsg`) with the voucher-bearing sections hidden, and
does **not** write the local `cht_voucher_<partner>` flag.

Specifics:

- `redeemVoucherRemote()` now requires `xhr.status === 200 && data.success === true`.
  Previously any truthy `data.success` counted, and the HTTP status was ignored
  entirely — a 403 body was read the same way as a 200.
- The `!navigator.onLine` case is refused before the request is even attempted,
  with offline-specific copy.
- The old "legacy partner without `wpId` → confirm locally" branch is gone. Every
  partner on the `cha/v1/content` feed carries `wpId` (`CHA_Rest::partner_record`
  always sets it), so that branch was unreachable for real data and is now a
  malformed-record guard that shows an error.
- `already_redeemed` (409) is kept as a *server verdict*, not a failure: it shows
  the "already redeemed" used state, which is not a voucher. This is the
  two-devices-sharing-one-token case.

**Danie's decision (26 Aug 2026), applied as given:** no offline redemption. Server
confirmation is always required; if the phone is offline or the request fails for
any reason, an error screen is shown, never the voucher. No separate offline design
was built.

**UX trade-off worth knowing:** after a rejection the voucher code box is hidden
along with the confirm button. To retry after, say, a dropped connection, the
visitor closes the modal and taps "Show My Voucher" again. That is one extra tap
in exchange for there being no state in which a rejected redemption leaves
something on screen that a cashier could accept. Say the word if you'd rather have
an inline "Try again" for transient failures specifically.

---

## 2. `/checkout` rate limiting

**Was:** nothing prevented `/checkout` from being called repeatedly for arbitrary
email addresses. Each call created a real Paystack transaction.

**Now:** two counters in one 10-minute window, both checked and both counting the
attempt, so neither can be sidestepped by varying the other:

| Bucket | Limit | Why this number |
|---|---|---|
| Per normalised email | **4 / 10 min** | The precise one. A real buyer needs one checkout, plus a couple of retries after abandoning the Paystack page. Four is comfortably above genuine use and well below anything worth abusing. Normalised to lowercase + trimmed, so `Foo@Bar.COM` and `  foo@bar.com  ` are one identity. |
| Per client IP | **20 / 10 min** | A blunt flood cap, deliberately loose. South African mobile networks run heavy CGNAT — one public IP can legitimately be dozens of unrelated buyers — so a tight per-IP number would lock out real customers long before it inconvenienced a script. |

Blocked requests get **HTTP 429** with a JSON `message`, which the app's existing
checkout handler already surfaces to the buyer unchanged. Crucially, the block
happens *before* the Paystack call, so a blocked attempt costs nothing at Paystack.

Implementation follows the existing GRHS pattern: the same transient-counter
approach `/verify-token` already used, refactored into a shared
`rate_limited_bucket()` so both routes use one implementation.

### Side-effect fix: the client IP behind Cloudflare

`clarensheritage.org` resolves to Cloudflare (`104.21.36.80`, `172.67.190.102`, NS
`*.ns.cloudflare.com`), so `$_SERVER['REMOTE_ADDR']` at the origin is a Cloudflare
edge address unless the host restores the visitor IP. The pre-existing
`/verify-token` limiter used `REMOTE_ADDR` directly — meaning if the host is *not*
restoring visitor IPs, every visitor in the world has been sharing a handful of
buckets at 20 attempts/minute between them. A new `client_ip()` helper prefers
`CF-Connecting-IP` (validated as an IP) and falls back to `REMOTE_ADDR`.

**This is defence in depth, not a boundary.** `CF-Connecting-IP` is only
trustworthy while the origin refuses non-Cloudflare traffic — see the Cloudflare
actions below. The per-email bucket does not depend on it.

### Cloudflare actions for Danie (outside code scope)

**1. Rate-limiting rule.** Dashboard → your zone → **Security → WAF → Rate limiting rules
→ Create rule**:

| Field | Value |
|---|---|
| Rule name | `CHA checkout flood` |
| If incoming requests match | `(http.request.uri.path eq "/wp-json/cha/v1/checkout" and http.request.method eq "POST")` |
| Characteristics | *IP with NAT support* |
| Period | 10 seconds → set to **10 minutes** |
| Requests | **30** |
| Then take action | **Block** |
| Duration | **1 hour** |

Thirty is deliberately above the app's own per-IP 20 so the application-level limit
is what a slightly over-eager real buyer meets (a clear 429 message), and Cloudflare
is what a script meets. Worth adding the same shape for
`/wp-json/cha/v1/redeem` and `/wp-json/cha/v1/verify-token` once this one is proven.

**2. Lock the origin to Cloudflare** so `CF-Connecting-IP` cannot be spoofed by
hitting the origin IP directly: SSL/TLS → Origin Server → **Authenticated Origin
Pulls**, and/or a host-level firewall allowing only
[Cloudflare's IP ranges](https://www.cloudflare.com/ips/).

**Decided (26 Aug 2026, Danie):** checked cPanel (za-dns.com shared hosting) —
no Authenticated Origin Pulls option, and IP Blocker there is deny-only, not an
allow-list, so this would mean hand-editing `.htaccess` on the live site. Danie
judged the residual risk extremely low and chose to leave the origin unlocked
rather than take that on. The per-email checkout limiter does not depend on this
and is unaffected; what remains exposed is specifically the per-IP flood cap,
bypassable by hitting the origin directly with a forged `CF-Connecting-IP`.

**3. Confirm the host restores visitor IPs** (mod_remoteip / mod_cloudflare, or
the host's "Cloudflare real IP" setting). If it does, `REMOTE_ADDR` is already
correct and the header preference is simply redundant rather than load-bearing.

---

## 3. Payment can no longer be initiated without a purchase row

**Was:** `/checkout` called Paystack first and inserted the `pending` purchase row
afterwards, ignoring the insert's return value. A failed insert still returned a
working `payment_url` — so a buyer could pay with no row for `/verify-token` or the
webhook to resolve against. Money in, no token, no recovery path.

**Now, in order:**

1. Rate-limit check (item 2).
2. Secret-key check.
3. Mint token + reference, read the server-authoritative price.
4. **`insert_pending()` — return value checked.** On `false`: log loudly, return
   **HTTP 500** with `ok: false` and no `payment_url`, and **never call Paystack**.
   The buyer is not charged because no transaction was ever created.
5. Only then, Paystack Initialize Transaction.

**One deliberate choice worth flagging:** if Paystack *itself* fails after the row
is written, the `pending` row is left in place rather than deleted or marked
failed. It costs a stale row in the admin list, and it buys the buyer-protective
property: if a charge ever did land against that reference, the webhook still finds
the row, still marks it paid, and still sends the token email. Deleting the row
would turn that into a "reference not found" dead end. Failed initialisations
should be rare; if they accumulate visibly in the purchases screen, say so and I'll
add a cleanup.

---

## 4. Voucher stock is now concurrency-safe

**Was:** `gate_check()` read `usedCount`, then `increment_used_count()` read it
again and wrote `used + 1`. Two concurrent redemptions both passed the gate, both
read the same number, and both wrote the same increment — a limited voucher gets
oversold *and the counter doesn't even show it*.

**Now:** `increment_used_count()` is replaced by `claim_stock()`, a single
conditional UPDATE whose predicate MySQL evaluates under the row lock the UPDATE
itself takes:

```sql
UPDATE wp_postmeta
   SET meta_value = CAST(meta_value AS UNSIGNED) + 1
 WHERE post_id = %d
   AND meta_key = 'usedCount'
   AND CAST(meta_value AS UNSIGNED) < %d   -- maxVouchers
```

Zero affected rows means this caller lost the race, and it is told `sold_out` (403).
`gate_check()` is unchanged and now explicitly documented as *advisory* — it still
gives the app a fast, friendly rejection and keeps `voucher-status` reporting
availability, but it is no longer what protects the cap.

Three supporting details:

- **The redemptions row is rolled back** when the stock claim fails. `/redeem`
  writes the `(token, partner_id)` uniqueness row before claiming stock; leaving it
  behind after a lost race would burn the visitor's one shot at that partner and
  block a legitimate retry after a restock.
- **First-ever redemption.** `usedCount` is registered with a default rather than
  written on save, so a never-redeemed partner has no postmeta row and a raw
  UPDATE would match nothing (reading as "sold out"). The row is created first.
  Two simultaneous first-ever redemptions can leave a duplicate `usedCount` row —
  this cannot oversell (the UPDATE matches every such row and the predicate applies
  to each), it only makes that partner's displayed counter briefly odd.
- **`wp_cache_delete()`** after the raw UPDATE, since it bypasses the meta API and
  would otherwise leave a stale cached value for the rest of the request.

Vendors affected by the cap: Bibliophile, Firkin, Highland Coffee Roastery,
Post House — anyone with a non-zero **Maximum Vouchers**.

---

## 5. Consistent HTML escaping of WordPress feed values

**Was:** `escapeHtml()` existed and was applied in the site detail view, but not in
the trail list, the free-sites list, the passport grid, the partner cards, the
voucher modal, or the map popups. Names, addresses, offers, icons, descriptions,
logos and ids went into `innerHTML` raw.

**Now** every feed-derived value that reaches `innerHTML` passes through
`escapeHtml()`, plus three structural changes:

- **`safeToken()`** for values used as CSS classes or DOM ids (`s.id`, `p.id`,
  `s.ac`). Escaping alone is not enough for these: an escaped id can still break a
  selector or collide with an element the app owns. Anything outside
  `[A-Za-z0-9_-]` is dropped whole.
- **No string-built event handlers.** The partner logo's
  `onerror="this.parentNode.innerHTML='…'"` is now an `addEventListener`
  (`wireLogoFallback`), and the map popup's
  `onclick="openSiteFromMap('<feed id>')"` is now a real listener on a DOM-built
  popup, closing over `s.id` rather than interpolating it into code.
- **Partner logos go through `safeUrl()`.** A `javascript:`, `data:` or relative
  logo URL now produces no `<img>` at all, matching the policy `s.photo` and
  `s.map` already followed. *Note:* a plain `http://` logo URL would now be
  dropped — the browser was already blocking those as mixed content on an https
  app, so this makes an existing failure explicit rather than causing a new one.

`escapeHtml()` also now handles numbers rather than silently returning `''` for
them, so numeric feed fields can be passed through it without changing output.

---

## Verification

A regression suite was added at `tests/` — this also partly covers the audit's
second follow-up recommendation. It needs **no WordPress, no database, and no
access to the live site**: the PHP tests stub WP and `$wpdb`, and the browser tests
run against a local mock API.

```
./tests/run.sh          # all suites
```

| Suite | Covers | Result |
|---|---|---|
| `tests/test-checkout.php` | items 2, 3 | **19 passed, 0 failed** |
| `tests/test-redeem-stock.php` | item 4 | **22 passed, 0 failed** |
| `tests/test-app-browser.js` | items 1, 5 | **47 passed, 0 failed** |

Requires `php`, `node`, and a Chrome/Chromium binary. `tests/mock-api-server.js` can
also be run on its own (`node tests/mock-api-server.js`) to click through the
rejection paths by hand at `http://localhost:8788/?scenario=sold_out` etc.

Verifications the brief asked for specifically:

- **Item 1** — deliberately bad token (401), sold-out voucher (403), expired (403),
  a 500 with an HTML body, a dropped connection, a request that never answers
  (client timeout), a response body faking `success: "true"`, and `navigator.onLine`
  forced false. None shows a voucher screen; none writes the local used flag.
- **Item 2** — repeated `/checkout` calls: same email from six different IPs
  (blocked after 4), one IP with 25 distinct emails (blocked from the 21st), case
  and whitespace variants sharing a bucket, window expiry, and 22 distinct visitors
  behind one Cloudflare edge all being served. Each blocked attempt was confirmed
  not to reach Paystack.
- **Item 3** — a simulated DB insert failure: HTTP 500, no `payment_url`, and zero
  outbound Paystack calls. Also covered: a Paystack failure *after* the row exists
  leaves the pending row intact.
- **Item 4** — the pre-fix read-then-write is reproduced on the same interleaved
  schedule as a control (two visitors redeem a stock of 1 and `usedCount` still
  reads 1 — oversold, invisibly). The fix admits exactly one; 5 interleaved claims
  against a stock of 3 admit exactly 3; the loser's redemptions row is rolled back.
- **Item 5** — the content feed is served with `<script>` and `<img onerror=…>`
  payloads in every WordPress-sourced field (site name, address, icon, facts, story,
  photo credit, accent class, partner name, type, offer, offer label, offer sub,
  description, address, logo), then every render path is exercised: trail list, free
  sites, passport, partner cards, site detail, map markers and map popups.
  **Pre-fix control: 57 script executions. Post-fix: 0.** Payloads render as visible
  text; no `<script>` or attacker `<img>` element is created; no inline handler
  attributes survive in feed-rendered content.

---

## Not done (and why)

- **Follow-up: `requiredSite` is client-side only.** Untouched — it needs a
  server-issued proof or a QR/NFC mechanism at the site, which is a design decision
  and a separate piece of work, not a remediation.
- **Follow-up: automated regression tests.** Done. `tests/test-webhook-idempotency.php`
  (added 26 Aug 2026, 34 assertions) now covers webhook idempotency directly:
  signature rejection, the already-paid short-circuit on a replayed/duplicate
  delivery (no second email, no second Paystack call), `mark_paid()`'s atomic
  pending→paid transition when a webhook races the `/verify-token` redirect path,
  a DB error during the write (must return 500 so Paystack retries rather than
  silently losing the payment), and an amount/currency mismatch. Required
  extending `tests/bootstrap-wp-stubs.php` with `$wpdb->get_row()`/`update()`,
  `wp_mail()`, and a couple of small WP function stubs.
- **The final live Paystack transaction test** (Development Plan v0.11 §4/§7)
  remains the outstanding launch-readiness item. Items 2 and 3 touch that same
  `/checkout` path, so this work should go live *with* that test, not before it.

## Deployment checklist (when approved)

1. Re-zip and re-upload the WordPress plugin (`class-cha-paystack.php`,
   `class-cha-redeem.php`).
2. `wrangler deploy` for `app/index.html`.
3. Create the Cloudflare rate-limiting rule (item 2 above).
4. Run the live Paystack transaction test.
5. Redeem one real voucher end-to-end, and confirm a deliberately-bad token shows
   the new error panel rather than a voucher.
