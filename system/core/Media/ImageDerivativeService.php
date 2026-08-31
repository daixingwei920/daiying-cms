<?php

declare(strict_types=1);

namespace Cms\Core\Media;

final class ImageDerivativeService
{
    /** @return array{source_path:string, cleanup_source:bool, privacy:array<string,mixed>, derivatives:array<string,array<string,mixed>>} */
    public function prepare(string $sourcePath, string $extension, int $width, int $height, string $temporaryRoot): array
    {
        $extension = strtolower($extension);
        $result = [
            'source_path' => $sourcePath,
            'cleanup_source' => false,
            'privacy' => [
                'processor' => 'none',
                'exif_stripped' => false,
                'reason' => '',
            ],
            'derivatives' => [],
        ];

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            $result['privacy']['reason'] = 'unsupported_image_format';
            return $result;
        }
        if (!extension_loaded('gd')) {
            $result['privacy']['reason'] = 'gd_unavailable';
            return $result;
        }

        $image = $this->load($sourcePath, $extension);
        if ($image === null) {
            $result['privacy']['reason'] = 'decode_failed';
            return $result;
        }

        $safeSource = $this->temporaryPath($temporaryRoot, $extension);
        if (!$this->write($image, $safeSource, $extension)) {
            $result['privacy']['reason'] = 'encode_failed';
            return $result;
        }

        $result['source_path'] = $safeSource;
        $result['cleanup_source'] = true;
        $result['privacy'] = [
            'processor' => 'gd',
            'exif_stripped' => true,
            'reason' => '',
        ];

        foreach (['thumbnail' => 320, 'small' => 960] as $name => $maxSide) {
            $derivative = $this->resize($image, $width, $height, $maxSide);
            if ($derivative === null) {
                continue;
            }
            $path = $this->temporaryPath($temporaryRoot, 'webp');
            if ($this->write($derivative, $path, 'webp')) {
                $size = @getimagesize($path);
                $result['derivatives'][$name] = [
                    'path' => $path,
                    'extension' => 'webp',
                    'mime_type' => 'image/webp',
                    'width' => is_array($size) ? (int) ($size[0] ?? 0) : 0,
                    'height' => is_array($size) ? (int) ($size[1] ?? 0) : 0,
                    'byte_size' => is_file($path) ? (int) filesize($path) : 0,
                ];
            }
        }

        return $result;
    }

    /** @return resource|\GdImage|null */
    private function load(string $path, string $extension): mixed
    {
        return match ($extension) {
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null,
            default => null,
        } ?: null;
    }

    private function resize(mixed $image, int $width, int $height, int $maxSide): mixed
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }
        $scale = min(1.0, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function write(mixed $image, string $path, string $extension): bool
    {
        return match ($extension) {
            'jpg', 'jpeg' => function_exists('imagejpeg') && imagejpeg($image, $path, 86),
            'png' => function_exists('imagepng') && imagepng($image, $path, 6),
            'webp' => function_exists('imagewebp') && imagewebp($image, $path, 82),
            'avif' => function_exists('imageavif') && imageavif($image, $path, 45),
            default => false,
        };
    }

    private function temporaryPath(string $temporaryRoot, string $extension): string
    {
        $directory = rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'media-derivatives';
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '.' . $extension;
    }
}
