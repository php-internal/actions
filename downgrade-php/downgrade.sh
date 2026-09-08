#!/usr/bin/env bash
#
# Downgrade PHP sources in place to a target version.
#
# Rector must never boot the *project's* autoloader. A composer autoloader eager-`require`s every
# `autoload.files` entry, which fatals the moment one uses syntax newer than the runtime (e.g.
# `readonly class` on PHP 8.1) — the very code we are here to downgrade. Two things keep it out:
# Rector is installed into a throwaway directory with its own composer.json (so installing it does
# not touch the project), and it is *run from* that directory, because Rector boots the
# `vendor/autoload.php` of its working directory — the project's if run from the project. The
# throwaway autoloader carries only Rector and PhpStan, which are runtime-compatible.
set -euo pipefail

paths="${INPUT_PATHS:?The 'paths' input is required.}"
php_version="${INPUT_PHP_VERSION:-8.1}"
rector_version="${INPUT_RECTOR_VERSION:-^2.6}"
action_dir="${GITHUB_ACTION_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"

# The paths input is workspace-relative; capture the workspace before switching directories so the
# Rector config resolves it regardless of where Rector runs from.
workspace_dir="${GITHUB_WORKSPACE:-$(pwd)}"

tool_dir="$(mktemp -d "${RUNNER_TEMP:-/tmp}/rector-downgrade.XXXXXX")"
trap 'rm -rf "$tool_dir"' EXIT

echo "::group::Install rector/rector ${rector_version} in isolation"
composer require "rector/rector:${rector_version}" \
  --working-dir="$tool_dir" --no-interaction --no-progress
echo "::endgroup::"

echo "Downgrading to PHP ${php_version}: ${paths}"
( cd "$tool_dir" \
  && GITHUB_WORKSPACE="$workspace_dir" \
     DOWNGRADE_PATHS="$paths" \
     DOWNGRADE_PHP_VERSION="$php_version" \
     DOWNGRADE_SKIP="${INPUT_SKIP:-}" \
     php vendor/bin/rector process \
       --config="${action_dir}/rector-downgrade.php" \
       --no-progress-bar )
