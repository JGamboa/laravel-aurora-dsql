# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.3.0] - Unreleased

### Changed
- The package moved to the Multiapi organization: Composer name `multiapi/laravel-aurora-dsql`,
  repository `github.com/multi-api/laravel-aurora-dsql`. It `replace`s `jgamboa/laravel-aurora-dsql`, which is
  abandoned on Packagist with this package as its replacement (its versions up to v0.2.2 stay installable).
- The PHP namespace is now `Multiapi\LaravelAuroraDsql` (service provider
  `Multiapi\LaravelAuroraDsql\DsqlServiceProvider`, auto-discovered). The configuration is unchanged.

### Deprecated
- The `JGamboa\LaravelAuroraDsql` class names. They remain as aliases of the new classes (including for type
  hints and `instanceof`) and are removed in 1.0.

## [0.2.2] - 2026-11-02

### Fixed
- The `dsql` driver applies the connection's `search_path` (or `schema`) on connect, like Laravel's `pgsql`
  driver. Leaving both unset keeps the role's default `search_path`.

## [0.2.1] - 2026-10-21

### Fixed
- Aurora DSQL has no `SAVEPOINT`: the connection reports `supportsSavepoints() === false` and nested
  transactions run inside the outer one.

## [0.2.0] - 2026-10-21

### Added
- Retry of OCC conflicts (SQLSTATE `40001`) for transactions and autocommit writes, with backoff.

### Fixed
- `afterCommit` callbacks of failed OCC attempts are discarded.

## [0.1.1] - 2026-10-08

### Changed
- README, docs, comments, error messages and tests translated to English.

## [0.1.0] - 2026-10-08

### Added
- Initial release: IAM token auth, DSQL-compatible schema builder (inline PK/FK, `ASYNC` indexes, identity)
  and migration helpers.

[0.3.0]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/multi-api/laravel-aurora-dsql/releases/tag/v0.1.0
