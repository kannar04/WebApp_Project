# Demo accommodation images

## Expanded room catalog — 2026-11-06

The optional [demo catalog](../database/seeds/demo_catalog.php) adds 24 fictional
rooms with 24 distinct illustrative photographs. Their titles, prices, locations,
capacities, amenities, and booking settings are development fixtures, not verified
accommodation offers. Photo alt text and room descriptions identify them as demo
content; the existing detail-page photo notice also applies.

Each photo ID in that catalog maps to a bundled JPEG in `public/assets/images/demo/`.
Source URL: `https://images.unsplash.com/photo-<photo-id>?auto=format&fit=crop&w=960&q=80&fm=jpg`.
These JPEGs were downloaded on November 6, 2026 under the [Unsplash License](https://unsplash.com/license).

To preview or add this inventory to the local demo database:

```powershell
php database/seeds/seed_demo_catalog.php
php database/seeds/seed_demo_catalog.php --apply
```

The first command only validates/counts; the second inserts new rooms in one
transaction. Guards restrict execution to CLI, `APP_ENV=local`, a loopback database
host, and `db_home2home`. The existing demo host must be active. Catalog markers
make repeat runs skip existing rooms, preserving any edits. Existing rooms and
booking counts are checked before committing. This seed does not create users,
bookings, reviews, or verification claims. Coordinates and addresses are approximate
demo locations. Standard setup retains its original small seed; this catalog is opt-in.

## Original three-room inventory

These photographs illustrate fictitious seed listings. They are not verified
photographs of properties in Đà Lạt or Hội An and are not AI-generated.

The five standard Unsplash CDN photos already referenced by `database/seed.sql`
were downloaded as JPEGs into `public/assets/images/demo/` on 2026-10-09.
Each is 1200 pixels wide, obtained with `auto=format&fit=crop&w=1200&q=85&fm=jpg`.
The files are shared demo assets, not runtime uploads.

| Photo identifier / local JPEG filename | Demo assignment |
|---|---|
| `1449158743715-0a90ebb6d2d8.jpg` | Forest cabin |
| `1600607687920-4e2a09cf159d.jpg` | Villa interior |
| `1600585154340-be6161a56a0c.jpg` | Villa exterior |
| `1600566753086-00f18fb6b3ea.jpg` | Villa living room |
| `1499793983690-e29da59ef1c2.jpg` | Beach accommodation |

Original source for each identifier:
`https://images.unsplash.com/photo-<identifier>?auto=format&fit=crop&w=1200&q=85&fm=jpg`.

License checked: [Unsplash License](https://unsplash.com/license). It permits
downloading, copying and using standard Unsplash images for commercial and
noncommercial purposes. Do not resell unmodified photographs or redistribute
them as a competing image collection. Original image URLs are retained in the
database; this seeder appends local paths rather than replacing existing records.

## Safe local seeding

```powershell
C:\xampp\php\php.exe database\seeds\seed_listing_images.php
C:\xampp\php\php.exe database\seeds\seed_listing_images.php --apply
```

The first command is a dry run with rollback. The second commits a transaction.
Run only against your own local demo database. Guards require `APP_ENV=local`, a
loopback DB host and the database name `db_home2home`. Listings are matched by demo
owner email, exact title and city; absent or ambiguous matches are skipped. Listings
with non-demo photos are skipped. Existing `(listing_id, image_url)` references are
skipped and a listing row lock serializes repeated runs. A repeat inserts zero images.

Only `listing_photos` is changed. Listing details, user uploads and old image rows
are preserved. The first local photo uses an unused sort order 0 so the demo cover loads without
hotlinking; subsequent photos append after the current maximum. Unique sort positions
are preserved in the pre-existing ERD database.
