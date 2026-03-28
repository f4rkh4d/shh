#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
exec php -S 127.0.0.1:8088 -t public router.php
