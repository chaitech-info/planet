<?php
// Bu faylı config.local.php adı ilə kopyala və açarı yaz. config.local.php git-ə düşmür (.gitignore).
// Serverdə (Hostinger) də eyni faylı əl ilə yarat; deploy skripti onu yükləmir.
return [
    // Google AI Studio → Get API key (https://aistudio.google.com/apikey)
    'gemini_api_key' => '',
    // Live API modeli (https://ai.google.dev/gemini-api/docs/models)
    'gemini_model' => 'gemini-3.8-live',
    // Səs (Gemini-nin hazır səslərindən biri, məs. Charon, Puck, Kore, Fenrir)
    'gemini_voice' => 'Charon',
    // Jarvis-in danışdığı dil: 'az' və ya 'en'
    'jarvis_language' => 'az',
    // Hər istifadəçi üçün gündəlik limit (dəqiqə)
    'jarvis_daily_minutes' => 60,
];
