<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductPathGenerator implements PathGenerator
{
    /**
     * Organizes uploads as: {prefix}/{nome-da-loja}-{store_id}/produtos/{product_id}/
     */
    public function getPath(Media $media): string
    {
        $prefix = trim((string) config('media-library.prefix', ''), '/');
        $model = $media->model;
        $storeFolder = $model->store?->mediaFolderName() ?? 'sem-loja';

        $path = $storeFolder . '/produtos/' . $model->id . '/';

        return $prefix !== '' ? $prefix . '/' . $path : $path;
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media) . 'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media) . 'responsive-images/';
    }
}
