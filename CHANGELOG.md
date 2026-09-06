# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.1.0] - 2026-09-06

### Added

- Test coverage for documented-but-untested `QueryBuilder` behavior:
  - `select()` with a raw, non-identifier expression (e.g. `COUNT(*) AS total`)
    is passed through unmodified instead of being backtick-quoted.
  - A dotted wildcard column (`users.*`) quotes the table but leaves the `*`
    unquoted.
  - `groupBy()` accepts multiple columns via its variadic signature and
    compiles all of them in order.
  - `orderBy()` throws `InvalidArgumentException` for a direction other than
    `ASC`/`DESC`.
  - `insert()` and `update()` throw `InvalidArgumentException` when given an
    empty data array.
  - The `IN` operator accepts a scalar (non-array) value and treats it as a
    single-element list rather than iterating its characters.

No behavioral changes were needed — all new edge-case tests passed against
the existing implementation.
