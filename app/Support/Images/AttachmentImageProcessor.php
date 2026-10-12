<?php

namespace App\Support\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Prepares an image attached to an issue for storage: checks that it really is an image,
 * turns it the right way up, scales it down if it is very large, and saves it as WebP.
 *
 * Laravel 13 has this built in (Illuminate\Support\Facades\Image, powered by the same
 * Intervention Image package used here). Once the app is on Laravel 13, process() becomes:
 *
 *     Image::fromUpload($file)->orient()->scale($max, $max)->toWebp()->quality($quality)
 */
class AttachmentImageProcessor
{
    /** What getimagesize() reports for the formats that may be uploaded */
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Whether the upload should go through image processing at all. PDFs and other documents do not.
     */
    public function handles(UploadedFile $file): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/');
    }

    /**
     * @throws InvalidImageException when the file is not a usable image
     */
    public function process(UploadedFile $file): ProcessedImage
    {
        $path = $file->getRealPath();
        $settings = config('images.attachments');

        // Read the header only: confirms the content is one of the allowed formats, whatever the
        // file is called, and gives the dimensions before anything is decoded into memory
        $info = $path ? @getimagesize($path) : false;

        if ($info === false || !isset(self::ALLOWED_TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImageException('is not a valid JPEG, PNG, GIF or WebP image.');
        }

        [$width, $height] = $info;

        if ($width * $height > $settings['max_pixels']) {
            throw new InvalidImageException(
                "is too large to process ({$width} x {$height} pixels). Please resize it and try again."
            );
        }

        try {
            $image = $this->manager()->decodePath($path);

            // Animations are kept exactly as uploaded: re-encoding would freeze them on one frame
            if ($image->isAnimated()) {
                return new ProcessedImage(
                    (string) file_get_contents($path),
                    $info[2] === IMAGETYPE_GIF ? 'gif' : 'webp',
                    self::ALLOWED_TYPES[$info[2]],
                    $width,
                    $height,
                    converted: false,
                );
            }

            // Upright first (phone photos carry their rotation as metadata), then fit inside the
            // limit keeping the proportions. scaleDown never enlarges and never crops.
            $image = $image->orient()->scaleDown($settings['max_dimension'], $settings['max_dimension']);

            // strip: drops metadata such as the GPS position a phone adds to a photo
            $encoded = $image->encode(new WebpEncoder(quality: $settings['quality'], strip: true));

            return new ProcessedImage($encoded->toString(), 'webp', 'image/webp', $image->width(), $image->height(), converted: true);
        } catch (Throwable $exception) {
            // A header that looks right but data that cannot be decoded: damaged or disguised
            Log::warning('Image attachment could not be processed', [
                'file' => $file->getClientOriginalName(),
                'error' => $exception->getMessage(),
            ]);

            throw new InvalidImageException('could not be read as an image. It may be damaged.');
        }
    }

    private function manager(): ImageManager
    {
        $driver = match (config('images.default')) {
            'imagick' => ImagickDriver::class,
            default => GdDriver::class,
        };

        return ImageManager::usingDriver($driver);
    }
}
