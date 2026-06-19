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
    if ($text === '') {
        return 0;
    }
    $ok = preg_match_all('/\p{Han}/u', $text, $m);
    if (is_int($ok) && $ok > 0) {
        return $ok;
    }
    $fallback = preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{f900}-\x{faFF}]/u', $text, $m2);
    return is_int($fallback) ? $fallback : 0;
}

function konvo_soul_count_latin_chars(string $text): int
{
    $ok = preg_match_all('/[A-Za-z]/', $text, $m);
    return is_int($ok) ? $ok : 0;
}

function konvo_soul_is_chinese_like(string $text): bool
{
    $han = konvo_soul_count_han_chars($text);
    if ($han < 40) {
        return false;
    }
    $latinCount = konvo_soul_count_latin_chars($text);
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

    if (preg_match('/(?:不使用疑问句|不得使用疑问句|不能是疑问句|结尾必须用陈述句|结尾应.*陈述句|不得写讨论引导|不得写提问式收束|不写提问式收束|不写讨论式收束)/u', $soul)) {
        $rules['statement_ending'] = true;
        $rules['question_ending'] = false;
    } elseif (preg_match('/(?:提出.*问题|讨论价值的问题|提问式收束|结尾提出)/u', $soul)) {
        $rules['question_ending'] = true;
    }

    if (preg_match('/科普/u', $soul)) {
        $rules['longform'] = true;
        if ($rules['min_han_chars'] < 500 && preg_match('/500/u', $soul)) {
            $rules['min_han_chars'] = 500;
        }
    }

    if (preg_match('/历史/u', $soul) && preg_match('/中文/u', $soul)) {
        $rules['longform'] = true;
        $rules['language'] = 'zh';
        if ($rules['min_han_chars'] < 500 && preg_match('/500/u', $soul)) {
            $rules['min_han_chars'] = 500;
        }
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
                '唐代两税法与中期财政稳定',
                '明代财政对白银依赖加深的制度背景',
                '宋代城市化与市场网络扩张',
                '汉代郡县制在地方治理中的运行逻辑',
                '科举制度与社会流动的影响边界',
                '明清漕运体系与国家治理成本',
                '边疆治理中军政与财政的互相牵制',
                '地方精英与国家权力在基层的互动',
                '史料叙事与制度现实之间的偏差',
            );
        }
        return array(
            '地图投影对空间直觉的影响',
            '季风与东亚季节生活方式',
            '夜空观测中的光污染问题',
            '时区划分背后的地理与政治逻辑',
            '港口城市空间形态的共同特征',
            '地铁网络对城市日常节奏的改变',
            '博物馆展陈与公众认知方式',
            '河流改道对聚落分布的长期影响',
            '气候带差异与日常饮食传播',
            '卫星图像与季节变化观察',
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
    $parts[] = '若 SOUL 要求中文科普长文，则 title 与 raw 必须中文，raw 必须超过 500 个中文字符，且 3 到 6 段。';
    if (($rules['language'] ?? 'any') === 'zh') {
        $parts[] = '【语言锁定】title 与 raw 必须全部使用简体中文书写，禁止英文正文或英文标题。'
            . 'plan_* 字段可简短英文，但 title/raw 不得出现英文句子。';
    }
    $parts[] = '若 SOUL 要求陈述句结尾，则 title 与 raw 全文都不得出现疑问句、问号、或“大家怎么看/欢迎讨论/如果方便请分享”等互动式收束。';
    $parts[] = '内容必须真实、非虚构；不得编造事实、数据、引语、来源、书目或网址，除非 SOUL 明确允许。';
    $parts[] = '严禁重复：不得与近期论坛话题或本 bot 近期帖子重复，也不得仅改写标题、换同义词或重排段落。必须提供明显不同的主题角度。';
    $parts[] = '不要签名；Discourse 已显示作者用户名。不要输出解释文字，只输出 JSON。';
    if ($categoryId > 0) {
        $parts[] = "目标分类 ID：{$categoryId}。";
    }
    return implode("\n", $parts);
}

