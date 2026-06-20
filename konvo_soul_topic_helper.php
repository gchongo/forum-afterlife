<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_soul_helper.php';

function konvo_soul_prompt_for_topic(array $bot): string
{
    $soulKey = trim((string)($bot['soul_key'] ?? strtolower((string)($bot['username'] ?? ''))));
    $soulFallback = trim((string)($bot['soul_fallback'] ?? ''));
    return konvo_load_soul($soulKey, $soulFallback);
}

function konvo_soul_sanitize_utf8(string $text): string
{
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }
    if (function_exists('iconv')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($clean) && $clean !== '') {
            return $clean;
        }
    }
    if (function_exists('mb_convert_encoding')) {
        $clean = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if (is_string($clean) && $clean !== '') {
            return $clean;
        }
    }
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    if (is_string($clean) && $clean !== '') {
        return $clean;
    }
    return $text;
}

function konvo_soul_count_han_chars(string $text): int
{
    $text = konvo_soul_sanitize_utf8($text);
    if ($text === '') {
        return 0;
    }
    $ok = @preg_match_all('/\p{Han}/u', $text, $m);
    if (is_int($ok) && $ok > 0) {
        return $ok;
    }
    $fallback = @preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{f900}-\x{faFF}]/u', $text, $m2);
    if (is_int($fallback) && $fallback > 0) {
        return $fallback;
    }
    $bytes = @preg_match_all('/[\xE4-\xE9][\x80-\xBF]{2}/', $text, $m3);
    return is_int($bytes) ? $bytes : 0;
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
        'news_bulletin' => false,
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

    if (preg_match('/科普/u', $soul) && !konvo_soul_is_news_bulletin_soul($soul)) {
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

    if (konvo_soul_is_news_bulletin_soul($soul)) {
        $rules = array_merge($rules, konvo_soul_news_bulletin_rules_patch());
    }

    return $rules;
}

function konvo_soul_news_bulletin_rules_patch(): array
{
    return array(
        'longform' => false,
        'news_bulletin' => true,
        'language' => 'zh',
        'min_han_chars' => 0,
        'min_paragraphs' => 0,
        'max_paragraphs' => 0,
        'min_body_chars' => 60,
        'max_body_len' => 2800,
        'max_title_len' => 100,
        'statement_ending' => false,
    );
}

function konvo_soul_apply_bot_topic_rules(array $rules, array $bot, int $categoryId = 0): array
{
    $key = strtolower(trim((string)($bot['soul_key'] ?? '')));
    $user = strtolower(trim((string)($bot['username'] ?? '')));
    $newsCategoryId = (int)(getenv('KONVO_NEWS_CATEGORY_ID') ?: 6);
    if ($categoryId === $newsCategoryId || in_array($key, array('kokoji'), true) || in_array($user, array('kokoji'), true)) {
        return array_merge($rules, konvo_soul_news_bulletin_rules_patch());
    }
    return $rules;
}

function konvo_soul_should_run_fact_judge(array $rules): bool
{
    if (!empty($rules['news_bulletin'])) {
        return false;
    }
    $env = strtolower(trim((string)getenv('KONVO_TOPIC_FACT_JUDGE')));
    if ($env === '') {
        return true;
    }
    return in_array($env, array('1', 'true', 'yes', 'on'), true);
}

function konvo_soul_is_news_bulletin_soul(string $soulRaw): bool
{
    $soulRaw = konvo_soul_sanitize_utf8(trim($soulRaw));
    if ($soulRaw === '') {
        return false;
    }
    return (bool)preg_match('/(?:每日号外|新闻号外|kokoji|新闻热点号外|体裁锁定.*新闻|不是科普)/ui', $soulRaw);
}

function konvo_soul_topic_llm_timeout(array $rules): int
{
    $fastModeEnv = strtolower(trim((string)getenv('KONVO_TOPIC_FAST_MODE')));
    $fastMode = ($fastModeEnv === '' || in_array($fastModeEnv, array('1', 'true', 'yes', 'on'), true));
    if (!empty($rules['longform'])) {
        return 120;
    }
    if (!empty($rules['news_bulletin'])) {
        return 60;
    }
    return $fastMode ? 28 : 22;
}

