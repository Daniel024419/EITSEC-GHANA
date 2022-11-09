<?php

namespace Spatie\MediaLibrary\Support\filenamer;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

abstract class filenamer
{
    public function originalfilename(string $filename): string
    {
        $extLength = strlen(pathinfo($filename, PATHINFO_EXTENSION));

        $baseName = substr($filename, 0, strlen($filename) - ($extLength ? $extLength + 1 : 0));

        return $baseName;
    }

    abstract public function conversionfilename(string $filename, Conversion $conversion): string;

    abstract public function responsivefilename(string $filename): string;

    public function temporaryfilename(Media $media, string $extension): string
    {
        return "{$this->responsivefilename($media->file_name)}.{$extension}";
    }

    public function extensionFromBaseImage(string $baseImage): string
    {
        return pathinfo($baseImage, PATHINFO_EXTENSION);
    }
}
