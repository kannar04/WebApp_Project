# Home2Home — focused guest experience and FE–BE handoff

Status: agreed UX direction with an implementation delivered on 2026-11-05. The baseline and proposed contracts below are retained for traceability; use the implementation status immediately below and linked report to distinguish shipped behavior from remaining work. Repository baseline inspected on 2026-11-05. FE means frontend; BE means PHP controllers/services/models and database procedures.

## Implementation status — 2026-11-05

The focused guest journey is now connected: compact discovery; inventory-backed city suggestions with keyboard/accent handling; automatic 12-home batches using shared PHP card markup; failure/retry/end states; Back restoration; collapsed advanced filters; trip-aware property links; saved controls; mobile Explore/Saved/Trips; state-aware quote bar; read-only booking review; safe login/registration returns; duplicate-confirmation recovery; price-change acceptance; explicit map-area search with filter parity; and an upcoming-trip card.

Actual integration choices:

- City suggestions are embedded as escaped JSON by PHP from `sp_focused_destinations`; no `/api/destinations` endpoint was needed for the current inventory. Cities are exact catalog strings, with text search as fallback. Region/landmark catalogs are not implemented.
- `/api/listings` retains its response and adds `data.html` rendered from the same escaped card partial as the initial page. The partial includes authenticated saved states, localized labels, and trip-aware links.
- Search adds `city` and map bounds. The new `sp_focused_listing_search` preserves the legacy procedure and supports map/feed filters through one query. Offset pagination remains; no snapshot/cursor guarantee under changing inventory.
- Trip inputs live in validated URL/form context; signed-in review snapshots are session-scoped, per-token, expire after 30 minutes, and bind user/listing/inputs/price-policy fingerprint. Confirmation rechecks in a transaction. A repeated completed token returns the same booking.
- Anonymous save links ask for sign-in and restore browsing context; saving then uses the account wishlist. Anonymous persistent/cross-device shortlists are not introduced.
- Base nightly prices stay explicitly labeled on cards; selected-trip totals are authoritative on detail and review. No batch trip-total search pricing yet.
- Existing type, host, reviews, rules, and trust data remain authoritative. New shared-space/privacy fields and pre-booking messaging remain unimplemented data/product extensions.
- Homepage no longer downloads the decorative hero. Wider asset-format/font consolidation remains optional performance work. Physical-device, cross-browser, and human usability studies are not claimed.

Run `php tests/fresh_setup_test.php --focused-ui` for an owned disposable database and real pagination/booking tests. See [implementation notes and verification](ui-experience/focused/README.md). Deployment requires the additive `database/procedures/100_focused_experience.sql` routines; the local preview database has been updated without changing its records.

## 1. Product goal and accepted decisions

Help guests find and book the right home with minimal distraction. People may visit infrequently, then return repeatedly while comparing homes, booking, and managing their trip. Use familiar web/mobile behavior and preserve their context instead of requiring them to relearn or repeat work.

- Keep the cream/forest identity, good photography, readable typography, subtle feedback, and reduced-motion support. Shorten the mobile hero; homes and search take priority over promotion.
- First visit does **not** require a destination or dates. Show compact search, inventory-backed destination chips, and homes explicitly labeled as nationwide exploration. With the current small inventory, prioritize homes over large destination sections.
- Empty destination input shows recent searches when available and supported destinations. Typing replaces these with matching cities, regions, and landmarks. Do not automatically select a city or request location permission on arrival.
- Selecting an area narrows the feed. Dates remain optional for exploration; availability and trip totals require dates. Never quietly broaden a selected boundary.
- **Automatic loading** is the normal discovery behavior. Load more near the end without shifting existing cards. Provide retry, end-of-results, and accessible manual/no-JavaScript fallbacks.
- Cards have two main actions: **open** and **save**. Preserve loaded results, filters, and scroll position when returning from a property.
- Property pages explain photos/location, type/privacy/capacity, host/reviews, rules/cancellation, and total price. Carry dates and guest count forward.
- Primary action progresses from **Choose dates** to **Review booking** to an explicit final action distinguishing instant booking from a host-approved request. Browsing and quoting do not create a booking.
- Stable guest navigation centers on Explore, Saved, and Trips. After booking, surface an upcoming-trip card without moving familiar navigation. Messages, directions, check-in information, and support become useful next actions.
- Desktop supports real links, new tabs, multiple cards, and optional map/list comparison. Mobile supports thumb-friendly controls and one primary bottom action area; avoid stacked navigation and booking bars.

