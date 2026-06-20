<?php

/*
 * Browser-callable casual topic poster.
 *
 * Example:
 * https://www.howhy.day/konvo_casual_topic_worker.php?key=YOUR_SECRET
 * https://www.howhy.day/konvo_casual_topic_worker.php?key=YOUR_SECRET&dry_run=1
 */

declare(strict_types=1);

@set_time_limit(120);
@ini_set('max_execution_time', '120');
@ignore_user_abort(true);

require_once __DIR__ . '/konvo_soul_helper.php';
require_once __DIR__ . '/konvo_soul_topic_helper.php';
require_once __DIR__ . '/konvo_soul_topic_pipeline.php';
require_once __DIR__ . '/konvo_signature_helper.php';
require_once __DIR__ . '/konvo_bot_registry.php';
$konvoForumPromptHelper = __DIR__ . '/konvo_forum_prompt_helper.php';
if (is_file($konvoForumPromptHelper)) {
    require_once $konvoForumPromptHelper;
}
$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'deepseek-chat';
    }
}

if (!defined('KONVO_WORKER_BUILD')) define('KONVO_WORKER_BUILD', '2026-06-20-pipeline-v15.4');
if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://www.howhy.day');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_DISCOURSE_API_USERNAME')) {
    $discourseApiUser = trim((string)getenv('KONVO_DISCOURSE_API_USERNAME'));
    define('KONVO_DISCOURSE_API_USERNAME', $discourseApiUser !== '' ? $discourseApiUser : 'system');
}
if (!defined('KONVO_TOPIC_FAST_MODE')) {
    $fastModeEnv = strtolower(trim((string)getenv('KONVO_TOPIC_FAST_MODE')));
    define('KONVO_TOPIC_FAST_MODE', ($fastModeEnv === '' || in_array($fastModeEnv, array('1', 'true', 'yes', 'on'), true)));
}
if (!defined('KONVO_CASUAL_DAILY_CAP')) {
    $dailyCapRaw = trim((string)getenv('KONVO_CASUAL_DAILY_CAP'));
    define('KONVO_CASUAL_DAILY_CAP', ($dailyCapRaw === '' ? 0 : max(0, (int)$dailyCapRaw)));
}
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)(getenv('LLM_API_KEY') ?: getenv('DEEPSEEK_API_KEY') ?: getenv('OPENAI_API_KEY'))));
if (!defined('KONVO_LLM_CHAT_COMPLETIONS_URL')) define('KONVO_LLM_CHAT_COMPLETIONS_URL', rtrim((string)(getenv('LLM_API_BASE_URL') ?: getenv('OPENAI_API_BASE') ?: 'https://api.deepseek.com'), '/') . '/chat/completions');
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_ALLOW_CASUAL_TOPIC_POSTS')) define('KONVO_ALLOW_CASUAL_TOPIC_POSTS', trim((string)getenv('KONVO_ALLOW_CASUAL_TOPIC_POSTS')));
if (!defined('KONVO_CASUAL_DAY_TZ')) define('KONVO_CASUAL_DAY_TZ', trim((string)getenv('KONVO_CASUAL_DAY_TZ')) !== '' ? trim((string)getenv('KONVO_CASUAL_DAY_TZ')) : 'America/Los_Angeles');
if (!defined('KONVO_CHAT_CATEGORY_ID')) define('KONVO_CHAT_CATEGORY_ID', 4);
if (!defined('KONVO_HISTORY_CATEGORY_ID')) define('KONVO_HISTORY_CATEGORY_ID', 10);
if (!defined('KONVO_TALK_CATEGORY_ID')) define('KONVO_TALK_CATEGORY_ID', (int)KONVO_CHAT_CATEGORY_ID);
if (!defined('KONVO_WEBDEV_CATEGORY_ID')) define('KONVO_WEBDEV_CATEGORY_ID', (int)KONVO_HISTORY_CATEGORY_ID);
if (!defined('KONVO_GAMING_CATEGORY_ID')) define('KONVO_GAMING_CATEGORY_ID', (int)KONVO_HISTORY_CATEGORY_ID);
if (!defined('KONVO_DESIGN_CATEGORY_ID')) define('KONVO_DESIGN_CATEGORY_ID', (int)KONVO_HISTORY_CATEGORY_ID);

$bots = konvo_bot_registry_enabled();

function casual_json_encode(array $data): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if (is_string($json) && $json !== '') {
        return $json;
    }
    return json_encode(array(
        'ok' => false,
        'error' => 'json_encode_failed',
        'json_last_error' => function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown',
        'worker_build' => (string)KONVO_WORKER_BUILD,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"ok":false,"error":"json_encode_failed"}';
}

function casual_safe_substr(string $text, int $maxChars): string
{
    return konvo_soul_safe_substr($text, $maxChars);
}

function casual_out(int $status, array $data): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo casual_json_encode($data);
    exit;
}

set_exception_handler(static function (\Throwable $e): void {
    $where = basename((string)$e->getFile()) . ':' . (int)$e->getLine();
    $msg = trim((string)$e->getMessage());
    if ($msg === '') $msg = 'Unhandled exception';
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo casual_json_encode(array('ok' => false, 'error' => 'Casual worker exception: ' . $msg . ' [' . $where . ']'));
    exit;
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) return;
    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array((int)($err['type'] ?? 0), $fatal, true)) return;

    $msg = trim((string)($err['message'] ?? 'Fatal error'));
    $file = basename((string)($err['file'] ?? 'unknown'));
    $line = (int)($err['line'] ?? 0);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo casual_json_encode(array('ok' => false, 'error' => 'Casual worker fatal: ' . $msg . ' [' . $file . ':' . $line . ']'));
});

function safe_hash_equals(string $a, string $b): bool
{
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function casual_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_topic_recent.json';
}

function casual_daily_counts_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_topic_daily_counts.json';
}

