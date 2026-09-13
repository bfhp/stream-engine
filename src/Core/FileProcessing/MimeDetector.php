<?php

declare(strict_types=1);

namespace StreamEngine\Core\FileProcessing;

use finfo;

class MimeDetector
{
    /**
     * What an unreadable or unrecognisable file is reported as.
     *
     * Deliberately not on UploadService::ALLOWED_MIME, so "we could not tell
     * what this is" and "we do not accept this" take the same path: the upload
     * is refused with the ordinary invalid-file-type message.
     */
    public const string UNKNOWN = 'application/octet-stream';

    public function detect(string $file): string
    {
        // finfo::file() emits a warning and returns false for anything it
        // cannot open - a path that no longer exists, a directory, a file the
        // process cannot read. Returning that from a `: string` method was a
        // TypeError, i.e. a 500 on the upload path instead of a rejected
        // upload. Check first so the common failure doesn't even warn.
        if (! is_file($file) || ! is_readable($file)) {
            return self::UNKNOWN;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $mime = $finfo->file($file);

        return $mime !== false ? $mime : self::UNKNOWN;
    }
}
