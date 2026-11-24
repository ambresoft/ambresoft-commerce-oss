<?php

namespace App\Order\Domain\Service;

use InvalidArgumentException;

/** Code 128 (set B) barcodes for printed order documents: printable ASCII only, drawn as an SVG. */
final class Code128
{
    /** Bar and space widths (in modules) of each symbol value 0–105, then the stop pattern. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    /** @return list<int> Alternating bar and space widths, starting with a bar (start, data, check digit, stop). */
    public static function widths(string $text): array
    {
        if ($text === '' || preg_match('/^[\x20-\x7e]+$/D', $text) !== 1) {
            throw new InvalidArgumentException('Code 128 set B encodes printable ASCII only.');
        }
        $values = array_map(fn (string $c): int => ord($c) - 32, str_split($text));
        $check = self::START_B;
        foreach ($values as $i => $value) {
            $check += $value * ($i + 1);
        }
        $symbols = [self::START_B, ...$values, $check % 103, 106];

        return array_map(intval(...), str_split(implode('', array_map(fn (int $s): string => self::PATTERNS[$s], $symbols))));
    }

    /** The barcode with a 10-module quiet zone on each side and the text underneath. */
    public static function svg(string $text, int $module = 2, int $height = 60): string
    {
        $x = 10 * $module;
        $bars = '';
        foreach (self::widths($text) as $i => $width) {
            if ($i % 2 === 0) {
                $bars .= '<rect x="'.$x.'" y="0" width="'.($width * $module).'" height="'.$height.'"/>';
            }
            $x += $width * $module;
        }
        $total = $x + 10 * $module;
        $label = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="'.$label.'" width="'.$total.'" height="'.($height + 22).'" viewBox="0 0 '.$total.' '.($height + 22).'">'
            .'<rect width="100%" height="100%" fill="#fff"/><g fill="#000">'.$bars.'</g>'
            .'<text x="'.($total / 2).'" y="'.($height + 18).'" text-anchor="middle" font-family="monospace" font-size="16" fill="#000">'.$label.'</text></svg>';
    }
}
