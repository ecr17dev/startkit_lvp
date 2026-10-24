#!/usr/bin/env bash
# Laravel Starter: one-command setup, non-interactive and safe to re-run.
#
#   ./setup.sh --owner=you@example.com                 # Docker if available, else local
#   ./setup.sh --new-project="Mi App" --owner=you@example.com   # your own project from this template
#   ./setup.sh --docker --owner=you@example.com        # no PHP/Node needed on the host
#   ./setup.sh --local --owner=you@example.com         # PHP 8.3+, Composer, Node 22, PostgreSQL
#   ./setup.sh --local --db-url=postgresql://user:pass@127.0.0.1:5432/starter
#   ./setup.sh --config=starter.config.json            # AI connections, agent, RAG, owner
#   RESEND_API_KEY=re_... ./setup.sh --mail-from=hola@tudominio.com   # real email via Resend
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
MAIL=auto
MAIL_FROM=""
APP_NAME_ARG=""
FRESH_GIT=false

usage() {
    sed -n '2,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    cat <<'EOF'
Options:
  --new-project=NAME     Turn this clone into your own project: --app-name=NAME
                         and --fresh-git
  --app-name=NAME        Application name (APP_NAME) and, in Docker, its own
                         Compose project so several projects can live on one machine
  --fresh-git            New git history with one initial commit; the template
                         stays as the remote "starter" (refuses with uncommitted
                         changes or unpushed commits)
  --docker | --local     Force a mode (default: local when PHP, Composer, Node
                         and PostgreSQL are ready; otherwise Docker)
  --owner=EMAIL          Owner account (logs in with a one-time code)
  --name=NAME            Owner display name
  --config=FILE          Apply a starter.config.json (see starter.config.example.json)
  --db-url=URL           PostgreSQL URL for the local mode (DB_URL)
  --url=URL              Public URL (default http://localhost:8000, Docker :8080)
  --rag                  Enable the knowledge module (needs pgvector)
  --mail=MAILER          resend, mailpit or log (default: resend when RESEND_API_KEY
                         is set in the environment or in apps/platform/.env,
                         otherwise Mailpit, or the log when there is no Mailpit)
  --mail-from=EMAIL      Sender address (Resend: a verified domain; without one
                         onboarding@resend.dev, which only reaches your Resend account)
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
        --mail=*) MAIL="${arg#*=}" ;;
        --new-project=*) APP_NAME_ARG="${arg#*=}"; FRESH_GIT=true ;;
        --app-name=*) APP_NAME_ARG="${arg#*=}" ;;
        --fresh-git) FRESH_GIT=true ;;
        --mail-from=*) MAIL_FROM="${arg#*=}" ;;
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

# --- Project -----------------------------------------------------------------

slugify() { # Compose project name: lowercase letters, digits and dashes
    local slug="$1"
    # Drop the accents (Ágil -> Agil) when Perl is there; otherwise they become dashes...
    if have perl; then
        slug=$(printf '%s' "$slug" | perl -CS -MUnicode::Normalize -pe '$_ = NFD($_); s/\p{Mn}//g' 2>/dev/null || printf '%s' "$1")
    fi
    slug=$(printf '%s' "$slug" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')
    printf '%s' "${slug:-laravel-starter}"
}

fresh_git() { # one initial commit; the template stays reachable as "starter"
    have git || fail 3 "--fresh-git needs git."
    local template="" revision=""
    if [ -e "$ROOT/.git" ] && [ ! -d "$ROOT/.git" ]; then
        fail 2 "--fresh-git does not work inside a git worktree or submodule." "Clone the template into its own folder"
    fi
    if [ -d "$ROOT/.git" ]; then
        if git -C "$ROOT" remote get-url starter >/dev/null 2>&1 && ! git -C "$ROOT" remote get-url origin >/dev/null 2>&1; then
            ok "Git: already a project of its own (the template is the remote starter)"
            return
        fi
        [ -z "$(git -C "$ROOT" status --porcelain 2>/dev/null)" ] \
            || fail 2 "--fresh-git needs a clean working tree." "Commit or stash your changes, or run without --fresh-git"
        if git -C "$ROOT" rev-parse --abbrev-ref '@{u}' >/dev/null 2>&1 \
            && [ "$(git -C "$ROOT" rev-list --count '@{u}..HEAD')" != 0 ]; then
            fail 2 "--fresh-git would drop local commits that are not pushed." "Push them first, or run without --fresh-git"
        fi
        template=$(git -C "$ROOT" remote get-url origin 2>/dev/null || true)
        revision=$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || true)
        rm -rf "$ROOT/.git"
    fi
    git -C "$ROOT" init -q -b main 2>/dev/null || { git -C "$ROOT" init -q && git -C "$ROOT" symbolic-ref HEAD refs/heads/main; }
    [ -n "$template" ] && git -C "$ROOT" remote add starter "$template"
    git -C "$ROOT" add -A
    if git -C "$ROOT" commit -q -m "Start from Laravel Starter${revision:+ $revision}" >/dev/null 2>&1; then
        ok "Git: new history on main${template:+ (template as remote starter)}"
    else
        warn "Git: repository created; commit when your git identity is set (git config user.email ...)"
    fi
    info "Publish it: git remote add origin <your repository URL> && git push -u origin main"
}

# --- Mail --------------------------------------------------------------------

