<?php

namespace App\Support\Images;

/**
 * An image ready to be stored: its bytes and what they now are.
 */
class ProcessedImage
{
    public function __construct(
        public readonly string $contents,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
        /** False when the original bytes were kept as they were (animations) */
        public readonly bool $converted,
    ) {
    }

    public function size(): int
    {
        return strlen($this->contents);
    }
}
