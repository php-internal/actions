#!/usr/bin/env bash
#
# Install a project's Composer dependencies for a PHP version *older* than the code targets,
# then downgrade whatever the older runtime cannot parse.
#
# Two resolves. Phase 0 is a throwaway install that ignores the platform, purely to pull every
# package's *sources* onto disk (a failed resolve installs nothing, so there would be nothing to
# copy from otherwise). Phase 1 is the real resolve with config.platform.php pinned to the target:
# Composer picks a target-compatible version of every dependency that has one (symfony 6 rather
# than 8) and fails *only* on packages that have no compatible version at all. Those hard packages
# are read off the failure, copied out of vendor/, re-advertised as target-compatible through a
# path repository, and the resolve is retried until it settles. A final Rector pass downgrades the
# copies (and any project paths) so the code parses on the target.
#
# Only the solver knows which incompatible packages are "hard" (no compatible version) versus
# merely droppable (a lower compatible version exists); that is why the failure output drives the
# relief rather than a walk of composer.lock, which cannot tell the two apart.
set -euo pipefail

target="${INPUT_PHP_VERSION:?The 'php-version' input is required.}"
paths="${INPUT_PATHS:-}"
skip="${INPUT_SKIP:-}"
deps="${INPUT_DEPENDENCY_VERSIONS:-highest}"
rector_version="${INPUT_RECTOR_VERSION:-^2.6}"
workdir="${INPUT_WORKING_DIRECTORY:-${GITHUB_WORKSPACE:-$PWD}}"
action_dir="${GITHUB_ACTION_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
helper="${action_dir}/composer-helper.php"

cd "$workdir"

downgrade_root=".php-downgrade"
mkdir -p "$downgrade_root"

update_flags=(--no-interaction --no-progress --no-scripts --with-all-dependencies)
case "$deps" in
  lowest) update_flags+=(--prefer-lowest) ;;
  highest | '') ;;
  *) echo "Unknown dependency-versions '${deps}'; expected 'lowest' or 'highest'." >&2; exit 1 ;;
esac

# Phase 0: fetch every package's sources onto disk. The platform is ignored so even
# target-incompatible packages install; this code is never run, only copied from.
echo "::group::composer update --ignore-platform-reqs (fetch sources)"
composer update "${update_flags[@]}" --ignore-platform-reqs
echo "::endgroup::"

# Phase 1: pin the platform and drop the root's own php gate, then relieve the hard packages the
# solver rejects, one failure wave at a time.
composer config platform.php "$target"
php "$helper" loosen-root composer.json "$target"

processed=" "   # space-delimited set of packages already relieved
relieved_dirs=()
status=1

for attempt in $(seq 1 50); do
  echo "::group::composer update (attempt ${attempt}, platform.php=${target})"
  set +e
  output="$(composer update "${update_flags[@]}" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"
  echo "::endgroup::"

  if [ "$status" -eq 0 ]; then
    echo "Composer resolved on PHP ${target}."
    break
  fi

  # A blocker is reported in one of two shapes, depending on whether one version or a range is
  # named (a range makes the verb "require", a single version "requires"):
  #   - vendor/pkg 1.2.3 requires php >=8.2 -> ...
  #   - vendor/pkg[1.0.0, ..., 2.0.0] require php >=8.2 -> ...
  # Keep the package token: the first field after the dash, stripped of any "[version list]".
  # The trailing space after "php" leaves ext requirements ("requires php-64bit") untouched.
  mapfile -t blockers < <(
    printf '%s\n' "$output" \
      | grep -E ' requires? php ' \
      | sed -E 's/^[[:space:]]*-[[:space:]]+//; s/\[.*$//; s/[[:space:]].*$//' \
      | grep -E '^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$' \
      | sort -u
  )

  new_count=0
  for pkg in "${blockers[@]:-}"; do
    [ -z "$pkg" ] && continue
    case "$processed" in *" ${pkg} "*) continue ;; esac

    dest="${downgrade_root}/${pkg}"
    php "$helper" relieve "$pkg" "$dest" "$target" composer.json
    processed="${processed}${pkg} "
    relieved_dirs+=("$dest")
    new_count=$((new_count + 1))
  done

  if [ "$new_count" -eq 0 ]; then
    echo "composer update failed with no new php-incompatible package to relieve; giving up." >&2
    exit 1
  fi
done

if [ "$status" -ne 0 ]; then
  echo "composer update did not converge within the attempt limit." >&2
  exit 1
fi

# Downgrade the relieved package copies (symlinked into vendor/) and any project paths, so the
# code parses on the target. Skip Rector entirely when there is nothing to rewrite.
rector_paths=("${relieved_dirs[@]}")
[ -n "$paths" ] && rector_paths=($paths "${rector_paths[@]}")

if [ "${#rector_paths[@]}" -eq 0 ]; then
  echo "Nothing to downgrade; every dependency already supports PHP ${target}."
  exit 0
fi

echo "Downgrading ${#rector_paths[@]} path(s) to PHP ${target}."
INPUT_PATHS="${rector_paths[*]}" \
INPUT_PHP_VERSION="$target" \
INPUT_SKIP="$skip" \
INPUT_RECTOR_VERSION="$rector_version" \
  bash "${action_dir}/../downgrade-rector/downgrade.sh"
