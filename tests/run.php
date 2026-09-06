<?php

declare(strict_types=1);

$__failures = 0;
function check(string $label, bool $condition): void
{
    global $__failures;
    echo ($condition ? "[PASS] " : "[FAIL] ") . $label . "\n";
    if (!$condition) {
        $__failures++;
    }
}

require_once __DIR__ . '/../src/QueryBuilder.php';

use Kasapdev\QueryBuilder\QueryBuilder;

// --- Basic SELECT ------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->toSql();
check('basic select all defaults to *', $sql === 'SELECT * FROM `users`');
check('basic select has no bindings', $bindings === []);

[$sql, $bindings] = QueryBuilder::table('users')->select(['id', 'name'])->toSql();
check('select with explicit columns', $sql === 'SELECT `id`, `name` FROM `users`');

// --- WHERE / bindings ----------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->where('id', '=', 5)->toSql();
check('where produces ? placeholder', $sql === 'SELECT * FROM `users` WHERE `id` = ?');
check('where binding value in order', $bindings === [5]);

[$sql, $bindings] = QueryBuilder::table('users')->where('age', '>', 18)->toSql();
check('where with custom operator', $sql === 'SELECT * FROM `users` WHERE `age` > ?');
check('where custom operator binding', $bindings === [18]);

// two-argument shorthand where($col, $val)
[$sql, $bindings] = QueryBuilder::table('users')->where('id', 5)->toSql();
check('two-arg where() shorthand defaults to =', $sql === 'SELECT * FROM `users` WHERE `id` = ?');
check('two-arg where() shorthand binding', $bindings === [5]);

// --- The core correctness property: values NEVER interpolated into SQL --------------

$maliciousValue = "'; DROP TABLE users; --";
[$sql, $bindings] = QueryBuilder::table('users')->where('name', '=', $maliciousValue)->toSql();
check('malicious where() value does not appear in the SQL string', !str_contains($sql, 'DROP TABLE'));
check('malicious where() value does not appear anywhere in SQL (exact scan)', !str_contains($sql, $maliciousValue));
check('SQL contains only a placeholder for the where value', $sql === 'SELECT * FROM `users` WHERE `name` = ?');
check('malicious value ends up exclusively in the bindings array', $bindings === [$maliciousValue]);

$maliciousColumnValue = "x'); DELETE FROM users WHERE ('1'='1";
[$sql, $bindings] = QueryBuilder::table('users')->insert('users', ['name' => $maliciousColumnValue]);
check('malicious insert() value does not appear in SQL string', !str_contains($sql, 'DELETE FROM'));
check('malicious insert() value lands only in bindings', $bindings === [$maliciousColumnValue]);

// --- andWhere / orWhere combination ---------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')
    ->where('active', '=', 1)
    ->andWhere('age', '>=', 21)
    ->orWhere('role', '=', 'admin')
    ->toSql();
check(
    'andWhere/orWhere compile with correct boolean operators',
    $sql === 'SELECT * FROM `users` WHERE `active` = ? AND `age` >= ? OR `role` = ?'
);
check('andWhere/orWhere bindings in call order', $bindings === [1, 21, 'admin']);

// --- IN / NOT IN -----------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->where('id', 'IN', [1, 2, 3])->toSql();
check('IN operator expands to multiple placeholders', $sql === 'SELECT * FROM `users` WHERE `id` IN (?, ?, ?)');
check('IN operator bindings are the array values in order', $bindings === [1, 2, 3]);

[$sql, $bindings] = QueryBuilder::table('users')->where('id', 'NOT IN', [])->toSql();
check('empty NOT IN() matches everything (1=1), no bindings', $sql === 'SELECT * FROM `users` WHERE 1 = 1' && $bindings === []);

// --- NULL handling -----------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->where('deleted_at', '=', null)->toSql();
check('null value with = compiles to IS NULL with no binding', $sql === 'SELECT * FROM `users` WHERE `deleted_at` IS NULL' && $bindings === []);

[$sql, $bindings] = QueryBuilder::table('users')->where('deleted_at', '!=', null)->toSql();
check('null value with != compiles to IS NOT NULL with no binding', $sql === 'SELECT * FROM `users` WHERE `deleted_at` IS NOT NULL' && $bindings === []);

// --- JOIN --------------------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')
    ->select(['users.id', 'orders.total'])
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->toSql();
check(
    'join compiles with quoted dotted identifiers',
    $sql === 'SELECT `users`.`id`, `orders`.`total` FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`'
);

