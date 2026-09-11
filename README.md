<h1 align="center">php-internal/actions</h1>

<div align="center">

[![Support on Boosty](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsorship&logo=Boosty&logoColor=white&color=%23F15F2C)](https://boosty.to/roxblnfk)

[![Vibe Index](https://img.shields.io/static/v1?label=Vibe+Index&message=0.0&color=6c5ce7&style=flat-square)](https://github.com/roxblnfk/action-vibe-index)
[![Tests](https://img.shields.io/github/actions/workflow/status/php-internal/actions/tests.yml?branch=main&style=flat-square&logo=github&label=tests)](https://github.com/php-internal/actions/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](composer.json)

</div>

Shared GitHub Actions for [php-internal](https://github.com/php-internal) packages. Each
action lives in its own directory and is referenced as `php-internal/actions/<name>@<ref>`.

## Actions

### `downgrade`

Installs a project's Composer dependencies for a PHP version *older* than the code targets,
then downgrades whatever the older runtime cannot parse. Use it to run a suite written for a
newer PHP on an older one in CI, without carrying compatibility shims in the real source.

It first installs once ignoring the platform, purely to pull every package's sources onto disk.
Then it pins `config.platform.php` to the target and resolves for real, so Composer picks a
target-compatible version of every dependency that has one (symfony 6 rather than 8) and fails
*only* on packages that have no compatible version at all. Those — and only those — are copied
out of `vendor/`, re-advertised as target-compatible through a path repository, and the resolve
is retried until it settles. A single Rector pass (via `downgrade-rector`) then rewrites the copied
packages and any project sources given in `paths`.

Only the solver can tell a *hard* package (no compatible version, e.g. a package that only ever
targeted 8.2+) from a merely *droppable* one (a lower compatible version exists, e.g. symfony),
so the retried resolve drives the relief rather than a walk of `composer.lock`, which cannot tell
the two apart. This is also why it is not `--ignore-platform-req=php`: dropping the constraint
outright makes Composer pull the newest of everything (symfony 8 on PHP 8.1), which is far more,
and far riskier, code to downgrade.

On an old runtime, prefer `dependency-versions: lowest`. Rector's downgrade sets cover syntax
and some polyfillable functions, but not every 8.2+ *semantic* (e.g. `memory_reset_peak_usage()`
has no polyfill); the lowest set keeps that surface small.

Requires PHP and Composer on the runner (e.g. via `shivammathur/setup-php`).

#### Inputs

| Input                 | Required | Default   | Description                                                        |
|-----------------------|----------|-----------|--------------------------------------------------------------------|
| `php-version`         | yes      | —         | Target PHP version: one of `8.0`, `8.1`, `8.2`, `8.3`, `8.4`.      |
| `paths`               | no       | `''`      | Extra project sources to downgrade (deps are handled automatically); only needed when the project's own code targets a newer PHP. Space-separated on one line, or one per line as a multiline block — the multiline form lets a path contain spaces. |
| `dependency-versions` | no       | `highest` | Which versions to resolve: `lowest` or `highest`.                  |
| `skip`                | no       | `''`      | Space-separated paths or glob patterns to skip during the downgrade. |
| `rector-version`      | no       | `^2.6`    | Composer version constraint for the throwaway `rector/rector`.     |
| `working-directory`   | no       | workspace | Project root.                                                      |

#### Usage

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.1'

- name: Install dependencies and downgrade for PHP 8.1
  uses: php-internal/actions/downgrade@v1
  with:
    php-version: '8.1'
    # paths is only needed when the project's own code targets a newer PHP:
    # paths: src tests

- run: composer test
```

### `downgrade-rector`

Downgrades PHP sources in place to a target version, so a codebase written for a newer PHP
can be exercised on an older runtime in CI. The transform runs before anything loads the
code — install dependencies, downgrade, then run the suite as usual.

Rector is installed into a throwaway directory with its own `composer.json`; the project's
`composer.json` and `vendor/` are never touched, and its autoloader is never booted. That
last point matters: a Rector installed into the project's own `vendor/` shares the project
autoloader and eager-loads every `autoload.files` entry, which fatals the instant one of
them uses syntax newer than the runtime — the very code being downgraded. The isolated
install avoids that, and the throwaway directory is removed when the step finishes.

Requires PHP and Composer on the runner (e.g. via `shivammathur/setup-php`).

#### Inputs

| Input            | Required | Default | Description                                                        |
|------------------|----------|---------|--------------------------------------------------------------------|
| `paths`          | yes      | —       | Files or directories to downgrade, workspace-relative. Space-separated on one line, or one per line as a multiline block — the multiline form lets a path contain spaces. |
| `php-version`    | no       | `8.1`   | Target PHP version: one of `8.0`, `8.1`, `8.2`, `8.3`, `8.4`.       |
| `skip`           | no       | `''`    | Space-separated paths or glob patterns to skip — e.g. unparseable resource stubs or fixtures. |
| `rector-version` | no       | `^2.6`  | Composer version constraint for the throwaway `rector/rector`.     |

#### Usage

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.1'

- uses: ramsey/composer-install@v3
  with:
    composer-options: --ignore-platform-req=php

- name: Downgrade the sources to PHP 8.1
  uses: php-internal/actions/downgrade-rector@v1
  with:
    paths: core plugin bridge tests testo.php
    php-version: '8.1'
    skip: bridge/symfony-console/resources/stubs

- run: composer test
```
