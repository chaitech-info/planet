<?php
// Bu faylı config.local.php adı ilə kopyala və açarı yaz. config.local.php git-ə düşmür (.gitignore).
// Serverdə (Hostinger) də eyni faylı əl ilə yarat; deploy skripti onu yükləmir.
return [
    // Gemini Live (hazırda söndürülüb, bax jarvis.php → $enabled). Google AI Studio → Get API key (https://aistudio.google.com/apikey)
    'gemini_api_key' => '',
    // Live API modeli (https://ai.google.dev/gemini-api/docs/models)
    'gemini_model' => 'gemini-3.8-live',
    // Səs (Gemini-nin hazır səslərindən biri, məs. Charon, Puck, Kore, Fenrir)
    'gemini_voice' => 'Charon',
    // fal.ai açarı (https://fal.ai/dashboard/keys): Grok Voice (xAI) səs modeli fal üzərindən işləyir
    'fal_key' => '',
    // Grok səsi: rex, leo, eve, ara, sal, orion, atlas, helios, … (fal.ai → xai/grok-voice/realtime)
    'grok_voice' => 'rex',
    // Default səs təminatçısı: 'grok' və ya 'gemini' (Gemini hazırda jarvis.php-də söndürülüb: $enabled)
    'jarvis_provider' => 'grok',
    // Jarvis-in danışdığı dil: 'az' və ya 'en'
    'jarvis_language' => 'az',
    // Hər istifadəçi üçün gündəlik limit (dəqiqə)
    'jarvis_daily_minutes' => 60,
];
