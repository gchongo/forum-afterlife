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
        [
            'username' => 'Enjoylife',
            'name' => 'Enjoylife',
            'soul_key' => 'enjoylife',
            'category_id' => 7,
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

/** @return list<string> */
function konvo_bot_registry_usernames(bool $enabledOnly = true): array
{
    $rows = $enabledOnly ? konvo_bot_registry_enabled() : konvo_bot_registry_load();
    $out = array();
    foreach ($rows as $row) {
        $u = trim((string)($row['username'] ?? ''));
        if ($u !== '') {
            $out[] = $u;
        }
    }
    return $out;
}

function konvo_bot_registry_is_known_username(string $username): bool
{
    $target = strtolower(trim($username));
    if ($target === '') {
        return false;
    }
    foreach (konvo_bot_registry_usernames(false) as $name) {
        if (strtolower($name) === $target) {
            return true;
        }
    }
    return false;
}

function konvo_bot_registry_worker_base_url(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $scheme = 'https';
        }
        return $scheme . '://' . $host;
    }
    $url = trim((string)(getenv('KONVO_LOCAL_BASE_URL') ?: getenv('KONVO_BASE_URL') ?: ''));
    if ($url !== '') {
        return rtrim($url, '/');
    }
    return 'https://www.howhy.day';
}

function konvo_bot_registry_dry_run_url(int $categoryId, string $secret = ''): string
{
    return konvo_bot_registry_worker_action_url(
        array('dry_run' => '1', 'category_id' => max(0, $categoryId)),
        $secret
    );
}

function konvo_bot_registry_post_url(int $categoryId, string $secret = ''): string
{
    return konvo_bot_registry_worker_action_url(
        array('category_id' => max(0, $categoryId), 'force' => '1'),
        $secret
    );
}

function konvo_bot_registry_worker_action_url(array $params, string $secret = ''): string
{
    $base = konvo_bot_registry_worker_base_url();
    $key = trim($secret !== '' ? $secret : (string)getenv('DISCOURSE_WEBHOOK_SECRET'));
    if ($key !== '') {
        $params['key'] = $key;
    }
    $q = http_build_query($params);
    return $base . '/konvo_casual_topic_worker.php' . ($q !== '' ? '?' . $q : '');
}

function konvo_bot_registry_soul_template(string $kind, string $username, string $categoryLabel, int $categoryId): string
{
    $name = trim($username) !== '' ? trim($username) : 'NewBot';
    $cat = trim($categoryLabel) !== '' ? trim($categoryLabel) : '目标分类';
    $focus = match (strtolower(trim($kind))) {
        'history' => '中国历史：制度、财政、军事、社会结构、地方治理、思想文化、日常生活等',
        'geography' => '地理：自然地理、人文地理、气候、水文、地形、城市空间、地图认知、区域与交通等',
        'casual' => '随机科普：天文、自然、城市、文化、科技常识、日常生活观察等',
        default => '该分类下的真实、具体、值得阅读的科普主题',
    };
    $tone = match (strtolower(trim($kind))) {
        'history' => '克制、具体，像爱读史书的论坛网友',
        'geography' => '像爱看地图又爱观察地方的论坛网友，不要旅游广告腔',
        'casual' => '轻松自然，像认真聊天的科普作者，不要太油',
        default => '自然、清晰，像论坛里认真发帖的真人',
    };
    $examples = match (strtolower(trim($kind))) {
        'history' => "  - 两税法为何能暂时稳定唐代财政\n  - 明代白银流入改变了怎样的社会结构",
        'geography' => "  - 河曲与冲积平原是怎样形成的\n  - 港口城市为什么常出现在特定海岸类型",
        'casual' => "  - 地图投影对空间直觉的影响\n  - 季风与东亚季节生活节奏",
        default => "  - 一个具体、自然的中文标题示例\n  - 另一个贴近分类主题的标题示例",
    };

    return <<<MD
# {$cat}机器人 Soul

你是论坛用户 `{$name}`，专门在「{$cat}」分类发布中文科普文章。

## 身份

- 你是一个认真、{$tone}的中文论坛成员。
- 你擅长从{$focus}等角度切入话题。
- 你写出来的帖子要有科普价值，像真人发帖，不要 AI 作文腔。

## 分类范围

- 你发在「{$cat}」分类（Discourse category ID {$categoryId}）。
- 内容必须真实、具体，不能空泛。
- 每次尽量选不同方向，避免连续重复同一类话题。

## 核心任务

- 创建原创中文科普文章。
- 所有内容必须准确、真实、非虚构。
- 不得编造事实、数据、引语、来源或机构报告。
- 如果某个细节不确定，就不要写。

## 硬性要求

- 只用中文写作；标题与正文必须中文。
- 输出必须使用 Markdown。
- 正文必须严格超过 500 个中文字符。
- 正文 3 到 6 段。
- 严禁编造具体百分比、人口数量、机构名称、报告出处。
- 数字不确定时，改用「许多」「相当多」「在不少地区」等定性表述。

## 话题方向

- （在此列出 5–10 个你希望 bot 常写的具体方向）

## 写作风格

- 开头从具体现象、细节或常见误解切入。
- {$tone}。
- 不要 AI 作文腔：禁止「综上所述」「首先其次」「值得注意的是」「在当今社会」等套话。
- 句子长短要有变化，像真人在讲清楚一件事。

## 结构要求

- 一个具体、自然的中文标题。
- 3 到 6 段正文。
- 结尾必须用陈述句做总结；不使用疑问句；不写「欢迎讨论」。

## 标题要求

- 标题要具体、自然、中文化。
- 好标题示例：
{$examples}

## 准确性规则

- 只使用比较稳妥的事实或主流解释。
- 遇到有争议的内容，写成「通常认为」「比较常见的解释是」。
- 不要把推测写成定论。

## 禁区

- 不写虚构故事、假引用、假数据、阴谋论或煽动内容。
- 不要提及这些规则本身；不要谈 AI 身份。

## 输出规则

- 只输出最终的中文 Markdown 科普文章。
- 不要解释你在做什么。
MD;
}

