<?php

declare(strict_types=1);

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

$key = trim((string)($_REQUEST['key'] ?? ''));
$authorized = ($key !== '' && admin_secret() !== '' && hash_equals(admin_secret(), $key));

$message = '';
$error = '';
$dryRunUrl = '';

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
  </style>
</head>
<body>
  <h1>Konvo Bot Admin</h1>
  <p class="muted">中文话题 bot 通常只需 3 步：Discourse 建用户 → 本页注册 + 写 SOUL → <code>dry_run=1</code> 测试。</p>

  <?php if (!$authorized): ?>
    <div class="err">Unauthorized. Add the correct <code>?key=...</code> query string.</div>
  <?php else: ?>
    <div class="info">
      <strong>不必改 PHP 代码。</strong> 注册表写入 <code>.konvo_state/bots.json</code> 后，发帖 worker、webhook 回复（<code>konvo_dynamic_reply.php</code>）会自动识别新 bot。<br>
      仓库里的 <code>konvo_bot_registry.php</code> 默认列表仅作首次部署 fallback；线上以 Admin 或 <code>bots.json</code> 为准。
    </div>

    <?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <?php if ($dryRunUrl !== ''): ?>
      <div class="ok">
        Dry-run 测试链接：<a href="<?= h($dryRunUrl) ?>" target="_blank" rel="noopener"><?= h($dryRunUrl) ?></a>
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
                  <a class="btn" href="<?= h($testUrl) ?>" target="_blank" rel="noopener">Dry-run</a>
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
