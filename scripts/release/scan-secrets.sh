#!/bin/sh
# Release-gate secret and default-credential scan. No network, no extra tools.
#
#   sh scripts/release/scan-secrets.sh                  # repository + image build context
#   IMAGE=click-tourism-backend:ci sh scripts/release/scan-secrets.sh   # also inspect a built image
#
# Exit 0 = nothing found. Exit 1 = findings (printed). Image inspection prints
# UNVERIFIED (and does not fail) when no Docker daemon is reachable.
#
# Suppress a reviewed false positive with the marker `scan-secrets: allow` and
# a reason on the same line (only the isolated demo compose file uses it).
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"
findings=0

report() {
    echo "FINDING: $*"
    findings=$((findings + 1))
}

# Never scan prose, lock files, third-party code or test fixtures that legitimately contain passwords.
EXCLUDES=':!tourism_erp_agent_prompts :!backend/tests :!backend/database/factories :!*.lock :!*package-lock.json :!docs/audits :!backend/vendor :!web/node_modules'

scan() { # scan <label> <extended regex>
    # shellcheck disable=SC2086
    hits=$(git grep -I -n -E -e "$2" -- . $EXCLUDES | grep -v 'scan-secrets: allow' || true)
    if [ -n "$hits" ]; then
        report "$1"
        echo "$hits" | sed 's/^/    /'
    fi
}

echo "== 1. Tracked files =="
scan "private key material" '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
scan "AWS access key id" 'AKIA[0-9A-Z]{16}'
scan "GitHub token" 'gh[pousr]_[A-Za-z0-9]{36,}'
scan "Slack token" 'xox[baprs]-[A-Za-z0-9-]{10,}'
scan "Stripe live key" '(sk|rk)_live_[0-9A-Za-z]{20,}'
scan "Google API key" 'AIza[0-9A-Za-z_-]{35}'
scan "Laravel APP_KEY literal" 'APP_KEY[=:] *"?base64:[A-Za-z0-9+/]{40,}'
scan "known default password assigned to a credential" '(PASSWORD|SECRET|TOKEN|PASSPHRASE)[A-Z_]*[=:] *["'"'"']?(password|passw0rd|secret|admin|root|changeme|change-me|123456|12345678|qwerty|letmein)["'"'"']?[ ,]*$'
scan "credential default fallback in compose (\${VAR:-weak})" '\$\{[A-Z_]*(PASSWORD|SECRET)[A-Z_]*:-[^}]+\}'

tracked_env=$(git ls-files | grep -E '(^|/)\.env($|\.)' | grep -v '\.example$' || true)
if [ -n "$tracked_env" ]; then
    report "environment file committed to git"
    echo "$tracked_env" | sed 's/^/    /'
fi

echo "== 2. Docker build contexts exclude secrets =="
for ctx in backend web; do
    ignore="$ctx/.dockerignore"
    if [ ! -f "$ignore" ]; then
        report "$ignore is missing: .env files would be copied into the image"
        continue
    fi
    if ! grep -qE '^\.env(\*|\.\*)?$' "$ignore"; then
        report "$ignore does not exclude .env files"
    fi
done
if ! grep -qE '^\.git$' backend/.dockerignore 2>/dev/null; then
    report "backend/.dockerignore does not exclude .git"
fi

echo "== 3. Release image =="
if [ -z "${IMAGE:-}" ]; then
    echo "UNVERIFIED: set IMAGE=<built backend image> to inspect a real image."
elif ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
    echo "UNVERIFIED: no reachable Docker daemon, image $IMAGE was not inspected."
else
    baked=$(docker run --rm --entrypoint sh "$IMAGE" -c \
        'find /var/www/html -maxdepth 3 \( -name ".env" -o -name ".env.*" -o -name "auth.json" -o -name "*.pem" -o -name "id_rsa*" -o -name "*.sql" -o -name "*.dump" \) -not -path "*/vendor/*" -not -name ".env.example" 2>/dev/null' || true)
    if [ -n "$baked" ]; then
        report "secret-like files inside image $IMAGE"
        echo "$baked" | sed 's/^/    /'
    fi
    envs=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$IMAGE" | grep -iE '(PASSWORD|SECRET|TOKEN|APP_KEY)=.+' || true)
    if [ -n "$envs" ]; then
        report "secret-looking ENV baked into image $IMAGE"
        echo "$envs" | sed 's/=.*/=<redacted>/' | sed 's/^/    /'
    fi
    leaked=$(docker history --no-trunc --format '{{.CreatedBy}}' "$IMAGE" | grep -iE '(PASSWORD|SECRET|TOKEN|APP_KEY)=[^ ]+' || true)
    if [ -n "$leaked" ]; then
        report "secret-looking value in image history of $IMAGE"
    fi
    echo "image inspected: $IMAGE"
fi

echo
if [ "$findings" -eq 0 ]; then
    echo "SECRET SCAN: PASS"
    exit 0
fi
echo "SECRET SCAN: FAIL ($findings finding(s))"
exit 1
