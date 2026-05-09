<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class FileStorage
{
    public static function diskName(): string
    {
        return (string) config('filesystems.cloud', 'r2');
    }

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return self::disk()->url($path);
    }
}
