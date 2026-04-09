<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class ImageUploadService
{
    /**
     * Compresses, resizes and uploads an image to R2.
     *
     * @param  int  $maxWidth  Max width in pixels (aspect ratio preserved).
     * @param  int  $quality   WebP quality 0-100.
     */
    public function upload(UploadedFile $file, string $folder, int $maxWidth = 800, int $quality = 75): string
    {
        $image = Image::read($file->getPathname());
        $image->scaleDown(width: $maxWidth);

        $webp = (string) $image->toWebp($quality);

        $path = $folder.'/'.Str::uuid().'.webp';
        Storage::disk('r2')->put($path, $webp, 'public');

        return Storage::disk('r2')->url($path);
    }

    /**
     * Deletes a file from R2 by its public URL.
     * Does nothing if the URL doesn't belong to the R2 bucket.
     */
    public function delete(string $url): void
    {
        $publicUrl = rtrim((string) config('filesystems.disks.r2.url', ''), '/');

        if ($publicUrl === '' || ! str_starts_with($url, $publicUrl)) {
            return;
        }

        $path = ltrim(substr($url, strlen($publicUrl)), '/');

        if ($path !== '') {
            Storage::disk('r2')->delete($path);
        }
    }
}
