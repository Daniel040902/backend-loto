<?php

return [
    'credentials' => [
        'file' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase-credentials.json')),
    ],
    'project' => env('FIREBASE_PROJECT_ID'),
    'server_key' => env('FCM_SERVER_KEY'),
];
