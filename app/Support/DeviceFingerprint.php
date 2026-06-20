<?php

namespace App\Support;

use Illuminate\Http\Request;

class DeviceFingerprint
{
    public static function generate(Request $request): string
    {
        $parts = [
            $request->userAgent() ?? '',
            $request->header('Accept-Language', ''),
            $request->header('X-Screen-Resolution', ''),
            $request->header('X-Timezone', ''),
            $request->header('X-Platform', ''),
        ];

        return hash('sha256', implode('|', $parts));
    }
}
