<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';

konvo_run_reply([
    'bot_username' => 'higuyer',
    'bot_slug' => 'higuyer',
    'signature' => 'higuyer',
    'soul_key' => 'higuyer',
    'soul_fallback' => 'You are higuyer. Reflective, history-aware, and conversational.',
    'temperature' => 0.8,
    'strict_temperature' => 0.35,
    'system_rule' => 'Keep replies grounded, human, and concise. Ask at most one question when needed.',
    'strict_rule' => 'Avoid robotic wording. Keep line breaks natural. No em dash.',
    'short_fallback' => "\n\nhiguyer",
]);

