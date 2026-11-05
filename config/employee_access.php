<?php

return [
    'validity_hours' => env('EMPLOYEE_TEMP_PASSWORD_HOURS', 24),
    'portal_url' => env('EMPLOYEE_PORTAL_URL', env('APP_URL', 'http://localhost:8090')),
];
