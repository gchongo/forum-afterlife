<?php

declare(strict_types=1);

require_once __DIR__ . '/konvo_bot_registry.php';

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
    return __DIR__ . '/souls/' . $soulKey . '.SOUL.md';
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
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $soul = trim((string)($_POST['soul'] ?? ''));
        $enabled = ((string)($_POST['enabled'] ?? '1')) === '1';

        if ($username === '' || $categoryId <= 0) {
            $error = 'username and category_id are required.';
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
                    $message = 'Bot saved: ' . $username;
                }
            }
        }
    }
}

$bots = konvo_bot_registry_load();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Konvo Bot Admin</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; max-width: 980px; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    label { display: block; font-size: 14px; margin-bottom: 8px; }
    input, textarea, select { width: 100%; box-sizing: border-box; padding: 8px; }
    textarea { min-height: 180px; font-family: ui-monospace, Menlo, Consolas, monospace; }
    .ok { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 8px; border-radius: 6px; margin-bottom: 10px; }
    .err { background: #fef2f2; border: 1px solid #fecaca; padding: 8px; border-radius: 6px; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; text-align: left; padding: 8px; font-size: 14px; }
    button { padding: 8px 12px; cursor: pointer; }
    .muted { color: #666; font-size: 13px; }
  </style>
</head>
<body>
  <h1>Konvo Bot Admin</h1>
  <p class="muted">Use <code>?key=YOUR_SECRET</code> in the URL to manage bots.</p>

  <?php if (!$authorized): ?>
    <div class="err">Unauthorized. Add the correct <code>?key=...</code> query string.</div>
  <?php else: ?>
    <?php if ($message !== ''): ?><div class="ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
      <h3>Add / Update Bot</h3>
      <form method="post">
        <input type="hidden" name="key" value="<?= h($key) ?>">
        <input type="hidden" name="action" value="upsert">
        <div class="row">
          <label>Username
            <input name="username" placeholder="higuyer" required>
          </label>
          <label>Display Name
            <input name="name" placeholder="higuyer">
          </label>
        </div>
        <div class="row">
          <label>Category ID
            <input type="number" name="category_id" placeholder="10" required>
          </label>
          <label>Enabled
            <select name="enabled">
              <option value="1" selected>Yes</option>
              <option value="0">No</option>
            </select>
          </label>
        </div>
        <label>SOUL Content (optional, but recommended)
          <textarea name="soul" placeholder="Write bot SOUL profile here..."></textarea>
        </label>
        <button type="submit">Save Bot</button>
      </form>
    </div>

    <div class="card">
      <h3>Current Bots</h3>
      <table>
        <thead>
          <tr><th>Username</th><th>Name</th><th>Category</th><th>SOUL Key</th><th>Enabled</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($bots as $b): ?>
            <tr>
              <td><?= h((string)($b['username'] ?? '')) ?></td>
              <td><?= h((string)($b['name'] ?? '')) ?></td>
              <td><?= h((string)($b['category_id'] ?? 0)) ?></td>
              <td><?= h((string)($b['soul_key'] ?? '')) ?></td>
              <td><?= !empty($b['enabled']) ? 'yes' : 'no' ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Delete this bot?');">
                  <input type="hidden" name="key" value="<?= h($key) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="username" value="<?= h((string)($b['username'] ?? '')) ?>">
                  <button type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</body>
</html>

