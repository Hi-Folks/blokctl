<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

/**
 * Read and write datasource entries as CSV.
 *
 * The header row needs `name` and `value`. Every other column is a dimension code,
 * for example `it` or `de`; an empty cell leaves that dimension value undeclared.
 *
 *   name,value,it,de
 *   red,Red,Rosso,Rot
 */
final class DatasourceEntriesCsv
{
    /**
     * @return list<array{name: string, value: string, dimensions?: array<string, string>}>
     *
     * @throws \RuntimeException if the file cannot be read or a required column or value is missing
     */
    public static function load(string $path): array
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file: ' . $path);
        }

        $headers = fgetcsv($handle, null, ',', '"', '');
        $headers = is_array($headers) ? array_map(static fn(mixed $header): string => trim((string) $header), $headers) : [];
        foreach (['name', 'value'] as $required) {
            if (!in_array($required, $headers, true)) {
                fclose($handle);
                throw new \RuntimeException(sprintf('CSV file %s must contain the column: %s', $path, $required));
            }
        }

        $entries = [];
        $line = 1;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }

            $values = array_combine($headers, array_pad(array_map(static fn(mixed $cell): string => (string) $cell, $row), count($headers), ''));
            $name = trim($values['name']);
            if ($name === '') {
                fclose($handle);
                throw new \RuntimeException(sprintf('Missing entry name at line %d of %s', $line, $path));
            }

            $entry = ['name' => $name, 'value' => $values['value']];
            $dimensions = [];
            foreach ($values as $column => $cell) {
                if (!in_array($column, ['name', 'value', ''], true) && $cell !== '') {
                    $dimensions[$column] = $cell;
                }
            }

            if ($dimensions !== []) {
                $entry['dimensions'] = $dimensions;
            }

            $entries[] = $entry;
        }

        fclose($handle);

        return $entries;
    }

    /**
     * Format rows as CSV with a header row. Same format that load() reads.
     *
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    public static function format(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create the CSV output.');
        }

        foreach ([$header, ...$rows] as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
