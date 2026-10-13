<?php
// Gizli fayl: paylaşma. Tokenləri dəyişmək üçün buranı redaktə et.
return [
    'timezone' => 'Asia/Baku',
    // Dashboard giriş parolu
    'dashboard_password' => 'cc7592b59e79',
    // token => istifadəçi adı (hər kəsə ayrı token ver)
    'users' => [
        '75fa0e31e7f3a6b174f231ff813ba579' => 'Askar',
        '5e2f6b65fd3432eb4e2b3046d98b357e' => 'Dost',
    ],
    // Shared hostingdə SQLite işləyir (data/ qovluğu .htaccess ilə bağlıdır).
    // MySQL istəsən: 'dsn' => 'mysql:host=localhost;dbname=XXX;charset=utf8mb4', 'user' => '...', 'pass' => '...'
    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/data/team-3533f44de576.sqlite'],
];