[$sql, $bindings] = QueryBuilder::table('users')->leftJoin('orders', 'users.id', '=', 'orders.user_id')->toSql();
check('leftJoin uses LEFT JOIN', str_contains($sql, 'LEFT JOIN `orders`'));

// --- ORDER BY / GROUP BY / HAVING / LIMIT / OFFSET ---------------------------------------

[$sql, $bindings] = QueryBuilder::table('orders')
    ->select(['user_id'])
    ->groupBy('user_id')
    ->having('user_id', '>', 0)
    ->orderBy('user_id', 'desc')
    ->limit(10)
    ->offset(20)
    ->toSql();

check(
    'group by / having / order by / limit / offset all compile together in order',
    $sql === 'SELECT `user_id` FROM `orders` GROUP BY `user_id` HAVING `user_id` > ? ORDER BY `user_id` DESC LIMIT ? OFFSET ?'
);
check('limit/offset/having bindings appended in clause order', $bindings === [0, 10, 20]);

// --- Full WHERE + JOIN + ORDER + LIMIT binding order end-to-end --------------------------

[$sql, $bindings] = QueryBuilder::table('users')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.active', '=', 1)
    ->andWhere('orders.total', '>', 100)
    ->orderBy('orders.total', 'DESC')
    ->limit(5)
    ->toSql();

check(
    'bindings appear in the exact order placeholders appear in the SQL',
    $bindings === [1, 100, 5]
);

// --- insert() ------------------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->insert('users', ['name' => 'Ada', 'email' => 'ada@example.com']);
check('insert compiles column list and placeholders', $sql === 'INSERT INTO `users` (`name`, `email`) VALUES (?, ?)');
check('insert bindings match data values in order', $bindings === ['Ada', 'ada@example.com']);

// --- upsert() --------------------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->upsert('users', ['id' => 1, 'email' => 'ada@example.com']);
check(
    'upsert compiles INSERT ... ON DUPLICATE KEY UPDATE, defaulting to every column',
    $sql === 'INSERT INTO `users` (`id`, `email`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `email` = VALUES(`email`)'
);
check('upsert bindings are the insert values only, in order', $bindings === [1, 'ada@example.com']);

[$sql, $bindings] = QueryBuilder::table('users')->upsert('users', ['id' => 1, 'email' => 'ada@example.com', 'hits' => 1], ['email']);
check(
    'upsert with explicit $updateColumns only refreshes those columns on conflict',
    $sql === 'INSERT INTO `users` (`id`, `email`, `hits`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `email` = VALUES(`email`)'
);
check('upsert bindings are unaffected by $updateColumns (still every inserted value)', $bindings === [1, 'ada@example.com', 1]);

$maliciousUpsertValue = "'; DROP TABLE users; --";
[$sql, $bindings] = QueryBuilder::table('users')->upsert('users', ['id' => 1, 'name' => $maliciousUpsertValue]);
check('malicious upsert() value does not appear in the SQL string', !str_contains($sql, 'DROP TABLE'));
check('malicious upsert() value lands only in bindings', $bindings === [1, $maliciousUpsertValue]);

