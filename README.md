# sk8-backend

Backend der SK8-Plattform: eine REST-API für die drei Frontends (Skate, Nutrition, Habits), Datenhaltung in PostgreSQL, UX-Telemetrie und – später – die KI-Funktionen. Stand: 7. September 2026.

Die Architekturentscheidungen liegen in `sk8-docs` (ADR-002 Stack, ADR-005 Deployment, ADR-006 API-Zugriff, ADR-007 Tests, ADR-008 Konventionen, ADR-009 Telemetrie, ADR-010 KI).

## Stack

| Baustein | Wahl |
|---|---|
| PHP | 8.5 (nur im Container, `dunglas/frankenphp:1-php8.5`) |
| Framework | Symfony 7.4 LTS |
| App-Server | FrankenPHP (Caddy + PHP in einem Prozess), Port 8000 |
| ORM | Doctrine ORM 3 + Doctrine Migrations |
| Datenbank | PostgreSQL 16 (`sk8_backend`, Tests: `sk8_backend_test`) |
| API-Stil | Controller + Request/Response-DTOs, Symfony Serializer + Validator |
| OpenAPI | NelmioApiDocBundle – UI unter `/api/doc`, JSON unter `/api/doc.json` |
| Sicherheit | Statischer API-Key im Header `X-Api-Key` (Symfony Access-Token-Authenticator) |
| CORS | NelmioCorsBundle, Allowlist über `CORS_ALLOW_ORIGIN` |
| Async | Symfony Messenger mit Doctrine-Transport |
| Logging | Monolog nach stderr (Prod: JSON) |
| Qualität | PHPUnit 13, PHPStan (Level max, strict rules), php-cs-fixer (`@Symfony` + `@PER-CS`) |

## Voraussetzungen

Nur **Docker** (Docker Desktop oder Engine ≥ 24 mit Compose v2). PHP, Composer und Symfony-CLI werden lokal nicht benötigt – alles läuft im Dev-Container.

PostgreSQL kommt aus `sk8-infrastructure` (`docker-compose.yml` dort) und wird aus dem Container über `host.docker.internal:5432` erreicht. Der Postgres-Container legt `sk8_backend` und `sk8_backend_test` an (Benutzer `sk8`/`sk8`).

## Einrichtung

```bash
git clone git@github.com:konradthiemann/sk8-backend.git
cd sk8-backend
./scripts/setup.sh        # aktiviert die Git-Hooks, baut das Dev-Image, installiert Abhängigkeiten
```

Läuft Postgres bei dir auf einem anderen Port oder Host, lege eine `.env.local` (und für Tests `.env.test.local`) an – beide sind gitignored:

```dotenv
# .env.local
DATABASE_URL="postgresql://sk8:sk8@host.docker.internal:5433/sk8_backend?serverVersion=16&charset=utf8"
```

```dotenv
# .env.test.local
DATABASE_URL="postgresql://sk8:sk8@host.docker.internal:5433/sk8_backend_test?serverVersion=16&charset=utf8"
```

Dann:

```bash
make migrate              # Schema in sk8_backend anlegen
make up                   # http://localhost:8000
curl -s localhost:8000/api/health
```

## Befehle (Makefile)

Jedes Ziel prüft, ob `php` auf dem Host vorhanden ist. Wenn ja, läuft der Befehl direkt; wenn nein, im Dev-Container via `docker compose run --rm --no-deps php …`. Sobald du PHP lokal installierst, funktionieren dieselben Befehle also ohne Änderung – auch `composer check` direkt.

| Befehl | Zweck |
|---|---|
| `make build` | Dev-Image bauen |
| `make up` / `make down` | Dev-Server starten (Port 8000) / stoppen |
| `make logs` | Logs des Dev-Servers verfolgen |
| `make sh` | Shell im Dev-Container |
| `make composer ARGS="require foo/bar"` | Composer |
| `make console ARGS="debug:router"` | `bin/console` |
| `make migrate` | Migrationen auf `sk8_backend` ausführen |
| `make test` | Migrationen auf `sk8_backend_test` + PHPUnit |
| `make phpstan` | Statische Analyse (Level max) |
| `make cs` / `make cs-fix` | Coding-Standard prüfen / korrigieren |
| `make check` | phpstan + cs (dry-run) + phpunit – läuft auch im Pre-Commit-Hook |
| `make openapi` | OpenAPI-Spec nach `var/openapi.json` schreiben |

Beim Start des Dev-Servers werden Migrationen **nicht** automatisch ausgeführt (`make migrate` oder `RUN_MIGRATIONS=1 make up`). In Produktion (`APP_ENV=prod`) migriert der Entrypoint beim Containerstart.

