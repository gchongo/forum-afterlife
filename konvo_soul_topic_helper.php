<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_soul_helper.php';

function konvo_soul_prompt_for_topic(array $bot): string
{
    $soulKey = trim((string)($bot['soul_key'] ?? strtolower((string)($bot['username'] ?? ''))));
    $soulFallback = trim((string)($bot['soul_fallback'] ?? ''));
    return konvo_load_soul($soulKey, $soulFallback);
}

function konvo_soul_count_han_chars(string $text): int
{
    $ok = preg_match_all('/\p{Han}/u', $text, $m);
    return is_int($ok) ? $ok : 0;
}

function konvo_soul_is_chinese_like(string $text): bool
{
    $han = konvo_soul_count_han_chars($text);
    if ($han < 40) {
        return false;
    }
    $latin = preg_match_all('/[A-Za-z]/', $text, $m2);
    $latinCount = is_int($latin) ? $latin : 0;
    return $latinCount <= max(30, (int)floor($han * 0.25));
}

function konvo_soul_body_ends_with_question(string $raw): bool
{
    $parts = preg_split('/\R+/u', trim($raw));
    if (!is_array($parts) || $parts === array()) {
        return false;
    }
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $line = trim((string)$parts[$i]);
        if ($line === '') {
            continue;
        }
        return str_contains($line, '？') || str_contains($line, '?');
    }
    return false;
}

function konvo_soul_parse_topic_rules(string $soulRaw): array
{
    $soul = trim($soulRaw);
    $rules = array(
        'longform' => false,
        'language' => 'any',
        'min_han_chars' => 0,
        'min_body_chars' => 80,
        'max_title_len' => 88,
        'max_body_len' => 2200,
        'min_paragraphs' => 0,
        'max_paragraphs' => 0,
        'statement_ending' => false,
        'question_ending' => false,
        'use_markdown' => false,
        'allow_code_blocks' => false,
    );

    if ($soul === '') {
        return $rules;
    }

    if (preg_match('/只用中文|必须中文|正文必须中文|标题必须中文/u', $soul)) {
        $rules['language'] = 'zh';
    } elseif (preg_match('/\b(english only|write in english)\b/i', $soul)) {
        $rules['language'] = 'en';
    }

    if (preg_match('/(?:超过|不少于|至少|严格超过)\s*(\d+)\s*个?中文字符/u', $soul, $m)) {
        $rules['min_han_chars'] = max(1, (int)$m[1]);
        $rules['longform'] = true;
    } elseif (preg_match('/500/u', $soul) && preg_match('/中文/u', $soul)) {
        $rules['min_han_chars'] = 500;
        $rules['longform'] = true;
    }

    if (preg_match('/(\d+)\s*到\s*(\d+)\s*段/u', $soul, $m)) {
        $rules['min_paragraphs'] = max(1, (int)$m[1]);
        $rules['max_paragraphs'] = max($rules['min_paragraphs'], (int)$m[2]);
        $rules['longform'] = true;
    }

    if (preg_match('/(?:不使用疑问句|不得使用疑问句|结尾必须用陈述句|结尾应.*陈述句|不得写讨论引导|不得写提问式收束)/u', $soul)) {
        $rules['statement_ending'] = true;
        $rules['question_ending'] = false;
    } elseif (preg_match('/(?:提出.*问题|讨论价值的问题|提问式收束|结尾提出)/u', $soul)) {
        $rules['question_ending'] = true;
    }

    if (preg_match('/使用\s*Markdown|Markdown/u', $soul)) {
        $rules['use_markdown'] = true;
    }

    if (preg_match('/科普文章|原创中文|论坛话题帖|长文/u', $soul)) {
        $rules['longform'] = true;
    }

    if (preg_match('/(\d+)\s*[-到]\s*(\d+)\s*句/u', $soul)) {
        $rules['longform'] = false;
        $rules['min_han_chars'] = 0;
        $rules['min_paragraphs'] = 0;
        $rules['max_paragraphs'] = 0;
        $rules['min_body_chars'] = 40;
        $rules['max_body_len'] = 1200;
    }

    if ($rules['longform']) {
        $rules['max_title_len'] = 120;
        $rules['max_body_len'] = 3800;
        $rules['min_body_chars'] = 200;
        if ($rules['min_paragraphs'] === 0) {
            $rules['min_paragraphs'] = 3;
            $rules['max_paragraphs'] = 6;
        }
        if ($rules['language'] === 'any' && preg_match('/[\x{4e00}-\x{9fff}]/u', $soul)) {
            $rules['language'] = 'zh';
        }
    }

    return $rules;
}