Not requirements: a social feed, AI recommendations, a new framework, or mandatory onboarding. Pre-booking host contact, anonymous cross-device saves, and new host/privacy fields require separate scope/data decisions; they are not existing capabilities.

## 2. Existing implementation: reuse these pieces

Routes verified in [routes/web.php](../routes/web.php).

| Existing capability | Entry point / source | Current limitation |
|---|---|---|
| Discovery page | `GET /`, [HomeController](../app/Controllers/HomeController.php), [home view](../app/Views/home.php) | Large hero; advanced filters expand for any active search. |
| Paginated search JSON | `GET /api/listings`, same controller | 12 items/page; offset pagination; no destination-suggestion endpoint. |
| Listing query | [Listing::searchPage](../app/Models/Listing.php), [055_quality_search.sql](../database/procedures/055_quality_search.sql) | Location matches city/address/title text, not a geographic place identity. |
| Map and bounds query | `GET /map`, `GET /api/listings/map`, [DiscoveryController](../app/Controllers/DiscoveryController.php) | Subset of home filters; cap of 100 results. |
| Property detail | `GET /listings/{id}`, [ListingController](../app/Controllers/ListingController.php), [detail view](../app/Views/listings/show.php) | Does not populate booking fields from trip search context. |
| Live price quote | `GET /api/listings/{id}/quote`, [BookingService](../app/Services/BookingService.php), [booking-extras.js](../public/assets/js/booking-extras.js) | Itemized quote exists; cards/mobile bar do not reflect a selected trip total. |
| Booking creation | `POST /listings/{id}/book`, [BookingController](../app/Controllers/BookingController.php) | Direct submit; needs review step and preserved input after errors. |
| Authentication | `GET/POST /login`, `GET/POST /register`, [AuthController](../app/Controllers/AuthController.php), [Controller](../core/Controller.php) | Referrer-derived login return drops query. Registration redirects home. No durable per-listing draft. |
| Account wishlist | `GET /wishlist`, `POST /api/wishlist/{id}`, [WishlistController](../app/Controllers/WishlistController.php) | Requires authentication; mutation toggles state; search JSON lacks saved state. |
| History/recommendations | [DiscoveryService](../app/Services/DiscoveryService.php), [Discovery model](../app/Models/Discovery.php) | Account-based history exists; guest recent searches and geographic autocomplete need work. |
| Trips | `GET /bookings`, `GET /bookings/{id}` | Reuse authorized booking data for upcoming-trip summary. |
| Messaging/check-in/payment | `GET /messages`, `POST /bookings/{id}/messages`, existing booking/payment routes | Messages are booking-based; preserve guide/payment authorization and state rules. |

Verified audit failures: trip dates/guests reset on property navigation and after login; valid searches expand the filter panel before results; mobile action remains “Choose dates” after quoting. See [audit evidence](ui-experience/focus-audit/README.md).

## 3. Task matrix: which UX changes affect the backend?

| ID / priority | UI/UX task | FE work | BE/data impact | Done when |
|---|---|---|---|---|
| F01 / P1 | Compact first-entry discovery | Short heading, visible search, type chips, homes early, guides secondary | Reuse listing/type data; inventory-backed destination choices need a query | Initial mobile viewport includes search and the beginning of a home; no invented counts. |
| F02 / P0 | Results before advanced controls | Collapse valid-search filters; show summary/count | Existing validation can be reused | Valid search reveals homes; invalid input exposes relevant errors without clearing values. |
| F03 / P1 | Automatic loading | Append, loading/retry/end states, stale-request protection, position restoration | Reuse `/api/listings`; normalize card/saved-state/pagination contract | No duplicate IDs or old-query cards; Back restores browsing position. |
| F04 / P1 | Destination suggestions | Keyboard/touch autocomplete with type and parent labels | New place suggestions/canonical location handling; regions/landmarks need data and queries | Place selection has defined scope; suggestions reflect actual public inventory. |
| F05 / P1 | List/map continuity | Preserve filters and selection; explicit “Search this area” | Map/feed filter parity, geographical feed query, cap semantics | Switching views preserves constraints; capped/missing-map results are explained. |
| F06 / P0 | Preserve trip context | Prefill and restore dates/guests after Back/auth/errors | Validated query context, per-listing drafts, safe return handling | Same trip survives each step; parallel tabs do not overwrite one another. |
| F07 / P1 | Save and shortlist | Heart states on initial/appended cards; recover after sign-in | Reuse wishlist; return saved state; explicit desired-state mutation may be needed | UI agrees with server after failures/reload/account changes. |
| F08 / P1 | Home-sharing decision facts | Type/privacy/host information before secondary amenities | Reuse type/trust/reviews; shared spaces/host presence/bathroom privacy may need schema and host forms | Every displayed fact comes from real data; unknowns stay honest. |
| F09 / P0 | Price, review, confirmation | State-aware price/action bar, itemized review, explicit submit | Reuse quote/create; add review flow, price-change handling, duplicate-submit protection | Guest sees current total and confirmation model before creating one booking. |
| F10 / P2 | Trip-centered return | Upcoming trip card linking to messages/check-in/payment | Reuse owner-authorized booking queries; bounded summary | Accurate state and authorized information; Explore remains available. |
| F11 / P2 | Familiar responsive interaction | Real links, keyboard focus, mobile overlays, reduced motion | Mostly FE; preserve route/access/locale contracts | Back/new-tab work; no hover-only actions or competing fixed bars. |
| F12 / P2 | Efficient assets | Responsive photography, necessary font weights | Usually assets/templates only | Measure mobile load; retain Vietnamese glyphs and asset licenses. |

