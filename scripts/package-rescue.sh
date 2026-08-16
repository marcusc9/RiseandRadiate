#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
plugin_root="$project_root/plugin"
dist_root="$project_root/dist"
archive="$dist_root/rise-and-radiate-rescue.zip"

mkdir -p "$dist_root"
cd "$plugin_root"
zip -q -r -FS "$archive" rise-and-radiate-rescue -x '*.DS_Store'
unzip -t "$archive"
printf 'Created %s\n' "$archive"