function konvo_soul_topic_llm_timeout(array $rules): int
{
    $fastModeEnv = strtolower(trim((string)getenv('KONVO_TOPIC_FAST_MODE')));
    $fastMode = ($fastModeEnv === '' || in_array($fastModeEnv, array('1', 'true', 'yes', 'on'), true));
    if (!empty($rules['longform'])) {
        return $fastMode ? 45 : 35;
    }
    return $fastMode ? 28 : 22;
}

function konvo_soul_default_seed_pool(string $soulRaw, array $rules): array
{
    if ($rules['language'] === 'zh' || preg_match('/历史/u', $soulRaw)) {
        if (preg_match('/历史/u', $soulRaw)) {
            return array(
                '唐代两税法为什么能稳定财政一段时间',
                '明代财政对白银依赖加深的制度原因',
                '宋代城市化与市场网络扩张的互动关系',
                '汉代郡县制在地方治理中的实际运行逻辑',
                '科举制度对地方社会流动的真实影响边界',
                '明清时期漕运体系与国家治理成本',
                '边疆治理中军政与财政如何互相牵制',
                '地方精英与国家权力在基层的协作与冲突',
            );
        }
        return array(
            '地图投影对空间直觉的影响',
            '季风与东亚季节生活方式的关系',
            '夜空观测中的光污染问题',
            '时区划分背后的地理与政治逻辑',
            '港口城市空间形态的共同特征',
            '地铁网络如何改变城市日常节奏',
            '博物馆展陈如何影响公众认知',
            '河流改道对聚落分布的长期影响',
            '气候带差异与日常饮食传播',
            '卫星图像怎样帮助观察季节变化',
        );
    }

    return array(
        'a structural problem that stays easy to overlook',
        'a common misunderstanding versus everyday reality',
        'how local choices add up to system-level outcomes',
        'a trend that looks random until you zoom out',
        'a tradeoff people only notice after it becomes painful',
    );
}

function konvo_soul_build_topic_system_prompt(string $soulPrompt, array $rules, int $categoryId): string
{
    $parts = array();
    if ($soulPrompt !== '') {
        $parts[] = "Bot SOUL（最高优先级，必须完全遵守）：\n{$soulPrompt}";
    }
    $parts[] = '你的任务是：为该 bot 创建一篇可直接发布到 Discourse 论坛的新话题帖。';
    $parts[] = '只返回 JSON，结构为：'
        . '{"plan_mood":"...","plan_angle":"...","plan_posting_intent":"...","plan_lane":"...","title":"...","raw":"..."}。';
    $parts[] = 'SOUL 中的所有语言、长度、结构、风格、禁区、准确性规则，优先于任何默认行为。';
    $parts[] = '若 SOUL 要求中文，则 title 与 raw 必须中文；若 SOUL 要求长文，则必须满足 SOUL 的字数与段落要求。';
    $parts[] = '若 SOUL 要求陈述句结尾，则 raw 结尾不得使用疑问句；若 SOUL 要求提问式结尾，则按 SOUL 执行。';
    $parts[] = '内容必须真实、非虚构；不得编造事实、数据、引语、来源、书目或网址，除非 SOUL 明确允许。';
    $parts[] = '不要签名；Discourse 已显示作者用户名。不要输出解释文字，只输出 JSON。';
    if ($categoryId > 0) {
        $parts[] = "目标分类 ID：{$categoryId}。";
    }
    return implode("\n", $parts);
}

