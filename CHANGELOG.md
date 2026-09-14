# Changelog

## [1.1.3](https://github.com/php-internal/actions/compare/v1.1.2...v1.1.3) (2026-09-14)


### Bug Fixes

* **downgrade:** expand empty path arrays safely on bash 3.2 ([83d10c0](https://github.com/php-internal/actions/commit/83d10c0dfe32299f3bf9b3eef8f91fc213d4a832))
* **downgrade:** read the solver blockers without mapfile ([32639de](https://github.com/php-internal/actions/commit/32639dee0bdbc7f1eb72dc2a127035d1218d4d8a))

## [1.1.2](https://github.com/php-internal/actions/compare/v1.1.1...v1.1.2) (2026-09-14)


### Bug Fixes

* **downgrade:** loosen project-local path packages in place instead of copying them ([8a9388c](https://github.com/php-internal/actions/commit/8a9388c501dd1c28da04b0c638e09ba3e64b0398))

## [1.1.1](https://github.com/php-internal/actions/compare/v1.1.0...v1.1.1) (2026-09-11)


### Bug Fixes

* **downgrade:** disable composer plugins during the throwaway installs ([b4acf11](https://github.com/php-internal/actions/commit/b4acf11e4ad7947f4a89255c43e146bcd9fa7993))
* **downgrade:** disable plugins for the platform-config step too ([2e68f21](https://github.com/php-internal/actions/commit/2e68f21aa57821eb7351767527b4c1b882ef8b5f))

## [1.1.0](https://github.com/php-internal/actions/compare/v1.0.0...v1.1.0) (2026-09-11)


### Features

* **downgrade:** accept a multiline paths list so a path can contain spaces ([8e2cfc5](https://github.com/php-internal/actions/commit/8e2cfc52b1cfcb9a8d603587f0cc5a745bcacf06))


### Documentation

* **readme:** center the title and point the top badge at this repo ([92d7095](https://github.com/php-internal/actions/commit/92d7095320a8ff3cd076d904b324ce41f482be7c))
* **readme:** drop the redundant GitHub Actions badge ([c522d2a](https://github.com/php-internal/actions/commit/c522d2a11b7ea00514b69d44b0eb52ff8674f0d2))
* **readme:** rework the header badges ([5e80be6](https://github.com/php-internal/actions/commit/5e80be612d70945399ff36f4d4f2af00891a1095))


### Code Refactoring

* **downgrade-rector:** extract input parsing into DowngradeInput ([d49c712](https://github.com/php-internal/actions/commit/d49c71236853d25e2e69ac08e9c70d7df4167687))
* **downgrade:** split composer-helper into pure transforms + I/O ([0122fde](https://github.com/php-internal/actions/commit/0122fdee37674daa7cd8333a740165c5771d6f6d))
