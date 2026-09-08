#!/usr/bin/env bash
#
# Install a project's Composer dependencies for a PHP version *older* than the code targets,
# then downgrade whatever is left that the older runtime cannot parse.
#
# The whole trick is to pin `config.platform.php` to the target: Composer then resolves the graph
# as if it were running on that PHP, picking a target-compatible version of every dependency that
# has one (symfony 6 instead of 8, and so on) and failing *only* on packages that have no
# compatible version at all. Those genuinely-incompatible packages — and only those — are copied
# out, advertised as target-compatible through a path repository, and the resolve is retried. Once
# it settles, a single Rector pass downgrades the copied packages and the project's own sources.
#
# The alternative — `--ignore-platform-req=php` — drops the php constraint from resolution
# entirely, so Composer pulls the *newest* of everything (symfony 8 on PHP 8.1) and there is far
# more, and far riskier, code to rewrite. Pinning the platform avoids that.
set -euo pipefail

target="${INPUT_PHP_VERSION:?The 'php-version' input is required.}"
paths="${INPUT_PATHS:?The 'paths' input is required.}"
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

# Pin the platform and drop the root's own php gate so the resolve is judged purely on the
# dependencies' requirements, one target-incompatible package at a time.
composer config platform.php "$target"
php "$helper" loosen-root composer.json "$target"

processed=" "   # space-delimited set of packages already relieved
relieved_dirs=()

for attempt in $(seq 1 50); do
  echo "::group::composer update (attempt ${attempt}, platform.php=${target})"
  set +e
  output="$(composer update "${update_flags[@]}" 2>&1)"
  status=$?
  set -e
  printf '%s\n' "$output"
  echo "::endgroup::"

  if [ "$status" -eq 0 ]; then
    echo "Composer resolved cleanly on PHP ${target}."
    break
  fi

  # Composer reports each blocker as a line like:
  #   - vendor/pkg 1.2.3 requires php >=8.2 -> your php version (8.1.0; ...) does not satisfy ...
  # Pull out the package names; the trailing space after "php" excludes ext requirements
  # such as "requires php-64bit".
  mapfile -t blockers < <(
    printf '%s\n' "$output" \
      | grep -oE '[A-Za-z0-9._-]+/[A-Za-z0-9._-]+ [^ ]+ requires php ' \
      | awk '{print $1}' | sort -u
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
    echo "composer update failed and no new php-incompatible package was found; giving up." >&2
    exit 1
  fi
done

if [ "${status:-1}" -ne 0 ]; then
  echo "composer update did not converge within the attempt limit." >&2
  exit 1
fi

# Everything is installed and target-compatible on paper; now make the code parse on the target.
# The relieved package copies are symlinked into vendor/, so downgrading the copies is enough.
echo "Downgrading sources and ${#relieved_dirs[@]} relieved package(s) to PHP ${target}."
INPUT_PATHS="${paths} ${relieved_dirs[*]}" \
INPUT_PHP_VERSION="$target" \
INPUT_SKIP="$skip" \
INPUT_RECTOR_VERSION="$rector_version" \
  bash "${action_dir}/../downgrade-php/downgrade.sh"
