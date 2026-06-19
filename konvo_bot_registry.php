<?php

declare(strict_types=1);

function konvo_bot_registry_state_dir(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function konvo_bot_registry_path(): string
{
    return konvo_bot_registry_state_dir() . '/bots.json';
}

function konvo_bot_registry_slugify(string $username): string
{
    $slug = strtolower(trim($username));
    $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? $slug;
    $slug = trim((string)$slug, '_');
    return $slug !== '' ? $slug : 'bot';
}

function konvo_bot_registry_default(): array
{
    return [
        [
            'username' => 'higuyer',
            'name' => 'higuyer',
            'soul_key' => 'higuyer',
            'category_id' => 10,
            'enabled' => true,
        ],
        [
            'username' => 'BAI',
            'name' => 'BAI',
            'soul_key' => 'bai',
            'category_id' => 4,
            'enabled' => true,
        ],
    ];
}

function konvo_bot_registry_normalize_row(array $row): ?array
{
    $username = trim((string)($row['username'] ?? ''));
    if ($username === '') {
        return null;
    }
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') {
        $name = $username;
    }
    $soulKey = trim((string)($row['soul_key'] ?? ''));
    if ($soulKey === '') {
        $soulKey = konvo_bot_registry_slugify($username);
    }
    $categoryId = (int)($row['category_id'] ?? 0);
    $enabled = !isset($row['enabled']) || (bool)$row['enabled'];

    return [
        'username' => $username,
        'name' => $name,
        'soul_key' => $soulKey,
        'category_id' => $categoryId,
        'enabled' => $enabled,
    ];
}

function konvo_bot_registry_load(): array
{
    $path = konvo_bot_registry_path();
    if (!is_file($path)) {
        return konvo_bot_registry_default();
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return konvo_bot_registry_default();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return konvo_bot_registry_default();
    }
    $rows = [];
    $seen = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n = konvo_bot_registry_normalize_row($row);
        if (!is_array($n)) {
            continue;
        }
        $key = strtolower($n['username']);
        if (isset($seen[$key])) {
            continue;
        }
        $rows[] = $n;
        $seen[$key] = true;
    }
    return $rows !== [] ? $rows : konvo_bot_registry_default();
}

function konvo_bot_registry_save(array $rows): bool
{
    $clean = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n = konvo_bot_registry_normalize_row($row);
        if (!is_array($n)) {
            continue;
        }
        $key = strtolower($n['username']);
        if (isset($seen[$key])) {
            continue;
        }
        $clean[] = $n;
        $seen[$key] = true;
    }
    if ($clean === []) {
        $clean = konvo_bot_registry_default();
    }
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }
    return @file_put_contents(konvo_bot_registry_path(), $json) !== false;
}

function konvo_bot_registry_enabled(): array
{
    $all = konvo_bot_registry_load();
    return array_values(array_filter($all, static function (array $row): bool {
        return !isset($row['enabled']) || (bool)$row['enabled'];
    }));
}

function konvo_bot_registry_find_by_username(string $username): ?array
{
    $target = strtolower(trim($username));
    if ($target === '') {
        return null;
    }
    foreach (konvo_bot_registry_enabled() as $row) {
        if (strtolower((string)$row['username']) === $target) {
            return $row;
        }
    }
    return null;
}

function konvo_bot_registry_pick_for_category(int $categoryId): ?array
{
    $enabled = konvo_bot_registry_enabled();
    foreach ($enabled as $row) {
        if ((int)($row['category_id'] ?? 0) === $categoryId) {
            return $row;
        }
    }
    return $enabled[0] ?? null;
}

