<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Uploads with real content, for checks that judge a file by what it contains.
 */
trait MakesUploads
{
    /**
     * An upload whose type is judged from its content, as a real upload's is (fake uploads go by
     * their name alone).
     */
    protected function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** The smallest workbook the server recognises as a real Excel file by its content */
    protected function xlsx(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('_rels/.rels', '<Relationships/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    /** The smallest old-style workbook the server recognises as an Excel file: an empty "Workbook" stream */
    protected function xls(string $name): UploadedFile
    {
        $entry = function (string $name, int $type, int $child): string {
            $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8') . "\0\0";

            return str_pad($utf16, 64, "\0") . pack('vCC', strlen($utf16), $type, 1)
                . pack('VVV', 0xFFFFFFFF, 0xFFFFFFFF, $child) . str_repeat("\0", 36)
                . pack('VVV', 0xFFFFFFFE, 0, 0);
        };

        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16)
            . pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
            . pack('VVVVVVVVVV', 0, 1, 1, 0, 4096, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0, 0) . str_repeat("\xFF", 432);
        $fat = pack('VV', 0xFFFFFFFD, 0xFFFFFFFE) . str_repeat("\xFF", 504);
        $directory = $entry('Root Entry', 5, 1) . $entry('Workbook', 2, 0xFFFFFFFF) . str_repeat("\0", 256);

        return $this->realUpload($name, $header . $fat . $directory);
    }
}
