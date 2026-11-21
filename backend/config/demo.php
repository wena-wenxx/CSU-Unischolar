<?php

/*
| The REAL test student (used with her permission) and the demo accounts shown on the login page.
| Her real e-mail, Student ID and photo are set in .env (never in GitHub). The values below are
| placeholders used until .env has them.
*/
return [
    'test_student' => [
        'email' => env('TEST_STUDENT_EMAIL') ?: 'test.student@carsu.edu.ph',
        'student_id' => env('TEST_STUDENT_ID') ?: '000-00000',
        'first_name' => env('TEST_STUDENT_FIRST_NAME') ?: 'Gleslimie',
        'middle_name' => env('TEST_STUDENT_MIDDLE_NAME') ?: 'B.',
        'last_name' => env('TEST_STUDENT_LAST_NAME') ?: 'Binag',
        'sex' => env('TEST_STUDENT_SEX') ?: 'Female',
        'course' => env('TEST_STUDENT_COURSE') ?: 'Bachelor of Science in Information Technology',
        'year_level' => env('TEST_STUDENT_YEAR_LEVEL') ?: '4th Year',
        'college' => env('TEST_STUDENT_COLLEGE') ?: 'College of Computing and Information Sciences',
        // A photo on this computer (e.g. C:\Users\you\Pictures\gleslimie.jpg); copied in when seeding.
        'photo' => env('TEST_STUDENT_PHOTO') ?: null,
    ],

    // "php artisan demo:reset" works only when this is true AND APP_ENV is local. Keep it false
    // on any computer with real data.
    'reset_allowed' => (bool) env('DEMO_RESET_ALLOWED', false),

    // Show the demo accounts (with their passwords) on the login page. Turn OFF for real use.
    'show_on_login' => (bool) env('DEMO_LOGIN_ACCOUNTS', false),
];
