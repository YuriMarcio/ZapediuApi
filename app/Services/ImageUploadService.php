<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class ImageUploadService
{
    /**
     * Uses R2 when it's actually configured; falls back to the local "public"
     * disk otherwise (e.g. local dev environments without R2 credentials).
     */
    private function disk(): string
    {
        return empty(config('filesystems.disks.r2.bucket')) ? 'public' : 'r2';
    }

    /**
     * Same root prefix used by the Spatie Media Library uploads (product photos),
     * so every asset lands under one organized root in the bucket.
     */
    private function rootPrefix(): string
    {
        $prefix = trim((string) config('media-library.prefix', ''), '/');

        return $prefix !== '' ? $prefix.'/' : '';
    }

    /**
     * Compresses, resizes and uploads an image, returning its public URL.
     *
     * @param  string  $folder    Relative folder, e.g. "{store_id}/perfil/logo".
     * @param  int  $maxWidth  Max width in pixels (aspect ratio preserved).
     * @param  int  $quality   WebP quality 0-100.
     */
    public function upload(UploadedFile $file, string $folder, int $maxWidth = 800, int $quality = 75): string
    {
        $image = Image::read($file->getPathname());
        $image->scaleDown(width: $maxWidth);

        $webp = (string) $image->toWebp($quality);

        $path = $this->rootPrefix().trim($folder, '/').'/'.Str::uuid().'.webp';
        $disk = $this->disk();
        Storage::disk($disk)->put($path, $webp, 'public');

        return Storage::disk($disk)->url($path);
    }

    /**
     * Deletes a previously uploaded file by its public URL.
     * Does nothing if the URL doesn't belong to the configured disk.
     */
    public function delete(string $url): void
    {
        $disk = $this->disk();
        $publicUrl = rtrim((string) config("filesystems.disks.{$disk}.url", ''), '/');

        if ($publicUrl === '' || ! str_starts_with($url, $publicUrl)) {
            return;
        }

        $path = ltrim(substr($url, strlen($publicUrl)), '/');

        if ($path !== '') {
            Storage::disk($disk)->delete($path);
        }
    }
}
