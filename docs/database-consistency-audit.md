# Home2Home — Database consistency audit

Date: 2026-10-09. Actual local server: XAMPP MariaDB 10.4.32, db_home2home. Read-only schema/routine metadata captured; no ALTER, table/database reset, grant change or shared routine installation/drop performed.

## Actual canonical structure

22 tables, 173 columns, 33 foreign-key column relationships, 60 indexes (including primary/unique/fulltext), 48 CHECK expressions (including JSON validity). No exact duplicate indexes by table/column order/uniqueness found; partial-prefix overlap is not treated as redundant without query-plan evidence. SHOW CREATE TABLE, not IF NOT EXISTS success, is the parity proof.

Source schema previously diverged: ENUM vs constrained VARCHAR states; DATETIME vs DATETIME(6); different widths/nullability/defaults; missing checks/fulltext/notification unique index/reports assigned-admin FK; different FK deletion behavior. The canonical initialization schema now matches the existing ERD database exactly, excluding transient AUTO_INCREMENT sequence values. This is a source initialization correction, NOT a migration applied to existing tables. Existing installations are protected by importer preflight.

| Domain / tables | ERD/class relationship and PHP mapping | Verified invariant |
|---|---|---|
| users, user_roles | User → roles; Auth/User model | BIGINT IDs, email unique, allowed status/role CHECK; roles composite PK |
| property_types, cancellation_policies, amenities | Catalogue and policy; Listing model | Active flags, policy range checks; original list routines retained |
| listings, listing_photos, listing_amenities | Host → listing → photos / amenity join | Owner/type/policy/reviewer FKs; photo ordering unique; amenity composite PK; soft deletion |
| listing_availability | Listing → date entries; Listing/calendar routines | Composite listing/date PK; confirmed-date guards; open flag CHECK |
| bookings, booking_nights | Guest/listing → booking → nightly snapshots | Half-open dates; positive guest count; frozen amount/policy JSON; unique nightly rows |
| booking_events, booking_cancellations | Booking lifecycle/cancellation; BookingService facade | Transition CHECK, actor nullable for system; one cancellation/booking; refund JSON |
| reviews, wishlist_items | Review author derived from booking; favorite user/listing join | One review/booking, rating 1–5, reply consistency; composite favorite PK |
| notifications, listing_moderation_events, admin_audit_logs | Event feedback and admin traceability | Notification event/channel unique; moderation verbs/checks; valid JSON audit |
| reports, account_status_events | Planned operations retained in ERD | Assigned Admin FK, closure/status checks; not discarded because UI incomplete |
| sessions, password_reset_tokens | Planned persisted session/reset model | ASCII token hashes unique, expiry constraints; PHP currently uses native sessions |

Class diagram is conceptual: UUID labels differ from actual BIGINT generated keys, blocked-date ranges map to per-day listing_availability, and optional payment/conversation/message classes have no current tables/routes. Preserve the original group diagram; do not invent those tables or mark those functions implemented. Use the ERD + canonical SQL for physical implementation. Current field mappings include base_nightly_rate→nightly_price, fee_amount→cleaning_fee and policy_snapshot/booking_nights for pricing.

## Initialization / migrations / seed / runtime separation

- schema.sql is the single table initialization definition: 22 unique CREATE TABLE IF NOT EXISTS statements in FK-safe order. It preserves all actual constraints and contains no user rows, credentials or live sequence counters.
- IF NOT EXISTS remains useful for rerunning fresh setup, but does not repair an existing schema. database/import.php now refuses normal initialization when any table already exists; metadata inspection only.
- seed.sql is explicit local demo data, known test hashes and public/test contact values. Rerunning preserves row counts but upserts can overwrite demo-ID records. --seed-only now requires --confirm-demo-seed. Do not run on shared/production data; no reseed of live DB was performed here.
- Routine source files 001/005/010/011/020/030 install in order; 8 overlapping legacy redefinitions are compatibility upgrades. 67 distinct production names / 75 CREATE executions. testing.sql adds 14 routines only on explicit opt-in.
- 040 historical prune migration is retained but skipped by the normal installer. Separate reviewed execution only with confirmation; never infer deletion safety merely from missing PHP CALL.
- CLI metadata/SHOW CREATE, installation DDL and owned isolated test DB setup/drop are reviewed administrative exceptions. No business SQL moved back into PHP.
- Runtime requests use CALL only; no schema initialization, migrations or seed in Controller/View paths. Binding, rowset draining, role/ownership, locks, savepoints, events and immutable prices remain covered by regression.

## Evidence

PASS: schema_test.php --compare-live compares normalized SHOW CREATE TABLE for all 22 newly created tables against the live structure, including FK/check/index/default/nullability/collation definitions. Seed repeated twice; eligible completed-stay review created once, duplicate/foreign guest rejected; exact own test DB cleaned.

PASS: fresh_setup_test.php executed from independent source copy with a separate database and PHP server. Production routines only, repeated seed, HTTP home/detail/login/assets/quote, Guest/Host/Admin login/pages/logout and Guest→Admin 403. Own server/database stopped/removed; shared database untouched.

PASS: codebase_cleanup_test.php records installer statements in a fake PDO without executing SQL: 75 production definitions, 14 test definitions by opt-in, zero DROP. Importer default and unconfirmed --seed-only both refused before data mutations on existing DB.

NOT_VERIFIED: remote GitHub clone/access, another teammate's Apache/XAMPP installation, Oracle MySQL compatibility, production grants, non-Vietnam timezone tables and load/deadlock stress. Setup instructions: [database-setup.md](database-setup.md).
