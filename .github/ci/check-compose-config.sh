#!/usr/bin/env bash
# Verifies the *resolved* Docker Compose configuration (no containers are started):
#   1. the documented default (.env.example) resolves to production settings and
#      does not include the development override;
#   2. the documented local-development variant still resolves to dev settings.
# Usage: .github/ci/check-compose-config.sh [env-file]   (default: .env.example)
set -euo pipefail

ENV_FILE="${1:-.env.example}"
OVERRIDE_FILE="docker-compose.override.yml"
PYTHON=""
for candidate in python3 python; do
  # Skip stubs (e.g. the Windows Store alias) that exist but cannot run code.
  if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c 'import json' >/dev/null 2>&1; then
    PYTHON="$candidate"; break
  fi
done
[ -n "$PYTHON" ] || { echo "FAIL: python is required to parse the resolved configuration"; exit 1; }

# Never inherit a caller's COMPOSE_FILE (CI sets one at job level).
unset COMPOSE_FILE

# Value of a `KEY=…` line (or the commented `# KEY=…` dev line) from the env file.
env_value() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 | tr -d '\r'; }
dev_value() { sed -n "s/^# $1=//p" "$ENV_FILE" | tail -n1 | tr -d '\r'; }

# Resolve one configuration and assert APP_ENV / APP_DEBUG / override markers.
check() {
  local label="$1" compose_file="$2" want_env="$3" want_debug="$4" want_override="$5"
  echo "== ${label}: COMPOSE_FILE=${compose_file}"

  case ":${compose_file}:" in
    *":${OVERRIDE_FILE}:"*) has_file=yes ;;
    *) has_file=no ;;
  esac
  if [ "$has_file" != "$want_override" ]; then
    echo "FAIL: ${OVERRIDE_FILE} listed=${has_file}, expected ${want_override}"; return 1
  fi

  COMPOSE_FILE="$compose_file" docker compose --env-file "$ENV_FILE" config --format json \
    | "$PYTHON" -c '
import json, sys
want_env, want_debug, want_override, label = sys.argv[1:5]
api = json.load(sys.stdin)["services"]["api"]
env = api.get("environment") or {}
# The dev override bind-mounts application source and a dev php.ini into the API.
mounts = [str(v.get("target", "")) for v in (api.get("volumes") or []) if v.get("type") == "bind"]
dev_mounts = any(t.startswith("/var/www/html/") or t.endswith("zzz-dev.ini") for t in mounts)
got = {"APP_ENV": env.get("APP_ENV"), "APP_DEBUG": str(env.get("APP_DEBUG")).lower(), "dev mounts": dev_mounts}
want = {"APP_ENV": want_env, "APP_DEBUG": want_debug, "dev mounts": want_override == "yes"}
print(f"   resolved: {got}")
if got != want:
    print(f"FAIL ({label}): expected {want}")
    sys.exit(1)
' "$want_env" "$want_debug" "$want_override" "$label"
}

default_files="$(env_value COMPOSE_FILE)"
dev_files="$(dev_value COMPOSE_FILE)"
[ -n "$default_files" ] || { echo "FAIL: no COMPOSE_FILE= line in ${ENV_FILE}"; exit 1; }
[ -n "$dev_files" ] || { echo "FAIL: no documented '# COMPOSE_FILE=' dev line in ${ENV_FILE}"; exit 1; }

check "default (production)" "$default_files" production false no
check "local development" "$dev_files" local true yes
echo "Compose configuration check passed."
