<?php

return [
    'enabled' => env('DEMO_MODE', false),
    'reset_seconds' => (int) env('DEMO_RESET_SECONDS', 600),
];
