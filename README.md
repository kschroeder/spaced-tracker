# kschroeder/spaced-tracker

PHP client for the [Space-Track.org](https://www.space-track.org) API. MIT license. Copyright 2026 Kevin Schroeder. It speaks every documented controller and request class, including classes added later through `Query::custom()`. The library depends on PSR interfaces and actively maintained HTTP packages. It does not depend on an existing Space-Track client.

This is the package the other libraries build on.

## Install

Packagist does not host this package. Point Composer at GitHub, then require version 1.0:

```bash
composer config repositories.spaced-tracker vcs https://github.com/kschroeder/spaced-tracker
composer require kschroeder/spaced-tracker:^1.0
```

PHP `^8.2`. For a concrete HTTP stack, also install one of:

```bash
composer require symfony/http-client nyholm/psr7
composer require guzzlehttp/guzzle nyholm/psr7
```

`ClientBuilder` prefers Symfony HttpClient, then Guzzle. Pass your own PSR-18 client if the application already has one. Amp and curl-multi are opt-in concurrency strategies and are not required for single requests.

## Configure

Environment variables and the array accepted by `ClientBuilder::configure()` use the same keys. `ClientBuilder::fromEnvironment()` reads the process environment. It does not open a `.env` file. [`.env.example`](.env.example) lists the keys. Export them in the PHP process, or pass the same array to `ClientBuilder::configure()`.

| Variable | Config key | Default |
| --- | --- | --- |
| `SPACETRACK_IDENTITY` | `identity` | required |
| `SPACETRACK_PASSWORD` | `password` | required |
| `SPACETRACK_BASE_URL` | `base_url` | `https://www.space-track.org` |
| `SPACETRACK_TIMEOUT` | `timeout` | `60` |
| `SPACETRACK_VERIFY_TLS` | `verify_tls` | `true` |
| `SPACETRACK_RATE_PER_MINUTE` | `rate_per_minute` | `29` |
| `SPACETRACK_RATE_PER_HOUR` | `rate_per_hour` | `299` |
| `SPACETRACK_LOG_PREDICATE_VALUES` | `log_predicate_values` | `false` |
| `SPACETRACK_SESSION_TTL` | `session_ttl` | `7200` |
| `SPACETRACK_USER_AGENT` | `user_agent` | `kschroeder-spaced-tracker/1.0` |
| `SPACETRACK_ENFORCE_ADVISORY` | `enforce_advisory_limits` | `false` |
| `SPACETRACK_CONCURRENCY` | `concurrency` | `auto` |

`auto` uses Symfony HttpClient multiplexing when the builder created that client, otherwise Guzzle promises, otherwise one request after another. `sequential` keeps an injected PSR-18 client and still preserves array keys. `curl`, `symfony`, `guzzle`, and `amp` select a specific batch transport. Curl multi does not inherit PSR-18 middleware. Amp is used only when `amphp/http-client` is installed and concurrency is `amp`.

Space-Track publishes per-class guidance such as hourly caps on `gp_history`. Those limits are advisory. The client always enforces the global windows above. Set `enforce_advisory_limits` to throw `AdvisoryLimitException` before a bulk history pull. A selective single-object query is not treated as bulk.

## Cache

`FileCache` is a PSR-16 cache with one JSON file per key. A null TTL uses the default of 60 seconds. A TTL of zero or less deletes the key. The cache does not unserialize PHP. The directory is created at mode 0700 and each file at 0600.

```php
use SpacedTracker\Cache\FileCache;

$client = ClientBuilder::fromEnvironment()
    ->withCache(new FileCache(sys_get_temp_dir() . '/spaced-tracker'))
    ->build();
```

`withCache()` keeps the Space-Track session in that cache when no session store was set. The session entry uses the session lifetime from `session_ttl`, so a 60 second cache default does not log the account out. Successful `execute()` and `executeMany()` responses are stored with a null TTL and therefore live for the cache default. Login, logout, uploads, model definitions, and failed responses are not cached. Without `withCache()`, the session stays in memory for that process and queries are not cached.

## Query

```php
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;
use SpacedTracker\ClientBuilder;
use SpacedTracker\Query\Direction;
use SpacedTracker\Query\Operator;
use SpacedTracker\Query\Query;

$client = ClientBuilder::fromEnvironment();

$result = $client->execute(
    Query::create(RequestClass::Gp)
        ->where('NORAD_CAT_ID', 25544)
        ->where('EPOCH', Operator::greaterThan(Operator::relativeToNow(-10)))
        ->orderBy('EPOCH', Direction::Desc)
        ->limit(1)
        ->format(OutputFormat::Json)
);

$iss = $result->records[0] ?? null;
```

`limit(100, 20)` is Space-Track's `limit/100,20` order: count, then offset.

Predicate values keep Space-Track operators: `>`, `<`, `<>`, `left--right`, `~~pattern`, `^prefix`, `null-val`, comma-separated OR, and `now-10`. `Operator` builds those strings. `<` and `>` are percent-encoded in the path. Commas and tildes are preserved.

Login is `POST /ajaxauth/login`. A failed login can still be HTTP 200, so the client treats a missing session cookie as failure. A later 401 logs in once and retries the query. Logout is `GET /ajaxauth/logout`.

Call `$client->modelDefinition('gp')` for `/basicspacedata/modeldef/class/gp/format/json`. Definitions are remembered on that client object. Logout does not clear them, and they are not written to the query cache.

### Request classes

`RequestClass` is the catalog. Wire names such as `download` exist on more than one controller, so the enum value is `controller.class` and `RequestClass::resolve('download')` throws until you pass the controller. `RequestClass::directory()` returns the current list with summaries. Deprecated classes (`tle`, `tle_latest`, `tle_publish`, `omm`, `csm`) stay addressable.

| Controller | Classes |
| --- | --- |
| `basicspacedata` | announcement, boxscore, cdm_public, decay, gp, gp_history, launch_site, satcat, satcat_change, satcat_debut, tip, tle, tle_latest, tle_publish, omm |
| `expandedspacedata` | car, cdm, maneuver, maneuver_history, organization, satellite, csm |
| `fileshare` | delete, download, file, folder, upload |
| `spephemeris` | download, file, file_history |
| `publicfiles` | dirs, download |

`publicfiles.download` sends the file name as the `name` query parameter. Upload is `POST /fileshare/query/class/upload/folder_id/{id}` with a multipart field named `file`:

```php
$client->uploadFile('10', 'elements.txt', $bytes, 'text/plain');
```

`sendAuthenticated()` replays a Space-Track path with the session cookie. Absolute URLs whose host is not the configured Space-Track host are rejected. Hop-by-hop headers, including any caller-supplied cookie, are stripped before the session cookie is added.

Unknown future classes:

```php
Query::custom('new_class', 'basicspacedata')->limit(10);
```

Class, controller, and field tokens must match `[A-Za-z][A-Za-z0-9_]*`. Folder ids used for upload match `[A-Za-z0-9][A-Za-z0-9_-]*`, so a numeric id is allowed.

## Batches

```php
$results = $client->executeMany([
    'iss' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 25544)->limit(1),
    'hst' => Query::create(RequestClass::Gp)->where('NORAD_CAT_ID', 20580)->limit(1),
]);
```

Keys are preserved. The global rate limiter still applies to each HTTP call, including the login that precedes a cold batch.

## Results and orbits

`ResultDecoder` turns JSON lists, `{data, request_metadata}` envelopes, CSV, and TLE/3LE groups into row arrays. Other formats stay in `rawBody`. `RecordMapper` hydrates SATCAT, GP, decay, TIP, boxscore, and launch-site rows. Extra columns remain on the record.

`OrbitRegime::fromElements()` labels LEO (period under 225 minutes), GEO (1200–1800), MEO (between those), and HEO (eccentricity at least 0.25 and apogee above 2000 km, or unknown apogee). These are catalog labels, not an orbit determination.

## Logging

Pass any PSR-3 logger. The builder wraps it in `RedactingLogger`. Logs include the request class, controller, field names, limit, format, status, size, and duration. Predicate values are omitted unless `log_predicate_values` is true. Passwords, the `identity` field, cookies, `chocolatechip`, and bearer tokens are removed even then. Response bodies are not logged. `Credentials` cannot be serialized, and `print_r()` / string casts show a redacted identity.

## Frameworks

Any PSR-11 container can use `SpacedTracker\Integration\Psr11ClientFactory`. It reads env vars, then overlays the `spaced-tracker` key of a `config` service. Optional services, when present, are the PSR-18 client, PSR-17 factories, PSR-3 logger, and PSR-20 clock.

Mezzio and Laminas register `SpacedTracker\Integration\Mezzio\ConfigProvider`.

Slim, CakePHP, Yii, CodeIgniter, and WordPress do not need a separate package. Put `Psr11ClientFactory` in the container, or call `ClientBuilder` from the bootstrap. Laravel and Symfony must extend their own provider and bundle classes, so those integrations live in `kschroeder/spaced-tracker-laravel` and `kschroeder/spaced-tracker-symfony`.

A clock is PSR-20. Session expiry and the rate-limit windows use it. Tests ship `FrozenClock` and `AdvancingSleeper` under `SpacedTracker\Testing` so suites do not sleep.

## Security

Keep the Space-Track password in the environment or a secret store. Do not put it in the browser, in a query string, or in a serialized session. The HTTP API and UI packages are designed so the browser never sees that password or the session cookie.

## Related packages

| Package | Role |
| --- | --- |
| [kschroeder/spaced-tracker-api](https://github.com/kschroeder/spaced-tracker-api) | HTTP API |
| [kschroeder/spaced-tracker-ui](https://github.com/kschroeder/spaced-tracker-ui) | CRT catalog console |
| [kschroeder/spaced-tracker-ai](https://github.com/kschroeder/spaced-tracker-ai) | Language-model adapters and orbital analysis |
| [kschroeder/spaced-tracker-laravel](https://github.com/kschroeder/spaced-tracker-laravel) | Laravel service provider and facade |
| [kschroeder/spaced-tracker-symfony](https://github.com/kschroeder/spaced-tracker-symfony) | Symfony bundle |
