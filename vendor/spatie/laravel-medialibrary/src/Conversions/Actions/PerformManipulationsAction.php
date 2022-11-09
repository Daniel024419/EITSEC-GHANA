<?php

namespace Spatie\MediaLibrary\Conversions\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\ImageFactory;

class PerformManipulationsAction
{
    public function execute(
        Media $media,
        Conversion $conversion,
        string $imageFile
    ): string {
        if ($conversion->getManipulations()->isEmpty()) {
            return $imageFile;
        }

        $conversionTempFile = $this->getConversionTempfilename($media, $conversion, $imageFile);

        File::copy($imageFile, $conversionTempFile);

        $supportedFormats = ['jpg', 'pjpg', 'png', 'gif'];
        if ($conversion->shouldKeepOriginalImageFormat() && in_array($media->extension, $supportedFormats)) {
            $conversion->format($media->extension);
        }

        ImageFactory::load($conversionTempFile)
            ->manipulate($conversion->getManipulations())
            ->save();

        return $conversionTempFile;
    }

    protected function getConversionTempfilename(
        Media $media,
        Conversion $conversion,
        string $imageFile
    ): string {
        $directory = pathinfo($imageFile, PATHINFO_DIRNAME);

        $filename = Str::random(32)."{$conversion->getName()}.{$media->extension}";

        return "{$directory}/{$filename}";
    }
}
