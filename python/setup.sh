#!/usr/bin/env bash
set -euo pipefail

# Run from the repository root. Container-local packages; no DB/config changes.
./vendor/bin/sail exec -T -u root laravel.test bash -lc 'apt-get update && apt-get install -y --no-install-recommends python3.12-venv'
./vendor/bin/sail exec -T laravel.test python3 -m venv .venv
./vendor/bin/sail exec -T laravel.test .venv/bin/python -m pip install --requirement python/requirements.txt
