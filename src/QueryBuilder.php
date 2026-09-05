<?php

declare(strict_types=1);

namespace Kasapdev\QueryBuilder;

use InvalidArgumentException;

/**
 * A fluent SQL query builder for MySQL-style syntax (backtick-quoted
 * identifiers). Every value supplied through where()/having()/insert()/
 * update() is emitted exclusively as a `?` placeholder binding — values are
 * NEVER interpolated into the generated SQL string.
 */
final class QueryBuilder
{
    private string $table;

    /** @var string[] */
    private array $columns = ['*'];

    /** @var array<int, array{boolean:string, column:string, operator:string, value:mixed}> */
    private array $wheres = [];

    /** @var array<int, array{type:string, table:string, first:string, operator:string, second:string}> */
    private array $joins = [];

    /** @var array<int, array{column:string, direction:string}> */
    private array $orders = [];

    /** @var string[] */
    private array $groups = [];

    /** @var array<int, array{boolean:string, column:string, operator:string, value:mixed}> */
    private array $havings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private function __construct(string $table)
    {
        $this->table = $table;
    }

    public static function table(string $table): self
    {
        return new self($table);
    }

    public function select(array $columns): self
    {
        $this->columns = $columns === [] ? ['*'] : $columns;

        return $this;
    }

    /**
     * Add a WHERE condition. Combined with previous conditions using AND
     * (the same as calling andWhere()), unless it's the first condition.
     *
     * Two-argument form `where($column, $value)` is shorthand for
     * `where($column, '=', $value)`.
     */
    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition($operator, $value, func_num_args());

        return $this->addWhere('AND', $column, $op, $val);
    }

    public function andWhere(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition($operator, $value, func_num_args());

        return $this->addWhere('AND', $column, $op, $val);
    }

    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition($operator, $value, func_num_args());

        return $this->addWhere('OR', $column, $op, $val);
    }

    public function having(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition($operator, $value, func_num_args());
        $this->havings[] = ['boolean' => 'AND', 'column' => $column, 'operator' => $op, 'value' => $val];

        return $this;
    }

    public function orHaving(string $column, mixed $operator, mixed $value = null): self
    {
        [$op, $val] = $this->normalizeCondition($operator, $value, func_num_args());
        $this->havings[] = ['boolean' => 'OR', 'column' => $column, 'operator' => $op, 'value' => $val];

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid order direction: {$direction}");
        }

        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * Compile the built SELECT query.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function toSql(): array
    {
        $bindings = [];

        $sql = 'SELECT ' . $this->compileColumns() . ' FROM ' . $this->quoteIdentifier($this->table);

        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $this->quoteIdentifier($join['table']),
                $this->quoteIdentifier($join['first']),
                $join['operator'],
                $this->quoteIdentifier($join['second'])
            );
        }

        if ($this->wheres !== []) {
            [$clause, $whereBindings] = $this->compileConditions($this->wheres);
            $sql .= ' WHERE ' . $clause;
            array_push($bindings, ...$whereBindings);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map($this->quoteIdentifier(...), $this->groups));
        }

        if ($this->havings !== []) {
            [$clause, $havingBindings] = $this->compileConditions($this->havings);
            $sql .= ' HAVING ' . $clause;
            array_push($bindings, ...$havingBindings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn (array $o): string => $this->quoteIdentifier($o['column']) . ' ' . $o['direction'],
                $this->orders
            ));
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ?';
            $bindings[] = $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ?';
            $bindings[] = $this->offset;
        }

        return [$sql, $bindings];
    }

    /**
     * Compile a standalone INSERT statement.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function insert(string $table, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot build INSERT with no data.');
        }

        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            $placeholders
        );

        return [$sql, array_values($data)];
    }

    /**
     * Compile a standalone UPDATE statement.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function update(string $table, array $data, array $where): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot build UPDATE with no data.');
        }

        $bindings = [];
        $setParts = [];
        foreach ($data as $column => $value) {
            $setParts[] = $this->quoteIdentifier((string) $column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf('UPDATE %s SET %s', $this->quoteIdentifier($table), implode(', ', $setParts));

        if ($where !== []) {
            [$clause, $whereBindings] = $this->compileEqualityWhere($where);
            $sql .= ' WHERE ' . $clause;
            array_push($bindings, ...$whereBindings);
        }

        return [$sql, $bindings];
    }

    /**
     * Compile a standalone DELETE statement.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function delete(string $table, array $where): array
    {
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($table);
        $bindings = [];

        if ($where !== []) {
            [$clause, $whereBindings] = $this->compileEqualityWhere($where);
            $sql .= ' WHERE ' . $clause;
            $bindings = $whereBindings;
        }

        return [$sql, $bindings];
    }

    /**
     * Simple `column = ?` equality where builder used by insert()/update()/delete(),
     * joined with AND. Values still only ever appear as bindings.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function compileEqualityWhere(array $where): array
    {
        $parts = [];
        $bindings = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $parts[] = $this->quoteIdentifier((string) $column) . ' IS NULL';
                continue;
            }
            $parts[] = $this->quoteIdentifier((string) $column) . ' = ?';
            $bindings[] = $value;
        }

        return [implode(' AND ', $parts), $bindings];
    }

    /**
     * @param array<int, array{boolean:string, column:string, operator:string, value:mixed}> $conditions
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function compileConditions(array $conditions): array
    {
        $sql = '';
        $bindings = [];

        foreach ($conditions as $i => $condition) {
            $prefix = $i === 0 ? '' : ' ' . $condition['boolean'] . ' ';
            $column = $this->quoteIdentifier($condition['column']);
            $operator = strtoupper($condition['operator']);
            $value = $condition['value'];

            if ($value === null && in_array($operator, ['=', '<>', '!='], true)) {
                $sql .= $prefix . $column . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }

            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                $values = is_array($value) ? array_values($value) : [$value];
                if ($values === []) {
                    // An empty IN() matches nothing; NOT IN() matches everything.
                    $sql .= $prefix . ($operator === 'IN' ? '1 = 0' : '1 = 1');
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $sql .= $prefix . $column . ' ' . $operator . ' (' . $placeholders . ')';
                array_push($bindings, ...$values);
                continue;
            }

            $sql .= $prefix . $column . ' ' . $condition['operator'] . ' ?';
            $bindings[] = $value;
        }

        return [$sql, $bindings];
    }

    private function addWhere(string $boolean, string $column, string $operator, mixed $value): self
    {
        $this->wheres[] = ['boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    /**
     * Normalize the (operator, value) pair, supporting the two-argument
     * shorthand where($column, $value) meaning where($column, '=', $value).
     *
     * @return array{0: string, 1: mixed}
     */
    private function normalizeCondition(mixed $operator, mixed $value, int $argCount): array
    {
        if ($argCount <= 2) {
            return ['=', $operator];
        }

        return [(string) $operator, $value];
    }

    private function compileColumns(): string
    {
        return implode(', ', array_map($this->quoteIdentifier(...), $this->columns));
    }

    /**
     * Quote a (possibly dotted, possibly aliased-as-is) identifier with
     * backticks. Expressions that aren't simple identifiers (contain
     * parentheses, spaces, etc.) are passed through unmodified so callers
     * can supply raw expressions like "COUNT(*) AS total" or "*".
     */
    private function quoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '*') {
            return '*';
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_*][a-zA-Z0-9_]*)?$/', $identifier)) {
            // Not a simple (optionally dotted) identifier — treat as a raw expression.
            return $identifier;
        }

        return implode('.', array_map(
            static fn (string $part): string => $part === '*' ? '*' : '`' . $part . '`',
            explode('.', $identifier)
        ));
    }
}
