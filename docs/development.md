# Local development

## Requirements

PHP 8.3 (intl, mbstring, mysqli, sqlite3, gd, zip), Composer, Node.js 22, MySQL 8 (or MariaDB),
and optionally Ollama for a local node.

## Backend

```bash
composer install
cp env .env                      # then set the values below
mkdir -p writable/{cache,logs,session,uploads}
php spark migrate --all
php spark db:seed AuthSeeder     # creates the first super admin (see the seeder for the email)
php spark serve                  # http://localhost:8080
```

Minimum `.env` for development:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'
database.default.hostname = 127.0.0.1
database.default.database = aistudio
database.default.username = aistudio
database.default.password = secret
database.default.DBDriver = MySQLi
JWT_SECRET = <output of: openssl rand -hex 32>
```

In development an unset `JWT_SECRET` falls back to a built-in value; production refuses to start
without a real one.

## Frontend

```bash
cd frontend
npm ci
AISTUDIO_API=http://localhost:8080 npm run dev    # http://localhost:5191, /api proxied to the backend
```

Without `AISTUDIO_API` the dev server proxies `/api` to the production URL configured in
`frontend/vite.config.ts`.

Commit `public/app` whenever you change the frontend (`npm run build`); CI fails if it does not
match the source.

## Tests and checks (what CI runs)

```bash
vendor/bin/phpunit                         # backend (SQLite in memory, no MySQL needed)
cd frontend && npx tsc -b --noEmit && npx vitest run && npx vite build
cd mcp-server && npm ci && npx tsc
```

Backend tests live in `tests/unit` and `tests/feature`; feature tests call the real routes and
filters. Frontend tests sit next to the code (`*.test.ts`).

## A local node

Install Ollama, `ollama pull llama3.2`, then add a server in the app with API URL
`http://127.0.0.1:11434`.

## API reference

`docs/api-reference.md`, generated from `app/Config/Routes.php`:

```bash
python3 docs/gen-api-reference.py
```