function konvo_soul_build_topic_user_prompt(
    string $seedTopic,
    array $rules,
    string $recentHints,
    string $recentOpeningHints,
    bool $strict,
    string $extraAvoidance
): string {
    $lines = array();
    $lines[] = "参考主题（可改写、可忽略，但必须符合 SOUL 的话题范围）：{$seedTopic}";
    $lines[] = '请严格按 SOUL 生成一篇可发帖的话题内容。';
    $lines[] = 'plan_lane 用一个简短标签概括方向。';
    if ($recentHints !== '') {
        $lines[] = "避免与近期帖子重复：\n{$recentHints}";
    }
    if ($recentOpeningHints !== '') {
        $lines[] = "避免复用这些开头：\n{$recentOpeningHints}";
    }
    if ($strict) {
        $lines[] = '这是重试，请明显更换切入角度，并更严格地遵守 SOUL。';
    }
    if ($extraAvoidance !== '') {
        $lines[] = '额外避免点：' . trim($extraAvoidance);
    }
    $lines[] = '输出 JSON。';
    return implode("\n\n", $lines);
}

function konvo_soul_validate_topic(string $title, string $raw, array $rules, bool $relaxed = false): array
{
    $title = trim($title);
    $raw = trim($raw);
    $longform = !empty($rules['longform']);

    if ($title === '' || strlen($title) < 6) {
        return array('ok' => false, 'error' => 'title too short');
    }
    $maxTitle = (int)($rules['max_title_len'] ?? 88);
    if (strlen($title) > $maxTitle) {
        return array('ok' => false, 'error' => 'title too long');
    }

    $minBody = $longform ? (int)($rules['min_body_chars'] ?? 200) : (int)($rules['min_body_chars'] ?? 80);
    if ($relaxed && $longform) {
        $minBody = max(40, (int)floor($minBody * 0.6));
    }
    if ($raw === '' || strlen($raw) < $minBody) {
        return array('ok' => false, 'error' => 'body too short');
    }
    $maxBody = (int)($rules['max_body_len'] ?? ($longform ? 3800 : 2200));
    if (strlen($raw) > $maxBody) {
        return array('ok' => false, 'error' => 'body too long');
    }

    $language = (string)($rules['language'] ?? 'any');
    if ($language === 'zh' && !konvo_soul_is_chinese_like($title . "\n" . $raw)) {
        return array('ok' => false, 'error' => 'SOUL requires Chinese output');
    }

    $minHan = (int)($rules['min_han_chars'] ?? 0);
    if ($minHan > 0) {
        $needHan = $relaxed ? max(200, (int)floor($minHan * 0.6)) : $minHan;
        if (konvo_soul_count_han_chars($raw) < $needHan) {
            return array('ok' => false, 'error' => 'SOUL requires at least ' . $needHan . ' Chinese chars in body');
        }
    }

    $minPara = (int)($rules['min_paragraphs'] ?? 0);
    $maxPara = (int)($rules['max_paragraphs'] ?? 0);
    if ($minPara > 0 || $maxPara > 0) {
        $paraCount = preg_match_all('/\n\s*\n/u', $raw, $m);
        $blocks = (is_int($paraCount) ? $paraCount : 0) + 1;
        $needMin = $relaxed ? max(1, $minPara - 1) : $minPara;
        $needMax = $maxPara > 0 ? $maxPara : 99;
        if ($blocks < $needMin || $blocks > $needMax) {
            return array('ok' => false, 'error' => 'SOUL requires ' . $needMin . '-' . $needMax . ' paragraphs');
        }
    }

    if (!empty($rules['statement_ending']) && !$relaxed && konvo_soul_body_ends_with_question($raw)) {
        return array('ok' => false, 'error' => 'SOUL requires statement ending, not a question');
    }

    if (empty($rules['allow_code_blocks']) && strpos($raw, '```') !== false) {
        return array('ok' => false, 'error' => 'code block not expected for this SOUL topic');
    }

    return array('ok' => true);
}

