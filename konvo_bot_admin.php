<?php

declare(strict_types=1);

if (!defined('KONVO_BOT_ADMIN_BUILD')) {
    define('KONVO_BOT_ADMIN_BUILD', '2026-06-20-admin-v2');
}

require_once __DIR__ . '/konvo_bot_registry.php';
require_once __DIR__ . '/konvo_soul_helper.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function admin_secret(): string
{
    return trim((string)getenv('DISCOURSE_WEBHOOK_SECRET'));
}

function soul_file_path(string $soulKey): string
{
    return __DIR__ . '/souls/' . konvo_normalize_soul_key($soulKey) . '.SOUL.md';
}

function read_soul(string $soulKey): string
{
    $path = soul_file_path($soulKey);
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    return is_string($raw) ? trim($raw) : '';
}

function write_soul(string $soulKey, string $content): bool
{
    $path = soul_file_path($soulKey);
    return @file_put_contents($path, $content) !== false;
}

function write_soul(string $soulKey, string $content): bool
{
    $path = soul_file_path($soulKey);
    return @file_put_contents($path, $content) !== false;
}

function admin_discourse_base_url(): string
{
    return rtrim(trim((string)(getenv('DISCOURSE_BASE_URL') ?: 'https://www.howhy.day')), '/');
}

function admin_http_json(string $url, int $timeoutSec = 20, array $headers = array()): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl extension missing');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return array('ok' => false, 'error' => 'curl_init failed');
    }
    $hdrs = array_merge(array('Accept: application/json'), $headers);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => min(20, $timeoutSec),
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => $hdrs,
    ));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body)) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'empty response', 'status' => $status);
    }
    $json = json_decode($body, true);
    return array(
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'raw' => $body,
        'error' => ($status >= 200 && $status < 300) ? '' : ('HTTP ' . $status),
    );
}

function admin_discourse_api(string $path, int $timeoutSec = 20): array
{
    $apiKey = trim((string)(getenv('DISCOURSE_API_KEY') ?: getenv('KONVO_API_KEY') ?: ''));
    if ($apiKey === '') {
        return array('ok' => false, 'error' => 'DISCOURSE_API_KEY not set');
    }
    $apiUser = trim((string)(getenv('KONVO_DISCOURSE_API_USERNAME') ?: getenv('DISCOURSE_API_USERNAME') ?: 'system'));
    $url = admin_discourse_base_url() . '/' . ltrim($path, '/');
    return admin_http_json($url, $timeoutSec, array(
        'Api-Key: ' . $apiKey,
        'Api-Username: ' . $apiUser,
    ));
}

function admin_check_discourse_user(string $username): array
{
    $username = trim($username);
    if ($username === '') {
        return array('ok' => false, 'error' => 'empty username');
    }
    $res = admin_discourse_api('users/' . rawurlencode($username) . '.json', 15);
    if (!$res['ok']) {
        return array('ok' => false, 'error' => (string)($res['error'] ?? 'user lookup failed'), 'status' => (int)($res['status'] ?? 0));
    }
    $user = is_array($res['json']['user'] ?? null) ? $res['json']['user'] : array();
    return array(
        'ok' => true,
        'username' => (string)($user['username'] ?? $username),
        'name' => (string)($user['name'] ?? ''),
        'active' => !empty($user['active']),
    );
}

function admin_check_discourse_category(int $categoryId): array
{
    if ($categoryId <= 0) {
        return array('ok' => false, 'error' => 'invalid category id');
    }
    $res = admin_discourse_api('c/' . $categoryId . '/show.json', 15);
    if (!$res['ok']) {
        return array('ok' => false, 'error' => (string)($res['error'] ?? 'category lookup failed'), 'status' => (int)($res['status'] ?? 0));
    }
    $cat = is_array($res['json']['category'] ?? null) ? $res['json']['category'] : array();
    return array(
        'ok' => true,
        'id' => (int)($cat['id'] ?? $categoryId),
        'name' => (string)($cat['name'] ?? ''),
        'slug' => (string)($cat['slug'] ?? ''),
    );
}

