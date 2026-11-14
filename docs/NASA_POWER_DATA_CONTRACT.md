# NASA POWER weather contract — P5

**Verified 2026-11-14.** User selected NASA POWER as primary, with T2M, RH2M, PRECTOTCORR and ALLSKY_SFC_SW_DWN. No legacy billing or climate dataset was imported.

## Verified API and units

NASA's [parameter manager](https://power.larc.nasa.gov/api/system/manager/parameters?community=RE&temporal=monthly&parameters=T2M,RH2M,PRECTOTCORR,ALLSKY_SFC_SW_DWN) and live [Monthly](https://power.larc.nasa.gov/docs/services/api/temporal/monthly/) / [Daily](https://power.larc.nasa.gov/docs/services/api/temporal/daily/) point responses expose all four parameters for community `RE`.

| Parameter | Native unit | Monthly feature in this implementation |
| --- | --- | --- |
| T2M | `C` | Arithmetic mean of all daily temperatures in the month |
| RH2M | `%` | Arithmetic mean of all daily relative humidities |
| PRECTOTCORR | `mm/day` | Mean daily rate; **separate** `rainfall_total_mm` = sum of daily rates × 1 day |
| ALLSKY_SFC_SW_DWN | `kW-hr/m^2/day` | Mean daily solar energy; **separate** `solar_total_kwh_m2` = sum of daily values × 1 day |

NASA's [processing methodology](https://power.larc.nasa.gov/docs/methodology/data/processing/) defines monthly values as means of daily values. The Monthly API's precipitation is **not** a monthly total; multiplying a rounded monthly mean by calendar days would differ from summing actual daily values. Monthly API responses also contain `YYYY13` annual summaries, which must never be treated as a thirteenth month.

## Ingestion choice and missing values

The application uses the **Daily point API**, four parameters in one request, `community=RE`, explicit `time-standard=LST`, JSON. Requests are grouped by intersecting year, capped at 36 completed calendar months per operation. Calendar completeness is gated using Asia/Manila; source days remain NASA **Local Solar Time**, not relabeled as civil Philippine time.

Each monthly parameter requires every expected daily value (including leap day). Null, absent, provider fill (`header.fill_value`, live value `-999`), non-numeric/non-finite, invalid humidity/nonnegative-rate or storage-unrepresentable values invalidate that parameter. Affected means/totals remain **null**, with raw values and missing-day keys preserved; no partial average, zero fill, interpolation or silent imputation. Valid zero rainfall remains zero. Missing weather does not authorize inventing a climate feature.

The service checks response type, Daily API identity/version, coordinates, LST, fill convention and exact units **before any DB writes**. Network/HTTP/contract failures reject the whole range. Fixed NASA endpoint, bounded timeouts/retries; no browser-supplied upstream URLs. GET pages do not call NASA. Repeated sync uses a unique location/aggregation identity + month; changed source values update the existing row, not create duplicates. Fetched-at/request metadata and content fingerprint are retained. Dataset snapshots must retain their own original weather/provenance even after a later refresh.

## Location/provenance

Shared pilot-complex reference: **14.6468 N, 121.0500 E**, public-map coordinates from [Quezon City Hall](https://en.wikipedia.org/wiki/Quezon_City_Hall#coordinates). This is a coarse gridded-data reference, not a surveyed sensor/building measurement. `config/weather.php` records the reference and permits explicit environment overrides; `.env` was not changed. Requested coordinates and NASA-returned geometry/elevation are both retained.

The earlier capstone CSV headers expose temperature/humidity/rainfall/solar columns but do not retain coordinates, units or acquisition metadata. Their exact coordinate/aggregation match is **unverified**; nothing is blindly ported. Current totals and means are explicitly named and source-tracked.

## Live evidence (not fixtures)

- Monthly point API: `v2.10.0`, all four units confirmed; 2025 Jan–Dec returned valid values plus annual `202513`. 2026 Monthly response had `-999` in recent months (including solar from August onward in that response).
- Daily point API: `v2.10.3`, same four units and LST/fill convention. Daily source availability differs from the Monthly service: complete daily values were returned for the requested Jan 2025–Oct 2026 interval.
- `./vendor/bin/sail artisan weather:sync 2025-01 2026-10`: **22 created, 0 updated, 0 unchanged, 0 incomplete**. Raw values/metadata persisted, not inferred from the Monthly response.
- Re-sync `2025-01`: **0 created, 0 updated, 1 unchanged, 0 incomplete**; table still 22 rows.
- October 2026 source list: `MERRA2`, `GEOSIT`, `SYN1DEG`, `FLASHFLUX`, `POWER`; fill `-999`, LST, full range through Oct 31. Daily-derived temperature 26.582903 °C, RH 84.920323%, rainfall mean 6.911290 mm/day / total 214.25 mm; solar mean 4.644958 kWh/m²/day / total 143.9937 kWh/m².
- Development billing records remain **0**. Weather is public source data, not a Meralco data import. Live availability is a dated observation, not a promise that future months are complete.
