# Focused guest journey — implementation

Implemented on 2026-11-05 from the [FE–BE plan](../../focused-experience-plan.md). The cream/forest visual identity remains; search, real homes, and continuity now lead the guest interface.

## Delivered

- Compact first-entry search with optional dates and real inventory-backed destination chips. Accessible suggestions support keyboard selection and Vietnamese input without accents. Recent destination suggestions are stored per browser tab/session.
- Initial PHP cards and automatically appended 12-home batches use one escaped template. Loading, retry, end states, unique-ID handling, saved states, trip-aware links, and no-JS pagination are supported.
- Returning from a property restores loaded batches and scroll position. A successful search keeps advanced filters collapsed; active conditions can be removed without resetting the whole trip.
- Mobile Explore / Saved / Trips navigation. Property pages use one bottom booking bar instead, with a total and Review booking action after a successful quote.
- Dates and guest count pass through property navigation, header sign-in, review, login failures, registration links, and expired anonymous submission. Review tokens bind the signed-in user, listing, selected trip, and accepted price/policy for 30 minutes.
- Read-only booking review before explicit final confirmation. Confirmation revalidates within a transaction; changed price returns to review. Repeating the same completed token returns the original booking.
- List/map use the same search engine and advanced filters. Moving the map does not silently search; Search this area commits the boundary and updates the link back to the feed. Missing-coordinate homes remain list-only and map results are capped at 100.
- Upcoming active trip card with a direct link to the existing authorized booking workspace.
- English/Vietnamese, light/dark styling, reduced motion, server-rendered content, and Bootstrap 4.6.2 remain supported.

## Integration files

| Responsibility | Files |
|---|---|
| Search and destination inventory | [HomeController](../../../app/Controllers/HomeController.php), [Listing](../../../app/Models/Listing.php), [new routines](../../../database/procedures/100_focused_experience.sql) |
| Shared cards / discovery controls | [home](../../../app/Views/home.php), [listing-cards](../../../app/Views/components/listing-cards.php), [focused-discovery.js](../../../public/assets/js/focused-discovery.js) |
| Trip return and authentication | [TripContext](../../../app/Services/TripContext.php), [AuthController](../../../app/Controllers/AuthController.php) |
| Review / confirmation | [BookingController](../../../app/Controllers/BookingController.php), [BookingService](../../../app/Services/BookingService.php), [review view](../../../app/Views/bookings/review.php), [focused-booking.js](../../../public/assets/js/focused-booking.js) |
| Map continuity | [DiscoveryController](../../../app/Controllers/DiscoveryController.php), [map.js](../../../public/assets/js/map.js) |
| Styling and labels | [focused.css](../../../public/assets/css/focused.css), [English dictionary](../../../lang/en/focused.php) |

The listing API adds `data.html` while retaining its previous envelope and pagination fields. Suggestions are embedded as JSON from eligible inventory, rather than requiring a separate autocomplete API for this small catalog. The added `city` filter is exact; typed location search remains a text fallback. No frontend framework was introduced; small vanilla JS enhancements handle the approved interactions.

## Database setup

The three additive routines in `database/procedures/100_focused_experience.sql` are required. They are included by the existing procedure installer. The local preview database was updated with only these routines; no shared listing/booking records were changed by installation.

For another local checkout configured as described in the project README:

```powershell
php database/install_procedures.php
```

Do not reseed an existing shared database. There is no new table migration in this release. Follow the repository's deployment process for nonlocal environments; the CLI installer is intentionally restricted to local configuration.

## Verification

```powershell
php tests/fresh_setup_test.php --focused-ui
python -X utf8 tests/ui_experience.browser.py
```

The first command creates and destroys an owned disposable database, seeds 26 additional homes for actual pagination, and exercises the complete journey including a price-change rejection, one booking, and repeated confirmation. Its fixtures refuse to run against the shared database. Python Playwright/Chromium must be available in the test environment.

The second command checks public, guest, host, and admin layouts/controls without creating bookings. PHP syntax and JavaScript parsing are also checked. [Focused test results](verification.json) and [broader UI results](../verification.json) record the latest counts.

Screenshots in this directory and the parent are generated/ignored as requested. They remain available locally and are regenerated by tests. Test fixture screenshots contain synthetic homes; the regular UI screenshots show the local development inventory.

## Remaining data/product extensions

- Regions and landmarks need a maintained geographic catalog. This release supports actual cities and explicit map areas; it does not invent regional membership or distances.
- New structured shared-space/bathroom/host-presence fields and pre-booking conversations need separate data and authorization work. Existing type, host, reviews, rules, and verification data remain truthful.
- Cards label base nightly prices. Exact selected-stay totals appear on property/review; batch-priced search totals are not implemented.
- Anonymous saves lead through sign-in and retain browsing context; the guest then saves to the account wishlist. Browser-only/cross-device anonymous saves are not implemented.
- Pagination is offset-based with deterministic ordering and client deduplication, not a snapshot guarantee under concurrent inventory edits. Restoration is bounded to 20 extra batches and 30 minutes.
- Home no longer loads the decorative hero image. Additional font/image format optimization is optional future work.
- Chromium emulation is not physical-device, cross-browser, screen-reader, or user-research validation. The archived audit remains a record of the earlier UI, not a description of the current implementation.