P0 repairs the existing journey; P1 implements discovery/decision capabilities; P2 improves return visits and polish. These are implementation priorities, not the audit's severity labels.

## 4. Shared FE–BE contracts

### 4.1 Preserve the existing response envelope

`Core\Controller::json()` returns:

```json
{"success": true, "message": "Localized message", "data": {}, "errors": []}
```

Validation errors use HTTP 422, `success: false`, and keyed field errors (an object rather than an empty array). Authenticated JSON calls must send `Accept: application/json` so an expired session returns 401 instead of HTML login. Preserve CSRF for mutations and current locale handling. Check `success` as well as HTTP status. **Additions below are proposals, not current API guarantees.**

### 4.2 Search and automatic loading

Current `GET /api/listings` accepts:

```text
location, check_in, check_out, guests, type, min_price, max_price,
bedrooms, sort, page, amenities[]
sort = newest | price_asc | price_desc | rating
```

Current successful `data`: `listings`, `total`, `page`, `per_page`, `pages`. Rows include `trusted_host`; do not assume normalized `url`, formatted price, or `saved` exists today.

Implementation contract:

1. Reuse 12 items/page. PHP renders the first page and normal pagination for direct-link/no-JS use.
2. Share one card representation between initial and appended items. Add explicit public fields for resolved image/detail URL, localized type, rating/review count, price/currency, and saved state. Escape dynamic content; do not inject untrusted listing strings as HTML.
3. Derive the next page from `page < pages`, or add consistent `next_page`/`has_more`. Empty first results are valid. Out-of-range pages terminate loading; failures display retry without discarding existing cards.
4. One in-flight batch per query; discard stale responses when filters/sort change, reset pagination, and deduplicate IDs. Keep keyboard focus stationary and announce appended results briefly.
5. Verify deterministic ordering with a unique ID tie-breaker. Offset pagination can skip/repeat rows under inventory changes; FE deduplication does not fix omissions. Add a server cursor/snapshot if stable traversal under writes is required, not merely because auto-loading exists.
6. Keep canonical filters in the URL; keep loaded IDs/page depth/scroll anchor in per-tab history/session state. Do not create a history entry per automatic batch. Restore content before scroll; if cache is unavailable, reload batches to the anchor with a bounded recovery path. Be truthful about changed/unavailable homes.

Replace the existing `Search keeps filters expanded` assertion in [ui_experience.browser.py](../tests/ui_experience.browser.py): preserve values, not an always-open panel.

### 4.3 Destination and geographic scope — new work

No inspected `/api/destinations` endpoint exists. Proposed contract:

```text
GET /api/destinations?q=<text>&limit=6
data.items[] = { id, label, kind, parent_label, listing_count }
kind = city | region | landmark
```

Agree names before coding. IDs should reference a maintained place catalog or explicit stable place key, not arbitrary display strings. Only expose counts when accurate; distinguish public inventory counts from availability for specific dates.

