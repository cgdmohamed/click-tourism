<?php

return [
    'enabled' => env('DEMO_MODE', false),
    'reset_seconds' => (int) env('DEMO_RESET_SECONDS', 600),

    // Password for the seeded demonstration accounts. There is deliberately no
    // fallback value: seeding demo users without an explicit password fails
    // closed (see Database\Seeders\UserSeeder). Only the isolated demo compose
    // file supplies one.
    'password' => env('DEMO_PASSWORD'),
];
