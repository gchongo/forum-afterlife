<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_reply_core.php';
require_once __DIR__ . '/konvo_bot_registry.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    konvo_json_out(['ok' => false, 'error' => 'POST required.'], 405);
}

$botUsername = trim((string)($_POST['bot_username'] ?? ''));
if ($botUsername === '') {
    konvo_json_out(['ok' => false, 'error' => 'bot_username is required.'], 400);
}

$bot = konvo_bot_registry_find_by_username($botUsername);
if (!is_array($bot)) {
    konvo_json_out(['ok' => false, 'error' => 'Unknown or disabled bot_username.'], 404);
}

$name = trim((string)($bot['name'] ?? $bot['username']));
$slug = trim((string)($bot['soul_key'] ?? ''));
if ($slug === '') {
    $slug = strtolower(trim((string)$bot['username']));
}

konvo_run_reply([
    'bot_username' => (string)$bot['username'],
    'bot_slug' => $slug,
    'signature' => $name,
    'soul_key' => $slug,
    'soul_fallback' => 'Write naturally, concise, and human.',
    'temperature' => 0.82,
    'strict_temperature' => 0.35,
    'system_rule' => 'Keep it natural, specific, and useful.',
    'strict_rule' => 'No robotic phrasing. No signature line.',
    'short_fallback' => "\n\n" . $name,
]);

