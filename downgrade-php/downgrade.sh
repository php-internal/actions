#!/usr/bin/env bash
#
# Downgrade PHP sources in place to a target version.
#
# Rector is installed into a throwaway directory with its own composer.json, so the
# project's own composer.json / vendor stay untouched and — crucially — the project's
# autoloader is never booted. A composer-installed Rector shares the project autoloader
# and eager-loads every `autoload.files` entry, which fatals the moment one of them uses
# syntax newer than the runtime (e.g. `readonly class` on PHP 8.1) — the very code we are
# here to downgrade. The isolated install sidesteps that entirely.
set -euo pipefail

paths="${INPUT_PATHS:?The 'paths' input is required.}"
php_version="${INPUT_PHP_VERSION:-8.1}"
rector_version="${INPUT_RECTOR_VERSION:-^2.6}"
action_dir="${GITHUB_ACTION_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"

tool_dir="$(mktemp -d "${RUNNER_TEMP:-/tmp}/rector-downgrade.XXXXXX")"
trap 'rm -rf "$tool_dir"' EXIT

echo "::group::Install rector/rector ${rector_version} in isolation"
composer require "rector/rector:${rector_version}" \
  --working-dir="$tool_dir" --no-interaction --no-progress
echo "::endgroup::"

echo "Downgrading to PHP ${php_version}: ${paths}"
DOWNGRADE_PATHS="$paths" \
DOWNGRADE_PHP_VERSION="$php_version" \
DOWNGRADE_SKIP="${INPUT_SKIP:-}" \
  php "${tool_dir}/vendor/bin/rector" process \
    --config="${action_dir}/rector-downgrade.php" \
    --no-progress-bar
