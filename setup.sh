#!/usr/bin/env bash
# Laravel Starter: one-command setup, non-interactive and safe to re-run.
#
#   ./setup.sh --owner=you@example.com                 # Docker if available, else local
#   ./setup.sh --docker --owner=you@example.com        # no PHP/Node needed on the host
#   ./setup.sh --local --owner=you@example.com         # PHP 8.3+, Composer, Node 22, PostgreSQL
#   ./setup.sh --local --db-url=postgresql://user:pass@127.0.0.1:5432/starter
#   ./setup.sh --config=starter.config.json            # AI connections, agent, RAG, owner
#   ./setup.sh --json                                  # machine-readable summary on stdout
#
# Exit codes: 0 ready · 2 bad arguments · 3 missing prerequisites
#             4 database unreachable · 5 dependency install failed
#             6 database setup failed · 7 verification failed
#
# Guide for people and AI agents: INSTALL.md

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="$ROOT/apps/platform"
ENV_FILE="$APP/.env"

MODE=auto
OWNER=""
OWNER_NAME=""
CONFIG=""
DB_URL=""
URL=""
RAG=false
SKIP_BUILD=false
JSON=false
OWNER_FROM_CONFIG=false

usage() {
    sed -n '2,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    cat <<'EOF'
Options:
  --docker | --local     Force a mode (default: local when PHP, Composer, Node
                         and PostgreSQL are ready; otherwise Docker)
  --owner=EMAIL          Owner account (logs in with a one-time code)
  --name=NAME            Owner display name
  --config=FILE          Apply a starter.config.json (see starter.config.example.json)
  --db-url=URL           PostgreSQL URL for the local mode (DB_URL)
  --url=URL              Public URL (default http://localhost:8000, Docker :8080)
  --rag                  Enable the knowledge module (needs pgvector)
  --skip-build           Local mode: do not compile the front-end assets
  --json                 Print a JSON summary on stdout (logs go to stderr)
  -h, --help             Show this help
EOF
}

for arg in "$@"; do
    case "$arg" in
        --docker) MODE=docker ;;
        --local) MODE=local ;;
        --owner=*) OWNER="${arg#*=}" ;;
        --name=*) OWNER_NAME="${arg#*=}" ;;
        --config=*) CONFIG="${arg#*=}" ;;
        --db-url=*) DB_URL="${arg#*=}" ;;
        --url=*) URL="${arg#*=}" ;;
        --rag) RAG=true ;;
        --skip-build) SKIP_BUILD=true ;;
        --json) JSON=true ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $arg (see ./setup.sh --help)" >&2; exit 2 ;;
    esac
done

# --- Output ------------------------------------------------------------------

if [ -t 2 ]; then BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'; else BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""; fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET" >&2; }
info() { printf '    %s\n' "$1" >&2; }
ok() { printf '    %s✓%s %s\n' "$GREEN" "$RESET" "$1" >&2; }
warn() { printf '    %s!%s %s\n' "$YELLOW" "$RESET" "$1" >&2; }

fail() { # code message [hint...]
    local code=$1 message=$2
    shift 2
    printf '\n%s✗ %s%s\n' "$RED" "$message" "$RESET" >&2
    for hint in "$@"; do printf '    → %s\n' "$hint" >&2; done
    if [ "$JSON" = true ]; then
        printf '{"status":"error","code":%s,"mode":"%s","message":"%s"}\n' "$code" "$MODE" "$(json_escape "$message")"
    fi
    exit "$code"
}

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

run() { # run a command, showing it; returns its status
    info "\$ $*"
    if [ "$JSON" = true ]; then "$@" >&2; else "$@"; fi
}

# --- .env helpers ------------------------------------------------------------