- Start with cities derived from approved, visible, non-deleted homes with eligible hosts, using the same eligibility rules as search.
- Region membership and landmark coordinates/radius need real maintained data. Text matching a region name is not geographic membership. Existing city strings may need reconciliation/backfill before adding destination IDs and indexes.
- Test accented/unaccented matching. Disambiguate names using parent labels. Preserve manual text search as a fallback, distinguished from exact-area selection.
- Proposed search extensions: `destination_id`, landmark radius with explicit units/limits, or validated `north/south/east/west` bounds. Selecting a destination clears old map bounds. Committing “Search this area” replaces the destination restriction while preserving dates/guests/filters; make the changed scope visible.
- Broad regions can offer child destinations above the feed. Nearby alternatives require explicit selection and clear labeling.
- Distance needs known coordinates. Label straight-line distance if that is what is calculated; do not invent travel times. No automatic guest geolocation.

Map currently supports location, dates, guests, type, price, and API bounds. Bedrooms, amenities, sort, geographic feed search, and the 100-result cap need integration before claiming parity. Homes without coordinates need an honest list-only explanation.

### 4.4 Trip context and authentication

Keep browsing state separate from booking inputs:

| State | Suggested storage | Authority / lifecycle |
|---|---|---|
| Area, dates, guests, filters, sort | Validated URL query | Shareable; BE validates all inputs. |
| Loaded batches and scroll anchor | Per-tab history/session storage | FE restoration hint; reset when canonical search changes. |
| Listing draft: dates, guests, optional promo, safe return path | Server session keyed by draft/listing with bounded expiry | Survives auth/errors; avoid one global draft that breaks parallel tabs. |
| Saved homes | Account wishlist | Server-owned; anonymous persistence needs a separate decision. |
| Price and availability | Fresh quote service | Recalculate after restore/edit and at final submit. |
| Booking status | Booking record | Server-owned; clear submitted draft after success. |

Carry context in listing links, validate in `ListingController::show`, and prefill the view. Preserve a safe same-origin relative return target including validated query context through login **and registration**; account for app base paths and reject external/protocol-relative targets. Preserve input on session expiry and booking errors.

Auth returns to review/detail; never automatically replay a booking POST. A draft is not a reservation, and a restored total is not authoritative. Do not replace a selected guest count with the default one.

### 4.5 Quote, review, and final submit

Existing quote input: `check_in`, `check_out`, `guests`, optional `promo_code`. Output includes `nights`, `nightly_price`, `subtotal`, `fee`, `total`, `length_discount`, `promo`, `instant_book`, and localized `breakdown`. Reuse `BookingService` and `sp_booking_quote` / `sp_booking_create`; do not reproduce pricing in JavaScript.

| State | Price and primary action |
|---|---|
| No dates | Nightly/base label; Choose dates; no trip-availability claim. |
| Quote loading | Preserve inputs; indicate recalculation; do not confirm an old amount. |
| Valid quote | Total for selected stay; Review booking. |
| Invalid/unavailable/network failure | Explain and offer correction/retry; preserve valid inputs. |
| Review | Property, dates, guests, itemized total, cancellation, confirmation/payment expectations. |
| Final submit | Confirm booking or Send booking request, based on server rules. |
| Success | Actual returned status: pending is not confirmed; confirmed is not paid. |

Proposed read-only `GET /listings/{id}/review` reuses draft validation and quote; **this route does not exist yet**. Keep final creation at the existing authenticated/CSRF-protected POST. Revalidate availability/price atomically. If the shown amount or material policy changes, return an updated review for explicit acceptance rather than silently accepting a new amount. Preserve database concurrency protections. Verify/add server idempotency for repeated submission; disabling a button is not sufficient.

Date-selected card totals require efficient batch pricing work. Do not request a separate quote for every appended card or calculate nightly price × nights while ignoring fees/discounts. Until available, label nightly prices honestly and show authoritative totals on detail. Current price filtering/sorting is based on nightly prices; explicitly define any switch to trip totals.

### 4.6 Save, host facts, and returning trips

- Reuse account wishlist. For anonymous saves, explicitly choose between preserving save intent through sign-in and browser-only saves with later merge. Do not imply account/cross-device persistence before it exists.
- Existing save API **toggles** state. Never automatically retry an uncertain toggle: it could undo a successful save. Prevent duplicate interactions or add an explicit desired-state contract. Refresh actual state after ambiguous failures/account changes.
- Collect new host/privacy facts in host editing, validate/store them, and expose them before displaying labels. Reuse existing type/rules/trust/reviews instead of duplicating data.
- Pre-booking contact needs a new authorized conversation flow; do not invent a booking ID to reuse booking messages. Keep outside the first discovery release unless separately scoped.
- Upcoming-trip summary must reuse booking ownership and status checks. Public search/cards must not expose access codes, private host contact details, or check-in guides. Preserve guide/payment eligibility rules.

