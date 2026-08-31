<?php

declare(strict_types=1);

namespace Cms\Core\ExternalMigration;

final class SqlDumpInsertParser
{
    /** @var list<string> */
    private array $allowedTables;

    /** @param list<string> $allowedTables */
    public function __construct(array $allowedTables)
    {
        $this->allowedTables = array_values(array_unique(array_map(static fn (string $v): string => strtolower($v), $allowedTables)));
    }

    /** @return array<string,list<array<string,string>>> */
    public function parse(string $payload): array
    {
        $this->assertSqlIsSafe($payload);
        $tables = [];
        foreach ($this->insertStatements($payload) as $statement) {
            if (preg_match('/^INSERT\s+INTO\s+`?([^`\s(]+)`?\s*\(([^)]*)\)\s*VALUES\s*(.*)$/is', $statement, $match) !== 1) {
                continue;
            }
            $table = $this->canonicalTable((string) $match[1]);
            if ($table === '') {
                continue;
            }
            $columns = array_map(static fn (string $v): string => trim($v, " `\t\r\n"), explode(',', (string) $match[2]));
            foreach ($this->parseValueRows((string) $match[3]) as $values) {
                if (count($columns) !== count($values)) {
                    continue;
                }
                $tables[$table][] = array_combine($columns, $values);
            }
        }

        return $tables;
    }

    /** @return list<string> */
    private function insertStatements(string $payload): array
    {
        $statements = [];
        $offset = 0;
        $length = strlen($payload);
        while (($start = stripos($payload, 'INSERT', $offset)) !== false) {
            $inString = false;
            $escape = false;
            for ($i = $start; $i < $length; $i++) {
                $char = $payload[$i];
                if ($inString) {
                    if ($escape) {
                        $escape = false;
                        continue;
                    }
                    if ($char === '\\') {
                        $escape = true;
                        continue;
                    }
                    if ($char === "'") {
                        $inString = false;
                    }
                    continue;
                }
                if ($char === "'") {
                    $inString = true;
                    continue;
                }
                if ($char === ';') {
                    $statements[] = trim(substr($payload, $start, $i - $start));
                    $offset = $i + 1;
                    continue 2;
                }
            }
            break;
        }

        return $statements;
    }

    public function assertSqlIsSafe(string $payload): void
    {
        if (strlen($payload) > 52428800) {
            throw new MigrationException('SQL 文件超过迁移大小限制。');
        }
        if (preg_match('/\b(DROP|ALTER|TRUNCATE|CREATE\s+TRIGGER|CREATE\s+PROCEDURE|CREATE\s+FUNCTION|GRANT|REVOKE|LOAD_FILE|INTO\s+OUTFILE)\b/i', $payload) === 1) {
            throw new MigrationException('SQL 文件包含危险语句，已拒绝迁移。');
        }
    }

    private function canonicalTable(string $table): string
    {
        $table = strtolower(trim($table, '`'));
        foreach ($this->allowedTables as $allowed) {
            if ($table === $allowed || str_ends_with($table, '_' . $allowed)) {
                return $allowed;
            }
        }

        return '';
    }

    /** @return list<list<string>> */
    private function parseValueRows(string $valuesSql): array
    {
        $rows = [];
        $length = strlen($valuesSql);
        $row = [];
        $value = '';
        $inString = false;
        $escape = false;
        $depth = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $valuesSql[$i];
            if ($inString) {
                if ($escape) {
                    $value .= match ($char) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $char,
                    };
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === "'") {
                    $inString = false;
                    continue;
                }
                $value .= $char;
                continue;
            }
            if ($char === "'") {
                $inString = true;
                continue;
            }
            if ($char === '(') {
                $depth++;
                if ($depth === 1) {
                    $row = [];
                    $value = '';
                    continue;
                }
            }
            if ($char === ')' && $depth === 1) {
                $row[] = $this->sqlValue($value);
                $rows[] = $row;
                $row = [];
                $value = '';
                $depth = 0;
                continue;
            }
            if ($char === ',' && $depth === 1) {
                $row[] = $this->sqlValue($value);
                $value = '';
                continue;
            }
            if ($depth >= 1) {
                $value .= $char;
            }
        }

        return $rows;
    }

    private function sqlValue(string $value): string
    {
        $value = trim($value);
        return strcasecmp($value, 'NULL') === 0 ? '' : $value;
    }
}