function admin_invoke_topic_worker(array $params, string $secret, int $timeoutSec = 360): array
{
    @set_time_limit(max(120, $timeoutSec + 30));
    @ini_set('max_execution_time', (string)max(120, $timeoutSec + 30));
    $params['key'] = $secret;
    $url = konvo_bot_registry_worker_action_url($params, $secret);
    $res = admin_http_json($url, $timeoutSec);
    $parsed = null;
    if (is_string($res['raw'] ?? null) && trim((string)$res['raw']) !== '') {
        $parsed = json_decode((string)$res['raw'], true);
    }
    return array(
        'ok' => !empty($res['ok']),
        'url' => $url,
        'status' => (int)($res['status'] ?? 0),
        'json' => is_array($parsed) ? $parsed : null,
        'raw' => (string)($res['raw'] ?? ''),
        'error' => (string)($res['error'] ?? ''),
    );
}

function admin_format_worker_result(array $result): string
{
    if (is_array($result['json'] ?? null)) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        return (string)json_encode($result['json'], $flags);
    }
    return trim((string)($result['raw'] ?? ''));
}

function admin_worker_summary(array $json): string
{
    if ($json === array()) {
        return '';
    }
    $parts = array();
    if (isset($json['ok'])) {
        $parts[] = !empty($json['ok']) ? 'ok' : 'failed';
    }
    if (!empty($json['posted'])) {
        $parts[] = 'posted';
    }
    if (isset($json['dry_run']) && !empty($json['dry_run'])) {
        $parts[] = 'dry_run';
    }
    if (isset($json['worker_build'])) {
        $parts[] = 'build=' . (string)$json['worker_build'];
    }
    if (isset($json['han_chars'])) {
        $parts[] = 'han=' . (string)$json['han_chars'];
    }
    if (isset($json['title_preview'])) {
        $title = (string)$json['title_preview'];
        $parts[] = 'title=' . (function_exists('mb_substr') ? mb_substr($title, 0, 24, 'UTF-8') : substr($title, 0, 24));
    }
    if (!empty($json['error'])) {
        $parts[] = 'error=' . (string)$json['error'];
    }
    return implode(' · ', $parts);
}

$key = trim((string)($_REQUEST['key'] ?? ''));
$authorized = ($key !== '' && admin_secret() !== '' && hash_equals(admin_secret(), $key));

$message = '';
$error = '';
$dryRunUrl = '';
$workerResult = null;
$workerSummary = '';
$discourseChecks = array();
$adminWarnings = array();

