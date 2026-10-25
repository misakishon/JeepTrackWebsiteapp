<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use RuntimeException;

class TransportSql
{
    /** Prepare only the supplied schema; never execute its DROP/USE statements. */
    public function prepare(string $source): string
    {
        $source = preg_replace('/^\s*--[^\r\n]*/m', '', $source);
        $source = preg_replace('/\bCREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+transport_system\s*;/i', '', $source);
        $source = preg_replace('/\bUSE\s+transport_system\s*;/i', '', $source);
        $source = preg_replace('/\bDROP\s+TABLE\s+IF\s+EXISTS\s+\w+\s*;/i', '', $source);

        // Preserve seed account credentials, but store only bcrypt password hashes.
        $source = preg_replace_callback('/(INSERT\s+INTO\s+Users\s*\([^;]+?\)\s*VALUES)([^;]+);/is', function ($match) {
            $values = preg_replace_callback("/\\('((?:[^']|'')*)',\\s*'((?:[^']|'')*)',\\s*'((?:[^']|'')*)',\\s*'(Admin|Driver|Passenger)'\\)/", function ($row) {
                $password = str_replace("''", "'", $row[3]);
                $hash = password_get_info($password)['algo'] ? $password : Hash::make($password);

                return "('{$row[1]}', '{$row[2]}', '{$hash}', '{$row[4]}')";
            }, $match[2]);

            return $match[1].$values.';';
        }, $source);

        foreach ($this->statements($source) as $statement) {
            if (! preg_match('/^(CREATE\s+TABLE|INSERT\s+INTO)\s+/i', ltrim($statement))) {
                throw new RuntimeException('Import rejected: only CREATE TABLE and INSERT INTO statements are allowed.');
            }
        }

        return trim($source);
    }

    /** Split SQL without treating semicolons inside quoted values as separators. */
    public function statements(string $sql): array
    {
        $out = [];
        $buffer = '';
        $quote = null;
        for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $n) {
                    $buffer .= $sql[++$i];

                    continue;
                }
                if ($char === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) {
                        $buffer .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
            } elseif (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
            } elseif ($char === ';') {
                if (trim($buffer) !== '') {
                    $out[] = trim($buffer);
                } $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        if ($quote !== null) {
            throw new RuntimeException('Unterminated quote in SQL input.');
        }
        if (trim($buffer) !== '') {
            $out[] = trim($buffer);
        }

        return $out;
    }
}
