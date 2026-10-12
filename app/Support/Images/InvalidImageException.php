<?php

namespace App\Support\Images;

use RuntimeException;

/**
 * The uploaded file could not be used as an image. The message is safe to show to the person who uploaded it.
 */
class InvalidImageException extends RuntimeException
{
}
