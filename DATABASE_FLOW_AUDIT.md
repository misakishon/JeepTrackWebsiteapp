# Database and connected-flow audit

Audit date: 27 October 2026 (Asia/Manila).

The core driver-to-passenger trip and GPS flow is connected and has been exercised against MySQL. This does not mean every screen or feature is implemented.

## Existing database

Database-backed mode and sample-data mode are enabled. All 14 checked operational tables exist. All 21 existing account snapshots loaded without errors: one Admin, eleven Drivers and nine Passengers. The database has 15 vehicles, five routes, 15 stops, and 15 trips. No orphaned trip/driver/vehicle/route references or duplicate active assignments were found.

The five currently active assignments belong to demo drivers. They have simulated positions, not real current GPS fixes. There are 134 historical real GPS points; the latest existing fix was captured on 25 October 2026 at 16:38:27 UTC. A real driver must start or resume GPS sharing before passengers can see a current real location.

Existing operational row counts were the same before and after this audit. Browser write checks used a separate temporary MySQL database, with temporary accounts and a copied schema. No existing account passwords or operational trips were changed. The temporary database and its credentials were removed after verification.

## Verified connections

| Flow | Evidence |
| --- | --- |
| Driver, Passenger and Admin login | Separate browser sessions reached the correct role workspaces. |
| Driver starts an assigned trip | UI confirmation and GPS acquisition persisted `In Progress`, departure and the initial fix. |
| Passenger sees the active driver trip | Passenger polling discovered the new trip and its vehicle. Tracking shows `Trip in progress`. |
| Driver location updates | Controlled browser geolocation callbacks uploaded changing fixes to MySQL; passenger snapshots and UI updated. |
| Individual and fleet maps | Actual Mapbox markers were checked against projected GPS coordinates in individual tracking, passenger home and Admin tracking. |
| Passenger boarding | Confirmation created an Active journey; duplicate active boarding was rejected. |
| Driver delay | Report persisted and the trip remained active. |
| Passenger arrival and history | Arrival timestamp and Completed journey persisted and appeared in history. |
| Feedback and Admin response | Feedback reached analytics; Admin response reached the passenger without leaking private Admin notes. |
| Driver ends the trip | Completion persisted, public GPS disappeared and later GPS writes were rejected. Passenger history remained intact. |
| Vehicle reused for another trip | Regression test keeps the passenger's journey on the original trip and separates the new trip's GPS. |
| Profile and password updates | Profile persistence, role preservation, current-password validation, password hashing, logout and new-password login passed. |
| Access restrictions | Guests, passengers and other drivers cannot perform unauthorized trip/GPS/Admin operations. |
| Analytics configuration | Tests cover role checks, threshold persistence, availability, schedules, delay records and missing-observation handling. |

The headless browser's native geolocation provider timed out during movement checks. The final browser run used controlled geolocation callbacks while exercising the actual UI, authentication, API and MySQL layers. Physical phone GPS, background operation and a locked phone were not certified. The application intentionally pauses or warns when browser visibility/connectivity or location permissions prevent reliable updates; phones require HTTPS.

## Fixes made

- Simulated jeepneys stop appearing when their assignment ends. A real GPS record, including a stale fix, takes priority over simulation. Simulations are disabled when sample-data mode is off.
- Drivers without an active assignment no longer receive other drivers' demo positions.
- Real trip GPS now reaches both passenger and Admin fleet maps, not only individual vehicle tracking. Offline coordinate previews include those positions.
- Passenger My Trip maps and GPS status use the boarded trip ID. They cannot follow a later trip simply because it reuses the same vehicle.
- Tracking explicitly identifies active trips. A passenger whose vehicle trip ended can still confirm their actual arrival without fabricated alighting records.
- Fleet labels use actual record counts rather than always advertising five simulated vehicles. Public simulation payloads no longer expose driver account email addresses.

Implementation: `app/Services/TransportDatabase.php`, `app/Services/DemoSimulationService.php`, `resources/js/Components/TransportMap.jsx`, `resources/js/Pages/DatabaseTransport.jsx`, and `resources/js/Pages/AdminAnalytics.jsx`. Regression coverage is in `tests/Feature/AccountConnectionTest.php`, `TripTrackingTest.php`, and `DemoSimulationTest.php`.

## Remaining gaps

- ETA prediction, nearby-distance search, manual boarding/alighting counts, occupancy reconciliation, duty status and some trip-summary metrics are still explicitly unavailable.
- Saving notification preferences, push/email delivery, password recovery, email verification and account deletion are not connected.
- Admin writes cover driver account creation (added after this audit), analytics settings, existing-trip schedules, availability and feedback review. Vehicle/route/trip creation and assignment management remain separate unfinished workflows.
- Twenty-four legacy starter tests still fail: 23 assume the default Breeze schema/routes instead of the imported transport schema; one expects `/` to return 200 although connected mode redirects to login. These failures were not hidden or disabled.
- The two operational migrations are applied. Three original Laravel scaffold migrations remain pending in this imported database; a normal fresh migration is not a verified installation path. They were not blindly applied to the existing schema.

## Validation results

- Relevant backend suites: **74 passed**.
- Complete PHP suite: **75 passed, 24 failed, 629 assertions**. Failures are the legacy starter tests described above.
- UI unit tests: **12 passed**.
- Isolated MySQL browser workflow: all connected-flow, map-position and runtime checks passed.
- Production Vite build passed.

Relevant backend command:

```sh
php artisan test --filter='AccountConnectionTest|TripTrackingTest|DemoSimulationTest|AnalyticsRecordsTest|TransportDatabaseTest|MySQLSimulationVerificationTest'
```

Other checks: `node --test tests/ui/*.test.mjs`, `npm run build`, and `php artisan test --compact` for the complete suite including the known starter-test failures.

## Follow-up: driver route choice (28 October 2026)

Driver-side route choice is now implemented. Home/Trips can save a registered route and available vehicle as a scheduled trip, and Trip Details can change an owned scheduled trip's route. It needs no Admin approval. Active routes remain locked, other drivers' reserved vehicles are excluded, repeated saves do not duplicate the pending trip, and GPS is still required to start/publish the trip. Admin vehicle/route/trip management remains a separate unfinished workflow. See the README for current instructions.

The new browser run used a separate temporary MySQL database and controlled GPS callbacks. It verified changing a scheduled Batac-Paoay trip to Batac-Laoag, starting it and receiving the selected route/GPS as a passenger. It also verified a newly created driver planning and starting their own trip without any prior assignment. Relevant backend suites now pass 101 tests (810 assertions), and all 12 UI unit tests pass. These focused results do not imply the previously documented legacy starter-test failures have been fixed.
