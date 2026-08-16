<?php
/**
 * Flexible $wpdb spy used by the Common Goals unit test suite.
 *
 * Records every SQL call and returns configurable, queueable values per method
 * so tests can simulate success, missing rows and database failures without a
 * live MySQL instance.
 *
 * @package CommonGoals\Tests\Unit\Support
 */

namespace CommonGoals\Tests\Unit\Support;

/**
 * Captures SQL and returns scripted values for each wpdb method.
 *
 * Each getter/mutator has a matching {@see queue_*()} method that pushes a
 * return value onto a FIFO queue. When the wpdb method is called it shifts the
 * next queued value; if the queue is empty a safe default is returned.
 */
final class WpdbSpy
{
    public string $prefix = 'wp_';
    public int $insert_id = 41;
    public int $rows_affected = 1;
    public string $last_error = '';

    /** @var array<int, array{method:string, sql:mixed, extra:mixed}> */
    public array $calls = [];

    /** @var array<string, array<int, mixed>> */
    private array $queues = [
        'get_var'    => [],
        'get_row'    => [],
        'get_results' => [],
        'get_col'    => [],
        'query'      => [],
        'insert'     => [],
        'update'     => [],
        'delete'     => [],
        'replace'    => [],
    ];

    /** @var array<string, mixed> */
    private array $defaults = [
        'get_var'    => null,
        'get_row'    => null,
        'get_results' => [],
        'get_col'    => [],
        'query'      => true,
        'insert'     => true,
        'update'     => 1,
        'delete'     => 1,
        'replace'    => 1,
    ];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    /**
     * Minimal wpdb::esc_like emulation for LIKE assertions.
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * Minimal wpdb::prepare emulation that substitutes placeholders so SQL
     * assertions can inspect the actual values that would be sent to MySQL.
     *
     * Supports %s, %d and %f in order. Values are escaped for %s.
     *
     * @param mixed ...$args Prepared parameters.
     */
    public function prepare(string $query, ...$args): string
    {
        if ($args === []) {
            return $query;
        }

        $offset = 0;
        $result = '';
        $arg_index = 0;
        while (preg_match('/%([sdf])/', $query, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $m[0][1];
            $result .= substr($query, $offset, $pos - $offset);
            $value = $args[$arg_index] ?? '';
            $result .= match ($m[1][0]) {
                'd' => (string) (int) $value,
                'f' => (string) (float) $value,
                default => "'" . $this->escape_string((string) $value) . "'",
            };
            $offset = $pos + 2;
            $arg_index++;
        }
        $result .= substr($query, $offset);

        return $result;
    }

    private function escape_string(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    public function get_var($sql = null)
    {
        $this->calls[] = ['method' => 'get_var', 'sql' => $sql, 'extra' => null];
        return array_shift($this->queues['get_var']) ?? $this->defaults['get_var'];
    }

    public function get_row($sql = null, $output = 'OBJECT')
    {
        $this->calls[] = ['method' => 'get_row', 'sql' => $sql, 'extra' => $output];
        return array_shift($this->queues['get_row']) ?? $this->defaults['get_row'];
    }

    public function get_results($sql = null, $output = 'OBJECT')
    {
        $this->calls[] = ['method' => 'get_results', 'sql' => $sql, 'extra' => $output];
        return array_shift($this->queues['get_results']) ?? $this->defaults['get_results'];
    }

    public function get_col($sql = null)
    {
        $this->calls[] = ['method' => 'get_col', 'sql' => $sql, 'extra' => null];
        return array_shift($this->queues['get_col']) ?? $this->defaults['get_col'];
    }

    public function query($sql = null): bool
    {
        $this->calls[] = ['method' => 'query', 'sql' => $sql, 'extra' => null];
        $value = array_shift($this->queues['query']) ?? $this->defaults['query'];
        return (bool) $value;
    }

    public function insert(string $table, array $data, $format = null)
    {
        $this->calls[] = ['method' => 'insert', 'sql' => $table, 'extra' => ['data' => $data, 'format' => $format]];
        $value = array_shift($this->queues['insert']) ?? $this->defaults['insert'];
        return $value === false ? false : (int) $this->rows_affected;
    }

    public function update(string $table, array $data, array $where, $format = null, $where_format = null)
    {
        $this->calls[] = ['method' => 'update', 'sql' => $table, 'extra' => ['data' => $data, 'where' => $where]];
        $value = array_shift($this->queues['update']) ?? $this->defaults['update'];
        return $value === false ? false : (int) $value;
    }

    public function delete(string $table, array $where, $where_format = null)
    {
        $this->calls[] = ['method' => 'delete', 'sql' => $table, 'extra' => ['where' => $where]];
        $value = array_shift($this->queues['delete']) ?? $this->defaults['delete'];
        return $value === false ? false : (int) $value;
    }

    public function replace(string $table, array $data, $format = null)
    {
        $this->calls[] = ['method' => 'replace', 'sql' => $table, 'extra' => ['data' => $data, 'format' => $format]];
        $value = array_shift($this->queues['replace']) ?? $this->defaults['replace'];
        return $value === false ? false : (int) $value;
    }

    public function queue_get_var($value): void
    {
        $this->queues['get_var'][] = $value;
    }

    public function queue_get_row($value): void
    {
        $this->queues['get_row'][] = $value;
    }

    public function queue_get_results($value): void
    {
        $this->queues['get_results'][] = $value;
    }

    public function queue_get_col($value): void
    {
        $this->queues['get_col'][] = $value;
    }

    public function queue_query($value): void
    {
        $this->queues['query'][] = $value;
    }

    public function queue_insert($value): void
    {
        $this->queues['insert'][] = $value;
    }

    public function queue_update($value): void
    {
        $this->queues['update'][] = $value;
    }

    public function queue_delete($value): void
    {
        $this->queues['delete'][] = $value;
    }

    public function queue_replace($value): void
    {
        $this->queues['replace'][] = $value;
    }

    /**
     * Returns every recorded SQL string for assertions.
     *
     * @return array<int, string>
     */
    public function sql_strings(): array
    {
        $sqls = [];
        foreach ($this->calls as $call) {
            if (is_string($call['sql'])) {
                $sqls[] = $call['sql'];
            }
        }
        return $sqls;
    }

    public function count_method(string $method): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                $count++;
            }
        }
        return $count;
    }
}
