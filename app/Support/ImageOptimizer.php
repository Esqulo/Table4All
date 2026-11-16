<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shrinks product photos before they are stored.
 *
 * Phone photos are typically 2-5 MB. The customer menu lists every product
 * with its picture, often over the customer's mobile data, so serving the
 * originals made the menu the slowest screen in the app. Pictures are
 * displayed at most ~400 CSS px wide, so 800 px covers 2x screens.
 *
 * Needs the GD extension. Without it (or for formats GD can't read) the
 * original file is stored unchanged, so uploads never fail because of this.
 */
final class ImageOptimizer
{
    public const MAX_SIDE = 800;

    private const WEBP_QUALITY = 78;

    private const JPEG_QUALITY = 80;

    /**
     * Stores an uploaded picture on the public disk and returns its path.
     * `$maxSide` caps the longest side (venue photos are shown wider than products).
     */
    public static function storeUpload(UploadedFile $file, string $directory, int $maxSide = self::MAX_SIDE): string
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents !== false) {
            $optimized = self::optimize($contents, self::exifOrientation($file->getRealPath()), $maxSide);

            if ($optimized !== null) {
                $path = $directory.'/'.Str::random(40).'.'.$optimized['extension'];
                Storage::disk('public')->put($path, $optimized['data']);

                return $path;
            }
        }

        $path = $file->store($directory, 'public');

        if ($path === false) {
            throw new \RuntimeException('Could not store the uploaded picture.');
        }

        return $path;
    }

    /**
     * Re-encodes an already stored picture. Returns the new path, or null when
     * the file could not be improved (it is left untouched in that case).
     */
    public static function reprocessStored(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        if ($contents === null) {
            return null;
        }

        $optimized = self::optimize($contents, self::exifOrientation($disk->path($path)));

        if ($optimized === null || strlen($optimized['data']) >= strlen($contents)) {
            return null;
        }

        $newPath = dirname($path).'/'.Str::random(40).'.'.$optimized['extension'];
        $disk->put($newPath, $optimized['data']);

        return $newPath;
    }

    /** @return array{data: string, extension: string}|null */
    private static function optimize(string $contents, int $orientation, int $maxSide = self::MAX_SIDE): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            return null;
        }

        $source = self::applyOrientation($source, $orientation);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newW, $newH);

        if (! $target instanceof GdImage) {
            return null;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

        ob_start();
        $encoded = function_exists('imagewebp')
            ? imagewebp($target, null, self::WEBP_QUALITY)
            : imagejpeg($target, null, self::JPEG_QUALITY);
        $data = (string) ob_get_clean();

        if (! $encoded || $data === '') {
            return null;
        }

        return ['data' => $data, 'extension' => function_exists('imagewebp') ? 'webp' : 'jpg'];
    }

    private static function exifOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);

        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
    }

    private static function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