function casual_daily_counts_load(): array
{
    $path = casual_daily_counts_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return array();
    $clean = array();
    foreach ($decoded as $day => $count) {
        $d = trim((string)$day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $clean[$d] = max(0, (int)$count);
    }
    ksort($clean);
    return $clean;
}

function casual_daily_counts_save(array $state): void
{
    $today = casual_today_key();
    $cutoffTs = strtotime($today . ' 00:00:00 UTC');
    $minTs = ($cutoffTs === false ? time() : $cutoffTs) - (45 * 24 * 3600);
    $clean = array();
    foreach ($state as $day => $count) {
        $d = trim((string)$day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $ts = strtotime($d . ' 00:00:00 UTC');
        if ($ts === false || $ts < $minTs) continue;
        $clean[$d] = max(0, (int)$count);
    }
    ksort($clean);
    @file_put_contents(casual_daily_counts_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_today_key(): string
{
    try {
        $tz = new DateTimeZone((string)KONVO_CASUAL_DAY_TZ);
    } catch (\Throwable $e) {
        $tz = new DateTimeZone('America/Los_Angeles');
    }
    $now = new DateTimeImmutable('now', $tz);
    return $now->format('Y-m-d');
}

function casual_daily_count_for(string $day): int
{
    $state = casual_daily_counts_load();
    return max(0, (int)($state[$day] ?? 0));
}

function casual_daily_count_increment(string $day): int
{
    $state = casual_daily_counts_load();
    $state[$day] = max(0, (int)($state[$day] ?? 0)) + 1;
    casual_daily_counts_save($state);
    return (int)$state[$day];
}

function casual_load_recent_topics(): array
{
    $path = casual_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_save_recent_topics(array $items): void
{
    $clean = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        $angle = trim((string)($item['plan_angle'] ?? ''));
        $lane = trim((string)($item['plan_lane'] ?? ''));
        $ts = (int)($item['ts'] ?? time());
        if ($title === '') continue;
        $clean[] = array(
            'title' => $title,
            'plan_angle' => $angle,
            'plan_lane' => $lane,
            'raw' => trim((string)($item['raw'] ?? '')),
            'ts' => $ts,
        );
    }

    usort($clean, static function ($a, $b) {
        return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
    });

    $clean = array_slice($clean, 0, 60);
    @file_put_contents(casual_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_remember_topic(string $title, string $planAngle, string $planLane = '', string $raw = ''): void
{
    $items = casual_load_recent_topics();
    array_unshift($items, array(
        'title' => trim($title),
        'plan_angle' => trim($planAngle),
        'plan_lane' => trim($planLane),
        'raw' => trim($raw),
        'ts' => time(),
    ));
    casual_save_recent_topics($items);
}

function casual_opening_stem(string $text): string
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
    $first = trim((string)strtok($text, "\n"));
    if ($first === '') return '';
    $first = preg_replace('/https?:\/\/\S+/i', '', $first) ?? $first;
    $first = strtolower($first);
    $first = preg_replace('/[^a-z0-9\s]/i', ' ', $first) ?? $first;
    $first = preg_replace('/\s+/', ' ', $first) ?? $first;
    $first = trim((string)$first);
    if ($first === '') return '';
    $parts = explode(' ', $first);
    if (count($parts) > 10) $parts = array_slice($parts, 0, 10);
    return trim((string)implode(' ', $parts));
}

function casual_recent_opening_stems(array $recent, int $limit = 14): string
{
    $stems = array();
    foreach ($recent as $item) {
        if (!is_array($item)) continue;
        $raw = trim((string)($item['raw'] ?? ''));
        if ($raw === '') continue;
        $stem = casual_opening_stem($raw);
        if ($stem === '' || isset($stems[$stem])) continue;
        $stems[$stem] = true;
        if (count($stems) >= max(6, $limit)) break;
    }
    if ($stems === array()) return '(none)';
    $lines = array();
    foreach (array_keys($stems) as $s) {
        $lines[] = '- ' . $s;
    }
    return implode("\n", $lines);
}

function casual_consensus_state_path(): string
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/casual_consensus_state.json';
}

function casual_consensus_load(): array
{
    $path = casual_consensus_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_consensus_save(array $state): void
{
    $clean = array();
    $now = time();
    foreach ($state as $k => $row) {
        $id = (string)$k;
        if (!preg_match('/^\d+$/', $id)) continue;
        if (!is_array($row)) continue;
        $createdTs = isset($row['created_ts']) ? (int)$row['created_ts'] : $now;
        if (($now - $createdTs) > (14 * 24 * 3600)) continue;
        $clean[$id] = $row;
    }
    @file_put_contents(casual_consensus_state_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function casual_consensus_register_topic(int $topicId, array $bot, string $title, int $categoryId, array $plan): void
{
    if ($topicId <= 0) return;
    $state = casual_consensus_load();
    $key = (string)$topicId;
    $now = time();
    $state[$key] = array(
        'topic_id' => $topicId,
        'title' => trim($title),
        'category_id' => $categoryId,
        'op_bot' => strtolower(trim((string)($bot['username'] ?? ''))),
        'op_signature' => trim((string)($bot['name'] ?? '')),
        'phase' => 'open',
        'created_ts' => $now,
        'updated_ts' => $now,
        'discussion_reply_count' => 0,
        'participant_bots' => array(),
        'consensus_posted' => false,
        'consensus_post_id' => 0,
        'plan_mood' => trim((string)($plan['mood'] ?? '')),
        'plan_angle' => trim((string)($plan['angle'] ?? '')),
        'plan_intent' => trim((string)($plan['posting_intent'] ?? '')),
    );
    casual_consensus_save($state);
}

function casual_recent_hint_lines(array $recent): string
{
    $lines = array();
    $max = min(12, count($recent));
    for ($i = 0; $i < $max; $i++) {
        $item = $recent[$i] ?? null;
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        $angle = trim((string)($item['plan_angle'] ?? ''));
        $lane = trim((string)($item['plan_lane'] ?? ''));
        if ($title === '') continue;
        $line = '- ' . $title;
        if ($angle !== '') {
            $line .= ' (angle: ' . $angle . ')';
        }
        if ($lane !== '') {
            $line .= ' [lane: ' . $lane . ']';
        }
        $lines[] = $line;
    }
    return $lines === array() ? '(none)' : implode("\n", $lines);
}

function casual_interest_lanes(): array
{
    return array(
        'games' => array(
            'label' => 'video games and player experience',
            'guidance' => 'Focus on game design, player behavior, creativity, community, mechanics, balance, or discovery. Not patch/news reposts.',
        ),
        'sci_fi_ai' => array(
            'label' => 'science fiction lens on AI',
            'guidance' => 'Use a sci-fi framing to discuss practical AI/product behavior today. Keep it grounded in real teams and products.',
        ),
        'business' => array(
            'label' => 'business and market impact',
            'guidance' => 'Focus on incentives, margins, hiring, go-to-market pressure, or organizational tradeoffs from AI/tech shifts.',
        ),
        'design' => array(
            'label' => 'design and UX impact',
            'guidance' => 'Focus on UX quality, trust, intent, agency, creativity, and design-system/product tradeoffs.',
        ),
        'dev_culture' => array(
            'label' => 'developer life and craft',
            'guidance' => 'Focus on debugging habits, code ownership, review quality, team learning, and engineering culture tradeoffs.',
        ),
        'product_workflow' => array(
            'label' => 'product and workflow decisions',
            'guidance' => 'Focus on process, collaboration, decision speed, and where automation helps or harms product outcomes.',
        ),
    );
}

function casual_lane_tokens(string $laneKey): array
{
    $map = array(
        'games' => array('game', 'gaming', 'npc', 'player', 'gameplay', 'level', 'quest', 'rpg', 'indie'),
        'sci_fi_ai' => array('sci-fi', 'science fiction', 'hal', 'skynet', 'agent', 'autonomy', 'future'),
        'business' => array('business', 'market', 'pricing', 'margin', 'hiring', 'revenue', 'cost', 'roi'),
        'design' => array('design', 'ux', 'ui', 'interface', 'usability', 'creative', 'workflow'),
        'dev_culture' => array('developer', 'engineering', 'code review', 'debugging', 'ownership', 'team'),
        'product_workflow' => array('product', 'process', 'workflow', 'decision', 'collaboration', 'roadmap'),
    );
    return $map[$laneKey] ?? array();
}

function casual_infer_lane_from_item(array $item): string
{
    $lane = trim((string)($item['plan_lane'] ?? ''));
    if ($lane !== '') return $lane;

    $blob = strtolower(trim((string)($item['title'] ?? '') . "\n" . (string)($item['plan_angle'] ?? '')));
    if ($blob === '') return '';
    foreach (array_keys(casual_interest_lanes()) as $laneKey) {
        $tokens = casual_lane_tokens($laneKey);
        foreach ($tokens as $tok) {
            if ($tok !== '' && strpos($blob, strtolower($tok)) !== false) {
                return $laneKey;
            }
        }
    }
    return '';
}

function casual_pick_interest_lane(array $recent): array
{
    $lanes = casual_interest_lanes();
    $counts = array();
    foreach (array_keys($lanes) as $k) {
        $counts[$k] = 0;
    }
    $max = min(12, count($recent));
    for ($i = 0; $i < $max; $i++) {
        $item = $recent[$i] ?? null;
        if (!is_array($item)) continue;
        $lane = casual_infer_lane_from_item($item);
        if ($lane !== '' && isset($counts[$lane])) {
            $counts[$lane]++;
        }
    }

    $min = null;
    $choices = array();
    foreach ($counts as $k => $c) {
        if ($min === null || $c < $min) {
            $min = $c;
            $choices = array($k);
        } elseif ($c === $min) {
            $choices[] = $k;
        }
    }
    if ($choices === array()) {
        $choices = array_keys($lanes);
    }
    shuffle($choices);
    $pickedKey = (string)$choices[0];
    $picked = $lanes[$pickedKey] ?? array('label' => 'technology tradeoffs', 'guidance' => '');
    return array(
        'key' => $pickedKey,
        'label' => (string)($picked['label'] ?? 'technology tradeoffs'),
        'guidance' => (string)($picked['guidance'] ?? ''),
        'counts' => $counts,
    );
}

function casual_lane_from_key(string $key, array $recent = array()): ?array
{
    $k = strtolower(trim($key));
    if ($k === '') return null;
    $lanes = casual_interest_lanes();
    if (!isset($lanes[$k])) return null;
    $picked = $lanes[$k];
    $auto = casual_pick_interest_lane($recent);
    $counts = is_array($auto) && isset($auto['counts']) && is_array($auto['counts']) ? $auto['counts'] : array();
    return array(
        'key' => $k,
        'label' => (string)($picked['label'] ?? 'technology tradeoffs'),
        'guidance' => (string)($picked['guidance'] ?? ''),
        'counts' => $counts,
        'override' => true,
    );
}

function casual_fetch_latest_topic_titles(int $max = 100, int $categoryId = 0): array
{
    if (!function_exists('curl_init')) return array();
    $titles = array();
    $page = 0;
    $maxPages = min(4, max(1, (int)ceil($max / 30)));
    while ($page < $maxPages && count($titles) < $max) {
        $url = rtrim(KONVO_BASE_URL, '/') . '/latest.json?order=created&ascending=false&page=' . $page;
        if ($categoryId > 0) {
            $url .= '&category=' . $categoryId;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => array(
                'Api-Key: ' . KONVO_API_KEY,
                'Api-Username: ' . KONVO_DISCOURSE_API_USERNAME,
            ),
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '' || $status < 200 || $status >= 300 || !is_string($body) || trim($body) === '') {
            break;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) break;
        $topics = $json['topic_list']['topics'] ?? array();
        if (!is_array($topics) || $topics === array()) break;
        foreach ($topics as $topic) {
            $t = trim((string)($topic['title'] ?? ''));
            if ($t === '') continue;
            $titles[] = $t;
            if (count($titles) >= $max) break;
        }
        $page++;
    }
    return array_values(array_unique($titles));
}

function casual_normalized_title_key(string $title): string
{
    return konvo_soul_normalize_title_key($title);
}

function casual_title_terms(string $title): array
{
    $s = casual_normalized_title_key($title);
    if ($s === '') return array();
    $parts = preg_split('/\s+/', $s);
    if (!is_array($parts)) return array();
    $stop = array(
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'for', 'on', 'with', 'is', 'are', 'be', 'it', 'that', 'this',
        'how', 'what', 'when', 'why', 'does', 'do', 'should', 'can', 'could', 'would', 'will', 'you', 'your', 'our',
        'my', 'we', 'they', 'them', 'their', 'me', 'i', 'at', 'by', 'from', 'as', 'if', 'than', 'then', 'vs', 'versus',
        'make', 'makes', 'made', 'making', 'better', 'best', 'worse', 'worst', 'feel', 'feels', 'felt', 'more', 'less',
        'really', 'just', 'still', 'very', 'much', 'too', 'about', 'around', 'into', 'out', 'over', 'under'
    );
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || strlen($p) < 3) continue;
        if (str_ends_with($p, 'ies') && strlen($p) > 4) {
            $p = substr($p, 0, -3) . 'y';
        } elseif (str_ends_with($p, 'ing') && strlen($p) > 5) {
            $p = substr($p, 0, -3);
        } elseif (str_ends_with($p, 'ed') && strlen($p) > 4) {
            $p = substr($p, 0, -2);
        } elseif (str_ends_with($p, 's') && strlen($p) > 4 && !str_ends_with($p, 'ss')) {
            $p = substr($p, 0, -1);
        }
        if (in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function casual_title_similarity_score(string $a, string $b): float
{
    $ta = casual_title_terms($a);
    $tb = casual_title_terms($b);
    if ($ta === array() || $tb === array()) return 0.0;
    $setA = array_fill_keys($ta, true);
    $setB = array_fill_keys($tb, true);
    $inter = 0;
    foreach ($setA as $k => $_) {
        if (isset($setB[$k])) $inter++;
    }
    $union = count($setA) + count($setB) - $inter;
    if ($union <= 0) return 0.0;
    return (float)$inter / (float)$union;
}

function casual_title_too_similar_to_recent(string $candidateTitle, array $recentTitles): bool
{
    $dup = konvo_soul_topic_is_duplicate($candidateTitle, '', array(), $recentTitles);
    return !empty($dup['duplicate']);
}

function casual_topic_too_similar(string $candidateTitle, string $candidateRaw, array $recentLocal, array $recentForumTitles): array
{
    return konvo_soul_topic_is_duplicate($candidateTitle, $candidateRaw, $recentLocal, $recentForumTitles);
}

function casual_topic_text_terms(string $title, string $raw = ''): array
{
    $blob = trim($title . "\n" . $raw);
    if ($blob === '') return array();
    $blob = preg_replace('/```[\s\S]*?```/m', ' ', (string)$blob) ?? $blob;
    $blob = preg_replace('/https?:\/\/\S+/i', ' ', (string)$blob) ?? $blob;
    $blob = preg_replace('/[^a-z0-9\s]/i', ' ', strtolower((string)$blob)) ?? strtolower((string)$blob);
    $blob = preg_replace('/\s+/', ' ', (string)$blob) ?? $blob;
    return casual_title_terms($blob);
}

function casual_semantic_similarity(string $titleA, string $rawA, string $titleB, string $rawB = ''): float
{
    $a = casual_topic_text_terms($titleA, $rawA);
    $b = casual_topic_text_terms($titleB, $rawB);
    if ($a === array() || $b === array()) return 0.0;
    $setA = array_fill_keys($a, true);
    $setB = array_fill_keys($b, true);
    $inter = 0;
    foreach ($setA as $k => $_) {
        if (isset($setB[$k])) $inter++;
    }
    $union = count($setA) + count($setB) - $inter;
    if ($union <= 0) return 0.0;
    return (float)$inter / (float)$union;
}

function casual_candidate_too_close_to_recent_local(string $candidateTitle, string $candidateRaw, array $recentLocal): bool
{
    $dup = konvo_soul_topic_is_duplicate($candidateTitle, $candidateRaw, $recentLocal, array());
    return !empty($dup['duplicate']);
}

function casual_uniqueness_gate_enabled(): bool
{
    $env = strtolower(trim((string)getenv('KONVO_TOPIC_UNIQUENESS_GATE')));
    return in_array($env, array('1', 'true', 'yes', 'on'), true);
}

function casual_uniqueness_gate_with_llm(string $candidateTitle, string $candidateRaw, array $recentLocal, array $recentForum): array
{
    if (!casual_uniqueness_gate_enabled()) {
        return array('ok' => true, 'passes' => true, 'score' => 5.0, 'reason' => 'uniqueness_gate_disabled');
    }
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => true, 'passes' => true, 'score' => 3.5, 'reason' => 'no_api_key_skip');
    }

    $localLines = casual_recent_hint_lines($recentLocal);
    $forumLines = array();
    $max = min(35, count($recentForum));
    for ($i = 0; $i < $max; $i++) {
        $t = trim((string)($recentForum[$i] ?? ''));
        if ($t === '') continue;
        $forumLines[] = '- ' . $t;
    }
    $forumHints = $forumLines === array() ? '(none)' : implode("\n", $forumLines);

    $system = 'You are a strict novelty judge for forum topic proposals. '
        . 'Return ONLY JSON with schema: {"passes":true|false,"novelty_score":0-5,"reason":"...","closest_match":"...","rewrite_hint":"..."}. '
        . 'Pass only when the candidate is clearly different in topic angle from ALL recent topics. '
        . 'Reject if same subject with reworded title, same structure, same examples, or same core argument. '
        . 'For Chinese posts, compare meaning not just characters.';
    $user = "Candidate title:\n{$candidateTitle}\n\nCandidate body:\n{$candidateRaw}\n\n"
        . "Recent topics from this worker:\n{$localLines}\n\n"
        . "Recent forum topics:\n{$forumHints}\n\n"
        . "Judge novelty now.";
    $payload = array(
        'model' => konvo_model_for_task('topic_uniqueness'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
    );

    $res = casual_openai_json($payload);
    if (!$res['ok']) {
        return array('ok' => true, 'passes' => true, 'score' => 3.5, 'reason' => 'uniqueness_llm_error_fail_open');
    }
    $content = trim((string)($res['json']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return array('ok' => true, 'passes' => true, 'score' => 3.5, 'reason' => 'uniqueness_empty_fail_open');
    }
    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return array('ok' => true, 'passes' => true, 'score' => 3.5, 'reason' => 'uniqueness_parse_error_fail_open');
    }
    $passes = !empty($obj['passes']);
    $score = (float)($obj['novelty_score'] ?? 0.0);
    if ($score < 0.0) $score = 0.0;
    if ($score > 5.0) $score = 5.0;
    $reason = trim((string)($obj['reason'] ?? ''));
    $closest = trim((string)($obj['closest_match'] ?? ''));
    $hint = trim((string)($obj['rewrite_hint'] ?? ''));
    return array(
        'ok' => true,
        'passes' => $passes && $score >= 3.5,
        'score' => $score,
        'reason' => $reason === '' ? 'no_reason' : $reason,
        'closest_match' => $closest,
        'rewrite_hint' => $hint,
    );
}

function casual_pick_bot(array $bots): array
{
    if ($bots === array()) {
        return array('username' => 'BAI', 'name' => 'BAI', 'soul_key' => 'bai', 'soul_fallback' => 'Write naturally, concise, and human.');
    }
    return $bots[0];
}

function casual_find_bot(array $bots, string $username): ?array
{
    $u = strtolower(trim($username));
    foreach ($bots as $bot) {
        $bu = strtolower(trim((string)($bot['username'] ?? '')));
        if ($bu !== '' && $bu === $u) return $bot;
    }
    return null;
}

function casual_pick_category_id_for_lane(array $lane): int
{
    $forcedCategoryId = (int)($_GET['category_id'] ?? 0);
    if ($forcedCategoryId > 0) {
        return $forcedCategoryId;
    }

    $forced = strtolower(trim((string)($_GET['category'] ?? '')));
    if ($forced === 'history' || $forced === 'historical' || $forced === 'history_long_river') {
        return (int)KONVO_HISTORY_CATEGORY_ID;
    }
    if ($forced === 'chat' || $forced === 'talk') {
        return (int)KONVO_CHAT_CATEGORY_ID;
    }

    $laneKey = strtolower(trim((string)($lane['key'] ?? '')));
    if (in_array($laneKey, array('sci_fi_ai', 'games'), true) && konvo_bot_registry_pick_for_category((int)KONVO_HISTORY_CATEGORY_ID) !== null) {
        return (int)KONVO_HISTORY_CATEGORY_ID;
    }
    $defaultBot = konvo_bot_registry_pick_for_category((int)KONVO_CHAT_CATEGORY_ID);
    if (is_array($defaultBot)) {
        return (int)KONVO_CHAT_CATEGORY_ID;
    }
    $enabled = konvo_bot_registry_enabled();
    if (is_array($enabled[0] ?? null)) {
        return (int)($enabled[0]['category_id'] ?? KONVO_CHAT_CATEGORY_ID);
    }
    return (int)KONVO_CHAT_CATEGORY_ID;
}

function casual_bot_for_category(int $categoryId, array $bots): array
{
    $picked = konvo_bot_registry_pick_for_category($categoryId);
    if (is_array($picked)) {
        return $picked;
    }
    return casual_pick_bot($bots);
}

function casual_is_gaming_topic(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    if (!preg_match('/\b(video game|gaming|gameplay|trailer|clip|dlc|patch|xbox|playstation|ps5|ps4|nintendo|switch|steam|epic games|riot games|blizzard|ubisoft|capcom|fromsoftware|fortnite|minecraft|valorant|league of legends|rpg|fps|mmo|easter egg)\b/i', $t)) {
        return false;
    }
    // Keep obvious entertainment/movie chatter out of gaming category.
    if (preg_match('/\b(movie|film|tv show|television|box office|actor|actress|hollywood)\b/i', $t) && !preg_match('/\b(video game|gameplay|console|pc game)\b/i', $t)) {
        return false;
    }
    return true;
}

function casual_is_design_topic(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;

    $physical = (bool)preg_match('/\b(architecture|architect|building|house|home|interior|pavilion|tower|skyscraper|museum|gallery|facade|façade|renovation|landscape architecture|urban planning|studio|residence)\b/i', $t);
    $uiux = (bool)preg_match('/\b(ui|ux|user interface|user experience|interaction design|visual design|design system|wireframe|prototype|figma|typography|color palette)\b/i', $t);
    if (!$physical && !$uiux) return false;
    if (!$physical && preg_match('/\b(system design|api design|database design|software architecture|computer architecture|backend architecture|technical design)\b/i', $t)) {
        return false;
    }
    return true;
}

function casual_is_allowed_topic_scope(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match(
        '/\b(ai|artificial intelligence|llm|language model|chatbot|agentic|automation|technology|tech|software|internet|web|browser|workflow|online community|social web|machine creativity|generative|video game|gaming|gameplay|player|npc|difficulty|level design|speedrun|retro game|science fiction|sci[- ]?fi|cyberpunk|space opera|futurism|product strategy|go to market|go-to-market|pricing|margin|hiring|business model|ux|ui|design system|creative process|developer experience|engineering culture|debugging|code review|product team)\b/i',
        $t
    );
}

function casual_has_depth_signal(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match(
        '/\b(tradeoff|trade-off|tension|constraint|second-order|side effect|friction|habit|workflow|trust|taste|craft|attention|memory|ownership|signal|meaning|quality|defaults|intuition|identity|abstraction|cost of convenience|creative process|human side|community|mastery|difficulty curve|discoverability|pricing pressure|hiring signal|creative control|player agency|team dynamics)\b/i',
        $t
    );
}

function casual_title_looks_question_like(string $title): bool
{
    $t = strtolower(trim($title));
    if ($t === '') return false;
    if (str_contains($t, '?')) return true;
    if (preg_match('/^(what|why|how|when|where|who|which|is|are|can|could|should|would|do|does|did|will|have|has|had)\b/i', $t)) return true;
    return false;
}

function casual_ensure_question_mark_title(string $title): string
{
    $title = trim($title);
    if ($title === '') return $title;
    if (!casual_title_looks_question_like($title)) return $title;
    $title = preg_replace('/[.!:;,\-]+$/', '', $title) ?? $title;
    $title = rtrim($title);
    if (!str_ends_with($title, '?')) $title .= '?';
    return $title;
}

function casual_normalize_title(string $title): string
{
    $title = konvo_soul_sanitize_utf8(trim(strip_tags($title)));
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    $title = trim($title, " \t\n\r\0\x0B\"'`");
    if ($title === '') {
        return '';
    }
    $maxLen = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    if ($maxLen > 60) {
        if (function_exists('mb_substr')) {
            $title = trim((string)mb_substr($title, 0, 60, 'UTF-8'));
        } else {
            $title = casual_safe_substr($title, 180);
        }
    }
    $title = preg_replace('/[:;,\.\-]+$/u', '', $title) ?? $title;
    return trim($title);
}

function casual_normalize_signature(string $text, string $signature): string
{
    $candidates = function_exists('konvo_signature_name_candidates')
        ? konvo_signature_name_candidates($signature)
        : array($signature);
    if (!is_array($candidates) || count($candidates) === 0) $candidates = array($signature);

    $lines = preg_split('/\R/', trim((string)$text));
    if (!is_array($lines)) $lines = array();
    while (!empty($lines)) {
        $last = trim((string)end($lines));
        $matched = false;
        foreach ($candidates as $candidate) {
            if (preg_match('/^' . preg_quote((string)$candidate, '/') . '\\.?$/i', $last)) {
                $matched = true;
                break;
            }
        }
        if ($last === '' || $matched) {
            array_pop($lines);
            continue;
        }
        break;
    }

    $body = trim(implode("\n", $lines));
    foreach ($candidates as $candidate) {
        $body = preg_replace('/\s+' . preg_quote((string)$candidate, '/') . '\\.?$/i', '', (string)$body) ?? $body;
    }
    $body = trim((string)$body);
    if ($body === '') return '';
    return $body;
}

function casual_quirky_media_urls(): array
{
    return array(
        'https://media.giphy.com/media/5VKbvrjxpVJCM/giphy.gif',
        'https://media.giphy.com/media/13CoXDiaCcCoyk/giphy.gif',
        'https://media.giphy.com/media/l0HlBO7eyXzSZkJri/giphy.gif',
        'https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif',
        'https://media.giphy.com/media/26ufdipQqU2lhNA4g/giphy.gif',
        'https://media.giphy.com/media/3o7aCTfyhYawdOXcFW/giphy.gif',
        'https://media.giphy.com/media/l3q2K5jinAlChoCLS/giphy.gif',
    );
}

function casual_media_url_is_reachable(string $url): bool
{
    $u = trim($url);
    if ($u === '' || !preg_match('/^https?:\/\/\S+$/i', $u)) return false;
    static $cache = array();
    if (isset($cache[$u])) return (bool)$cache[$u];

    if (!function_exists('curl_init')) {
        $cache[$u] = false;
        return false;
    }

    $ch = curl_init($u);
    curl_setopt_array($ch, array(
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'konvo-casual-worker/1.0',
    ));
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    $err = curl_error($ch);
    curl_close($ch);

    $ok = ($err === '' && $status >= 200 && $status < 400 && preg_match('/\b(image|video)\b/i', $ctype));
    $cache[$u] = (bool)$ok;
    return (bool)$ok;
}

function casual_pick_quirky_media_url(string $seed): string
{
    $urls = casual_quirky_media_urls();
    if ($urls === array()) return '';
    $hash = abs((int)crc32(strtolower(trim($seed))));
    $count = count($urls);
    $start = $hash % $count;
    for ($i = 0; $i < $count; $i++) {
        $idx = ($start + $i) % $count;
        $cand = trim((string)$urls[$idx]);
        if ($cand !== '' && casual_media_url_is_reachable($cand)) {
            return $cand;
        }
    }
    return '';
}

function casual_append_quirky_media_before_signature(string $raw, string $signature, string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('/^https?:\/\/\S+$/i', $url)) {
        return casual_normalize_signature($raw, $signature);
    }
    $norm = casual_normalize_signature($raw, $signature);
    if (!preg_match('/https?:\/\/\S+/i', $norm)) {
        $norm = trim($norm) . "\n\n" . $url;
    }
    return casual_normalize_signature($norm, $signature);
}

function casual_normalize_body(string $raw, string $signature): string
{
    $raw = konvo_soul_fix_inline_newlines((string)$raw);
    if ($raw === '') {
        return '';
    }
    return casual_normalize_signature($raw, $signature);
}

function casual_has_controversial_signals(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    $patterns = array(
        '/\b(politic|election|democrat|republican|senate|president|trump|biden|left wing|right wing)\b/i',
        '/\b(war|genocide|military conflict|terror|terrorism|weapon)\b/i',
        '/\b(religion|god|church|islam|christian|hindu|jewish|bible|quran)\b/i',
        '/\b(abortion|immigration|racism|sexism|sexual assault|violence|crime)\b/i',
        '/\b(vaccine|pandemic|covid|disease outbreak|public health emergency)\b/i',
        '/\b(stock pick|crypto pump|betting|gambling tip)\b/i',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, $t)) return true;
    }
    return false;
}

function casual_looks_too_technical(string $text): bool
{
    $t = strtolower(trim($text));
    if ($t === '') return false;
    return (bool)preg_match('/\b(javascript|typescript|css|html|react|vue|angular|api endpoint|database schema|backend|frontend|docker|kubernetes|ci\/cd|compiler|runtime|stack trace|queryselector|npm|package\.json|php warning|sql query)\b/i', $t);
}

function casual_extract_json_object(string $content): array
{
    $content = trim($content);
    if ($content === '') return array();

    if ($content[0] === '{') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return array();

    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : array();
}

function casual_llm_timeout_seconds(array $rules): int
{
    return konvo_soul_topic_llm_timeout($rules);
}

function casual_openai_json(array $payload, array $rules = array()): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'json' => array(), 'raw' => '');
    }

    $ch = curl_init(KONVO_LLM_CHAT_COMPLETIONS_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => casual_llm_timeout_seconds($rules),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . KONVO_OPENAI_API_KEY,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return array('ok' => false, 'status' => $status, 'error' => $err, 'json' => array(), 'raw' => '');
    }

    $decoded = json_decode((string)$body, true);
    return array(
        'ok' => ($status >= 200 && $status < 300 && is_array($decoded)),
        'status' => $status,
        'error' => '',
        'json' => is_array($decoded) ? $decoded : array(),
        'raw' => konvo_soul_sanitize_utf8((string)$body),
    );
}

function casual_pick_category_with_llm(string $title, string $raw, array $bot = array(), array $plan = array()): array
{
    $fallback = array(
        'ok' => false,
        'category_key' => 'talk',
        'category_id' => (int)KONVO_TALK_CATEGORY_ID,
        'reason' => 'category_llm_unavailable_fallback_talk',
        'confidence' => 0.0,
    );
    if (KONVO_OPENAI_API_KEY === '') {
        return $fallback;
    }

    $botName = trim((string)($bot['name'] ?? 'BAI'));
    $planMood = trim((string)($plan['mood'] ?? ''));
    $planAngle = trim((string)($plan['angle'] ?? ''));
    $planIntent = trim((string)($plan['posting_intent'] ?? ''));

    $system = 'Classify this forum topic into one category and return JSON only. '
        . 'Schema: {"category":"talk|web_dev|design|gaming","reason":"...","confidence":0.0}. '
        . 'Category rules: '
        . 'talk = broad thoughtful discussion about AI, technology, digital life, online communities, or creative tools that is not a coding help thread. '
        . 'web_dev = programming/software engineering/web development/technical architecture in software. '
        . 'design = UI/UX/visual design OR physical architecture/interior design. '
        . 'gaming = video games/gameplay/trailers/game culture. '
        . 'Important: software contexts using words like build/building/design/architecture/system belong to web_dev, not design. '
        . 'Pick exactly one category.';
    $user = "Topic title:\n{$title}\n\n"
        . "Topic body:\n{$raw}\n\n"
        . "Bot: {$botName}\n"
        . "Plan mood: {$planMood}\n"
        . "Plan angle: {$planAngle}\n"
        . "Plan intent: {$planIntent}\n\n"
        . "Return JSON now.";

    $payload = array(
        'model' => konvo_model_for_task('topic_category'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
    );

    $res = casual_openai_json($payload);
    if (!$res['ok']) {
        return $fallback;
    }
    $json = $res['json'];
    $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        return $fallback;
    }
    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return $fallback;
    }

    $key = strtolower(trim((string)($obj['category'] ?? '')));
    $map = array(
        'talk' => (int)KONVO_TALK_CATEGORY_ID,
        'general' => (int)KONVO_TALK_CATEGORY_ID,
        'web_dev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'webdev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'web-dev' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'programming' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'technical' => (int)KONVO_WEBDEV_CATEGORY_ID,
        'design' => (int)KONVO_DESIGN_CATEGORY_ID,
        'gaming' => (int)KONVO_GAMING_CATEGORY_ID,
        'games' => (int)KONVO_GAMING_CATEGORY_ID,
    );
    if (!isset($map[$key])) {
        return $fallback;
    }

    $categoryId = (int)$map[$key];
    $normalizedKey = 'talk';
    if ($categoryId === (int)KONVO_WEBDEV_CATEGORY_ID) $normalizedKey = 'web_dev';
    if ($categoryId === (int)KONVO_DESIGN_CATEGORY_ID) $normalizedKey = 'design';
    if ($categoryId === (int)KONVO_GAMING_CATEGORY_ID) $normalizedKey = 'gaming';

    $confidence = (float)($obj['confidence'] ?? 0.0);
    if ($confidence < 0.0) $confidence = 0.0;
    if ($confidence > 1.0) $confidence = 1.0;
    $reason = trim((string)($obj['reason'] ?? ''));
    if ($reason === '') $reason = 'llm_category_decision';

    return array(
        'ok' => true,
        'category_key' => $normalizedKey,
        'category_id' => $categoryId,
        'reason' => $reason,
        'confidence' => $confidence,
    );
}

