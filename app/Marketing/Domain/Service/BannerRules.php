<?php

namespace App\Marketing\Domain\Service;

/** Framework-free value rules for advanced banners (devices, enumerations, paddings, colours, YouTube IDs). */
final class BannerRules
{
    public const DEVICES = ['pc', 'tablet', 'phone'];

    public const VERTICAL = ['top', 'center', 'bottom'];

    public const HORIZONTAL = ['left', 'center', 'right'];

    /** How the banner's button reacts to the pointer. */
    public const BUTTON_HOVERS = ['none', 'darken', 'lighten', 'lift', 'grow', 'glow', 'invert'];

    /** Device-dependent layout fields with their allowed values (null = free-form, validated separately). */
    public const LAYOUT = [
        'valign' => self::VERTICAL, 'halign' => self::HORIZONTAL, 'paddings' => null, 'full_width' => 'bool',
        'title_size' => 'size', 'title_shadow' => 'bool', 'description_size' => 'size',
        'background_type' => ['image', 'video'], 'background_size' => ['cover', 'contain'], 'background_valign' => self::VERTICAL,
        'button_enabled' => 'bool', 'button_text_color' => 'color', 'button_color' => 'color',
        'mask_type' => ['none', 'semi', 'blur', 'gradient', 'color'], 'mask_position' => ['banner', 'content'],
        'mask_opacity' => 'percent', 'mask_direction' => ['auto', 'top', 'bottom', 'left', 'right'], 'mask_color' => 'color',
        'css_classes' => 'classes',
    ];

    /** PC values for a new banner. Colours stay null: "automatic from the colour scheme". */
    public const LAYOUT_DEFAULTS = [
        'valign' => 'center', 'halign' => 'center', 'paddings' => '10px 20px 20px 20px', 'full_width' => false,
        'title_size' => 22, 'title_shadow' => false, 'description_size' => 14,
        'background_type' => 'image', 'background_size' => 'cover', 'background_valign' => 'center',
        'button_enabled' => false, 'button_text_color' => null, 'button_color' => null,
        'mask_type' => 'none', 'mask_position' => 'banner', 'mask_opacity' => 50, 'mask_direction' => 'auto', 'mask_color' => null,
        'css_classes' => null,
    ];

    /** Fields the PC row must always carry. */
    public const LAYOUT_REQUIRED = ['valign', 'halign', 'paddings', 'full_width', 'title_size', 'title_shadow', 'description_size', 'background_type', 'background_size', 'background_valign', 'button_enabled', 'mask_type', 'mask_position', 'mask_opacity', 'mask_direction'];

    /** Device-independent settings with their allowed values. */
    public const SETTINGS = [
        'color_scheme' => ['light', 'dark'], 'object_type' => ['image', 'youtube', 'products'],
        'products_template' => ['grid', 'small', 'thumbnails'], 'object_valign' => self::VERTICAL, 'object_halign' => self::HORIZONTAL,
        'background_halign' => self::HORIZONTAL, 'title_weight' => ['thin', 'normal', 'bold', 'heavy'],
        'title_tag' => ['div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'button_style' => ['standard', 'outline', 'text'],
        'button_hover' => self::BUTTON_HOVERS,
    ];

    /** Ready-made CSS classes offered next to the free "CSS classes" field. */
    public const CSS_PRESETS = ['banner-button-bottom', 'banner-enlarge-image', 'banner-image-width', 'banner-image-edge', 'banner-background-fade', 'banner-mask-indent', 'banner-vertical-reverse'];

    /** "10px 20px" style shorthand: one to four values in px or %. Returns the normalised value or null when invalid. */
    public static function paddings(string $value): ?string
    {
        $parts = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || count($parts) > 4) {
            return null;
        }
        foreach ($parts as $i => $part) {
            if (preg_match('/^(\d{1,4})(px|%)?$/Di', $part, $m) !== 1) {
                return null;
            }
            $parts[$i] = ((int) $m[1]).(($m[2] ?? '') === '' ? 'px' : strtolower($m[2]));
        }

        return implode(' ', $parts);
    }

    /** #RRGGBB (or #RGB) normalised to lower-case six digits; null when invalid. */
    public static function color(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/D', $value, $m) === 1) {
            return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/D', $value) === 1 ? $value : null;
    }

    /** Space-separated CSS class names, de-duplicated; null when any name is invalid. */
    public static function classes(string $value): ?string
    {
        $names = array_values(array_unique(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z_-][A-Za-z0-9_-]{0,63}$/D', $name) !== 1) {
                return null;
            }
        }
        $joined = implode(' ', $names);

        return strlen($joined) <= 300 ? $joined : null;
    }

    /** Accepts a YouTube watch/short/embed URL or a bare 11-character video ID. */
    public static function youtube(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $value) === 1) {
            return $value;
        }
        $patterns = [
            '~^https?://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})(?:[&#].*)?$~D',
            '~^https?://(?:www\.)?youtube\.com/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})(?:[?#/].*)?$~D',
            '~^https?://youtu\.be/([A-Za-z0-9_-]{11})(?:[?#/].*)?$~D',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
