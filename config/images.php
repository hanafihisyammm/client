<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Image Driver
    |--------------------------------------------------------------------------
    |
    | The PHP extension used to read and write images: "gd" or "imagick".
    | This file and the IMAGE_DRIVER variable use the same names as Laravel 13's
    | own image configuration, so they carry over unchanged when the framework
    | is upgraded and App\Support\Images switches to the built-in Image API.
    |
    */

    'default' => env('IMAGE_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Issue Attachments
    |--------------------------------------------------------------------------
    |
    | Screenshots and photos attached to issues. They are scaled down to fit
    | inside max_dimension (keeping their proportions, never enlarged, never
    | cropped) and saved as WebP. 2000px keeps the text of a full-screen
    | screenshot readable when it is opened at full size.
    |
    | max_pixels refuses images whose decoded size would use too much memory,
    | however small the file is (width x height). 40 million covers the
    | cameras of current phones.
    |
    */

    'attachments' => [
        'max_dimension' => 2000,
        'quality' => 80,
        'max_pixels' => 40_000_000,
    ],

];