configure_mail() { # Resend when there is a key; otherwise the .env stays as it is
    [ -n "${RESEND_API_KEY:-}" ] && env_set RESEND_API_KEY "$RESEND_API_KEY"
    [ -n "$MAIL_FROM" ] && env_set MAIL_FROM_ADDRESS "$MAIL_FROM"
    if [ "$MAIL" = auto ] && [ -n "$(env_get RESEND_API_KEY)" ]; then MAIL=resend; fi
    case "$MAIL" in
        auto) ;;
        resend)
            [ -n "$(env_get RESEND_API_KEY)" ] || fail 2 "--mail=resend needs RESEND_API_KEY." \
                "Run: RESEND_API_KEY=re_... ./setup.sh --mail-from=hola@tudominio.com (or add it to apps/platform/.env)"
            env_set MAIL_MAILER resend
            case "$(env_get MAIL_FROM_ADDRESS)" in
                ""|*@example.com)
                    env_set MAIL_FROM_ADDRESS onboarding@resend.dev
                    warn "Sender onboarding@resend.dev: it only reaches the email of your Resend account. Verify your domain and pass --mail-from." ;;
            esac
            ok "Mail: Resend, sender $(env_get MAIL_FROM_ADDRESS)"
            ;;
        log)
            env_set MAIL_MAILER log
            ok "Mail: log (codes in apps/platform/storage/logs/laravel.log)"
            ;;
        mailpit)
            env_set MAIL_MAILER smtp
            env_set MAIL_HOST 127.0.0.1
            env_set MAIL_PORT 1025
            ok "Mail: Mailpit"
            ;;
    esac
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
    [ -n "$APP_NAME_ARG" ] && env_set APP_NAME "$APP_NAME_ARG" && ok "APP_NAME=$APP_NAME_ARG"
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
    configure_mail

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
    MAIL=$(env_get MAIL_MAILER)
    case "$MAIL" in
        log) INBOX="apps/platform/storage/logs/laravel.log" ;;
        resend) INBOX="the owner's inbox (Resend)" ;;
        smtp) [ "$(env_get MAIL_PORT)" = 1025 ] && MAIL=mailpit; INBOX="http://localhost:8025 (Mailpit) or your mail server" ;;
        *) INBOX="the owner's inbox ($MAIL)" ;;
    esac
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
    [ -n "$APP_NAME_ARG" ] && env_set APP_NAME "$APP_NAME_ARG" && ok "APP_NAME=$APP_NAME_ARG"
    [ -z "$URL" ] && env_set APP_URL "http://localhost:${STARTER_HTTP_PORT:-8080}"
    if [ -z "$(env_get APP_KEY)" ]; then
        env_set APP_KEY "$(random_key)"
        ok "Generated APP_KEY"
    fi
    configure_mail
    # Compose project: kept once chosen (it names the database volume); a
    # new project gets its own so several can live on one machine...
    PROJECT=$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$ROOT/docker/.env" 2>/dev/null | head -n 1)
    if [ -z "$PROJECT" ]; then
        if [ -n "$APP_NAME_ARG" ]; then PROJECT=$(slugify "$APP_NAME_ARG"); else PROJECT=laravel-starter; fi
    fi
    # Compose reads docker/.env for the project name, to create the database
    # with the same credentials and to pick the mailer (Mailpit unless
    # Resend or log)...
    {
        echo "COMPOSE_PROJECT_NAME=$PROJECT"
        echo "DB_DATABASE=$(env_get DB_DATABASE)"
        echo "DB_USERNAME=$(env_get DB_USERNAME)"
        echo "DB_PASSWORD=$(env_get DB_PASSWORD)"
        case "$MAIL" in resend|log) echo "MAIL_MAILER=$MAIL" ;; esac
    } > "$ROOT/docker/.env"
    chmod 600 "$ROOT/docker/.env" 2>/dev/null || true
    ok "docker/.env mirrors the database credentials (Compose project $PROJECT)"

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
    case "$MAIL" in
        resend) INBOX="the owner's inbox (Resend)" ;;
        log) INBOX="storage/logs/laravel.log inside the app container (docker compose ... exec app tail storage/logs/laravel.log)" ;;
        *) MAIL=mailpit; INBOX="http://localhost:${MAILPIT_UI_PORT:-8025} (Mailpit)" ;;
    esac
}

# --- Main --------------------------------------------------------------------

cd "$ROOT" || exit 2

if [ -n "$OWNER" ] && ! printf '%s' "$OWNER" | grep -qE '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; then
    fail 2 "--owner must be an email address."
fi
case "$MAIL" in auto|resend|mailpit|log) ;; *) fail 2 "--mail must be resend, mailpit or log." ;; esac
if [ -n "$MAIL_FROM" ] && ! printf '%s' "$MAIL_FROM" | grep -qE '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; then
    fail 2 "--mail-from must be an email address."
fi
CONFIG_PATH=""
if [ -n "$CONFIG" ]; then
    [ -f "$CONFIG" ] || fail 2 "Config file not found: $CONFIG"
    CONFIG_PATH="$(cd "$(dirname "$CONFIG")" && pwd)/$(basename "$CONFIG")"
fi

if [ "$FRESH_GIT" = true ]; then
    step "Git"
    fresh_git
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
    printf '{"status":"ready","mode":"%s","url":"%s","owner":"%s","mail":"%s","login_codes":"%s","start":"%s","stop":"%s"}\n' \
        "$MODE" "$(json_escape "$APP_URL_FINAL")" "$(json_escape "$OWNER")" "$MAIL" "$(json_escape "$INBOX")" \
        "$(json_escape "$START")" "$(json_escape "$STOP")"
fi