## 5. Stack boundary

Keep PHP MVC, MySQL stored procedures, semantic HTML, CSS, and Bootstrap 4.6.2. No React/Vue/new UI framework is needed.

**Automatic loading, live autocomplete, and in-page restoration require browser JavaScript.** HTML/CSS/PHP alone cannot provide these behaviors. The repository already uses vanilla JS/AJAX; the proposed integration needs small additions to that layer. This is an explicit dependency against the earlier “only Bootstrap/CSS/HTML/PHP” constraint, not a claim that Bootstrap supplies these behaviors. Retain functional PHP GET forms/pagination without scripting. This handoff does not change runtime code.

Use procedure migrations and the existing installer for query/schema changes. Do not reseed the shared database. Preserve Vietnamese/English labels, current formatting, authorization, and business rules.

## 6. Delivery order and acceptance checklist

### Phase A — repair the journey (F02, F06, F09)

- [ ] Dates/guests survive search → detail → login/register → review, errors, Back, and parallel tabs.
- [ ] Valid search shows homes before advanced controls; errors still reveal relevant fields.
- [ ] Review uses authoritative total and actual confirmation model; only final explicit submit creates a booking.
- [ ] Expired sessions, changed prices/availability, and duplicate submit recover safely without losing input.

### Phase B — discovery and shortlist (F01, F03, F04 city scope, F07)

- [ ] First visit offers nationwide homes, inventory-backed destinations, optional dates, and truthful labels.
- [ ] Initial/appended cards agree on content, locale, prices, and saved state.
- [ ] Auto-load handles slow/failing requests, stale responses, duplicate IDs, empty/end states, keyboard use, and no-JS fallback.
- [ ] Real links/new tabs work; Back restores results and scroll position.
- [ ] Save follows the chosen anonymous/auth model without losing browsing context.

### Phase C — precise areas and decision information (F04 extended, F05, F08)

- [ ] Accent variants, ambiguous names, regions, landmarks, unknown places, and nearby alternatives behave honestly.
- [ ] Map/list constraints agree; committing bounds changes scope visibly; capped and missing-coordinate results are explained.
- [ ] Host and shared/private space information comes from persisted data; no invented social proof.

### Phase D — return visits and presentation (F10–F12)

- [ ] Upcoming trip links to authorized messages/check-in/payment with accurate status.
- [ ] Check 320/390/768/1440 widths, keyboard, text enlargement, reduced motion, Vietnamese/English, light/dark.
- [ ] Bottom controls do not cover content or compete; essential actions are never hover-only.
- [ ] Measure loading on a constrained connection; test the full task with people unfamiliar with the app.

Use an isolated seeded DB for booking/payment/message mutation tests. Extend existing browser tests with these behavior assertions; the previous 170 passes do not establish acceptance of the new journey. Passing layout checks is not evidence of user satisfaction.

## 7. Git hygiene

The root `.gitignore` now excludes machine-local `.kilo/`, Python caches/virtual environments, and root Playwright outputs (`test-results/`, `playwright-report/`, `blob-report/`). Existing secret/log/upload/database-backup exclusions remain.

Keep source/tests, schema/procedure migrations, safe environment examples, `graph/` diagrams/tools, notes, audit JSON, the original Figma reference, and runtime fonts/images/vendor assets. `public/assets/vendor/` is used by the layout and differs from root Composer `/vendor/`; it must remain versionable.

Generated PNG screenshots in `docs/ui-experience/`, `docs/screenshots/`, `docs/quality/screenshots/`, and `docs/quality/visual/screenshots/`, plus `docs/figma/home-*.png` captures, are now ignored at the user's request. `docs/figma/home-reference.png` is explicitly retained. Previously tracked test screenshots are removed from the Git index only; local copies remain. Screenshot links in reports therefore refer to local/generated evidence and may need regeneration in a fresh checkout. Put future disposable captures in ignored test-output directories.

Related: [design direction](../DESIGN.md), [UI audit](ui-experience/focus-audit/README.md), [current UI verification](ui-experience/README.md), [historical ignore audit](gitignore-audit.md).
