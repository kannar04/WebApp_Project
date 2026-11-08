# Nearby services on listing pages

The listing navigation includes **Dịch vụ gần đây / Nearby services**. It displays restaurants, cafes, shops, bars/pubs, entertainment, attractions, parks, pharmacies and supermarkets within 1,000 meters of the listing's saved coordinates, sorted by straight-line distance. The map keeps a red pin on the property; selecting a result opens its map details and directions. Listings without coordinates show an explanatory message.

Markers show list numbers below zoom 17 and place names at zoom 17–18. Labels expand and crossfade on zoom changes; category changes animate the result list. Reduced-motion preferences disable these effects. Mobile category buttons scroll horizontally.

## Configuration

Create a Geoapify project at https://myprojects.geoapify.com/ and add its Places API key to the local `.env`:

```dotenv
GEOAPIFY_API_KEY=your_project_key
```

Restart the PHP server if it was started with an older environment variable. PHP needs its cURL extension enabled. The key stays on the server and is never rendered into the page or returned by the nearby services endpoint.

On Windows, the Geoapify request uses the native trusted certificate store when supported by cURL. This allows PHP builds without a bundled CA file to verify HTTPS certificates without disabling certificate or hostname verification.

## Request behavior

`GET /api/listings/{id}/nearby-services?category=all` accepts `all`, `food`, `cafe`, `shop`, `bar`, `entertainment`, `sights`, `park`, `pharmacy` or `supermarket`. It uses coordinates from the public listing, not arbitrary browser coordinates. The Geoapify Places request has a hard 1 km boundary and returns up to 40 places. Successful results are cached in PHP's temporary directory for five minutes, separately for each location and category. Missing API configuration or provider failures return HTTP 503 without interrupting the listing page.

The browser requests places when the nearby section approaches the viewport. The list scrolls within a container matching the map dimensions. Map tiles use the existing OpenStreetMap renderer and fallback; place results come from Geoapify.

## Local checks

```powershell
php tests/geoapify_places_test.php
node --test tests/nearby_services_test.cjs tests/tourism_map_test.cjs
```

The browser checks require Playwright, and `nearby_services_test.cjs` requires the PHP site at `http://127.0.0.1:8090` (override with `H2H_BASE_URL`). Provider responses are mocked; an actual Geoapify key is needed to verify live results. This repository currently ignores the `tests/` directory, so these checks are local artifacts.