function konvo_soul_is_boilerplate_topic(string $title, string $raw): bool
{
    $blob = trim($title . "\n" . $raw);
    if ($blob === '') {
        return true;
    }
    $patterns = array(
        '/关于「.+」.*新想法/u',
        '/大家最近有什么新想法/u',
        '/想听听/u',
        '/如果方便/u',
        '/欢迎分享/u',
        '/欢迎补充/u',
        '/有什么看法/u',
        '/是一个值得从制度、财政/u',
        '/很多人第一次接触「/u',
        '/从史料来看，我们往往更容易记住事件本身/u',
        '/最近在浏览论坛时，我又想到/u',
        '/有时候同一个问题，不同人的经历/u',
    );
    foreach ($patterns as $p) {
        if (preg_match($p, $blob)) {
            return true;
        }
    }
    return false;
}

function konvo_soul_pick_opening_style(): string
{
    $styles = array(
        '从一个具体现象或细节切入，不要写空泛总起句',
        '从一个常见误解切入，先指出误解再展开',
        '从两个时期或两个地区的对照切入',
        '从一个日常观察切入，再连接到知识背景',
        '从一个被忽视的细节切入，再扩展到整体',
        '从一次具体变化或转折切入，再解释原因',
    );
    shuffle($styles);
    return (string)$styles[0];
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
    $lines[] = '本篇开头方式：' . konvo_soul_pick_opening_style() . '。';
    $lines[] = '标题必须是具体名词短语，不得使用「关于…大家有什么新想法」这类讨论式标题。';
    $lines[] = '禁止套用固定模板；每篇的开头、段落顺序、举例方式都要明显不同。';
    $lines[] = 'plan_lane 用一个简短标签概括方向。';
    if ($recentHints !== '') {
        $lines[] = "避免与近期帖子重复：\n{$recentHints}";
    }
    if ($recentOpeningHints !== '') {
        $lines[] = "避免复用这些开头：\n{$recentOpeningHints}";
    }
    if (($rules['language'] ?? 'any') === 'zh') {
        $lines[] = '【再次强调】title 与 raw 必须全部是简体中文，正文至少 500 个汉字，不得输出英文段落。';
    }
    if ($strict) {
        $lines[] = '这是重试，请明显更换切入角度，并更严格地遵守 SOUL。';
    }
    if ($extraAvoidance !== '') {
        $lines[] = '额外避免点：' . trim($extraAvoidance);
        $retryHint = konvo_soul_retry_hint_for_error($extraAvoidance);
        if ($retryHint !== '') {
            $lines[] = $retryHint;
        }
    }
    $lines[] = '输出 JSON。';
    return implode("\n\n", $lines);
}

function konvo_soul_retry_hint_for_error(string $errorBlob): string
{
    $e = strtolower(trim($errorBlob));
    if ($e === '') {
        return '';
    }
    if (str_contains($e, 'chinese output') || str_contains($e, 'chinese chars')) {
        return '【修正要求】上次生成不合格：必须用简体中文写 title 和 raw，正文至少 500 个汉字，3 到 6 段，禁止英文正文。';
    }
    if (str_contains($e, 'question') || str_contains($e, 'interactive')) {
        return '【修正要求】不得使用问号、疑问句或“大家怎么看/欢迎讨论”等互动式收束，结尾必须是陈述句。';
    }
    if (str_contains($e, 'paragraph')) {
        return '【修正要求】正文必须分成 3 到 6 段，段与段之间空一行。';
    }
    if (str_contains($e, 'too similar') || str_contains($e, 'uniqueness')) {
        return '【修正要求】必须换一个完全不同的主题角度，不得改写近期已有话题。';
    }
    return '';
}

function konvo_soul_text_has_question(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    if (str_contains($text, '？') || str_contains($text, '?')) {
        return true;
    }
    if (preg_match('/(吗|呢)[。！]?$/u', $text)) {
        return true;
    }
    return (bool)preg_match('/(?:什么|怎么|如何|为何|为什么|哪些|哪几|难道|是不是|从何而来|何以|能否)/u', $text);
}