function konvo_soul_topic_fallback(array $bot, array $rules, string $seedTopic = ''): array
{
    $soul = konvo_soul_prompt_for_topic($bot);
    $seed = trim($seedTopic);
    if ($seed === '') {
        $pool = konvo_soul_default_seed_pool($soul, $rules);
        shuffle($pool);
        $seed = (string)($pool[0] ?? '一个值得认真展开的知识主题');
    }

    $longform = !empty($rules['longform']);
    $language = (string)($rules['language'] ?? 'any');
    $statementEnding = !empty($rules['statement_ending']);

    if ($longform && ($language === 'zh' || konvo_soul_count_han_chars($soul) > 20)) {
        $title = $seed;
        if (preg_match('/历史/u', $soul)) {
            $raw = $seed . "是一个值得从制度、财政与社会结构多个层面重新理解的问题。\n\n"
                . "从史料来看，我们往往更容易记住事件本身，却忽略了背后长期运行的治理逻辑。"
                . "政策设计时的约束条件、执行链条中的地方差异，以及不同群体在其中的实际处境，"
                . "常常决定了一个制度能否在较长时期内维持稳定。\n\n"
                . "如果把它放到更大的历史脉络里观察，会发现许多表面上的偶然结果，"
                . "其实都对应着更深层的结构性因素。"
                . "财政压力、军事需求、交通与信息条件、地方精英与国家权力的互动，"
                . "都会在不同阶段以不同方式显现。\n\n"
                . "因此，理解这类主题的关键，不只是复述结论，而是比较不同解释路径，"
                . "区分哪些判断有充分证据，哪些仍需要更谨慎地表述。\n\n"
                . "从现有研究看，这类问题往往需要在具体制度运行层面重新理解，"
                . "而不是停留在事件叙述或单一因果解释之上。";
        } else {
            $raw = "很多人第一次接触「{$seed}」这个主题，往往只注意到表面现象，"
                . "却忽略了它背后更稳定的知识结构与观察方法。\n\n"
                . "从常识层面看，这类问题并不复杂，但要把因果关系讲清楚，"
                . "需要同时考虑地理条件、历史积累、技术条件以及人们日常经验之间的相互作用。"
                . "如果只看局部案例，很容易把偶然现象误当成普遍规律。\n\n"
                . "比较常见的解释路径，是先区分直接可见的结果和长期起作用的背景因素。"
                . "前者通常更容易被讨论，后者却决定了现象能否在不同地区、不同时期重复出现。\n\n"
                . "进一步比较不同地区或不同条件下的案例，会发现同一主题往往呈现出多种面貌。"
                . "这些差异并不意味着知识失效，而是说明任何现象都需要放回具体环境中理解。\n\n"
                . "因此，把「{$seed}」放在更完整的知识脉络里观察，"
                . "有助于建立更稳健的空间感、时间感与因果感，"
                . "也能减少对单一解释的过度依赖。";
        }

        if ((int)($rules['min_han_chars'] ?? 0) > 0 && konvo_soul_count_han_chars($raw) < (int)$rules['min_han_chars']) {
            $raw .= "\n\n这类主题的价值，正在于它能把抽象概念与日常经验连接起来，"
                . "让阅读者获得一种可以反复使用的观察方式。";
        }

        if ($statementEnding && konvo_soul_body_ends_with_question($raw)) {
            $raw = preg_replace('/[？?]+$/u', '。', $raw) ?? $raw;
        }

        return array(
            'ok' => true,
            'title' => $title,
            'raw' => $raw,
            'plan' => array(
                'mood' => 'informative',
                'angle' => 'soul_template_fallback',
                'posting_intent' => 'template_fallback',
                'lane' => 'soul',
                'seed_topic' => $seed,
            ),
            'fallback' => true,
        );
    }

    $title = ($language === 'zh')
        ? ('关于「' . $seed . '」的一点想法')
        : ('Thoughts on "' . $seed . '"');
    $raw = ($language === 'zh')
        ? ("最近我又想到「{$seed}」这个主题。\n\n"
            . "它看起来不大，但仔细展开后往往能看到更多层次。"
            . "如果你也有相关观察，欢迎分享。")
        : ("I've been thinking about \"{$seed}\" again.\n\n"
            . "It seems small at first, but it opens up more layers once you look closer.");

    return array(
        'ok' => true,
        'title' => $title,
        'raw' => $raw,
        'plan' => array(
            'mood' => 'curious',
            'angle' => 'soul_template_fallback',
            'posting_intent' => 'template_fallback',
            'lane' => 'soul',
            'seed_topic' => $seed,
        ),
        'fallback' => true,
    );
}