env_get() { # key
    [ -f "$ENV_FILE" ] || return 0
    grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

env_set() { # key value (replaces the line, also a commented one, or appends)
    local key=$1 value=$2
    case "$value" in
        *\'*) value="\"$(printf '%s' "$value" | sed -e 's/[\\"]/\\&/g')\"" ;;
        *[[:space:]#\$\"\`\\]*) value="'$value'" ;; # single quotes: no interpolation
    esac
    local escaped
    escaped=$(printf '%s' "$value" | sed -e 's/[\\|&]/\\&/g')
    if grep -qE "^#? *$key=" "$ENV_FILE"; then
        sed -i.bak -E "s|^#? *$key=.*|$key=$escaped|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

port_open() { # host port
    (exec 3<>"/dev/tcp/$1/$2") 2>/dev/null
}

random_key() {
    printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
}

prepare_env() {
    if [ ! -f "$ENV_FILE" ]; then
        cp "$APP/.env.example" "$ENV_FILE"
        chmod 600 "$ENV_FILE" 2>/dev/null || true
        ok "Created apps/platform/.env from .env.example"
    else
        ok "Keeping the existing apps/platform/.env"
    fi

    [ -n "$URL" ] && env_set APP_URL "$URL"
    [ "$RAG" = true ] && env_set STARTER_FEATURE_RAG true
    return 0
}

# --- Prerequisites -----------------------------------------------------------

have() { command -v "$1" >/dev/null 2>&1; }

docker_ready() { have docker && docker compose version >/dev/null 2>&1 && docker info >/dev/null 2>&1; }

PNPM=(pnpm)

local_missing() { # prints what is missing for the local mode
    if ! have php; then
        echo "PHP 8.3+ (with pdo_pgsql, intl, mbstring, zip)"
    elif ! php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
        echo "PHP 8.3+ (found $(php -r 'echo PHP_VERSION;'))"
    else
        for extension in pdo_pgsql intl mbstring zip; do
            php -m | grep -qi "^$extension$" || echo "PHP extension $extension"
        done
    fi
    have composer || echo "Composer 2"
    if ! have node; then
        echo "Node.js 22.12+"
    elif ! node -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a>22||(a===22&&b>=12)?0:1)'; then
        echo "Node.js 22.12+ (found $(node --version))"
    fi
    if ! have pnpm && ! have corepack; then
        echo "pnpm 10 (corepack enable)"
    fi
}

database_endpoint() { # prints "host port" of the configured PostgreSQL
    local url=${DB_URL:-$(env_get DB_URL)}
    if [ -n "$url" ]; then
        printf '%s' "$url" | sed -E 's#^[a-z]+://([^@/]*@)?([^:/?]+)(:([0-9]+))?.*#\2 \4#'
    else
        printf '%s %s' "$(env_get DB_HOST)" "$(env_get DB_PORT)"
    fi
}

database_reachable() {
    local host port
    read -r host port <<<"$(database_endpoint)"
    port_open "${host:-127.0.0.1}" "${port:-5432}"
}

# --- Local mode --------------------------------------------------------------

ensure_database() { # create DB_DATABASE when missing, in its own process
    local result
    result=$(DB_HOST_V=$(env_get DB_HOST) DB_PORT_V=$(env_get DB_PORT) DB_NAME_V=$(env_get DB_DATABASE) \
        DB_USER_V=$(env_get DB_USERNAME) DB_PASS_V=$(env_get DB_PASSWORD) php -r '
        $name = getenv("DB_NAME_V");
        try {
            $pdo = new PDO("pgsql:host=".getenv("DB_HOST_V").";port=".(getenv("DB_PORT_V") ?: 5432).";dbname=postgres",
                getenv("DB_USER_V"), getenv("DB_PASS_V"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = ".$pdo->quote($name))->fetchColumn();
            if (! $exists) {
                $pdo->exec("CREATE DATABASE \"".str_replace("\"", "\"\"", $name)."\"");
                echo "created";
            } else {
                echo "exists";
            }
        } catch (Throwable $e) {
            echo "unknown: ".$e->getMessage();
        }' 2>&1)
    case "$result" in
        created) ok "Created the database $(env_get DB_DATABASE)" ;;
        exists) ok "Database $(env_get DB_DATABASE) found" ;;
        *) warn "Could not check or create the database (${result#unknown: }); trying the migrations anyway" ;;
    esac
}

setup_local() {
    step "Checking prerequisites (local mode)"
    local missing
    missing=$(local_missing)
    if [ -n "$missing" ]; then
        local hints=()
        while IFS= read -r line; do hints+=("Missing: $line"); done <<<"$missing"
        hints+=("Or use Docker instead: ./setup.sh --docker")
        fail 3 "The local mode needs PHP, Composer, Node and pnpm." "${hints[@]}"
    fi
    if ! have pnpm; then
        corepack enable >/dev/null 2>&1 || true
        have pnpm || PNPM=(corepack pnpm)
    fi
    [ "$(id -u)" = 0 ] && export COMPOSER_ALLOW_SUPERUSER=1
    ok "PHP $(php -r 'echo PHP_VERSION;'), Composer, Node $(node --version), pnpm"

    step "Environment"
    prepare_env
    if [ -n "$DB_URL" ]; then
        # Separate DB_* values (not DB_URL): Laravel only creates a missing
        # database named by DB_DATABASE...
        local parsed
        parsed=$(php -r '$u = parse_url($argv[1]);
            if (! is_array($u) || empty($u["host"])) { exit(1); }
            echo implode("\n", [$u["host"], $u["port"] ?? 5432, ltrim($u["path"] ?? "", "/") ?: "starter",
                urldecode($u["user"] ?? ""), urldecode($u["pass"] ?? "")]);' "$DB_URL") \
            || fail 2 "--db-url is not a valid URL (postgresql://user:password@host:5432/database)."
        local host port database user password
        { read -r host; read -r port; read -r database; read -r user; read -r password; } <<<"$parsed"
        env_set DB_URL ""
        env_set DB_HOST "$host"
        env_set DB_PORT "$port"
        env_set DB_DATABASE "$database"
        env_set DB_USERNAME "$user"
        env_set DB_PASSWORD "$password"
        DB_URL=""
        ok "Database: $database on $host:$port"
    fi
    [ -z "$URL" ] && [ -z "$(env_get APP_URL)" ] && env_set APP_URL http://localhost:8000

    # Without a mail server the login codes go to the log...
    if [ "$(env_get MAIL_MAILER)" = smtp ] && [ "$(env_get MAIL_PORT)" = 1025 ] \
        && ! port_open "$(env_get MAIL_HOST)" 1025; then
        env_set MAIL_MAILER log
        warn "No Mailpit on $(env_get MAIL_HOST):1025: MAIL_MAILER=log (codes in storage/logs/laravel.log)"
    fi

    step "Checking PostgreSQL"
    if ! database_reachable; then
        local endpoint
        endpoint=$(database_endpoint)
        fail 4 "PostgreSQL is not reachable at ${endpoint/ /:}." \
            "Start one with Docker: docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --profile local-db up -d postgres" \
            "Or point to yours: ./setup.sh --local --db-url=postgresql://user:password@host:5432/starter" \
            "Or run everything in Docker: ./setup.sh --docker"
    fi
    ok "PostgreSQL answers at $(database_endpoint | tr ' ' ':')"

    step "Installing dependencies (Composer and pnpm, from the lock files)"
    run composer install --working-dir="$APP" --no-interaction --prefer-dist --no-progress \
        || fail 5 "composer install failed." "Check the network and the PHP extensions, then re-run ./setup.sh"
    (cd "$ROOT" && run "${PNPM[@]}" install --frozen-lockfile) \
        || fail 5 "pnpm install failed." "Check the network and Node 22.12+, then re-run ./setup.sh"

    step "Database"
    if [ -z "$(env_get APP_KEY)" ]; then
        (cd "$APP" && run php artisan key:generate --force --no-interaction) || fail 6 "Could not generate APP_KEY."
    fi
    ensure_database
    (cd "$APP" && run php artisan migrate --seed --force --no-interaction) \
        || fail 6 "Migrations failed." \
            "The database user needs permission to create the database (or create it first)." \
            "Details: apps/platform/storage/logs/laravel.log"
    (cd "$APP" && run php artisan starter:permissions --no-interaction) || fail 6 "Could not sync the permissions."

    if [ -n "$CONFIG" ]; then
        (cd "$APP" && run php artisan starter:config-validate "$CONFIG_PATH" && run php artisan starter:config-apply "$CONFIG_PATH") \
            || fail 6 "starter.config.json could not be applied." "Validate it: php apps/platform/artisan starter:config-validate $CONFIG"
    fi
    if [ -n "$OWNER" ] && [ "$OWNER_FROM_CONFIG" = false ]; then
        (cd "$APP" && run php artisan starter:owner "$OWNER" ${OWNER_NAME:+"--name=$OWNER_NAME"}) \
            || fail 6 "Could not create the owner $OWNER."
    fi

    if [ "$SKIP_BUILD" = false ]; then
        step "Building the front-end"
        (cd "$ROOT" && run "${PNPM[@]}" build) || fail 5 "The front-end build failed."
    fi

    step "Verifying"
    (cd "$APP" && run php artisan starter:doctor) || fail 7 "starter:doctor reports failed required checks." "Read the table above; fix and re-run ./setup.sh"

    APP_URL_FINAL=$(env_get APP_URL)
    START="cd apps/platform && composer dev"
    STOP="Ctrl+C in the terminal of composer dev"
    if [ "$(env_get MAIL_MAILER)" = log ]; then
        INBOX="apps/platform/storage/logs/laravel.log"
    else
        INBOX="http://localhost:8025 (Mailpit) or your mail server"
    fi
}

# --- Docker mode -------------------------------------------------------------

COMPOSE=(docker compose -f "$ROOT/docker/compose.yaml" -f "$ROOT/docker/compose.local.yaml" --profile local-db --profile dev-mail)

setup_docker() {
    step "Checking prerequisites (Docker mode)"
    have docker || fail 3 "Docker is not installed." "Install Docker Engine or Docker Desktop with Compose v2, or use ./setup.sh --local"
    docker compose version >/dev/null 2>&1 || fail 3 "Docker Compose v2 is missing (docker compose)." "Update Docker or install the compose plugin"
    docker info >/dev/null 2>&1 || fail 3 "The Docker daemon is not running." "Start Docker Desktop or the docker service and re-run"
    ok "$(docker --version), $(docker compose version --short 2>/dev/null || echo 'compose v2')"

    step "Environment"
    prepare_env
    [ -z "$URL" ] && env_set APP_URL "http://localhost:${STARTER_HTTP_PORT:-8080}"
    if [ -z "$(env_get APP_KEY)" ]; then
        env_set APP_KEY "$(random_key)"
        ok "Generated APP_KEY"
    fi
    # Compose reads docker/.env to create the database with the same credentials...
    {
        echo "DB_DATABASE=$(env_get DB_DATABASE)"
        echo "DB_USERNAME=$(env_get DB_USERNAME)"
        echo "DB_PASSWORD=$(env_get DB_PASSWORD)"
    } > "$ROOT/docker/.env"
    chmod 600 "$ROOT/docker/.env" 2>/dev/null || true
    ok "docker/.env mirrors the database credentials"

    step "Building the images (the first time takes a few minutes)"
    run "${COMPOSE[@]}" build app web || fail 5 "The image build failed." "Check the network (Docker Hub, Packagist, npm) and re-run"

    step "Starting PostgreSQL and Mailpit"
    run "${COMPOSE[@]}" up -d --wait postgres mailpit || fail 4 "PostgreSQL or Mailpit did not start." "Is port ${MAILPIT_UI_PORT:-8025} free? docker compose logs postgres"

    step "Database"
    run "${COMPOSE[@]}" run --rm migrate || fail 6 "Migrations failed." "See: docker compose -f docker/compose.yaml logs"
    run "${COMPOSE[@]}" run --rm app php artisan db:seed --force --no-interaction || fail 6 "Seeding failed."
    if [ -n "$CONFIG" ]; then
        run "${COMPOSE[@]}" run --rm -v "$CONFIG_PATH:/tmp/starter.config.json:ro" app \
            sh -c 'php artisan starter:config-validate /tmp/starter.config.json && php artisan starter:config-apply /tmp/starter.config.json' \
            || fail 6 "starter.config.json could not be applied." "Secrets referenced as env:NAME must be in apps/platform/.env"
    fi
    if [ -n "$OWNER" ] && [ "$OWNER_FROM_CONFIG" = false ]; then
        run "${COMPOSE[@]}" run --rm app php artisan starter:owner "$OWNER" ${OWNER_NAME:+"--name=$OWNER_NAME"} \
            || fail 6 "Could not create the owner $OWNER."
    fi

    step "Starting the application"
    run "${COMPOSE[@]}" up -d --wait web app queue scheduler || fail 7 "The application did not start." "See: docker compose -f docker/compose.yaml logs app web"

    step "Verifying"
    run "${COMPOSE[@]}" exec -T app php artisan starter:doctor || fail 7 "starter:doctor reports failed required checks."
    local port=${STARTER_HTTP_PORT:-8080}
    if have curl; then
        curl -fsS "http://localhost:$port/up" >/dev/null && ok "http://localhost:$port/up answers" \
            || fail 7 "http://localhost:$port does not answer." "Is the port free? Set STARTER_HTTP_PORT and re-run"
    fi

    APP_URL_FINAL=$(env_get APP_URL)
    START="docker compose -f docker/compose.yaml -f docker/compose.local.yaml --profile local-db --profile dev-mail up -d"
    STOP="docker compose -f docker/compose.yaml -f docker/compose.local.yaml --profile local-db --profile dev-mail down"
    INBOX="http://localhost:${MAILPIT_UI_PORT:-8025} (Mailpit)"
}

# --- Main --------------------------------------------------------------------

cd "$ROOT" || exit 2

if [ -n "$OWNER" ] && ! printf '%s' "$OWNER" | grep -qE '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; then
    fail 2 "--owner must be an email address."
fi
CONFIG_PATH=""
if [ -n "$CONFIG" ]; then
    [ -f "$CONFIG" ] || fail 2 "Config file not found: $CONFIG"
    CONFIG_PATH="$(cd "$(dirname "$CONFIG")" && pwd)/$(basename "$CONFIG")"
fi

if [ "$MODE" = auto ]; then
    if [ -z "$(local_missing)" ] && { [ -f "$ENV_FILE" ] || [ -n "$DB_URL" ] || port_open 127.0.0.1 5432; } && database_reachable; then
        MODE=local
    elif docker_ready; then
        MODE=docker
    elif [ -z "$(local_missing)" ]; then
        MODE=local
    else
        fail 3 "Neither Docker nor the local tools are ready." \
            "Docker: install Docker Desktop/Engine with Compose v2 and start it, then ./setup.sh --docker" \
            "Local: PHP 8.3+ (pdo_pgsql, intl, mbstring, zip), Composer 2, Node 22.12+, pnpm 10 and PostgreSQL 16+"
    fi
    info "Mode: $MODE (force it with --docker or --local)"
fi

# The owner can also come from the configuration file (summary only)...
if [ -z "$OWNER" ] && [ -n "$CONFIG_PATH" ] && have php; then
    OWNER=$(php -r '$c = json_decode((string) file_get_contents($argv[1]), true); echo $c["owner"]["email"] ?? "";' "$CONFIG_PATH" 2>/dev/null || true)
    OWNER_FROM_CONFIG=true
fi

APP_URL_FINAL=""
START=""
STOP=""
INBOX=""

if [ "$MODE" = docker ]; then setup_docker; else setup_local; fi

step "Ready"
ok "Application: $APP_URL_FINAL"
if [ -n "$OWNER" ]; then
    ok "Sign in with $OWNER: the one-time code arrives at $INBOX"
else
    warn "No owner yet. Create it with: ./setup.sh --owner=you@example.com (or php artisan starter:owner)"
fi
info "Start: $START"
info "Stop:  $STOP"
info "AI without keys answers with the simulated provider; add real keys in Admin → AI → Connections or with --config."
info "Check anytime: php apps/platform/artisan starter:verify (Docker: docker compose ... exec app php artisan starter:verify)"

if [ "$JSON" = true ]; then
    printf '{"status":"ready","mode":"%s","url":"%s","owner":"%s","login_codes":"%s","start":"%s","stop":"%s"}\n' \
        "$MODE" "$(json_escape "$APP_URL_FINAL")" "$(json_escape "$OWNER")" "$(json_escape "$INBOX")" \
        "$(json_escape "$START")" "$(json_escape "$STOP")"
fi