function casual_seed_topic_pool(): array
{
    return array(
        '一个长期但常被忽略的结构性问题',
        '一个常见误解与现实之间的差距',
        '同一制度在不同时期为何效果不同',
        '局部优化与整体结果之间的错位',
        '表面现象背后的治理与激励逻辑',
        '个人体验与系统约束之间的张力',
        '看似偶然现象背后的长期趋势',
        '跨时期比较里最值得重看的变量',
    );
}

function casual_pick_random_seed_topic(array $recentLocal, array $recentForumTitles, array $pool = array()): string
{
    if ($pool === array()) {
        $pool = casual_seed_topic_pool();
    }
    $recentTitles = array();
    foreach ($recentLocal as $item) {
        if (!is_array($item)) continue;
        $t = trim((string)($item['title'] ?? ''));
        if ($t !== '') $recentTitles[] = $t;
    }
    foreach ($recentForumTitles as $t) {
        $t = trim((string)$t);
        if ($t !== '') $recentTitles[] = $t;
        if (count($recentTitles) >= 80) break;
    }
    $candidates = array();
    foreach ($pool as $seed) {
        $seed = trim((string)$seed);
        if ($seed === '') continue;
        $tooClose = false;
        foreach ($recentTitles as $rt) {
            if (casual_title_too_similar_to_recent($seed, array($rt))) {
                $tooClose = true;
                break;
            }
        }
        if (!$tooClose) $candidates[] = $seed;
    }
    if ($candidates === array()) $candidates = $pool;
    shuffle($candidates);
    return (string)$candidates[0];
}

