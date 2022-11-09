<?php

namespace Spatie\MediaLibrary\MediaCollections;

use Exception;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAdded;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\File;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Spatie\MediaLibrary\Support\RemoteFile;

class Filesystem
{
    protected array $customRemoteHeaders = [];

    public function __construct(
        protected Factory $filesystem
    ) {
    }

    public function add(string $file, Media $media, ?string $targetfilename = null): void
    {
        $this->copyToMediaLibrary($file, $media, null, $targetfilename);

        event(new MediaHasBeenAdded($media));

        app(FileManipulator::class)->createDerivedFiles($media);
    }

    public function addRemote(RemoteFile $file, Media $media, ?string $targetfilename = null): void
    {
        $this->copyToMediaLibraryFromRemote($file, $media, null, $targetfilename);

        event(new MediaHasBeenAdded($media));

        app(FileManipulator::class)->createDerivedFiles($media);
    }

    public function copyToMediaLibraryFromRemote(RemoteFile $file, Media $media, ?string $type = null, ?string $targetfilename = null): void
    {
        $destinationfilename = $targetfilename ?: $file->getfilename();

        $destination = $this->getMediaDirectory($media, $type).$destinationfilename;

        $diskDriverName = (in_array($type, ['conversions', 'responsiveImages']))
            ? $media->getConversionsDiskDriverName()
            : $media->getDiskDriverName();

        if ($this->shouldCopyFileOnDisk($file, $media, $diskDriverName)) {
            $this->copyFileOnDisk($file->getKey(), $destination, $media->disk);

            return;
        }

        $storage = Storage::disk($file->getDisk());

        $headers = $diskDriverName === 'local'
            ? []
            : $this->getRemoteHeadersForFile(
                $file->getKey(),
                $media->getCustomHeaders(),
                $storage->mimeType($file->getKey())
            );

        $this->streamFileToDisk(
            $storage->getDriver()->readStream($file->getKey()),
            $destination,
            $media->disk,
            $headers
        );
    }

    protected function shouldCopyFileOnDisk(RemoteFile $file, Media $media, string $diskDriverName): bool
    {
        if ($file->getDisk() !== $media->disk) {
            return false;
        }

        if ($diskDriverName === 'local') {
            return true;
        }

        if (count($media->getCustomHeaders()) > 0) {
            return false;
        }

        if ((is_countable(config('media-library.remote.extra_headers')) ? count(config('media-library.remote.extra_headers')) : 0) > 0) {
            return false;
        }

        return true;
    }

    protected function copyFileOnDisk(string $file, string $destination, string $disk): void
    {
        $this->filesystem->disk($disk)
            ->copy($file, $destination);
    }

    protected function streamFileToDisk($stream, string $destination, string $disk, array $headers): void
    {
        $this->filesystem->disk($disk)
            ->getDriver()->writeStream(
                $destination,
                $stream,
                $headers
            );
    }

    public function copyToMediaLibrary(string $pathToFile, Media $media, ?string $type = null, ?string $targetfilename = null)
    {
        $destinationfilename = $targetfilename ?: pathinfo($pathToFile, PATHINFO_BASENAME);

        $destination = $this->getMediaDirectory($media, $type).$destinationfilename;

        $file = fopen($pathToFile, 'r');

        $diskName = (in_array($type, ['conversions', 'responsiveImages']))
            ? $media->conversions_disk
            : $media->disk;

        $diskDriverName = (in_array($type, ['conversions', 'responsiveImages']))
            ? $media->getConversionsDiskDriverName()
            : $media->getDiskDriverName();

        if ($diskDriverName === 'local') {
            $this->filesystem
                ->disk($diskName)
                ->put($destination, $file);

            fclose($file);

            return;
        }

        $this->filesystem
            ->disk($diskName)
            ->put(
                $destination,
                $file,
                $this->getRemoteHeadersForFile($pathToFile, $media->getCustomHeaders()),
            );

        if (is_resource($file)) {
            fclose($file);
        }
    }

    public function addCustomRemoteHeaders(array $customRemoteHeaders): void
    {
        $this->customRemoteHeaders = $customRemoteHeaders;
    }

    public function getRemoteHeadersForFile(
        string $file,
        array $mediaCustomHeaders = [],
        string $mimeType = null
    ): array {
        $mimeTypeHeader = ['ContentType' => $mimeType ?: File::getMimeType($file)];

        $extraHeaders = config('media-library.remote.extra_headers');

        return array_merge(
            $mimeTypeHeader,
            $extraHeaders,
            $this->customRemoteHeaders,
            $mediaCustomHeaders
        );
    }

    public function getStream(Media $media)
    {
        $sourceFile = $this->getMediaDirectory($media).'/'.$media->file_name;

        return $this->filesystem->disk($media->disk)->readStream($sourceFile);
    }