$form = array(
    'username' => '',
    'name' => '',
    'category_id' => '',
    'category_label' => '',
    'enabled' => '1',
    'soul' => '',
    'template_kind' => 'general',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authorized) {
    $action = trim((string)($_POST['action'] ?? 'upsert'));
    $rows = konvo_bot_registry_load();

    if ($action === 'delete') {
        $username = trim((string)($_POST['username'] ?? ''));
        $rows = array_values(array_filter($rows, static function (array $r) use ($username): bool {
            return strtolower(trim((string)($r['username'] ?? ''))) !== strtolower($username);
        }));
        if (!konvo_bot_registry_save($rows)) {
            $error = 'Failed to save bot registry.';
        } else {
            $message = 'Bot deleted: ' . $username;
        }
    } elseif (in_array($action, array('worker_ping', 'worker_dry_run', 'worker_post'), true)) {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        if ($action === 'worker_ping') {
            $workerResult = admin_invoke_topic_worker(array('ping' => '1'), $key, 30);
            $message = 'Worker ping finished.';
        } elseif ($categoryId <= 0) {
            $error = 'category_id is required for worker actions.';
        } elseif ($action === 'worker_dry_run') {
            $workerResult = admin_invoke_topic_worker(array('dry_run' => '1', 'category_id' => $categoryId), $key, 360);
            $workerSummary = admin_worker_summary(is_array($workerResult['json'] ?? null) ? $workerResult['json'] : array());
            $message = 'Dry-run finished for category ' . $categoryId . ($username !== '' ? ' (' . $username . ')' : '') . '.';
        } else {
            $workerResult = admin_invoke_topic_worker(array('category_id' => $categoryId, 'force' => '1'), $key, 360);
            $workerSummary = admin_worker_summary(is_array($workerResult['json'] ?? null) ? $workerResult['json'] : array());
            $message = 'Live post attempt finished for category ' . $categoryId . ($username !== '' ? ' (' . $username . ')' : '') . '.';
        }
    } elseif ($action === 'load_template') {
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $form['name'] = trim((string)($_POST['name'] ?? ''));
        $form['category_id'] = trim((string)($_POST['category_id'] ?? ''));
        $form['category_label'] = trim((string)($_POST['category_label'] ?? ''));
        $form['enabled'] = (string)($_POST['enabled'] ?? '1');
        $form['template_kind'] = trim((string)($_POST['template_kind'] ?? 'general'));
        $form['soul'] = konvo_bot_registry_soul_template(
            $form['template_kind'],
            $form['username'] !== '' ? $form['username'] : 'NewBot',
            $form['category_label'] !== '' ? $form['category_label'] : '目标分类',
            (int)$form['category_id']
        );
        $message = 'SOUL template loaded — edit it, then click Save Bot.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $soul = trim((string)($_POST['soul'] ?? ''));
        $enabled = ((string)($_POST['enabled'] ?? '1')) === '1';
        $runDryRunAfterSave = ((string)($_POST['run_dry_run_after_save'] ?? '0')) === '1';

        if ($username === '' || $categoryId <= 0) {
            $error = 'username and category_id are required.';
            $form = array(
                'username' => $username,
                'name' => $name,
                'category_id' => (string)$categoryId,
                'category_label' => trim((string)($_POST['category_label'] ?? '')),
                'enabled' => $enabled ? '1' : '0',
                'soul' => $soul,
                'template_kind' => trim((string)($_POST['template_kind'] ?? 'general')),
            );
        } else {
            $soulKey = konvo_bot_registry_slugify($username);
            $row = [
                'username' => $username,
                'name' => $name !== '' ? $name : $username,
                'soul_key' => $soulKey,
                'category_id' => $categoryId,
                'enabled' => $enabled,
            ];

            $replaced = false;
            foreach ($rows as $idx => $existing) {
                if (strtolower(trim((string)($existing['username'] ?? ''))) === strtolower($username)) {
                    $rows[$idx] = $row;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $rows[] = $row;
            }

            if (!konvo_bot_registry_save($rows)) {
                $error = 'Failed to save bot registry.';
            } else {
                if ($soul !== '') {
                    if (!write_soul($soulKey, $soul)) {
                        $error = 'Bot saved, but failed to write SOUL file.';
                    } else {
                        $message = 'Bot saved and SOUL updated: ' . $username;
                    }
                } else {
                    $existingSoul = read_soul($soulKey);
                    if ($existingSoul === '') {
                        $error = 'Bot saved, but no SOUL file yet — paste SOUL content or load a template.';
                    } else {
                        $message = 'Bot saved (existing SOUL kept): ' . $username;
                    }
                }
                $dryRunUrl = konvo_bot_registry_dry_run_url($categoryId, $key);
                $userCheck = admin_check_discourse_user($username);
                $catCheck = admin_check_discourse_category($categoryId);
                $discourseChecks = array('user' => $userCheck, 'category' => $catCheck);
                if (!$userCheck['ok']) {
                    $adminWarnings[] = 'Discourse 用户未找到：' . $username . '（请先在论坛建用户并授权分类）';
                }
                if (!$catCheck['ok']) {
                    $adminWarnings[] = 'Discourse 分类 ID ' . $categoryId . ' 无法访问，请核对 ID。';
                }
                if ($runDryRunAfterSave) {
                    $workerResult = admin_invoke_topic_worker(array('dry_run' => '1', 'category_id' => $categoryId), $key, 360);
                    $workerSummary = admin_worker_summary(is_array($workerResult['json'] ?? null) ? $workerResult['json'] : array());
                    $message .= ' Dry-run 已完成。';
                }
            }

            $form = array(
                'username' => $username,
                'name' => $name !== '' ? $name : $username,
                'category_id' => (string)$categoryId,
                'category_label' => trim((string)($_POST['category_label'] ?? '')),
                'enabled' => $enabled ? '1' : '0',
                'soul' => $soul !== '' ? $soul : read_soul($soulKey),
                'template_kind' => trim((string)($_POST['template_kind'] ?? 'general')),
            );
        }
    }
} elseif ($authorized) {
    $edit = trim((string)($_GET['edit'] ?? ''));
    if ($edit !== '') {
        foreach (konvo_bot_registry_load() as $bot) {
            if (strtolower(trim((string)($bot['username'] ?? ''))) !== strtolower($edit)) {
                continue;
            }
            $soulKey = trim((string)($bot['soul_key'] ?? konvo_bot_registry_slugify($edit)));
            $form = array(
                'username' => (string)($bot['username'] ?? ''),
                'name' => (string)($bot['name'] ?? ''),
                'category_id' => (string)($bot['category_id'] ?? ''),
                'category_label' => '',
                'enabled' => !empty($bot['enabled']) ? '1' : '0',
                'soul' => read_soul($soulKey),
                'template_kind' => 'general',
            );
            $dryRunUrl = konvo_bot_registry_dry_run_url((int)($bot['category_id'] ?? 0), $key);
            break;
        }
    }
}

$bots = konvo_bot_registry_load();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Konvo Bot Admin</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; max-width: 980px; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
    label { display: block; font-size: 14px; margin-bottom: 8px; }
    input, textarea, select { width: 100%; box-sizing: border-box; padding: 8px; }
    textarea { min-height: 220px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; }
    .ok { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 8px; border-radius: 6px; margin-bottom: 10px; }
    .err { background: #fef2f2; border: 1px solid #fecaca; padding: 8px; border-radius: 6px; margin-bottom: 10px; }
    .info { background: #eff6ff; border: 1px solid #bfdbfe; padding: 10px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; text-align: left; padding: 8px; font-size: 14px; vertical-align: top; }
    button, .btn { padding: 8px 12px; cursor: pointer; display: inline-block; text-decoration: none; border: 1px solid #ccc; border-radius: 6px; background: #fafafa; color: #111; font-size: 14px; }
    .btn-primary { background: #111; color: #fff; border-color: #111; }
    .muted { color: #666; font-size: 13px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    code { background: #f3f4f6; padding: 2px 4px; border-radius: 4px; }
    pre.result { background: #0b1020; color: #e5e7eb; padding: 12px; border-radius: 8px; overflow: auto; max-height: 420px; font-size: 12px; line-height: 1.45; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #f3f4f6; font-size: 12px; margin-right: 6px; }
  </style>
</head>
<body>
  <h1>Konvo Bot Admin</h1>
  <p class="muted">build <code><?= h((string)KONVO_BOT_ADMIN_BUILD) ?></code> — 在本页完成：注册 bot、编辑 SOUL、Discourse 检查、Dry-run、发帖测试。唯一仍需在 Discourse 后台做的是<strong>创建用户并授权分类</strong>。</p>
  <?php if ($key !== '' && str_contains($key, '@')): ?>
    <div class="info">提示：URL 里的 <code>@</code> 建议写成 <code>%40</code>，否则部分浏览器会把 key 截断。</div>
  <?php endif; ?>

  <?php if (!$authorized): ?>
    <div class="err">Unauthorized. Add the correct <code>?key=...</code> query string.</div>
  <?php else: ?>
    <div class="info">
      <strong>一站式操作。</strong> 保存 bot 后，webhook 回复与发帖 worker 会自动读取 <code>.konvo_state/bots.json</code> 与 <code>souls/*.SOUL.md</code>，无需改 PHP。<br>
      Worker 地址：<code><?= h(konvo_bot_registry_worker_base_url()) ?>/konvo_casual_topic_worker.php</code>
    </div>

    <div class="card">
      <h3>Worker 状态</h3>
      <div class="actions">
        <form method="post">
          <input type="hidden" name="key" value="<?= h($key) ?>">
          <input type="hidden" name="action" value="worker_ping">
          <button type="submit">Ping Worker</button>
        </form>
      </div>
      <p class="muted">Ping 会检查 LLM key、pipeline 版本、SOUL 文件是否存在（约 1 秒）。Dry-run / 发帖约 30–120 秒。</p>
    </div>

    <?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <?php foreach ($adminWarnings as $warn): ?>
      <div class="info"><?= h($warn) ?></div>
    <?php endforeach; ?>
    <?php if ($dryRunUrl !== ''): ?>
      <div class="ok">
        Dry-run 外链：<a href="<?= h($dryRunUrl) ?>" target="_blank" rel="noopener"><?= h($dryRunUrl) ?></a>
      </div>
    <?php endif; ?>

    <?php if ($discourseChecks !== array()): ?>
      <div class="card">
        <h3>Discourse 检查</h3>
        <?php if (!empty($discourseChecks['user']['ok'])): ?>
          <div class="ok">用户 ✓ <span class="pill"><?= h((string)$discourseChecks['user']['username']) ?></span> <?= h((string)$discourseChecks['user']['name']) ?></div>
        <?php elseif (isset($discourseChecks['user'])): ?>
          <div class="err">用户 ✗ <?= h((string)($discourseChecks['user']['error'] ?? 'not found')) ?> — 请先在 Discourse 创建该用户。</div>
        <?php endif; ?>
        <?php if (!empty($discourseChecks['category']['ok'])): ?>
          <div class="ok">分类 ✓ #<?= h((string)$discourseChecks['category']['id']) ?> <?= h((string)$discourseChecks['category']['name']) ?> <span class="muted">(<?= h((string)$discourseChecks['category']['slug']) ?>)</span></div>
        <?php elseif (isset($discourseChecks['category'])): ?>
          <div class="err">分类 ✗ <?= h((string)($discourseChecks['category']['error'] ?? 'not found')) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (is_array($workerResult)): ?>
      <div class="card">
        <h3>Worker 结果<?= $workerSummary !== '' ? ' — ' . h($workerSummary) : '' ?></h3>
        <?php if (!$workerResult['ok']): ?>
          <div class="err"><?= h((string)($workerResult['error'] ?? 'worker request failed')) ?> (HTTP <?= h((string)($workerResult['status'] ?? 0)) ?>)</div>
        <?php endif; ?>
        <p class="muted"><code><?= h((string)($workerResult['url'] ?? '')) ?></code></p>
        <pre class="result"><?= h(admin_format_worker_result($workerResult)) ?></pre>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Add / Update Bot</h3>
      <form method="post">
        <input type="hidden" name="key" value="<?= h($key) ?>">
        <input type="hidden" name="action" id="bot-admin-action" value="upsert">
        <div class="row">
          <label>Username（与 Discourse 一致）
            <input name="username" value="<?= h($form['username']) ?>" placeholder="Enjoylife" required>
          </label>
          <label>Display Name
            <input name="name" value="<?= h($form['name']) ?>" placeholder="Enjoylife">
          </label>
        </div>
        <div class="row3">
          <label>Category ID
            <input type="number" name="category_id" value="<?= h($form['category_id']) ?>" placeholder="7" required>
          </label>
          <label>分类名称（写进 SOUL 模板）
            <input name="category_label" value="<?= h($form['category_label']) ?>" placeholder="地理">
          </label>
          <label>Enabled
            <select name="enabled">
              <option value="1"<?= $form['enabled'] === '1' ? ' selected' : '' ?>>Yes</option>
              <option value="0"<?= $form['enabled'] === '0' ? ' selected' : '' ?>>No</option>
            </select>
          </label>
        </div>
        <div class="row">
          <label>SOUL 模板类型
            <select name="template_kind">
              <option value="general"<?= $form['template_kind'] === 'general' ? ' selected' : '' ?>>通用中文科普</option>
              <option value="history"<?= $form['template_kind'] === 'history' ? ' selected' : '' ?>>历史</option>
              <option value="geography"<?= $form['template_kind'] === 'geography' ? ' selected' : '' ?>>地理</option>
              <option value="casual"<?= $form['template_kind'] === 'casual' ? ' selected' : '' ?>>随机畅聊</option>
            </select>
          </label>
          <label>&nbsp;
            <button type="submit" onclick="document.getElementById('bot-admin-action').value='load_template'">加载 SOUL 模板到下方</button>
          </label>
        </div>
        <label>SOUL Content
          <textarea name="soul" placeholder="Write bot SOUL profile here..."><?= h($form['soul']) ?></textarea>
        </label>
        <div class="actions">
          <button type="submit" class="btn-primary" onclick="document.getElementById('bot-admin-action').value='upsert'">Save Bot</button>
          <label class="muted" style="display:inline-flex;align-items:center;gap:6px;margin:0;">
            <input type="checkbox" name="run_dry_run_after_save" value="1" style="width:auto;">
            保存后立即 Dry-run
          </label>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>Current Bots</h3>
      <table>
        <thead>
          <tr><th>Username</th><th>Category</th><th>SOUL</th><th>Enabled</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($bots as $b):
            $uname = (string)($b['username'] ?? '');
            $soulKey = (string)($b['soul_key'] ?? konvo_bot_registry_slugify($uname));
            $catId = (int)($b['category_id'] ?? 0);
            $testUrl = konvo_bot_registry_dry_run_url($catId, $key);
            $soulOk = is_file(soul_file_path($soulKey));
          ?>
            <tr>
              <td><strong><?= h($uname) ?></strong><br><span class="muted"><?= h((string)($b['name'] ?? '')) ?></span></td>
              <td><?= h((string)$catId) ?></td>
              <td><?= $soulOk ? '✓ ' . h($soulKey) . '.SOUL.md' : '<span class="err">missing</span>' ?></td>
              <td><?= !empty($b['enabled']) ? 'yes' : 'no' ?></td>
              <td>
                <div class="actions">
                  <a class="btn" href="?key=<?= h($key) ?>&edit=<?= h($uname) ?>">Edit</a>
                  <form method="post">
                    <input type="hidden" name="key" value="<?= h($key) ?>">
                    <input type="hidden" name="action" value="worker_dry_run">
                    <input type="hidden" name="category_id" value="<?= h((string)$catId) ?>">
                    <input type="hidden" name="username" value="<?= h($uname) ?>">
                    <button type="submit">Dry-run</button>
                  </form>
                  <form method="post" onsubmit="return confirm('确定要真正发帖到 Discourse 吗？');">
                    <input type="hidden" name="key" value="<?= h($key) ?>">
                    <input type="hidden" name="action" value="worker_post">
                    <input type="hidden" name="category_id" value="<?= h((string)$catId) ?>">
                    <input type="hidden" name="username" value="<?= h($uname) ?>">
                    <button type="submit">发帖</button>
                  </form>
                  <form method="post" onsubmit="return confirm('Delete this bot?');">
                    <input type="hidden" name="key" value="<?= h($key) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="username" value="<?= h($uname) ?>">
                    <button type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</body>
</html>
