# JeepTrackWebsiteapp

**PUJ Transportation Analytics** is a jeepney tracking and transportation reporting system for Ilocos Norte. It includes mobile-first Driver and Passenger interfaces and a responsive Admin analytics website.

![Jeepney Tracker logo](public/images/jeepney-tracker-logo.png)

## Technology

- Laravel 12 / PHP 8.2+ with role-scoped authenticated APIs
- React 18, Inertia.js and Vite
- MySQL, using PHP PDO_MYSQL for application requests
- Mapbox GL JS for maps and browser geolocation for driver GPS
- MySQL Connector/J and a Java utility for database verification and guarded imports

## System flow

```mermaid
flowchart TD
    A[Login] --> B{Assigned account role}
    R[Passenger registration] --> P[Passenger account]
    P --> A
    B -->|Admin| C[Analytics and Reports]
    B -->|Admin| AC[Drivers: Add driver account]
    AC --> DL[Driver signs in with the supplied credentials]
    DL --> D
    B -->|Driver| D[Assigned trips]
    B -->|Passenger| E[Search routes and stops]
    D --> F[Confirm vehicle and route]
    F --> G[Grant location permission and start trip]
    G --> H[Save active trip and trip-linked GPS]
    H --> I[Passenger tracks vehicle on map]
    E --> I
    I --> J[Confirm I Have Boarded]
    J --> K[Active passenger journey]
    K --> L[Confirm alighting stop and arrival]
    L --> M[Journey history and feedback]
    G --> N[Report delay if needed]
    N --> H
    H --> O[Driver confirms End Trip]
    O --> Q[Completed trip and tracking stopped]
    Q --> C
    M --> C
    C --> S[Apply filters and review charts]
    S --> T[Preview report and download PDF or CSV]
```

### Authentication and account access

Login redirects to the account's assigned role: Admin to `/admin/analytics`, Driver to `/driver/home`, and Passenger to `/passenger/home`. Public registration creates Passenger accounts only; it cannot grant Driver or Admin privileges.

To create a driver, an administrator opens **Admin → Drivers → Add driver**, enters the full name, unique email, unique license number, password and confirmation, then selects **Create driver account**. The login and linked driver record are saved together, with a hashed password. The administrator stays signed in. The driver uses the normal login page with the supplied credentials and can change their password in Profile. Creating an account does not assign a vehicle, route or trip. Admin account creation is not exposed through public registration.

Drivers can access their assigned trips and vehicle information. Passengers can access their own journeys, profile and feedback. Admin analytics endpoints require the Admin role. Password changes verify the current password, and legacy plaintext passwords are upgraded to hashes after a successful login.

### Driver flow

The bottom navigation is **Home · Trips · Tracking · Profile**.

1. Sign in using the driver account created by the administrator.
2. Open **Trips** and review an existing assignment's vehicle, route, stops and departure time. An account with no assignments cannot start a trip.
3. Confirm the assigned vehicle and route, then allow location access and select **Start Trip**.
4. The server saves the active trip and first valid GPS point together. Conflicting active trips are rejected. Passengers can now see the active trip and its recorded location.
5. Keep the browser open and visible. Valid GPS fixes are uploaded approximately every five seconds and are associated with the driver, vehicle and trip.
6. Report a delay without ending the trip, when safely stopped.
7. Confirm the destination to end the trip. Actual arrival is recorded and public GPS sharing stops. Ending a trip never fabricates passenger alighting records; passengers confirm their own actual arrival.

**Route choice today:** drivers can start only their assigned trips. If a driver is assigned **Batac–Paoay**, they cannot change that trip to **Batac–Laoag**. The server rejects a vehicle or route that does not match the assignment. There is currently no driver route-selection/request screen and no Admin screen for creating or reassigning trips. The existing Admin schedule editor changes departure/arrival times and direction, not the assigned route, driver or vehicle.

Browser tracking can pause when the phone is locked or the page is in the background. Physical-phone GPS requires HTTPS and permission; localhost is suitable for desktop development. Passenger maps label stale GPS and unavailable ETA.

### Passenger flow

The bottom navigation is **Home · Routes · My Trip · History · Profile**.

1. Create a passenger account through public registration, or sign in to an existing passenger account.
2. Search local routes and select boarding and destination stops.
3. Track an active vehicle. Active vehicle locations refresh approximately every three seconds while the relevant page is visible. Real GPS takes priority over demo simulation; missing or stale GPS is labeled.
4. Select **I Have Boarded** and confirm the trip and stops. The server prevents duplicate active journeys.
5. View the active journey in **My Trip**. Tracking stays associated with the boarded trip, even if the vehicle is later reused for another trip.
6. Confirm the alighting stop with **I Have Arrived**. If the driver has already ended the vehicle trip, the passenger can still record their actual arrival.
7. Review journey history and submit a rating, complaint or suggestion for a completed journey.
8. Use **Joined the Wrong Trip?** to correct a mistaken boarding. The original record is retained for audit and excluded from journey totals.
9. Read saved admin responses under My Feedback. Internal admin notes are never sent to passenger browsers.