    public function copyFromMediaLibrary(Media $media, string $targetFile): string
    {
        file_put_contents($targetFile, $this->getStream($media));

        return $targetFile;
    }

    public function removeAllFiles(Media $media): void
    {
        $mediaDirectory = $this->getMediaDirectory($media);

        if ($media->disk !== $media->conversions_disk) {
            $this->filesystem->disk($media->disk)->deleteDirectory($mediaDirectory);
        }

        $conversionsDirectory = $this->getMediaDirectory($media, 'conversions');

        $responsiveImagesDirectory = $this->getMediaDirectory($media, 'responsiveImages');

        collect([$mediaDirectory, $conversionsDirectory, $responsiveImagesDirectory])
            ->each(function (string $directory) use ($media) {
                try {
                    $this->filesystem->disk($media->conversions_disk)->deleteDirectory($directory);
                } catch (Exception $exception) {
                    report($exception);
                }
            });
    }

    public function removeFile(Media $media, string $path): void
    {
        $this->filesystem->disk($media->disk)->delete($path);
    }

    public function removeResponsiveImages(Media $media, string $conversionName = 'media_library_original'): void
    {
        $responsiveImagesDirectory = $this->getResponsiveImagesDirectory($media);

        $allFilePaths = $this->filesystem->disk($media->disk)->allFiles($responsiveImagesDirectory);

        $responsiveImagePaths = array_filter(
            $allFilePaths,
            fn (string $path) => Str::contains($path, $conversionName)
        );

        $this->filesystem->disk($media->disk)->delete($responsiveImagePaths);
    }

    public function syncfilenames(Media $media): void
    {
        $this->renameMediaFile($media);

        $this->renameConversionFiles($media);
    }

    public function syncMediaPath(Media $media): void
    {
        $factory = PathGeneratorFactory::create($media);

        $oldMedia = (clone $media)->fill($media->getOriginal());

        if ($oldMedia->getPath() === $media->getPath()) {
            return;
        }

        $this->filesystem->disk($media->disk)
            ->move($factory->getPath($oldMedia), $factory->getPath($media));
    }

    protected function renameMediaFile(Media $media): void
    {
        $newfilename = $media->file_name;
        $oldfilename = $media->getOriginal('file_name');

        $mediaDirectory = $this->getMediaDirectory($media);

        $oldFile = "{$mediaDirectory}/{$oldfilename}";
        $newFile = "{$mediaDirectory}/{$newfilename}";

        $this->filesystem->disk($media->disk)->move($oldFile, $newFile);
    }

    protected function renameConversionFiles(Media $media): void
    {
        $mediaWithOldfilename = config('media-library.media_model')::find($media->id);
        $mediaWithOldfilename->file_name = $mediaWithOldfilename->getOriginal('file_name');

        $conversionDirectory = $this->getConversionDirectory($media);

        $conversionCollection = ConversionCollection::createForMedia($media);

        foreach ($media->getMediaConversionNames() as $conversionName) {
            $conversion = $conversionCollection->getByName($conversionName);

            $oldFile = $conversionDirectory.$conversion->getConversionFile($mediaWithOldfilename);
            $newFile = $conversionDirectory.$conversion->getConversionFile($media);

            $disk = $this->filesystem->disk($media->conversions_disk);

            // A media conversion file might be missing, waiting to be generated, failed etc.
            if (! $disk->exists($oldFile)) {
                continue;
            }

            $disk->move($oldFile, $newFile);
        }
    }

    public function getMediaDirectory(Media $media, ?string $type = null): string
    {
        $directory = null;
        $pathGenerator = PathGeneratorFactory::create($media);

        if (! $type) {
            $directory = $pathGenerator->getPath($media);
        }

        if ($type === 'conversions') {
            $directory = $pathGenerator->getPathForConversions($media);
        }

        if ($type === 'responsiveImages') {
            $directory = $pathGenerator->getPathForResponsiveImages($media);
        }

        $diskDriverName = in_array($type, ['conversions', 'responsiveImages'])
            ? $media->getConversionsDiskDriverName()
            : $media->getDiskDriverName();

        $diskName = in_array($type, ['conversions', 'responsiveImages'])
            ? $media->conversions_disk
            : $media->disk;

        if (! in_array($diskDriverName, ['s3'], true)) {
            $this->filesystem->disk($diskName)->makeDirectory($directory);
        }

        return $directory;
    }

    public function getConversionDirectory(Media $media): string
    {
        return $this->getMediaDirectory($media, 'conversions');
    }

    public function getResponsiveImagesDirectory(Media $media): string
    {
        return $this->getMediaDirectory($media, 'responsiveImages');
    }
}
