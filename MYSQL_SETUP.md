# MySQL connection

The running application is configured for the existing `PUJ` database on local MySQL port 3306. Credentials are in the ignored `.env` file, not in application source. Existing tables and records were adopted; the destructive statements in `database/transport_system.sql` were not executed.

The supplied `mysql-connector-j-26.7.0.jar` is used by the Java connection/import utility. Laravel HTTP requests use the installed PHP `pdo_mysql` driver. No Java web service is required.

Verify both paths:

```powershell
php artisan transport:import --check
php artisan transport:import --connector=pdo --check
```

`JAVA_BINARY` points to the installed JDK 24 executable in the local configuration. On another machine, configure Java 11 or later and set `MYSQL_JDBC_JAR` if the JAR is moved. The tool passes credentials through stdin rather than command-line arguments. `.env` values containing `#` must remain quoted.

## Existing database and accounts

The schema uses `Users.user_id` and `Users.fullname`, not Laravel's default `users.id` and `users.name`. Models map all nine supplied tables and their primary keys. Laravel sessions and cache use files, so no framework session/cache tables are needed. Queue jobs run synchronously.

Sign in at `/login` with a user account in `Users`. Database credentials are separate from application account credentials. Assigned roles control navigation and access: Admin to Analytics, Driver to their assigned trips, Passenger to routes and their own account/feedback. Registration always creates a Passenger and a matching Passengers row in one transaction. Requests cannot grant Driver/Admin privileges.

Legacy plaintext account passwords are upgraded to bcrypt when the correct password is first used. New registrations and password changes always store password hashes. Passwords are excluded from JSON. Database errors returned to the browser do not include SQL or connection credentials.

Available endpoints:

- `POST /login`, `POST /register`, `POST /logout`
- `GET /api/transport/snapshot` — authenticated and scoped to account role
- `GET /api/transport/trips/{id}` — Admin or the assigned Driver
- `POST /api/transport/trips/{id}/start` — assigned Driver, with confirmed assignment and fresh GPS
- `POST /api/transport/trips/{id}/gps` — assigned Driver, active trip only
- `POST /api/transport/trips/{id}/end` — assigned Driver, with destination confirmation
- `PATCH /api/transport/profile` — signed-in account only
- `PUT /api/transport/password` — signed-in account; verifies current password

Admin pages use the actual route, driver, vehicle, trip, stop, and feedback records. Default dates follow the database's recorded trip period. PDF and CSV exports use those same records. The imported data is marked as sample information via `TRANSPORT_SAMPLE_DATA=true`.

## Schema coverage

Trip start, trip-linked GPS, and trip completion are now connected through the tracking extension below. Passenger journeys, scheduled arrival times, available operating hours, permanent vehicle assignments, delay reports, dated feedback, feedback-to-trip relationships, admin responses, notification preferences, contact numbers, and email verification remain unavailable. No demo GPS positions, journeys, or arrival estimates are substituted. Profile/password updates also persist.

Existing `DATETIME` values are interpreted as Asia/Manila local time. API timestamps are converted to UTC ISO strings and displayed in Asia/Manila. Stops have no sequence field, so their order is not asserted. Historical feedback without dates is excluded from date-filtered reports and available in the Feedback Management directory.

## Import into a new, empty database only

The current `PUJ` database does not need an import. For a separate empty database, configure its name and credentials in `.env`, then run:

```powershell
php artisan config:clear
php artisan transport:import
```

The importer strips `DROP TABLE`, `USE`, and `CREATE DATABASE` statements from the source, selects the configured database, hashes sample passwords before insertion, and refuses any destination that already has tables. Only CREATE TABLE and INSERT INTO statements are accepted. MySQL DDL is not transactional; if a new import fails mid-schema, inspect that new target before retrying. The tool never clears a partially populated database automatically.

The original Laravel starter migrations are not the import path for this schema.

## Checks

```powershell
php artisan test --filter='TripTrackingTest|TransportDatabaseTest'
node --test tests/ui/analytics-data.test.mjs
npm.cmd run build
```

The PHP tests use isolated in-memory SQLite fixtures matching the supplied columns; they do not modify MySQL. Actual MySQL/JDBC connectivity and browser sign-in, role isolation, scoped data reads, and database-backed CSV generation were also verified against the existing local server.

### UI continuity

Connected screens retain the original Driver and Passenger navigation, visual shell, route cards, and map sheets. Mapbox maps are available even when no GPS record exists. Latest location reads are scoped to assigned vehicles for drivers; passengers receive public route/vehicle data. Stop coordinates are shown without asserting an unconfigured stop order or fabricating a route line. If the Mapbox service cannot load, the same map panel offers a labeled coordinate preview rather than removing the map. GPS and ETA remain unavailable until their actual records exist.

## Start Trip and live GPS

The tracking extension is installed in the local PUJ database. For another database containing the supplied base tables, apply only the additive migration at database/migrations/2026_10_25_000100_add_trip_gps_tracking.php using artisan migrate --path with that path.

