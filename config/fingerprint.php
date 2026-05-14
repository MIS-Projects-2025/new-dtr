<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SourceAFIS Worker Executable Path
    |--------------------------------------------------------------------------
    | Path to the compiled C# fingerprint worker (.exe on Windows).
    | Set FINGERPRINT_WORKER_PATH in your .env to override.
    |
    */
    'script_path' => env(
        'FINGERPRINT_WORKER_PATH',
        base_path('fingerprint-worker/bin/Release/net8.0/fingerprint-worker.exe')
    ),

    /*
    |--------------------------------------------------------------------------
    | Image DPI
    |--------------------------------------------------------------------------
    | DigitalPersona U.are.U 4500 captures at 500 DPI.
    | Wrong DPI = poor SourceAFIS accuracy, so keep this correct.
    |
    */
    'dpi' => (int) env('FINGERPRINT_DPI', 500),

];