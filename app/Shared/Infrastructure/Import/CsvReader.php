<?php

namespace App\Shared\Infrastructure\Import;

use Generator;
use RuntimeException;

final class CsvReader
{
    /** @return Generator<int, list<string>> Logical CSV record numbers, header = 1. */
    public function rows(string $file, string $delimiter, string $configuration, string $messages): Generator
    {
        if (! in_array($delimiter, config($configuration.'.delimiters'), true)) {
            throw new RuntimeException(__($messages.'.invalid_delimiter'));
        }
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException(__($messages.'.unavailable'));
        }
        try {
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $row = 0;
            while (! feof($stream)) {
                $start = ftell($stream);
                $values = fgetcsv($stream, 0, $delimiter, '"', '');
                if ($values === false) {
                    break;
                }
                $row++;
                $end = ftell($stream);
                $length = $end - $start;
                if ($length > config($configuration.'.max_record_bytes')) {
                    throw new RuntimeException(__($messages.'.record_large', ['row' => $row]));
                }
                fseek($stream, $start);
                $raw = fread($stream, $length);
                fseek($stream, $end);
                $d = preg_quote($delimiter, '~');
                $field = '(?:"(?:[^"]++|"")*+"|[^"'.$d.'\r\n]*+)';
                if (! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0") || preg_match('~\A'.$field.'(?:'.$d.$field.')*\r?\n?\z~D', $raw) !== 1) {
                    throw new RuntimeException(__($messages.'.malformed', ['row' => $row]));
                }
                if ($row > (int) config($configuration.'.max_rows') + 1) {
                    throw new RuntimeException(__($messages.'.too_many'));
                }
                yield $row => array_map(fn (?string $value): string => $value ?? '', $values);
            }
        } finally {
            fclose($stream);
        }
    }
}