function konvo_soul_has_interactive_closing(string $raw): bool
{
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }
    return (bool)preg_match('/(?:想听听|欢迎分享|欢迎补充|大家怎么看|有什么看法|如果方便|分享一个具体例子|有什么新想法)/u', $raw);
}

function konvo_soul_expand_han_chars(string $raw, int $targetHan, string $suffix = ''): string
{
    $raw = trim($raw);
    $guard = 0;
    while (konvo_soul_count_han_chars($raw) < $targetHan && $guard < 6) {
        $guard++;
        $raw .= "\n\n" . ($suffix !== '' ? $suffix : '这类问题之所以值得反复讨论，关键在于它连接了具体现象与更长期的结构变化。');
    }
    return $raw;
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
        $hanTotal = konvo_soul_count_han_chars($title . "\n" . $raw);
        $hanBody = konvo_soul_count_han_chars($raw);
        $latinTotal = konvo_soul_count_latin_chars($title . "\n" . $raw);
        return array(
            'ok' => false,
            'error' => 'SOUL requires Chinese output',
            'han_chars' => $hanBody,
            'han_total' => $hanTotal,
            'latin_chars' => $latinTotal,
            'title_preview' => mb_substr($title, 0, 80, 'UTF-8'),
        );
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

    if (!empty($rules['statement_ending']) && !$relaxed) {
        if (konvo_soul_text_has_question($title)) {
            return array('ok' => false, 'error' => 'SOUL forbids question-style title');
        }
        if (konvo_soul_text_has_question($raw)) {
            return array('ok' => false, 'error' => 'SOUL forbids question marks or question phrasing in body');
        }
        if (konvo_soul_has_interactive_closing($raw)) {
            return array('ok' => false, 'error' => 'SOUL forbids interactive discussion-style closing');
        }
    }

    if (empty($rules['allow_code_blocks']) && strpos($raw, '```') !== false) {
        return array('ok' => false, 'error' => 'code block not expected for this SOUL topic');
    }

    if (konvo_soul_is_boilerplate_topic($title, $raw)) {
        return array('ok' => false, 'error' => 'content matches forbidden boilerplate template');
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

    $longform = !empty($rules['longform']) || (int)($rules['min_han_chars'] ?? 0) >= 500;
    $language = (string)($rules['language'] ?? 'any');
    $minHan = max(500, (int)($rules['min_han_chars'] ?? 500));

    if (!$longform) {
        return array(
            'ok' => false,
            'error' => 'template_fallback_refused_non_longform_soul',
            'hint' => 'SOUL requires longform content; fix LLM generation instead of using short fallback.',
        );
    }

    if (preg_match('/历史/u', $soul)) {
        $title = preg_replace('/[？?]+$/u', '', $seed) ?? $seed;
        $title = preg_replace('/(?:为什么|为何|如何|怎么|哪些).*/u', '', $title) ?? $title;
        $title = trim($title);
        if ($title === '') {
            $title = '一个值得重新理解的历史制度问题';
        }
        $raw = $title . "是一个需要从制度、财政与社会结构多个层面重新理解的历史议题。\n\n"
            . "从史料来看，我们往往更容易记住重大事件，却忽略了背后长期运行的治理逻辑。"
            . "政策设计时的约束条件、执行链条中的地方差异，以及不同群体在其中的实际处境，"
            . "常常决定了一个制度能否在较长时期内维持稳定。\n\n"
            . "如果把它放到更大的历史脉络里观察，会发现许多表面上的偶然结果，"
            . "其实都对应着更深层的结构性因素。"
            . "财政压力、军事需求、交通与信息条件、地方精英与国家权力的互动，"
            . "都会在不同阶段以不同方式显现。\n\n"
            . "因此，理解这类主题的关键，不只是复述结论，而是比较不同解释路径，"
            . "区分哪些判断有充分证据，哪些仍需要更谨慎地表述。"
            . "从现有研究看，这类问题往往需要在具体制度运行层面重新理解，"
            . "而不是停留在事件叙述或单一因果解释之上。\n\n"
            . "把具体史实放回制度脉络中观察，有助于我们避免把复杂过程简化成单一说法，"
            . "也能更清楚地看到历史变化背后的长期动力。";
        $raw = konvo_soul_expand_han_chars(
            $raw,
            $minHan,
            '历史研究的价值，正在于它提醒我们：任何时代的问题，都同时连接着制度设计、资源分配与社会结构。'
        );
    } else {
        $title = preg_replace('/[？?]+$/u', '', $seed) ?? $seed;
        $title = trim($title);
        if ($title === '') {
            $title = '一个值得展开的生活知识主题';
        }
        $raw = "很多人第一次接触「{$title}」这个主题，往往只注意到表面现象，"
            . "却忽略了它背后更稳定的知识结构与观察方法。\n\n"
            . "从常识层面看，这类问题并不复杂，但要把因果关系讲清楚，"
            . "需要同时考虑地理条件、历史积累、技术条件以及人们日常经验之间的相互作用。"
            . "如果只看局部案例，很容易把偶然现象误当成普遍规律。\n\n"
            . "比较常见的解释路径，是先区分直接可见的结果和长期起作用的背景因素。"
            . "前者通常更容易被讨论，后者却决定了现象能否在不同地区、不同时期重复出现。\n\n"
            . "进一步比较不同地区或不同条件下的案例，会发现同一主题往往呈现出多种面貌。"
            . "这些差异并不意味着知识失效，而是说明任何现象都需要放回具体环境中理解。\n\n"
            . "因此，把「{$title}」放在更完整的知识脉络里观察，"
            . "有助于建立更稳健的空间感、时间感与因果感，"
            . "也能减少对单一解释的过度依赖。"
            . "这类主题的真正价值，在于它能把抽象概念与日常经验连接起来，"
            . "让阅读者获得一种可以反复使用的观察方式。";
        $raw = konvo_soul_expand_han_chars(
            $raw,
            $minHan,
            '当一种现象被放回更完整的背景中理解时，我们看到的就不再是孤立的知识点，而是一种更耐用的认识方式。'
        );
    }

    $candidate = array(
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

    $valid = konvo_soul_validate_topic($title, $raw, $rules, false);
    if (empty($valid['ok'])) {
        return array(
            'ok' => false,
            'error' => 'template_fallback_failed_soul_validation',
            'validation' => $valid,
            'han_chars' => konvo_soul_count_han_chars($raw),
        );
    }

    return $candidate;
}

function konvo_soul_han_compact(string $text): string
{
    if (konvo_soul_count_han_chars($text) === 0) {
        return '';
    }
    preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{f900}-\x{faFF}]/u', $text, $m);
    return implode('', $m[0] ?? array());
}

function konvo_soul_normalize_title_key(string $title): string
{
    $han = konvo_soul_han_compact($title);
    if ($han !== '') {
        return $han;
    }
    $s = strtolower(trim($title));
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/https?:\/\/\S+/i', ' ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function konvo_soul_han_bigram_similarity(string $a, string $b): float
{
    $a = konvo_soul_han_compact($a);
    $b = konvo_soul_han_compact($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    $lenA = mb_strlen($a, 'UTF-8');
    $lenB = mb_strlen($b, 'UTF-8');
    if ($lenA >= 6 && mb_strpos($b, mb_substr($a, 0, 6, 'UTF-8'), 0, 'UTF-8') !== false) {
        return 0.82;
    }
    if ($lenB >= 6 && mb_strpos($a, mb_substr($b, 0, 6, 'UTF-8'), 0, 'UTF-8') !== false) {
        return 0.82;
    }

    $build = static function (string $s): array {
        $grams = array();
        $len = mb_strlen($s, 'UTF-8');
        if ($len < 2) {
            return $grams;
        }
        for ($i = 0; $i < ($len - 1); $i++) {
            $g = mb_substr($s, $i, 2, 'UTF-8');
            if ($g === '') {
                continue;
            }
            if (!isset($grams[$g])) {
                $grams[$g] = 0;
            }
            $grams[$g]++;
        }
        return $grams;
    };

    $ga = $build($a);
    $gb = $build($b);
    if ($ga === array() || $gb === array()) {
        return 0.0;
    }
    $inter = 0;
    $union = 0;
    $keys = array_values(array_unique(array_merge(array_keys($ga), array_keys($gb))));
    foreach ($keys as $k) {
        $ca = (int)($ga[$k] ?? 0);
        $cb = (int)($gb[$k] ?? 0);
        $inter += min($ca, $cb);
        $union += max($ca, $cb);
    }
    if ($union <= 0) {
        return 0.0;
    }
    return (float)$inter / (float)$union;
}

function konvo_soul_topic_similarity(string $titleA, string $rawA, string $titleB, string $rawB = ''): float
{
    $titleSim = konvo_soul_han_bigram_similarity($titleA, $titleB);
    $bodySim = konvo_soul_han_bigram_similarity($rawA, $rawB);
    $fullSim = konvo_soul_han_bigram_similarity(trim($titleA . "\n" . $rawA), trim($titleB . "\n" . $rawB));
    return max($titleSim, $bodySim * 0.92, $fullSim * 0.96);
}

function konvo_soul_topic_dedup_thresholds(): array
{
    return array(
        'title' => 0.42,
        'body' => 0.30,
        'full' => 0.28,
    );
}

function konvo_soul_topic_find_duplicate(
    string $title,
    string $raw,
    array $recentLocal,
    array $recentForumTitles
): array {
    $title = trim($title);
    $raw = trim($raw);
    $key = konvo_soul_normalize_title_key($title);
    $thresholds = konvo_soul_topic_dedup_thresholds();

    if ($key !== '') {
        foreach ($recentForumTitles as $rt) {
            $rt = trim((string)$rt);
            if ($rt === '') {
                continue;
            }
            if (konvo_soul_normalize_title_key($rt) === $key) {
                return array(
                    'duplicate' => true,
                    'reason' => 'exact_or_normalized_title_match',
                    'closest_title' => $rt,
                    'score' => 1.0,
                );
            }
        }
        foreach ($recentLocal as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rt = trim((string)($item['title'] ?? ''));
            if ($rt !== '' && konvo_soul_normalize_title_key($rt) === $key) {
                return array(
                    'duplicate' => true,
                    'reason' => 'exact_or_normalized_title_match_local',
                    'closest_title' => $rt,
                    'score' => 1.0,
                );
            }
        }
    }

    foreach ($recentForumTitles as $rt) {
        $rt = trim((string)$rt);
        if ($rt === '') {
            continue;
        }
        $titleSim = konvo_soul_han_bigram_similarity($title, $rt);
        if ($titleSim >= $thresholds['title']) {
            return array(
                'duplicate' => true,
                'reason' => 'title_too_similar_forum',
                'closest_title' => $rt,
                'score' => $titleSim,
            );
        }
    }

    foreach ($recentLocal as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rt = trim((string)($item['title'] ?? ''));
        if ($rt === '') {
            continue;
        }
        $rr = trim((string)($item['raw'] ?? ''));
        $titleSim = konvo_soul_han_bigram_similarity($title, $rt);
        $bodySim = ($rr !== '') ? konvo_soul_han_bigram_similarity($raw, $rr) : 0.0;
        $fullSim = konvo_soul_topic_similarity($title, $raw, $rt, $rr);
        if ($titleSim >= $thresholds['title'] || $bodySim >= $thresholds['body'] || $fullSim >= $thresholds['full']) {
            return array(
                'duplicate' => true,
                'reason' => 'content_too_similar_local',
                'closest_title' => $rt,
                'score' => max($titleSim, $bodySim, $fullSim),
            );
        }
    }

    return array(
        'duplicate' => false,
        'reason' => 'unique_enough',
        'closest_title' => '',
        'score' => 0.0,
    );
}

function konvo_soul_topic_is_duplicate(
    string $title,
    string $raw,
    array $recentLocal,
    array $recentForumTitles
): array {
    return konvo_soul_topic_find_duplicate($title, $raw, $recentLocal, $recentForumTitles);
}
