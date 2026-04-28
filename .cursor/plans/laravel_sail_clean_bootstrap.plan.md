---
name: Laravel Sail clean bootstrap
overview: Prepare a repeatable bootstrap flow that starts from a clean git pull, installs PHP/JS dependencies, and launches Sail using host-native CPU architecture for better dev performance.
todos:
  - id: codify-bootstrap-sequence
    content: Document and standardize clean-pull bootstrap command sequence for Laravel + Sail.
    status: pending
  - id: arch-policy
    content: Define and document SAIL_PLATFORM native-arch policy with rebuild/verification steps.
    status: pending
  - id: env-alignment
    content: Align first-run .env DB settings with compose pgsql service expectations.
    status: pending
  - id: optional-bootstrap-script
    content: Add optional automation script/target for architecture detection and ordered startup.
    status: pending
  - id: validation-checks
    content: Add a concise validation checklist for container health, architecture, and app readiness.
    status: pending
isProject: false
---

# Laravel Sail Runbook (Clean Pull -> Running App)

## Scope
This runbook starts from a clean pull of this repository and brings the app up with Laravel Sail, Composer, and NPM using host-native CPU architecture for best local performance.

## Preflight
- Run from the repository root (the directory containing `artisan`, `composer.json`, and `compose.yaml`).
- Docker Desktop must be running.
- If `.env` does not exist, copy from `.env.example`.
- For this stack, use Postgres settings in `.env`:
  - `DB_CONNECTION=pgsql`
  - `DB_HOST=pgsql`
  - `DB_PORT=5432`
  - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

## Architecture Selection (Required)
- Apple Silicon (M1/M2/M3/M4): `SAIL_PLATFORM=linux/arm64`
- Intel/AMD64: `SAIL_PLATFORM=linux/amd64`
- Set this in `.env` before first `sail up`.

## Path A: Apple Silicon (Recommended Native)
```bash
git pull
cp .env.example .env 2>/dev/null || true

# in .env:
# SAIL_PLATFORM=linux/arm64
# DB_CONNECTION=pgsql
# DB_HOST=pgsql
# DB_PORT=5432
# DB_DATABASE=deployer
# DB_USERNAME=sail
# DB_PASSWORD=password

composer install
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

## Path B: Windows via WSL (Recommended)
Use this path from a WSL2 Linux shell (Ubuntu recommended) with Docker Desktop WSL integration enabled.

```bash
git pull
cp .env.example .env 2>/dev/null || true

# in .env:
# SAIL_PLATFORM=linux/amd64   # typical Windows x64 machines
# (if Windows on ARM, use linux/arm64)
# DB_CONNECTION=pgsql
# DB_HOST=pgsql
# DB_PORT=5432
# DB_DATABASE=deployer
# DB_USERNAME=sail
# DB_PASSWORD=password

composer install
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

## If Local Composer Is Missing
Use Sail's Composer container to bootstrap `vendor/bin/sail`, then continue.

macOS/Linux:
```bash
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php85-composer:latest \
  composer install --ignore-platform-reqs
```

Windows via WSL:
```bash
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php85-composer:latest \
  composer install --ignore-platform-reqs
```

## Verify Architecture and Health
macOS/Linux:
```bash
./vendor/bin/sail ps
./vendor/bin/sail config | grep -i platform
docker inspect "$("./vendor/bin/sail" ps -q laravel.test)" --format '{{.Os}}/{{.Architecture}}'
```

Windows via WSL:
```bash
./vendor/bin/sail ps
./vendor/bin/sail config | grep -i platform
docker inspect "$("./vendor/bin/sail" ps -q laravel.test)" --format '{{.Os}}/{{.Architecture}}'
```

Expected:
- Services are `Up`.
- `laravel.test` platform matches `SAIL_PLATFORM`.
- App responds and migrations succeed.

## Switching Architecture Later
Use this only when moving between `arm64` and `amd64`:
```bash
./vendor/bin/sail down
# update SAIL_PLATFORM in .env
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

## Performance Policy
- Default to host-native architecture for daily development.
- Force `linux/amd64` on Apple Silicon only for compatibility issues with specific binaries/dependencies.

## Troubleshooting Quick Hits
- `vendor/bin/sail` missing: run Composer install first.
- DB health check failing: confirm `DB_*` in `.env` align with `pgsql` service.
- Wrong architecture running: set `SAIL_PLATFORM`, then rebuild with `--no-cache`.

## Appendix: WSL Setup Checklist (Windows)
- Install WSL2 and Ubuntu: `wsl --install -d Ubuntu`
- Enable Docker Desktop integration for your WSL distro.
- Clone/open the repo inside the WSL filesystem (or ensure Docker file sharing is configured if using `/mnt/c/...`).
- Run all Laravel/Sail commands from the WSL shell using the Linux command paths in this runbook.