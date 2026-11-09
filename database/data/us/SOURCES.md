# US location data

| File | Contents | Source | Licence |
|---|---|---|---|
| `states.csv` | 50 states, DC and Puerto Rico with FIPS codes | US Census Bureau cartographic boundaries via `us-atlas` 3.0.1 | Public domain (Census); ISC (us-atlas) |
| `counties.csv` | 3,220 counties and equivalents with FIPS codes and centre points | US Census Bureau cartographic boundaries via `us-atlas` 3.0.1 | Public domain (Census); ISC (us-atlas) |
| `cities.csv.gz` | 29,738 cities and towns with county, coordinates and population | Places: kelvins/US-Cities-Database (MIT). Population: GeoNames via `all-the-cities` 3.1.0 | MIT; GeoNames data CC BY 4.0 |

Population comes from GeoNames, available for places of roughly 1,000 people or more; it is used to rank search results and to choose launch metros. Each city carries the single county given by the source; admins can correct it in Admin → Locations.

Attribution: "Contains data from GeoNames (geonames.org), licensed under CC BY 4.0."

Metro areas (CBSAs) are imported from the official US Census delineation file at deploy time:
`php artisan t2t:locations:import-metros path/to/list1.csv` (Census "Core based statistical areas (CBSAs), metropolitan divisions, and combined statistical areas", CSV export).

Rebuild: `tools/locations/build.cjs` (merges the sources above).