Tracking a vehicle does not reserve a seat. Seat availability and ETA are not invented when reliable observations are missing.

### Admin flow

1. Sign in with an existing Admin account.
2. Open **Drivers → Add driver** to create a driver's login and license record. The directory includes drivers who have no trips yet.
3. Review existing driver, vehicle, route and trip records. **Assignment management is not implemented:** creating a driver does not automatically give them a trip, and the current directory screens cannot create or reassign vehicle/route/trip records.
4. Use **Live Tracking** to monitor recorded trip GPS. Demo simulations are labeled separately and stop appearing when a real GPS record takes priority or the demo assignment ends.
5. Use **Settings** to update supported analytics settings, vehicle availability and the schedule of an existing upcoming trip. Schedules cannot be changed after departure.
6. Review trips, journeys, routes, drivers and vehicles in Analytics. Apply date/route filters and inspect recorded data rather than assuming missing observations are zero.
7. Open **Feedback** to review passenger submissions, save a response, add private internal notes and update resolution status. Passengers receive the saved response, not internal notes.
8. Open **Reports** to preview the selected report and export the filtered results as PDF or CSV.

### Proposed route-selection flow — not implemented

The route-choice workflow discussed for a driver who wants **Batac–Laoag** but is assigned **Batac–Paoay** is a proposed enhancement, not a current feature:

1. Admin defines the vehicle and routes a driver is allowed to operate.
2. Before starting a new trip, the driver selects an approved route. A route outside that set would require an Admin-approved change.
3. Starting the trip saves the selected route with the driver, vehicle and initial GPS fix. Passengers see that trip under the selected route.
4. The route remains locked during the active trip. The driver ends it before starting a different route.

**Decision still needed:** whether drivers may choose freely among Admin-approved routes or must request approval for every route change. No approval/request workflow or route-selection permission has been added yet. Until that feature is implemented, the current assigned-trip rules above remain in effect.

### Admin analytics and reports

| Page | URL | Purpose |
| --- | --- | --- |
| Overview | `/admin/analytics` | Completed trips, journeys, travel duration and arrival delays |
| Route Analytics | `/admin/analytics/routes` | Route demand, paired scheduled/actual duration and reliability |
| Passenger Analytics | `/admin/analytics/passengers` | Recorded journeys, unique registered passengers and boarding patterns |
| Driver Performance | `/admin/analytics/drivers` | Driver activity, departure punctuality and arrival delay |
| Vehicle Utilization | `/admin/analytics/vehicles` | Operating hours, available hours, estimated distance and GPS coverage |
| Feedback Analytics | `/admin/analytics/feedback` | Ratings, categories, resolution status and satisfaction trends |
| Reports Center | `/admin/reports` | Filtered previews and downloadable PDF/CSV reports |

Filters, grouping, search, sorting, pagination and exports use shared metric helpers. Refresh reads MySQL, and visible admin pages refresh automatically every 30 seconds. The Reports Center supports Trip Summary, Route Analytics, Passenger Analytics, Driver Performance, Vehicle Utilization and Feedback Summary reports.

Under `/admin/settings`, administrators can save the delay threshold, date-specific available vehicle hours and upcoming trip schedules. Historical schedules cannot be changed after departure. `/admin/feedback` includes undated legacy submissions and supports saved replies, private internal notes and resolution status.

## Data and calculation rules

- Completed trips come from trip records whose status is Completed.
- Reporting dates use Asia/Manila. New operational timestamps are stored in UTC; legacy DATETIME values are interpreted as Asia/Manila.
- Journey totals use recorded boarding/journey records; correction records are excluded. Request IDs and active-journey checks prevent duplicate submissions.
- Unique registered passengers are distinct registered passenger IDs, not journey counts. Anonymous manual counts are not currently collected.
- Actual duration requires valid actual departure and arrival timestamps.
- Departure punctuality and arrival delay are separate metrics. Both use the configured delay threshold.
- Driver duration comparisons require a selected route. A delay is not automatically attributed to the driver.
- Vehicle utilization requires configured available operating hours for every selected vehicle-day. Missing or zero denominators do not produce a utilization percentage.
- GPS-derived distance is estimated. Poor accuracy, implausible jumps and gaps over 120 seconds are excluded; incomplete coverage is flagged.
- Feedback without submission dates is excluded from date-filtered analytics. Historical missing dates, schedules, journeys and GPS are not fabricated.
- Sample records are labeled **Demo data**. Local sample routes include Batac–Laoag, Batac–Paoay, Paoay–Laoag, Laoag–San Nicolas and San Nicolas–Batac. These are illustrative records, not an official terminal registry.

## Local setup

### Requirements

Install PHP 8.2+ with PDO_MYSQL and SQLite support for tests, Composer, Node.js 20.19+ or 22.12+, and MySQL 8. Java 11+ is required when using the Connector/J utility.

```powershell
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
```

Configure your local `.env`:

