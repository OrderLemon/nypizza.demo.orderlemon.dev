#!/usr/bin/env bash
# DEV/TEST ONLY. Writes the test secret config, waits for the backends, then
# either serves v2 (default) or runs the test suite ("test").
set -euo pipefail

# Copy the requested secrets file to the path expected by v2/config.php.
SECRET_FILE="${PMSRAPI_SECRET_FILE:-/opt/pmsrapi/test-env/nypizza.demo.json}"
MOCKUPS="${MOCKUPS:-/opt/pmsrapi/mockups/}"
MARVIN_1="${MARVIN:-/opt/pmsrapi/marvin.v1.txt}"
MARVIN_2="${MARVIN:-/opt/pmsrapi/marvin.v2.txt}"
MARVIN_3="${MARVIN:-/opt/pmsrapi/marvin.v3.txt}"
MENU="${MENU:-/opt/pmsrapi/menu.json}"
PORT="${PMSRAPI_PORT:-8080}"

if [ ! -f "$SECRET_FILE" ]; then
  echo "❌ secret file not found: $SECRET_FILE" >&2
  exit 1
fi

cp "$SECRET_FILE" /opt/nypizza.demo.json
mkdir -p /home/demo.data/98/mockups
cp -r "${MOCKUPS%/}/." /home/demo.data/98/mockups/
mkdir -p /home/demo.data/marvin_prompts/
cp "${MARVIN_1}" /home/demo.data/marvin_prompts/marvin.v1.txt
cp "${MARVIN_2}" /home/demo.data/marvin_prompts/marvin.v2.txt
cp "${MARVIN_3}" /home/demo.data/marvin_prompts/marvin.v3.txt
mkdir -p /home/demo.data/98/menu
cp "${MENU}" /home/demo.data/98/menu.json
mkdir -p /home/errors/
touch /home/errors/nypizza.demo.log

wait_for() { # host port name
  echo "⏳ waiting for $3 ($1:$2)…"
  for _ in $(seq 1 60); do
    if php -r "\$c=@fsockopen('$1',$2,\$e,\$s,1); exit(\$c?0:1);"; then
      echo "✅ $3 reachable"; return 0
    fi
    sleep 1
  done
  echo "❌ timed out waiting for $3"; return 1
}

wait_for db 3306 MariaDB
wait_for redis 6379 Redis

case "${1:-serve}" in
  serve)
    echo "🚀 serving v2 at http://0.0.0.0:${PORT}/v2 (Ctrl-C to stop)"
    exec php -S 0.0.0.0:${PORT} /opt/pmsrapi/v2/server.php
    ;;
  test)
    echo "🧪 starting server in background for the test run…"
    php -S 0.0.0.0:${PORT} /opt/pmsrapi/v2/server.php >/tmp/server.log 2>&1 &
    SRV=$!
    for _ in $(seq 1 40); do
      if php -r "exit(@file_get_contents('http://127.0.0.1:${PORT}/v2/_debug')!==false?0:1);"; then break; fi
      sleep 0.5
    done
    set +e
    php /opt/pmsrapi/test-env/tests/run.php
    CODE=$?
    set -e
    kill "$SRV" 2>/dev/null || true
    exit "$CODE"
    ;;
  *)
    exec "$@"
    ;;
esac