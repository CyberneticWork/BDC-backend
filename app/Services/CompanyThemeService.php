<?php

namespace App\Services;

use Illuminate\Support\Str;

class CompanyThemeService
{
    public function slugFromCode(string $code): string
    {
        $slug = Str::slug(strtolower($code), '-');
        return $slug !== '' ? $slug : 'company';
    }

    public function hostFromSlug(string $slug): string
    {
        $base = env('HR_TENANT_HOST_SUFFIX', 'hr.cyberneticde.site');
        $base = ltrim($base, '.');
        return strtolower($slug) . '.' . $base;
    }

    /**
     * Sample a logo file and return professional CSS-friendly colors.
     */
    public function themeFromLogoFile(?string $absolutePath): array
    {
        $fallback = [
            'primary' => '#0B4F5C',
            'secondary' => '#0D9488',
            'accent' => '#FF6B4A',
            'ink' => '#062A32',
            'surface' => '#F3FBF9',
        ];

        if (!$absolutePath || !is_file($absolutePath) || !function_exists('imagecreatefromstring')) {
            return $fallback;
        }

        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return $fallback;
        }
        $img = @imagecreatefromstring($raw);
        if (!$img) {
            return $fallback;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $buckets = [];
        $stepX = max(1, (int) floor($w / 40));
        $stepY = max(1, (int) floor($h / 40));

        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 255;
                $g = ($rgb >> 8) & 255;
                $b = $rgb & 255;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                if ($max > 245 && $min > 230) {
                    continue;
                }
                if ($max < 25) {
                    continue;
                }
                $key = sprintf('%02x%02x%02x', (int) round($r / 32) * 32, (int) round($g / 32) * 32, (int) round($b / 32) * 32);
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }
        imagedestroy($img);

        if (!$buckets) {
            return $fallback;
        }
        arsort($buckets);
        $hex = '#' . array_key_first($buckets);
        $primary = $this->normalizeHex($hex) ?: $fallback['primary'];
        $ink = $this->shade($primary, -0.35);
        $secondary = $this->shade($primary, 0.18);
        $accent = $this->complement($primary);

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'accent' => $accent,
            'ink' => $ink,
            'surface' => '#F7FAFC',
        ];
    }

    public function normalizeHex(?string $hex): ?string
    {
        $hex = strtoupper(trim((string) $hex));
        if (preg_match('/^#?[0-9A-F]{6}$/', $hex)) {
            return str_starts_with($hex, '#') ? $hex : '#' . $hex;
        }
        return null;
    }

    private function shade(string $hex, float $amount): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $r = (int) max(0, min(255, $r + (255 * $amount)));
        $g = (int) max(0, min(255, $g + (255 * $amount)));
        $b = (int) max(0, min(255, $b + (255 * $amount)));
        if ($amount < 0) {
            $r = (int) max(0, min(255, $r * (1 + $amount)));
            $g = (int) max(0, min(255, $g * (1 + $amount)));
            $b = (int) max(0, min(255, $b * (1 + $amount)));
        }
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    private function complement(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = 255 - hexdec(substr($hex, 0, 2));
        $g = 255 - hexdec(substr($hex, 2, 2));
        $b = 255 - hexdec(substr($hex, 4, 2));
        $r = (int) (($r + hexdec(substr($hex, 0, 2))) / 2);
        return sprintf('#%02X%02X%02X', max(40, $r), max(80, min(200, $g)), max(40, $b));
    }
}