function casual_generate_with_llm(array $bot, string $signature, array $recent, array $recentForumTitles, bool $strict, string $extraAvoidance = '', array $lane = array(), int $categoryId = 0): array
{
    $soulPrompt = konvo_soul_prompt_for_topic($bot);
    $rules = konvo_soul_parse_topic_rules($soulPrompt);
    $recentHints = casual_recent_hint_lines($recent);
    $recentOpeningHints = casual_recent_opening_stems($recent, 14);
    $laneKey = strtolower(trim((string)($lane['key'] ?? 'general')));
    $seedPool = konvo_soul_default_seed_pool($soulPrompt, $rules);
    $seedTopic = casual_pick_random_seed_topic($recent, $recentForumTitles, $seedPool);

    if (konvo_soul_two_stage_enabled($rules)) {
        $pipe = konvo_soul_topic_pipeline_generate(
            $soulPrompt,
            $rules,
            $seedTopic,
            $recentHints . ($recentOpeningHints !== '' ? "\n" . $recentOpeningHints : ''),
            $strict,
            $extraAvoidance,
            $categoryId,
            $laneKey,
            static fn(string $t) => casual_normalize_title($t),
            static fn(string $b) => casual_normalize_body($b, $signature)
        );
        if (!empty($pipe['ok'])) {
            return array(
                'ok' => true,
                'title' => (string)$pipe['title'],
                'raw' => (string)$pipe['raw'],
                'plan' => is_array($pipe['plan'] ?? null) ? $pipe['plan'] : array(),
                'soul_rules' => $rules,
                'pipeline' => (string)($pipe['pipeline'] ?? 'two_stage_v15'),
                'fact_judge' => $pipe['fact_judge'] ?? null,
                'paragraph_count' => (int)($pipe['paragraph_count'] ?? 0),
                'han_chars' => (int)($pipe['han_chars'] ?? 0),
            );
        }
        return array(
            'ok' => false,
            'error' => (string)($pipe['error'] ?? 'pipeline failed'),
            'stage' => (string)($pipe['stage'] ?? ''),
            'title' => (string)($pipe['title'] ?? ''),
            'raw' => (string)($pipe['raw'] ?? ''),
            'han_chars' => isset($pipe['han_chars']) ? (int)$pipe['han_chars'] : null,
            'validation' => $pipe['validation'] ?? null,
            'fact_judge' => $pipe['fact_judge'] ?? null,
            'hint' => (string)($pipe['hint'] ?? ''),
        );
    }

    $system = konvo_soul_build_topic_system_prompt($soulPrompt, $rules, $categoryId);
    $user = konvo_soul_build_topic_user_prompt(
        $seedTopic,
        $rules,
        $recentHints,
        $recentOpeningHints,
        $strict,
        $extraAvoidance
    );

    $isZh = (($rules['language'] ?? 'any') === 'zh');
    $payload = array(
        'model' => konvo_model_for_task('casual_topic'),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => $isZh ? 0.55 : 0.95,
        'max_tokens' => !empty($rules['longform']) ? 3200 : 1200,
    );
    if ($isZh) {
        $payload['response_format'] = array('type' => 'json_object');
    }

    $res = casual_openai_json($payload, $rules);
    if (!$res['ok'] && $isZh && !empty($payload['response_format'])) {
        unset($payload['response_format']);
        $res = casual_openai_json($payload, $rules);
    }
    if (!$res['ok']) {
        return array(
            'ok' => false,
            'error' => 'OpenAI request failed',
            'detail' => $res['error'],
            'status' => $res['status'],
            'llm_snippet' => casual_safe_substr((string)($res['raw'] ?? ''), 240),
        );
    }

    $json = $res['json'];
    $content = konvo_soul_sanitize_utf8(trim((string)($json['choices'][0]['message']['content'] ?? '')));
    if ($content === '') {
        return array('ok' => false, 'error' => 'Model returned empty content');
    }

    $obj = casual_extract_json_object($content);
    if (!is_array($obj) || $obj === array()) {
        return array(
            'ok' => false,
            'error' => 'Model returned non-JSON content',
            'raw' => casual_safe_substr($content, 400),
            'llm_snippet' => casual_safe_substr($content, 240),
        );
    }

    $title = casual_normalize_title((string)($obj['title'] ?? ''));
    $raw = casual_normalize_body((string)($obj['raw'] ?? ''), $signature);
    $planMood = trim((string)($obj['plan_mood'] ?? ''));
    $planAngle = trim((string)($obj['plan_angle'] ?? ''));
    $planIntent = trim((string)($obj['plan_posting_intent'] ?? ''));
    $planLane = trim((string)($obj['plan_lane'] ?? $laneKey));

    if ($title === '' || $raw === '') {
        return array('ok' => false, 'error' => 'Model JSON missing title/raw', 'parsed' => $obj);
    }

    $prepared = konvo_soul_prepare_topic($title, $raw, $rules);
    $title = $prepared['title'];
    $raw = $prepared['raw'];

    $valid = konvo_soul_validate_hard($title, $raw, $rules);
    if (!$valid['ok']) {
        return array(
            'ok' => false,
            'error' => (string)($valid['error'] ?? 'validation failed'),
            'title' => $title,
            'raw' => $raw,
            'han_chars' => konvo_soul_count_han_chars($raw),
            'latin_chars' => konvo_soul_count_latin_chars($title . "\n" . $raw),
            'validation' => $valid,
            'llm_snippet' => casual_safe_substr($content, 240),
        );
    }
    $judge = konvo_soul_fact_judge($title, $raw, $soulPrompt);
    if (!empty($judge['ok']) && empty($judge['publishable'])) {
        return array(
            'ok' => false,
            'error' => 'fact judge rejected (legacy single-stage path)',
            'fact_judge' => $judge,
            'title' => $title,
            'raw' => $raw,
            'han_chars' => konvo_soul_count_han_chars($raw),
        );
    }

    return array(
        'ok' => true,
        'title' => $title,
        'raw' => $raw,
        'plan' => array(
            'mood' => $planMood,
            'angle' => $planAngle,
            'posting_intent' => $planIntent,
            'lane' => $planLane,
            'seed_topic' => $seedTopic,
        ),
        'soul_rules' => $rules,
    );
}

