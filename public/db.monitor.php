<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';

use Queue\Http;
use Queue\Lightspeed\Web;

// Auth (reuse the same bearer policy; monitor is admin-only)
if (!Http::ensureAuth()) { exit; }

[$ok, $data] = Web::dbSanityData();
$tables = $data['tables'] ?? [];
$db = htmlspecialchars((string)($data['db'] ?? 'unknown'));
$checkedAt = htmlspecialchars((string)($data['checked_at'] ?? date('c')));
$writeProbe = (string)($data['write_probe'] ?? 'n/a');

$bad = !$ok;
foreach ($tables as $t => $info) {
    if (empty($info['exists'])) { $bad = true; break; }
}
$statusColor = $bad ? '#dc3545' : '#28a745';
$statusText = $bad ? 'CRITICAL' : 'HEALTHY';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CIS DB Monitor</title>
  <style>
    body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Helvetica Neue,Arial,Noto Sans,sans-serif; margin: 16px; }
    h1 { margin: 0 0 8px 0; }
    .status { display: inline-flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; color: #fff; background: <?php echo $statusColor; ?>; font-weight: 600; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; margin-top: 16px; }
    .card { border: 1px solid #e1e4e8; border-radius: 8px; padding: 12px; background: #fff; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #eee; font-size: 14px; }
    th { text-align: left; background: #f6f8fa; position: sticky; top: 0; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; color: #fff; font-weight: 600; }
    .ok { background: #28a745; }
    .warn { background: #ffc107; color: #000; }
    .bad { background: #dc3545; }
    .muted { color: #6a737d; font-size: 12px; }
    .mono { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,monospace; }
  </style>
</head>
<body>
  <h1>Database Monitor</h1>
  <div class="status">Status: <?php echo $statusText; ?></div>
  <p class="muted">Database: <span class="mono"><?php echo $db; ?></span> · Checked: <?php echo $checkedAt; ?> · Write probe: <span class="mono"><?php echo htmlspecialchars($writeProbe); ?></span></p>

  <div class="card">
    <h3>Tables</h3>
    <div style="max-height: 70vh; overflow: auto;">
      <table>
        <thead>
          <tr>
            <th>Table</th>
            <th>Exists</th>
            <th>Approx Rows</th>
            <th>Last Activity</th>
            <th>Indexes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tables as $name => $info):
            $exists = !empty($info['exists']);
            $rowc = $info['row_count'] ?? null; $rowTxt = ($rowc === null) ? 'n/a' : (string)$rowc;
            $act = $info['last_activity'] ?? null; $actTxt = $act ? htmlspecialchars((string)$act) : 'n/a';
            $pillCls = $exists ? 'ok' : 'bad';
          ?>
          <tr>
            <td class="mono"><?php echo htmlspecialchars((string)$name); ?></td>
            <td><span class="pill <?php echo $pillCls; ?>"><?php echo $exists ? 'yes' : 'NO'; ?></span></td>
            <td class="mono"><?php echo htmlspecialchars($rowTxt); ?></td>
            <td class="mono"><?php echo $actTxt; ?></td>
            <td>
              <?php if (!empty($info['indexes']) && is_array($info['indexes'])): ?>
                <?php foreach ($info['indexes'] as $k => $v):
                  $icls = ($v === 'ok') ? 'ok' : 'warn';
                  $label = ($v === 'ok') ? 'ok' : 'missing';
                ?>
                  <span class="pill <?php echo $icls; ?>" title="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="muted">n/a</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p class="muted">Endpoints: <a href="https://staff.vapeshed.co.nz/assets/services/queue/public/db.sanity.php">JSON sanity</a> · <a href="https://staff.vapeshed.co.nz/assets/services/queue/public/api.html">API index</a></p>
</body>
</html>