$threw = false;
try {
    QueryBuilder::table('users')->upsert('users', []);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('upsert() throws InvalidArgumentException when given no data (same guard as insert())', $threw);

$threw = false;
try {
    QueryBuilder::table('users')->upsert('users', ['id' => 1], ['not_a_column']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('upsert() throws InvalidArgumentException when an update column is not in $data', $threw);

// --- update() ------------------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->update('users', ['name' => 'Grace'], ['id' => 7]);
check('update compiles SET and WHERE clauses', $sql === 'UPDATE `users` SET `name` = ? WHERE `id` = ?');
check('update bindings are [new values..., where values...]', $bindings === ['Grace', 7]);

[$sql, $bindings] = QueryBuilder::table('users')->update('users', ['name' => 'Grace'], []);
check('update with no where clause omits WHERE', $sql === 'UPDATE `users` SET `name` = ?');

// --- delete() ------------------------------------------------------------------------------

[$sql, $bindings] = QueryBuilder::table('users')->delete('users', ['id' => 7]);
check('delete compiles WHERE clause', $sql === 'DELETE FROM `users` WHERE `id` = ?');
check('delete bindings', $bindings === [7]);

$maliciousDeleteValue = "1 OR 1=1";
[$sql, $bindings] = QueryBuilder::table('users')->delete('users', ['name' => $maliciousDeleteValue]);
check('malicious delete() where value never appears in SQL string', !str_contains($sql, $maliciousDeleteValue));
check('malicious delete() where value lands only in bindings', $bindings === [$maliciousDeleteValue]);

// --- End-to-end execution against a real SQLite database --------------------------------

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// SQLite also accepts backtick-quoted identifiers (MySQL compatibility mode), so the
// exact same generated SQL can be executed directly against it.
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)');

[$sql, $bindings] = QueryBuilder::table('users')->insert('users', ['id' => 1, 'name' => 'Alice', 'active' => 1]);
$stmt = $pdo->prepare($sql);
$stmt->execute($bindings);

[$sql, $bindings] = QueryBuilder::table('users')->insert('users', ['id' => 2, 'name' => "'; DROP TABLE users; --", 'active' => 0]);
$stmt = $pdo->prepare($sql);
$stmt->execute($bindings);

$tableStillExists = (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
check('executing a query built with a malicious value does not drop the table (safe binding)', $tableStillExists);

$row = $pdo->query('SELECT COUNT(*) as c FROM users')->fetch(PDO::FETCH_ASSOC);
check('both rows were inserted safely', ((int) $row['c']) === 2);

[$sql, $bindings] = QueryBuilder::table('users')->select(['name'])->where('id', '=', 1)->toSql();
$stmt = $pdo->prepare($sql);
$stmt->execute($bindings);
$name = $stmt->fetchColumn();
check('generated SELECT executes correctly against real SQLite and returns expected row', $name === 'Alice');

[$sql, $bindings] = QueryBuilder::table('users')->update('users', ['active' => 0], ['id' => 1]);
$pdo->prepare($sql)->execute($bindings);
$active = $pdo->query('SELECT active FROM users WHERE id = 1')->fetchColumn();
check('generated UPDATE executes correctly against real SQLite', ((int) $active) === 0);

[$sql, $bindings] = QueryBuilder::table('users')->delete('users', ['id' => 2]);
$pdo->prepare($sql)->execute($bindings);
$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
check('generated DELETE executes correctly against real SQLite', $count === 1);

// --- quoteIdentifier(): raw expressions and dotted wildcards ------------------------------

[$sql, $bindings] = QueryBuilder::table('orders')->select(['COUNT(*) AS total'])->toSql();
check(
    'a non-identifier select expression (raw SQL) is passed through unmodified, not backtick-quoted',
    $sql === 'SELECT COUNT(*) AS total FROM `orders`'
);

[$sql, $bindings] = QueryBuilder::table('users')->select(['users.*'])->toSql();
check(
    'a dotted wildcard column (table.*) quotes the table but leaves * unquoted',
    $sql === 'SELECT `users`.* FROM `users`'
);

// --- groupBy() with multiple columns via variadic args ------------------------------------

[$sql, $bindings] = QueryBuilder::table('orders')->groupBy('user_id', 'status')->toSql();
check(
    'groupBy() accepts multiple columns and compiles all of them in order',
    $sql === 'SELECT * FROM `orders` GROUP BY `user_id`, `status`'
);

// --- orderBy() rejects an invalid direction -------------------------------------------------

$threw = false;
try {
    QueryBuilder::table('users')->orderBy('name', 'sideways');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('orderBy() throws InvalidArgumentException for a direction other than ASC/DESC', $threw);

// --- insert()/update() reject empty data ----------------------------------------------------

$threw = false;
try {
    QueryBuilder::table('users')->insert('users', []);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('insert() throws InvalidArgumentException when given no data', $threw);

$threw = false;
try {
    QueryBuilder::table('users')->update('users', [], ['id' => 1]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('update() throws InvalidArgumentException when given no data to set', $threw);

// --- IN operator accepts a scalar (non-array) value as a single-element list --------------

[$sql, $bindings] = QueryBuilder::table('users')->where('id', 'IN', 5)->toSql();
check(
    'IN with a scalar value is treated as a single-element list, not iterated char-by-char',
    $sql === 'SELECT * FROM `users` WHERE `id` IN (?)' && $bindings === [5]
);

echo $__failures === 0 ? "\nAll tests passed.\n" : "\n$__failures test(s) FAILED.\n";
exit($__failures === 0 ? 0 : 1);
