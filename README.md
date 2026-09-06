# php-query-builder

[![CI](https://github.com/kasapdev/php-query-builder/actions/workflows/ci.yml/badge.svg)](https://github.com/kasapdev/php-query-builder/actions/workflows/ci.yml) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)

A fluent, zero-dependency SQL query builder for PHP. It builds MySQL-style SQL (backtick-quoted
identifiers) as plain strings plus a bindings array, ready to hand to `PDO::prepare()` /
`PDOStatement::execute()`. The core guarantee: **values you pass in are never interpolated into
the SQL string** — they only ever appear, in order, in the returned bindings array.

## Installation

Once published to Packagist:

```bash
composer require kasapdev/php-query-builder
```

Or just require the file directly:

```php
require_once 'src/QueryBuilder.php';
```

## Usage

```php
use Kasapdev\QueryBuilder\QueryBuilder;

// SELECT ...
[$sql, $bindings] = QueryBuilder::table('users')
    ->select(['users.id', 'users.name', 'orders.total'])
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.active', '=', 1)
    ->andWhere('orders.total', '>', 100)
    ->orWhere('users.role', '=', 'admin')
    ->orderBy('orders.total', 'DESC')
    ->limit(10)
    ->offset(0)
    ->toSql();

// $sql:      "SELECT `users`.`id`, `users`.`name`, `orders`.`total` FROM `users`
//             INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`
//             WHERE `users`.`active` = ? AND `orders`.`total` > ? OR `users`.`role` = ?
//             ORDER BY `orders`.`total` DESC LIMIT ? OFFSET ?"
// $bindings: [1, 100, "admin", 10, 0]

$pdo = new PDO('mysql:host=localhost;dbname=app', $user, $pass);
$stmt = $pdo->prepare($sql);
$stmt->execute($bindings);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INSERT / UPDATE / DELETE are terminal helpers on the same class:
$qb = QueryBuilder::table('users');

[$sql, $bindings] = $qb->insert('users', ['name' => 'Ada', 'email' => 'ada@example.com']);
$pdo->prepare($sql)->execute($bindings);

// upsert() = INSERT, or UPDATE the existing row if a unique/primary key collides:
[$sql, $bindings] = $qb->upsert('users', ['id' => 7, 'name' => 'Ada', 'hits' => 1]);
$pdo->prepare($sql)->execute($bindings);

[$sql, $bindings] = $qb->update('users', ['name' => 'Grace'], ['id' => 7]);
$pdo->prepare($sql)->execute($bindings);

[$sql, $bindings] = $qb->delete('users', ['id' => 7]);
$pdo->prepare($sql)->execute($bindings);
```

### Why bindings-only matters

```php
$evil = "'; DROP TABLE users; --";
[$sql, $bindings] = QueryBuilder::table('users')->where('name', '=', $evil)->toSql();

// $sql:      "SELECT * FROM `users` WHERE `name` = ?"   <-- no injected SQL, ever
// $bindings: ["'; DROP TABLE users; --"]                <-- the raw value, safely parameterized
```

This is enforced structurally: the builder never does string concatenation of a *value* into the
SQL text. Every value-producing code path (`where`, `having`, `insert`, `update`, `delete`)
appends a `?` to the SQL and pushes the raw value onto the bindings array instead.

### Supported WHERE forms

```php
->where('id', 5)                 // shorthand for where('id', '=', 5)
->where('id', '=', 5)
->where('age', '>=', 18)
->where('id', 'IN', [1, 2, 3])    // expands to IN (?, ?, ?)
->where('id', 'NOT IN', [1, 2])
->where('deleted_at', '=', null) // compiles to IS NULL
->where('deleted_at', '!=', null) // compiles to IS NOT NULL
->andWhere(...)                  // explicit AND
->orWhere(...)                   // explicit OR
```

### Upsert

```php
// If a row with a colliding unique/primary key already exists, refresh every
// given column on it instead of failing the INSERT:
[$sql, $bindings] = QueryBuilder::table('users')->upsert('users', [
    'id' => 7,
    'email' => 'ada@example.com',
    'login_count' => 1,
]);
// INSERT INTO `users` (`id`, `email`, `login_count`) VALUES (?, ?, ?)
// ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `email` = VALUES(`email`), `login_count` = VALUES(`login_count`)

// Pass $updateColumns to only refresh specific columns on conflict (e.g. leave
// login_count alone and just bump the email):
[$sql, $bindings] = QueryBuilder::table('users')->upsert(
    'users',
    ['id' => 7, 'email' => 'ada@example.com', 'login_count' => 1],
    ['email']
);
// ... ON DUPLICATE KEY UPDATE `email` = VALUES(`email`)
```

This compiles MySQL/MariaDB's `INSERT ... ON DUPLICATE KEY UPDATE`. Which row counts as a
"duplicate" is decided by the table's own unique/primary key constraints, not by anything passed
to `upsert()` — there's no separate `$uniqueBy` argument because MySQL doesn't need one.

## API

### `QueryBuilder`

- `QueryBuilder::table(string $table): self` — start a query
- `select(array $columns): self`
- `where(string $column, mixed $operator, mixed $value = null): self`
- `andWhere(...)` / `orWhere(...)` — same signature as `where()`
- `join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self`
- `leftJoin(...)` / `rightJoin(...)` — same signature as `join()` minus `$type`
- `orderBy(string $column, string $direction = 'ASC'): self`
- `groupBy(string ...$columns): self`
- `having(string $column, mixed $operator, mixed $value = null): self` / `orHaving(...)`
- `limit(int $limit): self`
- `offset(int $offset): self`
- `toSql(): array` → `[string $sql, array $bindings]`
- `insert(string $table, array $data): array` → `[string $sql, array $bindings]`
- `upsert(string $table, array $data, array $updateColumns = []): array` → `[string $sql, array $bindings]`
  — `INSERT ... ON DUPLICATE KEY UPDATE`; `$updateColumns` defaults to every column in `$data`
- `update(string $table, array $data, array $where): array` → `[string $sql, array $bindings]`
- `delete(string $table, array $where): array` → `[string $sql, array $bindings]`

All identifiers (table names, column names) are quoted with backticks. Dotted identifiers
(`table.column`) are quoted per-segment. Expressions that aren't simple identifiers (containing
spaces, parentheses, etc.) are passed through unmodified so you can supply raw expressions like
`COUNT(*) AS total`.

## Testing

```bash
php tests/run.php
```

The test suite includes an explicit SQL-injection regression test (a value containing
`'; DROP TABLE users; --'` is asserted to appear only in the bindings array, never in the SQL
string) plus end-to-end execution of generated SQL against a real in-memory SQLite database.

## License

MIT