function casual_template_fallback(array $bot, int $categoryId, string $seedTopic = ''): array
{
    $soulPrompt = konvo_soul_prompt_for_topic($bot);
    $rules = konvo_soul_parse_topic_rules($soulPrompt);
    return konvo_soul_topic_fallback($bot, $rules, $seedTopic);
}

function casual_post_topic(string $botUsername, string $title, string $raw, int $categoryId): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }

    $postAs = trim($botUsername);
    if ($postAs === '') {
        return array('ok' => false, 'status' => 0, 'error' => 'No Api-Username configured for posting.', 'body' => array(), 'raw' => '');
    }

    $title = konvo_soul_sanitize_utf8(trim($title));
    $raw = konvo_soul_fix_inline_newlines(trim($raw));
    $categoryId = max(0, (int)$categoryId);

    if ($title === '' || konvo_soul_count_han_chars($title) < 4) {
        return array(
            'ok' => false,
            'status' => 0,
            'error' => 'post_blocked_invalid_title',
            'body' => array(),
            'raw' => '',
            'post_debug' => array(
                'title_preview' => casual_safe_substr($title, 80),
                'title_han' => konvo_soul_count_han_chars($title),
                'category_id' => $categoryId,
            ),
        );
    }
    if ($categoryId <= 0) {
        return array(
            'ok' => false,
            'status' => 0,
            'error' => 'post_blocked_invalid_category',
            'body' => array(),
            'raw' => '',
            'post_debug' => array('category_id' => $categoryId),
        );
    }

    $payload = array(
        'title' => $title,
        'raw' => $raw,
        'category' => $categoryId,
    );

    $jsonBody = casual_json_encode($payload);
    if ($jsonBody === '' || $jsonBody === '{"ok":false,"error":"json_encode_failed"}') {
        return array(
            'ok' => false,
            'status' => 0,
            'error' => 'post_payload_json_encode_failed',
            'body' => array(),
            'raw' => '',
            'post_debug' => array(
                'title_preview' => casual_safe_substr($title, 80),
                'title_han' => konvo_soul_count_han_chars($title),
                'raw_han' => konvo_soul_count_han_chars($raw),
                'category_id' => $categoryId,
                'json_last_error' => function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown',
            ),
        );
    }

    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json; charset=utf-8',
            'Api-Key: ' . KONVO_API_KEY,
            'Api-Username: ' . $postAs,
        ),
        CURLOPT_POSTFIELDS => $jsonBody,
    ));

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string)$body, true);
    return array(
        'ok' => ($err === '' && $status >= 200 && $status < 300 && is_array($decoded)),
        'status' => $status,
        'error' => $err,
        'post_as' => $postAs,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    casual_out(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !safe_hash_equals(KONVO_SECRET, $providedKey)) {
    casual_out(403, array(
        'ok' => false,
        'error' => 'Forbidden',
        'hint' => 'Pass ?key=YOUR_SECRET',
        'worker_build' => (string)KONVO_WORKER_BUILD,
    ));
}

if (isset($_GET['ping']) && (string)$_GET['ping'] === '1') {
    $hanTest = konvo_soul_count_han_chars('中国历史科普测试');
    $pingRules = konvo_soul_parse_topic_rules(konvo_load_soul('bai', ''));
    casual_out(200, array(
        'ok' => true,
        'ping' => true,
        'worker_build' => (string)KONVO_WORKER_BUILD,
        'han_count_test' => $hanTest,
        'mbstring' => function_exists('mb_substr'),
        'llm_key_set' => KONVO_OPENAI_API_KEY !== '',
        'two_stage_pipeline' => konvo_soul_two_stage_enabled($pingRules),
        'fact_judge' => konvo_soul_fact_judge_enabled(),
        'max_execution_time' => (int)ini_get('max_execution_time'),
        'iconv' => function_exists('iconv'),
        'files' => array(
            'konvo_soul_topic_helper.php' => is_file(__DIR__ . '/konvo_soul_topic_helper.php'),
            'konvo_soul_topic_pipeline.php' => is_file(__DIR__ . '/konvo_soul_topic_pipeline.php'),
            'souls/bai.SOUL.md' => is_file(__DIR__ . '/souls/bai.SOUL.md'),
            'souls/higuyer.SOUL.md' => is_file(__DIR__ . '/souls/higuyer.SOUL.md'),
        ),
    ));
}
if (KONVO_API_KEY === '') {
    casual_out(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}
if (KONVO_OPENAI_API_KEY === '') {
    casual_out(500, array('ok' => false, 'error' => 'OPENAI_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$allowNewTopicsEnv = strtolower(trim((string)getenv('KONVO_ALLOW_NEW_TOPICS')));
$allowNewTopics = in_array($allowNewTopicsEnv, array('1', 'true', 'yes', 'on'), true);
$allowCasualEnv = strtolower(trim((string)KONVO_ALLOW_CASUAL_TOPIC_POSTS));
$allowCasualTopics = ($allowCasualEnv === '')
    ? true
    : in_array($allowCasualEnv, array('1', 'true', 'yes', 'on'), true);
$allowPosting = $allowNewTopics || $allowCasualTopics;

if (!$dryRun && !$allowPosting && !$force) {
    casual_out(200, array(
        'ok' => true,
        'posted' => false,
        'reason' => 'new_topic_creation_disabled',
        'hint' => 'Set KONVO_ALLOW_CASUAL_TOPIC_POSTS=1 (or KONVO_ALLOW_NEW_TOPICS=1) or pass force=1 to override.',
    ));
}

$recent = casual_load_recent_topics();
$lane = casual_pick_interest_lane($recent);
$laneOverride = trim((string)($_GET['lane'] ?? ''));
if ($laneOverride !== '') {
    $over = casual_lane_from_key($laneOverride, $recent);
    if (is_array($over)) {
        $lane = $over;
    }
}
$categoryId = casual_pick_category_id_for_lane($lane);
$bot = casual_bot_for_category($categoryId, $bots);
$soulPromptRun = konvo_soul_prompt_for_topic($bot);
$soulRulesRun = konvo_soul_parse_topic_rules($soulPromptRun);
$soulKeyRun = trim((string)($bot['soul_key'] ?? strtolower((string)($bot['username'] ?? ''))));
$soulPathRun = __DIR__ . '/souls/' . konvo_normalize_soul_key($soulKeyRun) . '.SOUL.md';
if (strlen(trim($soulPromptRun)) < 80) {
    casual_out(500, array(
        'ok' => false,
        'error' => 'SOUL file missing or too short for this bot.',
        'bot' => $bot,
        'expected_soul_path' => $soulPathRun,
        'hint' => 'Upload souls/*.SOUL.md to the server or save SOUL content in konvo_bot_admin.php.',
    ));
}
$signatureSeed = strtolower((string)($bot['username'] ?? 'bai') . '|casual-topic|' . date('Y-m-d-H'));
$signature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BAI'), $signatureSeed)
    : (string)($bot['name'] ?? 'BAI');
$today = casual_today_key();
$dailyCap = (int)KONVO_CASUAL_DAILY_CAP;
if (!$dryRun && !$force && $dailyCap > 0) {
    $todayCount = casual_daily_count_for($today);
    if ($todayCount >= $dailyCap) {
        casual_out(200, array(
            'ok' => true,
            'posted' => false,
            'reason' => 'daily_casual_topic_cap_reached',
            'date' => $today,
            'today_post_count' => $todayCount,
            'daily_cap' => $dailyCap,
            'hint' => 'Pass force=1 to bypass, or set KONVO_CASUAL_DAILY_CAP=0 in .env for no limit.',
        ));
    }
}
$recentForumTitles = casual_fetch_latest_topic_titles(100, $categoryId);

$attempts = array();
$generated = null;
$extraAvoidance = '';
$topicModeRun = konvo_soul_two_stage_enabled($soulRulesRun) ? 'two_stage_pipeline' : (!empty($soulRulesRun['longform']) ? 'soul_longform' : 'soul');
$requestStartTs = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
$fastMode = (bool)KONVO_TOPIC_FAST_MODE;
$maxAttempts = $fastMode ? 2 : 3;
$requestBudget = !empty($soulRulesRun['longform']) ? 360.0 : 55.0;

for ($i = 0; $i < $maxAttempts; $i++) {
    if ((microtime(true) - $requestStartTs) > $requestBudget) {
        break;
    }
    $strict = $i > 0;
    $res = casual_generate_with_llm($bot, $signature, $recent, $recentForumTitles, $strict, $extraAvoidance, $lane, $categoryId);
    if (!empty($res['ok'])) {
        $dup = casual_topic_too_similar(
            (string)($res['title'] ?? ''),
            (string)($res['raw'] ?? ''),
            $recent,
            $recentForumTitles
        );
        if (!empty($dup['duplicate'])) {
            $res = array(
                'ok' => false,
                'error' => 'topic too similar to recent posts',
                'duplicate' => $dup,
                'title' => (string)($res['title'] ?? ''),
            );
        }
    }
    $attempts[] = $res;
    if (!empty($res['ok'])) {
        $generated = $res;
        break;
    }
    $err = trim((string)($res['error'] ?? ''));
    $gateHint = '';
    $closest = is_array($res['duplicate'] ?? null) ? trim((string)($res['duplicate']['closest_title'] ?? '')) : '';
    if ($closest === '' && is_array($res['fact_judge'] ?? null)) {
        $closest = trim((string)($res['fact_judge']['rewrite_hint'] ?? ''));
    }
    $pieces = array();
    if ($err !== '') {
        $retryHint = konvo_soul_retry_hint_for_error($err);
        $pieces[] = $retryHint !== '' ? $retryHint : $err;
    }
    if ($closest !== '') $pieces[] = 'Too close to: ' . $closest;
    if ($gateHint !== '') $pieces[] = 'Rewrite hint: ' . $gateHint;
    $extraAvoidance = implode(' ', $pieces);
}

if (!is_array($generated) || empty($generated['ok'])) {
    $errors = array();
    $attemptDetails = array();
    foreach ($attempts as $a) {
        $errors[] = isset($a['error']) ? (string)$a['error'] : 'unknown generation failure';
        $attemptDetails[] = array(
            'error' => isset($a['error']) ? (string)$a['error'] : 'unknown generation failure',
            'han_chars' => isset($a['han_chars']) ? (int)$a['han_chars'] : null,
            'latin_chars' => isset($a['latin_chars']) ? (int)$a['latin_chars'] : null,
            'title_preview' => isset($a['title']) ? casual_safe_substr((string)$a['title'], 80) : '',
            'raw_preview' => isset($a['raw']) ? casual_safe_substr((string)$a['raw'], 160) : '',
            'llm_snippet' => isset($a['llm_snippet']) ? (string)$a['llm_snippet'] : '',
            'llm_status' => isset($a['status']) ? (int)$a['status'] : null,
            'stage' => isset($a['stage']) ? (string)$a['stage'] : '',
            'fact_judge' => $a['fact_judge'] ?? null,
        );
    }
    casual_out(200, array(
        'ok' => false,
        'posted' => false,
        'error' => 'Failed to generate a unique SOUL-compliant topic; nothing was posted.',
        'attempt_errors' => $errors,
        'attempt_details' => $attemptDetails,
        'attempt_count' => count($attempts),
        'fast_mode' => $fastMode,
        'worker_build' => (string)KONVO_WORKER_BUILD,
        'soul_rules' => $soulRulesRun,
        'soul_loaded' => strlen($soulPromptRun) > 0,
        'soul_chars' => strlen($soulPromptRun),
        'elapsed_seconds' => round(microtime(true) - $requestStartTs, 2),
        'hint' => 'Pipeline v15: two-stage generate → prepare → validate_hard → fact_judge → dup → post. Set KONVO_TOPIC_TWO_STAGE=0 to disable.',
    ));
}

if (!empty($generated['fallback'])) {
    casual_out(500, array(
        'ok' => false,
        'error' => 'Template fallback is disabled; refusing to post boilerplate content.',
        'worker_build' => (string)KONVO_WORKER_BUILD,
    ));
}

$title = (string)$generated['title'];
$raw = (string)$generated['raw'];
$preparedFinal = konvo_soul_prepare_topic($title, $raw, $soulRulesRun);
$title = $preparedFinal['title'];
$raw = $preparedFinal['raw'];
$plan = isset($generated['plan']) && is_array($generated['plan']) ? $generated['plan'] : array();

$finalDupCheck = casual_topic_too_similar($title, $raw, $recent, $recentForumTitles);
if (!empty($finalDupCheck['duplicate'])) {
    casual_out(200, array(
        'ok' => false,
        'posted' => false,
        'error' => 'Final duplicate check blocked post.',
        'duplicate' => $finalDupCheck,
        'title_preview' => $title,
        'recent_forum_count' => count($recentForumTitles),
        'recent_local_count' => count($recent),
        'worker_build' => (string)KONVO_WORKER_BUILD,
    ));
}

$categoryDecision = array(
    'ok' => true,
    'category_key' => 'registry',
    'category_id' => $categoryId,
    'reason' => 'bot_registry_category_with_soul_rules',
    'confidence' => 1.0,
    'soul_rules' => $soulRulesRun,
);
$gamingDetected = false;
$quirkyMode = false;
$quirkyMediaUrl = '';

if ($dryRun) {
    casual_out(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_casual_topic',
        'bot' => $bot,
        'post_as' => (string)($bot['username'] ?? ''),
        'plan' => $plan,
        'lane' => $lane,
        'used_fallback' => false,
        'worker_build' => (string)KONVO_WORKER_BUILD,
        'topic_mode' => $topicModeRun,
        'soul_rules' => $soulRulesRun,
        'fast_mode' => $fastMode,
        'fact_judge' => $generated['fact_judge'] ?? null,
        'pipeline' => (string)($generated['pipeline'] ?? ''),
        'topic' => array(
            'title' => $title,
            'category_id' => $categoryId,
            'han_chars' => konvo_soul_count_han_chars($raw),
            'raw_preview' => casual_safe_substr($raw, 800),
            'gaming_detected' => $gamingDetected,
            'category_decision' => $categoryDecision,
        ),
        'quirky_media' => array(
            'enabled' => $quirkyMode,
            'url' => $quirkyMediaUrl,
        ),
        'recent_count' => count($recent),
    ));
}

$post = casual_post_topic((string)($bot['username'] ?? 'BAI'), $title, $raw, $categoryId);
if (!$post['ok']) {
    casual_out(200, array(
        'ok' => false,
        'posted' => false,
        'error' => 'Failed to post casual topic.',
        'status' => $post['status'],
        'curl_error' => $post['error'],
        'response' => $post['body'],
        'raw' => $post['raw'],
        'worker_build' => (string)KONVO_WORKER_BUILD,
        'post_debug' => array(
            'title_preview' => casual_safe_substr($title, 80),
            'title_han' => konvo_soul_count_han_chars($title),
            'raw_han' => konvo_soul_count_han_chars($raw),
            'category_id' => $categoryId,
            'post_as' => (string)($post['post_as'] ?? ''),
        ),
        'hint' => '422 with empty title/category usually means json_encode failed on invalid UTF-8 in LLM output. v9 fixes this; rebuild container.',
    ));
}

$topicId = (int)($post['body']['topic_id'] ?? 0);
$postNumber = (int)($post['body']['post_number'] ?? 1);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
casual_remember_topic($title, (string)($plan['angle'] ?? ''), (string)($plan['lane'] ?? (string)($lane['key'] ?? '')), $raw);
casual_consensus_register_topic($topicId, $bot, $title, $categoryId, $plan);
$todayCountAfterPost = casual_daily_count_increment($today);

casual_out(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_casual_topic',
    'topic_url' => $topicUrl,
    'bot' => $bot,
    'post_as' => (string)($post['post_as'] ?? ''),
    'plan' => $plan,
    'lane' => $lane,
    'used_fallback' => false,
    'worker_build' => (string)KONVO_WORKER_BUILD,
    'topic_mode' => $topicModeRun,
    'soul_rules' => $soulRulesRun,
    'fast_mode' => $fastMode,
    'topic' => array(
        'title' => $title,
        'category_id' => $categoryId,
        'gaming_detected' => $gamingDetected,
        'category_decision' => $categoryDecision,
    ),
    'daily_cap' => array(
        'date' => $today,
        'count_after_post' => $todayCountAfterPost,
        'max_per_day' => $dailyCap > 0 ? $dailyCap : null,
    ),
    'quirky_media' => array(
        'enabled' => $quirkyMode,
        'url' => $quirkyMediaUrl,
    ),
));