```dotenv
APP_NAME="PUJ Transportation Analytics"
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=PUJ
DB_USERNAME=your_mysql_user
DB_PASSWORD="your_mysql_password"
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
TRANSPORT_DATABASE_ENABLED=true
TRANSPORT_SAMPLE_DATA=true
VITE_MAPBOX_ACCESS_TOKEN=your_mapbox_public_access_token
JAVA_BINARY=java
```

Keep `.env` private. Use your own Mapbox public token with appropriate URL restrictions. Database credentials and application login credentials are separate.

### Database initialization

For an **existing compatible database**, configure the connection and apply only the additive feature migrations below. Do not reimport its sample data.

For a **new installation**, first create an empty MySQL database and configure `.env`. The supplied SQL includes legacy destructive statements: use the guarded importer instead of executing that SQL directly against an existing database.

```powershell
php artisan config:clear
php artisan transport:import --check
# New empty database only:
php artisan transport:import
```

The importer uses the supplied `mysql-connector-j-26.7.0.jar`, strips destructive statements, hashes seed passwords and refuses a populated target. Set `JAVA_BINARY` to your Java executable and `MYSQL_JDBC_JAR` if the JAR is stored elsewhere. PHP connectivity can also be checked with `php artisan transport:import --connector=pdo --check`.

Apply the feature migrations in order:

```powershell
php artisan migrate --path=database/migrations/2026_10_25_000100_add_trip_gps_tracking.php
php artisan migrate --path=database/migrations/2026_10_25_000200_add_analytics_records.php
```

The original Laravel starter migrations do not describe the imported transport schema. Do not use an unrestricted migrate or migrate:fresh command for an existing PUJ database. The transport migrations preserve existing records.

### Run the application

Use two terminals:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
npm run dev
```

Open `http://127.0.0.1:8000/login`. Sign in with an account provisioned in your database. Public registration is available for passengers.

For a production asset build:

```powershell
npm run build
```

Production deployment also requires a configured PHP web server, HTTPS, private environment configuration, writable Laravel runtime directories and production-appropriate database accounts. The development server is not a production deployment.

## Main application endpoints

| Area | Endpoints |
| --- | --- |
| Authentication | `POST /login`, `POST /register`, `POST /logout` |
| Role-scoped data | `GET /api/transport/snapshot`, `GET /api/transport/trips/{id}` |
| Driver trip operations | `POST /api/transport/trips/{id}/start`, `/gps`, `/end`, `/delay` |
| Passenger journeys | `POST /api/transport/journeys`, `POST /api/transport/journeys/{id}/arrive`, `/correct`, `/feedback` |
| Account | `PATCH /api/transport/profile`, `PUT /api/transport/password` |
| Admin analytics | `GET /api/admin/analytics` |
| Driver account management | `GET /api/admin/drivers`, `POST /api/admin/drivers` (Admin only) |
| Analytics configuration | `PUT /api/admin/analytics/settings`, `/availability`, `/trips/{id}/schedule` |
| Feedback review | `PATCH /api/admin/analytics/feedback/{id}` |

All operational endpoints require authentication, appropriate role and record ownership checks. Browser requests use Laravel session authentication and CSRF protection.

## Project structure

```text
app/Http/Controllers/       Authentication, trip/GPS, journey and analytics APIs
app/Models/                 Models mapped to the supplied MySQL schema
app/Services/               Database serialization, analytics, tracking and import logic
database/migrations/        Additive tracking and analytics schema extensions
database/transport_system.sql  Local sample schema/data source
resources/js/Pages/         Driver, Passenger and Admin interfaces
resources/js/Components/    Shared map, icons and UI components
resources/js/admin/         Analytics calculations, charts, settings and exports
resources/js/hooks/         Browser GPS lifecycle
routes/web.php              Web pages and authenticated API routes
tests/Feature/              Isolated backend feature tests
tests/ui/                   Analytics and export calculation tests
tools/mysql/                Java Connector/J utility
```

## Verification

```powershell
php artisan test --filter='DriverAccountsTest|AccountConnectionTest|AnalyticsRecordsTest|TransportDatabaseTest|TripTrackingTest|DemoSimulationTest'
node --test tests/ui/*.test.mjs
npm run build
```

The focused PHP tests use isolated in-memory SQLite fixtures, not the running MySQL database. The current focused checks cover role isolation, GPS/trip lifecycle, journey deduplication, feedback privacy, schedules, operating hours and analytics calculations. Unmodified Laravel scaffold tests are not the transport application's acceptance suite.

## Current limitations

Email delivery/verification and password recovery, saved notification preferences, manual passenger counting, contact-number storage, reliable occupancy information and an ETA prediction service are not connected. Driver route choice, route-change approval and vehicle/route/trip assignment management are not implemented. Driver account creation is available, but other administrative directory pages remain read-only. Browser GPS cannot guarantee background tracking. A physical-phone road test is still required before operational use.

See [MYSQL_SETUP.md](MYSQL_SETUP.md) for database integration details and implementation history.
