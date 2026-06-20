<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_soul_helper.php';
require_once __DIR__ . '/konvo_soul_topic_helper.php';

/**
 * Topic pipeline (v15):
 *   P1  two-stage LLM (outline → expand)
 *   P2  konvo_soul_prepare_topic (format only)
 *   P3  konvo_soul_validate_hard + konvo_soul_fact_judge
 *
 * Env:
 *   KONVO_TOPIC_TWO_STAGE=1|0   (default 1 for longform zh)
 *   KONVO_TOPIC_FACT_JUDGE=1|0  (default 1)
 */

function konvo_soul_two_stage_enabled(array $rules): bool
{
    if (empty($rules['longform']) || (($rules['language'] ?? 'any') !== 'zh')) {
        return false;
    }
    $env = strtolower(trim((string)getenv('KONVO_TOPIC_TWO_STAGE')));
    if ($env === '') {
        return true;
    }
    return in_array($env, array('1', 'true', 'yes', 'on'), true);
}

function konvo_soul_fact_judge_enabled(): bool
{
    $env = strtolower(trim((string)getenv('KONVO_TOPIC_FACT_JUDGE')));
    if ($env === '') {
        return true;
    }
    return in_array($env, array('1', 'true', 'yes', 'on'), true);
}

function konvo_soul_llm_chat_json(array $payload, array $rules = array()): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'json' => array(), 'raw' => '');
    }
    $apiKey = trim((string)(getenv('LLM_API_KEY') ?: getenv('DEEPSEEK_API_KEY') ?: getenv('OPENAI_API_KEY')));
    if ($apiKey === '') {
        return array('ok' => false, 'status' => 0, 'error' => 'LLM API key missing', 'json' => array(), 'raw' => '');
    }
    $base = rtrim((string)(getenv('LLM_API_BASE_URL') ?: getenv('OPENAI_API_BASE') ?: 'https://api.deepseek.com'), '/');
    $url = $base . '/chat/completions';
    $timeout = function_exists('konvo_soul_topic_llm_timeout') ? konvo_soul_topic_llm_timeout($rules) : 90;

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        return array('ok' => false, 'status' => $status, 'error' => $err, 'json' => array(), 'raw' => '');
    }
    $decoded = json_decode(konvo_soul_sanitize_utf8((string)$body), true);
    return array(
        'ok' => ($status >= 200 && $status < 300 && is_array($decoded)),
        'status' => $status,
        'error' => '',
        'json' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

function konvo_soul_extract_llm_json_object(string $content): array
{
    $content = konvo_soul_sanitize_utf8(trim($content));
    if ($content === '') {
        return array();
    }
    if ($content[0] === '{') {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return array();
    }
    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : array();
}

function konvo_soul_build_outline_system_prompt(string $soulPrompt, array $rules, int $categoryId): string
{
    $parts = array(
        '你是中文科普大纲助手。任务：为论坛话题帖写「大纲」，不是成文。',
        '【事实纪律·最高优先级】大纲阶段就禁止：具体百分比、精确人口/比例、机构名称（协会/基金会/研究院/报告/调查）、「据统计/数据显示」+数字、编造出处。',
        '不确定的内容不要写进大纲；宁可写「机制/背景/影响/日常观察」等定性方向。',
        '只返回 JSON：{"plan_mood":"...","plan_angle":"...","plan_posting_intent":"...","plan_lane":"...","title":"...","outline_paragraphs":["要点1","要点2",...]}。',
        'outline_paragraphs 必须 3 到 6 条；每条 1 到 2 句，只写本段要讲什么，不写完整段落。',
        'title 必须是具体中文名词短语，不得是问句。',
    );
    if ($soulPrompt !== '') {
        $parts[] = "Bot SOUL：\n{$soulPrompt}";
    }
    if ($categoryId > 0) {
        $parts[] = "目标分类 ID：{$categoryId}。";
    }
    return implode("\n", $parts);
}

function konvo_soul_build_outline_user_prompt(
    string $seedTopic,
    string $recentHints,
    bool $strict,
    string $extraAvoidance
): string {
    $lines = array(
        "参考主题：{$seedTopic}",
        '请按 SOUL 写大纲。outline_paragraphs 共 3–6 条，禁止出现任何具体数字、机构名、报告名。',
    );
    if ($recentHints !== '') {
        $lines[] = "避免与近期重复：\n{$recentHints}";
    }
    if ($strict) {
        $lines[] = '这是重试：换全新角度，并更严格遵守事实纪律。';
    }
    if ($extraAvoidance !== '') {
        $lines[] = '额外避免：' . trim($extraAvoidance);
    }
    return implode("\n\n", $lines);
}

function konvo_soul_build_expand_system_prompt(string $soulPrompt, array $rules): string
{
    $minHan = max(500, (int)($rules['min_han_chars'] ?? 500));
    $parts = array(
        '你是中文科普写作者。根据给定大纲，把每条 outline 扩写成完整段落。',
        '【事实纪律·最高优先级】禁止编造：具体百分比、精确统计、机构/报告/调查名称、假引用。不确定就改写为定性表述或删除该句。',
        '每个词必须写完整，禁止缺字、断词；禁止段内换行。',
        '只返回 JSON：{"paragraphs":["第一段完整正文","第二段完整正文",...]}。',
        'paragraphs 条数必须与大纲一致；合并后全文汉字不少于 ' . $minHan . ' 字。',
        '全文不得出现问号（？或?）；结尾必须是陈述句，不得互动式收束。',
        '不要输出 title，不要输出解释。',
    );
    if ($soulPrompt !== '') {
        $parts[] = "Bot SOUL：\n{$soulPrompt}";
    }
    return implode("\n", $parts);
}

function konvo_soul_build_expand_user_prompt(string $title, array $outlineParagraphs, bool $strict, string $extraAvoidance): string
{
    $outlineText = '';
    foreach ($outlineParagraphs as $i => $p) {
        $outlineText .= ($i + 1) . '. ' . trim((string)$p) . "\n";
    }
    $lines = array(
        "标题（已定，不要改）：{$title}",
        "大纲：\n{$outlineText}",
        '请逐条扩写成 paragraphs 数组。每段 100–180 个汉字，段内不换行。',
    );
    if ($strict) {
        $lines[] = '这是重试：修正上次的事实/缺字问题，仍须遵守事实纪律。';
    }
    if ($extraAvoidance !== '') {
        $lines[] = '额外避免：' . trim($extraAvoidance);
    }
    return implode("\n\n", $lines);
}

function konvo_soul_validate_hard(string $title, string $raw, array $rules): array
{
    $title = konvo_soul_sanitize_utf8(trim($title));
    $raw = konvo_soul_sanitize_utf8(trim($raw));

    if ($title === '' || konvo_soul_count_han_chars($title) < 4) {
        return array('ok' => false, 'tier' => 'P3', 'error' => 'title invalid or too short');
    }

    $language = (string)($rules['language'] ?? 'any');
    if ($language === 'zh' && !konvo_soul_is_chinese_like($title . "\n" . $raw)) {
        return array(
            'ok' => false,
            'tier' => 'P3',
            'error' => 'SOUL requires Chinese output',
            'han_chars' => konvo_soul_count_han_chars($raw),
        );
    }

    $minHan = (int)($rules['min_han_chars'] ?? 0);
    if ($minHan > 0 && konvo_soul_count_han_chars($raw) < $minHan) {
        return array(
            'ok' => false,
            'tier' => 'P3',
            'error' => 'SOUL requires at least ' . $minHan . ' Chinese chars in body',
            'han_chars' => konvo_soul_count_han_chars($raw),
        );
    }

    if (!empty($rules['statement_ending'])) {
        if (konvo_soul_title_is_question($title)) {
            return array('ok' => false, 'tier' => 'P3', 'error' => 'SOUL forbids question-style title');
        }
        if (konvo_soul_body_has_forbidden_question($raw)) {
            return array('ok' => false, 'tier' => 'P3', 'error' => 'SOUL forbids question-style closing');
        }
    }

    if (konvo_soul_is_boilerplate_topic($title, $raw)) {
        return array('ok' => false, 'tier' => 'P3', 'error' => 'content matches forbidden boilerplate template');
    }

    if (preg_match('/根据.{0,18}(?:协会|基金会|研究院|调查组|报告)/u', $raw) && preg_match('/\d+\s*[%％]/u', $raw)) {
        return array('ok' => false, 'tier' => 'P3', 'error' => 'forbidden fabricated citation pattern (organization + percentage)');
    }

    return array('ok' => true, 'tier' => 'P3');
}

function konvo_soul_fact_judge(string $title, string $raw, string $soulPrompt): array
{
    if (!konvo_soul_fact_judge_enabled()) {
        return array('ok' => true, 'publishable' => true, 'factual_risk' => 1, 'reason' => 'fact_judge_disabled');
    }
    $apiKey = trim((string)(getenv('LLM_API_KEY') ?: getenv('DEEPSEEK_API_KEY') ?: getenv('OPENAI_API_KEY')));
    if ($apiKey === '') {
        return array('ok' => true, 'publishable' => true, 'factual_risk' => 2, 'reason' => 'no_api_key_skip');
    }

    $soulBrief = konvo_soul_safe_substr($soulPrompt, 1200);
    $bodyBrief = konvo_soul_safe_substr($raw, 2200);
    $system = '你是科普事实质检员，只判断「能否作为可信科普发表」。'
        . '返回 JSON：{"publishable":true|false,"factual_risk":1-5,"issues":["..."],"rewrite_hint":"..."}。'
        . 'factual_risk: 1=可信常识, 3=有不确定具体断言, 5=明显编造数据/机构/出处。'
        . '以下应 publishable=false：具体百分比/精确人口统计、编造机构或报告名、无法核实的精确数字、明显断词缺字。'
        . '以下可 publishable=true：定性科普、常识机制、无具体数字的一般性描述。';
    $user = "SOUL 摘要：\n{$soulBrief}\n\n标题：{$title}\n\n正文：\n{$bodyBrief}\n\n请质检。";

    $payload = array(
        'model' => trim((string)(getenv('KONVO_FACT_JUDGE_MODEL') ?: getenv('MODEL_TIER_S') ?: 'deepseek-chat')),
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature' => 0.1,
        'max_tokens' => 600,
        'response_format' => array('type' => 'json_object'),
    );

    $res = konvo_soul_llm_chat_json($payload, array('longform' => true));
    if (!$res['ok']) {
        unset($payload['response_format']);
        $res = konvo_soul_llm_chat_json($payload, array('longform' => true));
    }
    if (!$res['ok']) {
        return array('ok' => true, 'publishable' => true, 'factual_risk' => 2, 'reason' => 'fact_judge_llm_error_fail_open');
    }

    $content = konvo_soul_sanitize_utf8(trim((string)($res['json']['choices'][0]['message']['content'] ?? '')));
    $obj = konvo_soul_extract_llm_json_object($content);
    if ($obj === array()) {
        return array('ok' => true, 'publishable' => true, 'factual_risk' => 2, 'reason' => 'fact_judge_parse_fail_open');
    }

    $risk = (int)($obj['factual_risk'] ?? 3);
    $publishable = !empty($obj['publishable']) && $risk <= 2;
    return array(
        'ok' => true,
        'publishable' => $publishable,
        'factual_risk' => $risk,
        'issues' => is_array($obj['issues'] ?? null) ? $obj['issues'] : array(),
        'rewrite_hint' => trim((string)($obj['rewrite_hint'] ?? '')),
        'reason' => $publishable ? 'fact_judge_pass' : 'fact_judge_rejected',
    );
}

function konvo_soul_topic_pipeline_generate(
    string $soulPrompt,
    array $rules,
    string $seedTopic,
    string $recentHints,
    bool $strict,
    string $extraAvoidance,
    int $categoryId,
    string $laneKey,
    callable $normalizeTitle,
    callable $normalizeBody
): array {
    $model = function_exists('konvo_model_for_task') ? konvo_model_for_task('casual_topic') : 'deepseek-chat';

    // --- P1 Stage 1: outline ---
    $outlinePayload = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'system', 'content' => konvo_soul_build_outline_system_prompt($soulPrompt, $rules, $categoryId)),
            array('role' => 'user', 'content' => konvo_soul_build_outline_user_prompt($seedTopic, $recentHints, $strict, $extraAvoidance)),
        ),
        'temperature' => 0.45,
        'max_tokens' => 900,
        'response_format' => array('type' => 'json_object'),
    );
    $outlineRes = konvo_soul_llm_chat_json($outlinePayload, $rules);
    if (!$outlineRes['ok']) {
        unset($outlinePayload['response_format']);
        $outlineRes = konvo_soul_llm_chat_json($outlinePayload, $rules);
    }
    if (!$outlineRes['ok']) {
        return array('ok' => false, 'stage' => 'outline', 'error' => 'outline LLM failed', 'detail' => $outlineRes['error']);
    }

    $outlineObj = konvo_soul_extract_llm_json_object((string)($outlineRes['json']['choices'][0]['message']['content'] ?? ''));
    $title = $normalizeTitle((string)($outlineObj['title'] ?? ''));
    $outlineParagraphs = $outlineObj['outline_paragraphs'] ?? $outlineObj['paragraphs'] ?? null;
    if (!is_array($outlineParagraphs)) {
        $outlineParagraphs = array();
    }
    $outlineParagraphs = array_values(array_filter(array_map(static fn($p) => trim((string)$p), $outlineParagraphs), static fn($p) => $p !== ''));
    $planMood = trim((string)($outlineObj['plan_mood'] ?? ''));
    $planAngle = trim((string)($outlineObj['plan_angle'] ?? ''));
    $planIntent = trim((string)($outlineObj['plan_posting_intent'] ?? ''));
    $planLane = trim((string)($outlineObj['plan_lane'] ?? $laneKey));

    if ($title === '' || count($outlineParagraphs) < 3) {
        return array('ok' => false, 'stage' => 'outline', 'error' => 'invalid outline JSON', 'parsed' => $outlineObj);
    }
    if (count($outlineParagraphs) > 6) {
        $outlineParagraphs = array_slice($outlineParagraphs, 0, 6);
    }

    // --- P1 Stage 2: expand ---
    $expandPayload = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'system', 'content' => konvo_soul_build_expand_system_prompt($soulPrompt, $rules)),
            array('role' => 'user', 'content' => konvo_soul_build_expand_user_prompt($title, $outlineParagraphs, $strict, $extraAvoidance)),
        ),
        'temperature' => 0.5,
        'max_tokens' => 2800,
        'response_format' => array('type' => 'json_object'),
    );
    $expandRes = konvo_soul_llm_chat_json($expandPayload, $rules);
    if (!$expandRes['ok']) {
        unset($expandPayload['response_format']);
        $expandRes = konvo_soul_llm_chat_json($expandPayload, $rules);
    }
    if (!$expandRes['ok']) {
        return array('ok' => false, 'stage' => 'expand', 'error' => 'expand LLM failed', 'detail' => $expandRes['error']);
    }

    $expandObj = konvo_soul_extract_llm_json_object((string)($expandRes['json']['choices'][0]['message']['content'] ?? ''));
    $paragraphs = $expandObj['paragraphs'] ?? null;
    if (!is_array($paragraphs) || count($paragraphs) < 1) {
        return array('ok' => false, 'stage' => 'expand', 'error' => 'expand JSON missing paragraphs', 'parsed' => $expandObj);
    }
    $paragraphs = array_values(array_filter(array_map(static fn($p) => trim((string)$p), $paragraphs), static fn($p) => $p !== ''));
    $raw = $normalizeBody(implode("\n\n", $paragraphs));

    if ($raw === '') {
        return array('ok' => false, 'stage' => 'expand', 'error' => 'empty body after expand');
    }

    // --- P2 prepare (format only) ---
    $prepared = konvo_soul_prepare_topic($title, $raw, $rules);
    $title = $prepared['title'];
    $raw = $prepared['raw'];

    // --- P3 hard validate ---
    $hard = konvo_soul_validate_hard($title, $raw, $rules);
    if (empty($hard['ok'])) {
        return array(
            'ok' => false,
            'stage' => 'validate_hard',
            'error' => (string)($hard['error'] ?? 'validate_hard failed'),
            'validation' => $hard,
            'title' => $title,
            'raw' => $raw,
            'han_chars' => konvo_soul_count_han_chars($raw),
        );
    }

    // --- P3 fact judge ---
    $judge = konvo_soul_fact_judge($title, $raw, $soulPrompt);
    if (!empty($judge['ok']) && empty($judge['publishable'])) {
        $hint = trim((string)($judge['rewrite_hint'] ?? ''));
        $issues = is_array($judge['issues'] ?? null) ? implode('; ', $judge['issues']) : '';
        return array(
            'ok' => false,
            'stage' => 'fact_judge',
            'error' => 'fact judge rejected (科普不允许瞎编或不可信断言)',
            'fact_judge' => $judge,
            'title' => $title,
            'raw' => $raw,
            'han_chars' => konvo_soul_count_han_chars($raw),
            'hint' => $hint !== '' ? $hint : $issues,
        );
    }

    return array(
        'ok' => true,
        'pipeline' => 'two_stage_v15',
        'title' => $title,
        'raw' => $raw,
        'han_chars' => konvo_soul_count_han_chars($raw),
        'paragraph_count' => konvo_soul_count_paragraphs($raw),
        'fact_judge' => $judge,
        'outline' => $outlineParagraphs,
        'plan' => array(
            'mood' => $planMood,
            'angle' => $planAngle,
            'posting_intent' => $planIntent,
            'lane' => $planLane,
            'seed_topic' => $seedTopic,
        ),
    );
}