## Umgebungsvariablen

Defaults stehen in `.env` (committed, ungefährlich). Lokale Abweichungen gehören in `.env.local`; Produktionswerte werden ausschließlich in Railway gesetzt. Echte Umgebungsvariablen haben Vorrang vor allen `.env*`-Dateien.

| Variable | Default (`.env`) | Bedeutung |
|---|---|---|
| `APP_ENV` | `dev` | `dev`, `test` oder `prod` |
| `APP_SECRET` | leer (Dev-Wert in `.env.dev`) | Symfony-Secret; in Prod setzen |
| `DATABASE_URL` | `postgresql://sk8:sk8@host.docker.internal:5432/sk8_backend?serverVersion=16&charset=utf8` | Doctrine-DSN |
| `APP_API_KEY` | `dev-key-change-me` | Wert des Headers `X-Api-Key` (ADR-006); Tests: `test-key` |
| `CORS_ALLOW_ORIGIN` | `^https?://localhost(:[0-9]+)?$` | Regex der erlaubten Origins |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Messenger-Transport |
| `AI_MODEL_VISION`, `AI_MODEL_TEXT` | leer | Modell-IDs für KI-Funktionen (ADR-010) |
| `ANTHROPIC_API_KEY` | leer | Ohne Key liefern KI-Endpunkte 503 |
| `DEFAULT_URI` | `http://localhost:8000` | URL-Generierung in CLI-Kontexten |
| `SERVER_NAME` / `PORT` | `:8000` | Adresse, auf der Caddy lauscht; `PORT` wird von Railway gesetzt |
| `RUN_MIGRATIONS` | `0` | `1` = Migrationen auch außerhalb von Prod beim Start ausführen |

## Projektstruktur

```
src/
├── Controller/Api/   HTTP-Eingang: mappt Request → DTO, ruft Service, gibt JsonResponse
├── Dto/              Request-/Response-Objekte mit Validator- und OpenAPI-Attributen
├── Service/          Geschäftslogik ohne Framework-Abhängigkeit, unit-testbar
├── Entity/           Doctrine-Entities (Persistenzmodell)
├── Repository/       Doctrine-Abfragen
├── Enum/             Backed Enums (App, Event-Typ)
├── Security/         API-Key-Handler, technischer ApiUser, 401-Handler
└── EventListener/    ApiExceptionListener: einheitliches JSON-Fehlerformat
config/packages/      Bundle-Konfiguration (security, doctrine, nelmio_*, rate_limiter, …)
migrations/           Doctrine-Migrationen (ux_event, messenger_messages)
translations/         validators.de.yaml – deutsche Validierungsmeldungen
tests/Unit/           Tests ohne Kernel
tests/Functional/     WebTestCase gegen sk8_backend_test (Rollback je Test via dama)
tests/Factory/        Foundry-Factories
frankenphp/           Caddyfile, php.ini-Fragmente, docker-entrypoint.sh
```

## API-Übersicht (v0)

Alle Antworten sind JSON in camelCase. Fehler haben überall dieselbe Form:

| Status | Body |
|---|---|
| 401 | `{"error":"unauthorized"}` |
| 404 | `{"error":"not_found"}` |
| 405 | `{"error":"method_not_allowed"}` |
| 422 | `{"error":"validation_failed","violations":[{"field":"events[0].type","message":"…"}]}` |
| 500 | `{"error":"internal_error"}` |

### `GET /api/health` – öffentlich

```bash
curl -s localhost:8000/api/health
# {"status":"ok","time":"2026-09-07T10:00:00+00:00"}
```

### `POST /api/telemetry/events` – Header `X-Api-Key`

Nimmt 1–100 UX-Events einer Frontend-Session an (ADR-009). `type` ∈ `screen_view | time_on_screen | interaction | navigation`, `app` ∈ `skate | nutrition | habits`.

```bash
curl -s -X POST localhost:8000/api/telemetry/events \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: dev-key-change-me' \
  -d '{
    "app": "skate",
    "sessionId": "6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f",
    "events": [
      {"type": "screen_view", "screen": "home", "occurredAt": "2026-09-07T09:58:12.345Z"},
      {"type": "interaction", "screen": "session-new", "target": "save-button",
       "meta": {"trick": "kickflip"}, "occurredAt": "2026-09-07T09:59:01.000Z"}
    ]
  }'
# 202 {"accepted":2}
```

Die Frontends senden pro Event-Typ diese Felder (`meta` ist frei, wird als JSONB gespeichert und nicht weiter validiert):

