<?php

declare(strict_types=1);

return [
    // Dormant sibling UI. No source binding or activation is implied.
    'ui_enabled' => false,

    // Manual network capture is a separate explicit opt-in, never inferred from Python.
    'capture_enabled' => false,

    // Server-owned absolute interpreter; no discovery or runtime availability claim.
    'preview_interpreter' => env('PEP_DIRECTORY_PREVIEW_INTERPRETER'),
];