It adds nullable scheduled/actual UTC timestamp columns and completion notes to Trips, plus Trip_GPS_Points with trip, vehicle, driver, accuracy, client event ID, UTC capture time, and UTC receipt time. Existing trip rows are preserved. GPS_Locations receives a compatibility copy of each fix in its existing local-time format. The canonical feed uses Trip_GPS_Points; the copies are not combined or counted twice.

A driver confirms an assigned Scheduled trip and grants precise location permission. The first valid GPS fix and In Progress status are committed together. Row locks prevent conflicting active trips for the driver or vehicle. Repeated starts preserve departure time, and client GPS IDs make retries idempotent. Invalid coordinates, poor accuracy, stale fixes, unauthorized users, and inactive trips are rejected.

The driver watches location and requests fresh fixes about every five seconds while visible and online. Navigating within the driver app does not stop the watcher. An authorized tracking tab resumes after reload; an active trip opened without that tab's consent offers Resume GPS Tracking. Outages, denied permissions, connection loss, and hidden pages have explicit states. Locked or background browsers cannot guarantee continuous tracking.

Passenger Home, Routes, and vehicle tracking refresh active assignments and positions every three seconds while visible. The Mapbox marker moves without rebuilding the map. Recenter resumes following after manual panning. GPS timestamps show seconds and relative age; location accuracy is displayed. Positions older than 30 seconds are marked stale. ETA remains unavailable.

End Trip confirms the destination, stores actual arrival and completion notes, marks the trip Completed, and stops GPS sharing. Completed-trip positions disappear from the passenger feed, later uploads are rejected, and no passenger alighting records are generated. Reassigned trips cannot reuse their previous assignment's location.

Phone GPS requires a trusted HTTPS origin and location permission. Desktop localhost works for development. An HTTP LAN address will not provide browser GPS; use an HTTPS deployment or trusted HTTPS development address. Browser requirements: https://developer.mozilla.org/en-US/docs/Web/API/Geolocation/watchPosition

Validation: 23 backend tests with 130 assertions, the analytics tests, and simulated driver/passenger browser checks for permission-gated start, successive uploads, marker movement without map recreation, stale labels, and end-trip cleanup. Simulated coordinates stayed in isolated tests and intercepted browser responses. No fake GPS coordinates were inserted into PUJ. A physical phone road test has not been performed here.

## Local sample terminals

The five placeholder sample routes in PUJ are now Batac–Laoag, Batac–Paoay, Paoay–Laoag, Laoag–San Nicolas, and San Nicolas–Batac. Generic Downtown/Airport/North/South stops were replaced with Laoag, San Nicolas, and Batac terminals using existing local sample coordinate points. Route and stop IDs, all ten trip records, accounts, vehicles, and GPS history were preserved. The source SQL now includes the localized route names and fifteen local stops for future empty-database imports. These are labeled Demo data, not an official terminal registry or verified route geometry. The guarded, idempotent transport:localize-samples command only accepts known placeholder/localized sample records and refuses non-sample/custom routes.
## Analytics database connection — 25 October 2026

All six analytics destinations and Reports Center use the authenticated MySQL dataset. Refresh fetches `/api/admin/analytics`; visible admin pages refresh every 30 seconds. Filters, grouping, search, sorting, pagination, report preview, PDF and CSV use the same metric helpers. Missing arrival schedules no longer count as on-time observations. Route duration comparisons use paired valid scheduled and actual timestamps. Vehicle utilization includes inactive vehicles and requires date-specific availability for every selected day.

Additive migration `2026_10_25_000200_add_analytics_records.php` was applied to PUJ. It adds passenger journeys, delay reports, date-specific vehicle availability, analytics settings, trip schedule/direction fields and nullable feedback review metadata. Existing records were preserved; historical schedules, passenger journeys, GPS and feedback dates were not invented. For another installation, apply the GPS migration first, then this analytics migration using their specific `--path` arguments rather than running the unrelated starter migrations.

Admin Settings saves the delay threshold, available vehicle hours, and upcoming trip schedules to MySQL. Historical trip schedules cannot be changed after departure. Feedback Management includes undated legacy feedback, while date-filtered analytics excludes it. Reviews save passenger-visible responses separately from internal notes. Passenger snapshots explicitly whitelist feedback columns, preventing internal notes from reaching passenger browsers.

Passenger boarding, arrival, wrong-trip corrections and completed-journey feedback now persist through authenticated APIs and feed analytics. Duplicate active journeys are rejected; request UUIDs and per-journey feedback checks prevent duplicate records. Corrections retain the original record but exclude it from totals. Driver delay reports persist without ending the trip. Manual counts are not collected and are excluded from the coverage note and totals. GPS-derived distance rejects poor-accuracy observations, implausible jumps and segments separated by more than 120 seconds; estimates and partial coverage are labeled.

Verification: focused PHP feature tests cover authentication/scoping, settings, schedules, journey deduplication, feedback privacy, delay reports and GPS calculations. Nine Node analytics/export tests pass. Production build succeeds. Browser checks loaded every analytics page, exercised a real MySQL refresh, downloaded actual CSV/PDF files, checked settings/review form API contracts with intercepted writes, and verified navigation without horizontal overflow at 390px. No test journeys, fake GPS or fabricated historical analytics were inserted into PUJ.
