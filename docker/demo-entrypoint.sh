#!/usr/bin/env bash
# Container entrypoint: prepare the site (bin/site-start.sh, shared with bare-metal hosts), mark it ready for
# the healthcheck, then hand over to Apache.
set -euo pipefail
rm -f /tmp/demo-ready
/opt/demo/bin/site-start.sh
touch /tmp/demo-ready
exec "$@"
