<?php

namespace Spatie\MediaLibrary\Conversions\Actions;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompleted;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStart;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;

class PerformConversionAction
{
    public function execute(
        Conversion $conversion,
        Media $media,
        string $copiedOriginalFile
    ) {
        event(new ConversionWillStart($media, $conversion, $copiedOriginalFile));

        $imageGenerator = ImageGeneratorFactory::forMedia($media);

        $copiedOriginalFile = $imageGenerator->convert($copiedOriginalFile, $conversion);

        $manipulationResult = (new PerformManipulationsAction())->execute($media, $conversion, $copiedOriginalFile);

        $newfilename = $conversion->getConversionFile($media);

        $renamedFile = $this->renameInLocalDirectory($manipulationResult, $newfilename);

        if ($conversion->shouldGenerateResponsiveImages()) {
            /** @var ResponsiveImageGenerator $responsiveImageGenerator */
            $responsiveImageGenerator = app(ResponsiveImageGenerator::class);

            $responsiveImageGenerator->generateResponsiveImagesForConversion(
                $media,
                $conversion,
                $renamedFile
            );
        }

        app(Filesystem::class)->copyToMediaLibrary($renamedFile, $media, 'conversions');

        $media->markAsConversionGenerated($conversion->getName());

        event(new ConversionHasBeenCompleted($media, $conversion));
    }

    protected function renameInLocalDirectory(
        string $filenameWithDirectory,
        string $newfilenameWithoutDirectory
    ): string {
        $targetFile = pathinfo($filenameWithDirectory, PATHINFO_DIRNAME).'/'.$newfilenameWithoutDirectory;

        rename($filenameWithDirectory, $targetFile);

        return $targetFile;
    }
}
