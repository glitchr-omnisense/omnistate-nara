# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

### Added
- `policy.json`, the register's usage policy in omnisense/contracts' schema (named in
  `composer.json`, `extra.omnisense.policy`), given by `usagePolicy()` (`DeclaresUsagePolicyInterface`):
  limits 10,000 a month per key; the rights unknown until the terms are read again. Requires
  `omnisense/contracts`.
- A 429 is thrown with `$status` 429: through the facade, the next calls wait for its `retryAfter`.
- PHPStan at level max, its baseline what the package had on 2026-11-10.

### Changed
- Requires `omnisense/contracts: ^1.0`, `omnisense/omnistate: ^1.0`: stable versions, so `composer require omnistate/nara` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
- PHPStan at level max with an empty baseline, types only.

### Fixed
- An unreachable NARA Catalog no longer carries the key: the `UnavailableException` wrapped the HTTP
  client's own exception, whose trace kept the request's headers in its arguments
  (`zend.exception_ignore_args` off). The previous is now a copy of it (the same class and code, the
  key masked in its message) and no trace of the chain keeps its arguments. Needs
  omnisense/omnistate's `Http\Redaction`.