function konvo_soul_default_seed_pool(string $soulRaw, array $rules): array
{
    if (konvo_soul_is_news_bulletin_soul($soulRaw)) {
        return array(
            '今日国内一则值得跟进的政策或经济动态号外',
            '今日国际地缘或外交一则公开报道号外',
            '今日科技产业一条热议新闻号外',
            '今日金融市场或大宗商品一则波动号外',
            '今日社会公共事件一则报道梳理号外',
            '今日文化体育领域一则热点号外',
            '近期网络热议话题的事实梳理与号外评论',
            '今日东亚地区一则时事号外',
            '今日欧美市场隔夜几则动态号外',
        );
    }
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
        if (preg_match('/地理/u', $soulRaw) || preg_match('/Enjoylife/u', $soulRaw)) {
            return array(
                '河曲与冲积平原的形成过程',
                '季风区雨季推进的地理机制',
                '港口城市与海岸类型的对应关系',
                '等高线密集区对交通选线的影响',
                '流域分水岭与上下游差异',
                '沙漠扩张与降水分布的长期关系',
                '三角洲地貌与河口沉积',
                '时区划分背后的经度与政治因素',
                '雪线高度与纬度、坡向的关系',
                '都市圈空间结构的地形约束',
                '洋流对沿岸气候的调节作用',
                '地图投影造成的形状与面积失真',
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

function konvo_soul_safe_substr(string $text, int $maxChars): string
{
    if ($maxChars <= 0 || $text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return (string)mb_substr($text, 0, $maxChars, 'UTF-8');
    }
    if (strlen($text) <= $maxChars) {
        return $text;
    }
    $slice = substr($text, 0, $maxChars);
    return preg_replace('/[\x80-\xBF]+$/', '', $slice) ?? $slice;
}

function konvo_soul_build_topic_system_prompt(string $soulPrompt, array $rules, int $categoryId): string
{
    $parts = array();
    $isZh = (($rules['language'] ?? 'any') === 'zh');
    if ($isZh) {
        $parts[] = '【最高优先级】你是中文论坛发帖助手。title 与 raw 必须全部使用简体中文。禁止英文标题、英文正文、英文段落。';
    }
    if ($soulPrompt !== '') {
        $parts[] = "Bot SOUL（必须完全遵守）：\n{$soulPrompt}";
    }
    $parts[] = '你的任务是：为该 bot 创建一篇可直接发布到 Discourse 论坛的新话题帖。';
    $parts[] = '只返回 JSON，结构为：'
        . '{"plan_mood":"...","plan_angle":"...","plan_posting_intent":"...","plan_lane":"...","title":"...","raw":"..."}。';
    $parts[] = 'SOUL 中的所有语言、长度、结构、风格、禁区、准确性规则，优先于任何默认行为。';
    if (!empty($rules['news_bulletin'])) {
        $parts[] = '【号外模式】写今日/近日新闻热点号外，不是科普、不是地理教科书。篇幅灵活，无 500 字要求。';
    } elseif ($isZh && !empty($rules['longform'])) {
        $parts[] = '若 SOUL 要求中文科普长文，则 title 与 raw 必须中文，raw 必须超过 500 个中文字符，且 3 到 6 段。';
    }
    if ($isZh) {
        $parts[] = '【语言锁定】title 与 raw 必须全部使用简体中文书写，禁止英文正文或英文标题。'
            . 'plan_* 字段可简短英文，但 title/raw 不得出现英文句子。';
    }
    $parts[] = 'raw 正文格式：3 到 6 段，段与段之间仅用一个空行（两个换行符）分隔；段内不得换行，每段写成连贯的一整块文字。';
    $parts[] = konvo_soul_human_voice_rules(konvo_soul_infer_voice_tone($soulPrompt));
    $parts[] = '【事实纪律】严禁编造：具体百分比、精确人口比例、机构名称（如“XX协会”“XX报告”）、调查数据、年份统计。不确定时改用“许多”“相当多”“在不少城市”等定性表述，不要假装引用数据。';
    $parts[] = '【文字完整】每个词必须写完整，禁止出现缺字、断词、单字引号（如只写“天”而不写“天光/光害”），禁止段内换行。';
    $parts[] = '若 SOUL 要求陈述句结尾：title 不得是问句；结尾段不得有问号或“大家怎么看/欢迎讨论”等互动收束。正文中间可用“如何”“什么”做科普叙述。';
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

function konvo_soul_human_voice_rules(string $tone = 'any'): string
{
    $lines = array(
        '【人类感·必须遵守】写像论坛里真人认真发帖，不要像 AI 生成的科普稿或作文模板。',
        '禁止套话与 AI 腔：综上所述、总而言之、值得注意的是、不难发现、从某种意义上说、在当今社会、随着…的发展、在…的背景下、深刻反映了、具有重要意义、提供了重要参考、本文将、让我们、接下来、不仅…而且…（连续对称排比）、一方面…另一方面…、这不仅仅…更是…、可以说、赋能、底层逻辑、闭环、格局、多维、全方位。',
        '禁止「首先/其次/再次/最后」机械分条；禁止每段用相同句式开头；禁止段末空洞升华句。',
        '句子长短要有变化，允许用「但、其实、不过、倒」等自然连接，但不要口水话或网络梗。',
        '不要自指（不写「这篇文章」「下文将介绍」）；不要写总结性小标题感；不要像考试标准答案或论文摘要。',
        '用事实、例子、对照推进，像一个人在讲清楚一件事，不是在交作业。',
    );
    if ($tone === 'history') {
        $lines[] = '历史帖：克制、具体，像爱读史书的论坛网友，不要演讲腔，不要「历史告诉我们」式说教。';
    } elseif ($tone === 'casual') {
        $lines[] = '畅聊帖：轻松自然，像认真聊天的网友，可有一点个人观察语气，但不要油、不要段子体。';
    } else    if ($tone === 'geography') {
        $lines[] = '地理帖：像爱看地图又爱出门的论坛网友，具体、克制，不要旅游广告腔，不要教科书式定义堆砌。';
    } elseif ($tone === 'news') {
        $lines[] = '号外帖：像转述今日新闻的论坛网友；先事实后评论，不要科普、不要地理教科书、不要港口城市原理课。';
    }
    return implode("\n", $lines);
}

function konvo_soul_infer_voice_tone(string $soulPrompt): string
{
    $blob = konvo_soul_sanitize_utf8($soulPrompt);
    if ($blob === '') {
        return 'any';
    }
    if (preg_match('/历史/u', $blob) && preg_match('/higuyer|历史长河/u', $blob)) {
        return 'history';
    }
    if (preg_match('/谈天说地|BAI/u', $blob)) {
        return 'casual';
    }
    if (preg_match('/地理/u', $blob) && preg_match('/Enjoylife|地理分类/u', $blob)) {
        return 'geography';
    }
    if (konvo_soul_is_news_bulletin_soul($blob)) {
        return 'news';
    }
    return 'any';
}

function konvo_soul_ai_slop_patterns(): array
{
    return array(
        '/综上所述/u',
        '/总而言之/u',
        '/值得注意的是/u',
        '/不难发现/u',
        '/从某种意义上说/u',
        '/在当今(?:社会|时代)/u',
        '/随着.{0,12}的发展/u',
        '/在.{0,12}的背景下/u',
        '/深刻反映了/u',
        '/具有重要意义/u',
        '/提供了重要(?:参考|启示)/u',
        '/本文将/u',
        '/让我们(?:来|一起)/u',
        '/接下来(?:我们|将)/u',
        '/这不仅仅/u',
        '/可以说是/u',
        '/一方面.{0,40}另一方面/u',
        '/首先.{0,80}其次/u',
        '/不仅.{0,40}而且/u',
        '/赋能/u',
        '/底层逻辑/u',
        '/全方位/u',
        '/历史告诉我们/u',
        '/从.{0,6}角度来看/u',
    );
}

function konvo_soul_detect_ai_slop(string $text): array
{
    $text = konvo_soul_sanitize_utf8($text);
    if ($text === '') {
        return array();
    }
    $hits = array();
    foreach (konvo_soul_ai_slop_patterns() as $pattern) {
        if (@preg_match($pattern, $text)) {
            $hits[] = $pattern;
        }
    }
    return $hits;
}

function konvo_soul_paragraph_opener_hint(int $index, int $total): string
{
    $first = array(
        '开头直接从具体现象、细节或误解切入，不要空泛总起。',
        '开头用一个具体场景或对比，不要「在…中」式背景句。',
        '开头先抛一个具体事实或观察，再展开。',
    );
    $middle = array(
        '本段换种开头，不要用「此外/另外/同时」起头。',
        '本段从具体例子或对照写起，避免承接上段的同样句式。',
        '本段可从一个被忽视的细节切入。',
    );
    $last = array(
        '结尾段用平实的陈述句收束，不要升华口号，不要「综上所述」。',
        '结尾段总结认识即可，不要互动式或演讲式收束。',
        '结尾段像随手写下的结论，不要作文式「总之」。',
    );
    if ($index <= 0) {
        shuffle($first);
        return (string)$first[0];
    }
    if ($index >= $total - 1) {
        shuffle($last);
        return (string)$last[0];
    }
    shuffle($middle);
    return (string)$middle[0];
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
    if (!empty($rules['news_bulletin'])) {
        $lines[] = '【号外】必须写新闻热点号外：先事实后评论；禁止港口地理、科普教科书、冷知识体。';
    }
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
        if (!empty($rules['news_bulletin'])) {
            $lines[] = '【再次强调】title 与 raw 必须全部是简体中文；号外新闻体，无最低字数。';
        } else {
            $lines[] = '【再次强调】title 与 raw 必须全部是简体中文，正文必须超过 520 个汉字（宁长勿短），不得输出英文段落。';
        }
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
    if (str_contains($e, 'corrupt') || str_contains($e, 'fragment') || str_contains($e, 'isolated single')) {
        return '【修正要求】上次文本有缺字或断词，每个词必须写完整，段内不要换行，不要出现单字引号。';
    }
    if (str_contains($e, 'fabricat') || str_contains($e, 'organization') || str_contains($e, 'statistics')) {
        return '【修正要求】不要编造机构名称和精确百分比，改用定性描述（如“许多大城市”“相当比例的人口”）。';
    }
    if (str_contains($e, 'chinese output') || str_contains($e, 'chinese chars')) {
        return '【修正要求】上次生成不合格：必须用简体中文写 title 和 raw，正文必须超过 520 个汉字（不是 500），3 到 6 段，禁止英文正文。';
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
    if (str_contains($e, 'ai slop') || str_contains($e, 'robotic') || str_contains($e, 'template')) {
        return '【修正要求】去掉 AI 套话和作文模板腔（如综上所述、首先其次、值得注意的是），改成像论坛真人发帖的自然中文。';
    }
    return '';
}

function konvo_soul_count_paragraphs(string $raw): int
{
    $raw = konvo_soul_sanitize_utf8(trim($raw));
    if ($raw === '') {
        return 0;
    }
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;
    $parts = preg_split('/\n\s*\n/', $raw);
    if (!is_array($parts)) {
        return 1;
    }
    $count = 0;
    foreach ($parts as $part) {
        if (trim((string)$part) !== '') {
            $count++;
        }
    }
    return max(1, $count);
}

function konvo_soul_flatten_paragraph_text(string $text): string
{
    $text = konvo_soul_sanitize_utf8(str_replace(array("\r\n", "\r"), "\n", $text));
    $text = preg_replace('/\s*\n+\s*/u', '', $text) ?? $text;
    $text = preg_replace('/[ \t\x{00A0}]+/u', '', $text) ?? $text;
    return trim($text);
}

function konvo_soul_fix_inline_newlines(string $raw): string
{
    $raw = konvo_soul_sanitize_utf8(str_replace(array("\r\n", "\r"), "\n", $raw));
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    // 句中的换行（含 \n\n）一律抹平，避免「可能↵↵满」被当成段落
    $raw = preg_replace('/(?<![。！？!?])\n+/u', '', $raw) ?? $raw;
    // 仅句末标点后保留段落分隔
    $raw = preg_replace('/([。！？!?])\s*\n\s*(?=[\x{4e00}-\x{9fff}「"（(])/u', "$1\n\n", $raw) ?? $raw;
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;

    $parts = preg_split('/\n\s*\n/', $raw);
    if (!is_array($parts) || $parts === array()) {
        return konvo_soul_flatten_paragraph_text($raw);
    }

    $fixed = array();
    foreach ($parts as $part) {
        $part = konvo_soul_flatten_paragraph_text((string)$part);
        if ($part !== '') {
            $fixed[] = $part;
        }
    }

    return implode("\n\n", $fixed);
}

function konvo_soul_body_has_inline_newlines(string $raw): bool
{
    foreach (preg_split('/\n\s*\n/', trim($raw)) as $block) {
        $block = trim((string)$block);
        if ($block !== '' && str_contains($block, "\n")) {
            return true;
        }
    }
    return (bool)preg_match('/[\x{4e00}-\x{9fff}]\s*\n\s*[\x{4e00}-\x{9fff}]/u', $raw);
}

function konvo_soul_normalize_paragraphs(string $raw, int $minPara, int $maxPara): string
{
    $raw = konvo_soul_fix_inline_newlines(konvo_soul_sanitize_utf8(trim($raw)));
    $raw = preg_replace('/\n{3,}/', "\n\n", $raw) ?? $raw;
    $parts = preg_split('/\n\s*\n/', $raw);
    if (!is_array($parts)) {
        return trim($raw);
    }
    $blocks = array();
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $blocks[] = $part;
        }
    }
    if ($blocks === array()) {
        return trim($raw);
    }
    if ($maxPara > 0) {
        while (count($blocks) > $maxPara) {
            $last = array_pop($blocks);
            $blocks[count($blocks) - 1] .= "\n\n" . $last;
        }
    }
    return implode("\n\n", $blocks);
}

function konvo_soul_text_has_question_mark(string $text): bool
{
    return str_contains($text, '？') || str_contains($text, '?');
}

function konvo_soul_title_is_question(string $title): bool
{
    $title = trim($title);
    if ($title === '') {
        return false;
    }
    if (konvo_soul_text_has_question_mark($title)) {
        return true;
    }
    if (preg_match('/(吗|呢)[。！]?$/u', $title)) {
        return true;
    }
    return (bool)preg_match('/^(?:为什么|为何|怎么|如何|什么|哪些)/u', $title);
}

function konvo_soul_body_has_forbidden_question(string $raw): bool
{
    if (konvo_soul_has_interactive_closing($raw)) {
        return true;
    }
    $parts = preg_split('/\n\s*\n/', trim($raw));
    if (!is_array($parts) || $parts === array()) {
        return konvo_soul_text_has_question_mark($raw);
    }
    $last = trim((string)end($parts));
    if ($last === '') {
        return false;
    }
    if (konvo_soul_text_has_question_mark($last)) {
        return true;
    }
    if (preg_match('/(吗|呢)[。！]?$/u', $last)) {
        return true;
    }
    return (bool)preg_match('/(?:你觉得|大家怎么看|你认为|你知道吗|是不是|欢迎讨论|如果方便)[？?]?$/u', $last);
}

function konvo_soul_text_has_question(string $text): bool
{
    return konvo_soul_title_is_question($text) || konvo_soul_body_has_forbidden_question($text);
}

function konvo_soul_has_interactive_closing(string $raw): bool
{
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }
    return (bool)preg_match('/(?:想听听|欢迎分享|欢迎补充|大家怎么看|有什么看法|如果方便|分享一个具体例子|有什么新想法)/u', $raw);
}

function konvo_soul_pick_closing_suffix(): string
{
    $suffixes = array(
        '把上述现象放回更完整的背景里观察，有助于建立更稳健的认识方式，也能减少对单一解释的依赖。',
        '因此，这类主题的价值在于它能把抽象知识与日常经验连接起来，形成可以反复使用的观察框架。',
        '从更长的时间尺度看，这些细节往往比表面结论更能说明问题的来龙去脉。',
        '综合以上各点，这一主题呈现出的结构比初看时更为清晰，也更具解释力。',
    );
    shuffle($suffixes);
    return (string)$suffixes[0];
}

function konvo_soul_split_body_into_paragraphs(string $raw, int $minPara, int $maxPara): string
{
    $blocks = konvo_soul_count_paragraphs($raw);
    if ($blocks >= $minPara) {
        return $raw;
    }
    $flat = preg_replace('/\s*\n\s*/u', '', $raw) ?? $raw;
    $sentences = preg_split('/(?<=[。！？!?])/u', $flat, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($sentences) || count($sentences) < $minPara) {
        return $raw;
    }
    $target = max($minPara, min($maxPara > 0 ? $maxPara : 6, (int)ceil(count($sentences) / 2)));
    $perGroup = max(1, (int)ceil(count($sentences) / $target));
    $groups = array();
    for ($i = 0; $i < count($sentences); $i += $perGroup) {
        $chunk = trim(implode('', array_slice($sentences, $i, $perGroup)));
        if ($chunk !== '') {
            $groups[] = $chunk;
        }
    }
    if (count($groups) < $minPara) {
        return $raw;
    }
    if ($maxPara > 0 && count($groups) > $maxPara) {
        while (count($groups) > $maxPara) {
            $last = array_pop($groups);
            $groups[count($groups) - 1] .= $last;
        }
    }
    return implode("\n\n", $groups);
}

function konvo_soul_prepare_topic(string $title, string $raw, array $rules): array
{
    $title = konvo_soul_sanitize_utf8(trim($title));
    $raw = konvo_soul_sanitize_utf8(trim($raw));
    $minPara = (int)($rules['min_paragraphs'] ?? 0);
    $maxPara = (int)($rules['max_paragraphs'] ?? 0);
    $raw = konvo_soul_fix_inline_newlines($raw);
    if ($minPara > 0) {
        $raw = konvo_soul_split_body_into_paragraphs($raw, $minPara, $maxPara > 0 ? $maxPara : 6);
    }
    if ($minPara > 0 || $maxPara > 0) {
        $raw = konvo_soul_normalize_paragraphs($raw, $minPara, $maxPara > 0 ? $maxPara : 99);
    }
    $minHan = (int)($rules['min_han_chars'] ?? 0);
    if ($minHan > 0) {
        $han = konvo_soul_count_han_chars($raw);
        if ($han < $minHan && $han >= max(1, $minHan - 80)) {
            $raw = konvo_soul_expand_han_chars($raw, $minHan + 8, konvo_soul_pick_closing_suffix());
        }
    }
    if ($minPara > 0) {
        $blocks = konvo_soul_count_paragraphs($raw);
        while ($blocks < $minPara) {
            $raw .= "\n\n" . konvo_soul_pick_closing_suffix();
            $blocks++;
        }
        if ($maxPara > 0 && $blocks > $maxPara) {
            $raw = konvo_soul_normalize_paragraphs($raw, $minPara, $maxPara);
        }
    }
    return array('title' => $title, 'raw' => trim($raw));
}

function konvo_soul_validate_content_quality(string $title, string $raw): ?string
{
    $blob = $title . "\n" . $raw;
    if (str_contains($blob, '�') || preg_match('/\x{FFFD}/u', $blob)) {
        return 'text contains UTF-8 replacement characters (corrupt encoding)';
    }
    if (preg_match('~[\x{4e00}-\x{9fff}]\n[\x{4e00}-\x{9fff}]~u', $raw)) {
        return 'inline newline remains in body (corrupt formatting)';
    }
    if (preg_match('~[，。：；、""\'\'(（][\x{4e00}-\x{9fff}][，。：；""\'\' )）]~u', $raw)) {
        return 'isolated single Chinese character between punctuation (likely missing text)';
    }
    if (preg_match('~[""「『][\x{4e00}-\x{9fff}][""」』]~u', $raw)) {
        return 'single Chinese character in quotation marks (incomplete term)';
    }
    if (preg_match('/国暗夜/u', $raw) || preg_match('/球过/u', $raw) || preg_match('/天越强/u', $raw)) {
        return 'suspicious broken Chinese fragment detected (missing characters)';
    }
    if (preg_match('/根据.{0,18}(?:协会|基金会|研究院|调查组|报告)/u', $raw) && preg_match('/\d+\s*[%％]/u', $raw)) {
        return 'do not cite organization names with precise percentages (likely fabricated statistics)';
    }
    return null;
}

function konvo_soul_expand_han_chars(string $raw, int $targetHan, string $suffix = ''): string
{
    $raw = trim($raw);
    $suffixText = $suffix !== '' ? $suffix : '这类问题之所以值得反复讨论，关键在于它连接了具体现象与更长期的结构变化。';
    $guard = 0;
    while (konvo_soul_count_han_chars($raw) < $targetHan && $guard < 4) {
        $guard++;
        if (str_contains($raw, "\n\n")) {
            $parts = preg_split('/\n\s*\n/', $raw);
            if (!is_array($parts) || $parts === array()) {
                $raw = rtrim($raw) . $suffixText;
                continue;
            }
            $last = rtrim(trim((string)array_pop($parts)));
            $parts[] = $last . $suffixText;
            $raw = implode("\n\n", $parts);
        } else {
            $raw = rtrim($raw) . $suffixText;
        }
    }
    return $raw;
}

function konvo_soul_validate_topic(string $title, string $raw, array $rules, bool $relaxed = false): array
{
    $title = konvo_soul_sanitize_utf8(trim($title));
    $raw = konvo_soul_sanitize_utf8(trim($raw));
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
            'title_preview' => konvo_soul_safe_substr($title, 80),
        );
    }

    $minHan = (int)($rules['min_han_chars'] ?? 0);
    if ($minHan > 0) {
        $needHan = $minHan;
        if (konvo_soul_count_han_chars($raw) < $needHan) {
            return array('ok' => false, 'error' => 'SOUL requires at least ' . $needHan . ' Chinese chars in body');
        }
    }

    $minPara = (int)($rules['min_paragraphs'] ?? 0);
    $maxPara = (int)($rules['max_paragraphs'] ?? 0);
    if ($minPara > 0 || $maxPara > 0) {
        $blocks = konvo_soul_count_paragraphs($raw);
        $needMin = $minPara;
        $needMax = $maxPara > 0 ? $maxPara : 99;
        if ($blocks < $needMin || $blocks > $needMax) {
            return array(
                'ok' => false,
                'error' => 'SOUL requires ' . $needMin . '-' . $needMax . ' paragraphs',
                'paragraph_count' => $blocks,
            );
        }
    }

    if (!empty($rules['statement_ending'])) {
        if (konvo_soul_title_is_question($title)) {
            return array('ok' => false, 'error' => 'SOUL forbids question-style title');
        }
        if (konvo_soul_body_has_forbidden_question($raw)) {
            return array('ok' => false, 'error' => 'SOUL forbids question marks or question-style closing in body');
        }
    }

    if (empty($rules['allow_code_blocks']) && strpos($raw, '```') !== false) {
        return array('ok' => false, 'error' => 'code block not expected for this SOUL topic');
    }

    if (konvo_soul_is_boilerplate_topic($title, $raw)) {
        return array('ok' => false, 'error' => 'content matches forbidden boilerplate template');
    }

    $qualityError = konvo_soul_validate_content_quality($title, $raw);
    if ($qualityError !== null) {
        return array('ok' => false, 'error' => $qualityError);
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