| `type` | `target` | `meta` |
|---|---|---|
| `screen_view` | – | `{from: string\|null}` |
| `time_on_screen` | – | `{ms: number}` |
| `interaction` | Wert von `data-track` | `{via: "click"\|"submit", tag: string}` |
| `navigation` | Ziel-Pfad | `{from, to, via}` |

Weitere Eigenschaften des Vertrags:

- `occurredAt` ist ISO 8601 mit Millisekunden und `Z`. Die Spalte ist `timestamptz(0)`, Millisekunden werden also verworfen.
- `screen` darf der Literalstring `unknown` sein.
- Die Frontends leeren ihren Puffer bei 20 Events; der Endpunkt akzeptiert bis zu 100.
- `GET /api/health` ist öffentlich, akzeptiert aber einen mitgesendeten `X-Api-Key` (die Frontends setzen den Header auf allen Requests).
- Der Default von `CORS_ALLOW_ORIGIN` deckt `http://localhost:5173`, `:5174` und `:5175` ab (Skate, Nutrition, Habits).

Ungültige Daten:

```bash
# 422 {"error":"validation_failed","violations":[{"field":"events[0].type","message":"Unbekannter Event-Typ. Erlaubt sind: screen_view, time_on_screen, interaction, navigation."}]}
```

### OpenAPI

- Swagger-UI: <http://localhost:8000/api/doc>
- JSON: <http://localhost:8000/api/doc.json>
- Datei erzeugen: `make openapi` → `var/openapi.json` (Vertrag für die Frontends, MSW-Handler)

Die Fehler-Envelopes sind als benannte Schemas veröffentlicht (`ErrorResponse`, `Violation`,
`ValidationErrorResponse`, definiert in `config/packages/nelmio_api_doc.yaml`) und werden von jeder
Operation referenziert, die sie zurückgeben kann. Generierte Clients können `error` und `violations`
damit typisiert lesen; `meta` ist bewusst offen (`additionalProperties`), damit die frontend-seitigen
Payloads je Event-Typ hineinpassen. `tests/Functional/Api/ApiDocTest.php` sichert das ab – ein
fehlendes Schema fällt dort auf, bevor es die Frontends erreicht.

### Rate-Limiter

`config/packages/rate_limiter.yaml` definiert `api_ai` (30 Anfragen/Stunde, Sliding Window). Er ist noch keiner Route zugeordnet – der erste KI-Controller bindet ihn ein.

## Tests

```bash
make test                 # migriert sk8_backend_test, dann PHPUnit (Suites: Unit, Functional)
make check                # phpstan + cs + phpunit
```

- `tests/Unit/` läuft ohne Kernel und ohne Datenbank.
- `tests/Functional/` nutzt `WebTestCase` gegen die echte Postgres-Testdatenbank; `dama/doctrine-test-bundle` rollt jede Test-Transaktion zurück.
- Der Header `X-Api-Key` kommt aus der Konstante `TEST_API_KEY` (`tests/bootstrap.php`), die `APP_API_KEY` aus `.env.test` spiegelt.
- Testdaten: Foundry-Factories in `tests/Factory/`.

Der Pre-Commit-Hook (`.githooks/pre-commit`, aktiviert durch `scripts/setup.sh`) führt `make check` aus. GitHub Actions (`.github/workflows/ci.yml`) macht dasselbe gegen einen Postgres-Service.

## Deployment (Railway)

- `railway.json`: Builder `DOCKERFILE`, Healthcheck `/api/health`, Restart `ON_FAILURE`.
- Das Dockerfile baut standardmäßig das Ziel `frankenphp_prod`: `composer install --no-dev`, optimierter Autoloader, gewärmter Cache, Opcache ohne Timestamp-Prüfung.
- Beim Start wartet der Entrypoint auf die Datenbank und führt `doctrine:migrations:migrate` aus (ADR-005), danach startet FrankenPHP auf `$PORT` (Fallback 8000).
- Zu setzende Variablen in Railway: `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`, `APP_API_KEY`, `CORS_ALLOW_ORIGIN` (Frontend-Origins), später `ANTHROPIC_API_KEY`, `AI_MODEL_*`.

Lokal lässt sich das Prod-Image so prüfen:

```bash
docker build -t sk8-backend:prod .
docker run --rm -p 8000:8000 --add-host host.docker.internal:host-gateway \
  -e APP_SECRET=change-me -e APP_API_KEY=change-me \
  -e DATABASE_URL='postgresql://sk8:sk8@host.docker.internal:5432/sk8_backend?serverVersion=16&charset=utf8' \
  sk8-backend:prod
```
