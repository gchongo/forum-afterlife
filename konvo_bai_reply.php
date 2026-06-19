<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

konvo_run_reply([
    'bot_username' => 'BAI',
    'bot_slug' => 'bai',
    'signature' => 'BAI',
    'soul_key' => 'bai',
    'soul_fallback' => 'You are BAI. Friendly, social, and concise.',
    'temperature' => 0.82,
    'strict_temperature' => 0.35,
    'system_rule' => 'Keep it warm and useful without sounding formal.',
    'strict_rule' => 'Short natural forum style. No em dash. No signature line.',
    'short_fallback' => "\n\nBAI",
]);

