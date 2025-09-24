<?php declare(strict_types=1);
$title='Lightspeed — Transfers'; $active='transfers'; require __DIR__.'/header.php';
require_once __DIR__ . '/../../../src/PdoConnection.php';
use Queue\PdoConnection;

$since = isset($_GET['since_date']) ? (string)$_GET['since_date'] : '';
$type  = isset($_GET['type']) ? (string)$_GET['type'] : '';
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;

$rows = []; $err = '';
try {
  $pdo = PdoConnection::instance();
  $where = []; $params = [];
  if ($since !== '') { $where[] = 't.created_at >= :sd'; $params[':sd'] = $since; }
  if ($type !== '') { $where[] = 'UPPER(t.type) IN (:ty1,:ty2,:ty3,:ty4,:ty5,:ty6,:ty7,:ty8)';
    $ty = strtoupper(trim($type));
    // expand synonyms to improve matching
    $map = [ 'ST' => ['ST','STOCK','STOCKTAKE','STOCK TAKE','STOCK-TAKE','STK'],
             'JT' => ['JT','JUICE','JUI'],
             'IT' => ['IT','INTERNAL','INTER','SPECIAL','STAFF'],
             'STAFF' => ['STAFF','IT','INTERNAL','INTER','SPECIAL'],
             'RT' => ['RT','RETURN','RET'] ];
    $list = $map[$ty] ?? [$ty];
    // pad to 8 for static placeholders
    $list = array_slice(array_merge($list, array_fill(0, 8, $list[0])), 0, 8);
    $params[':ty1']=$list[0]; $params[':ty2']=$list[1]; $params[':ty3']=$list[2]; $params[':ty4']=$list[3]; $params[':ty5']=$list[4]; $params[':ty6']=$list[5]; $params[':ty7']=$list[6]; $params[':ty8']=$list[7];
  }
  $sql = 'SELECT t.id, t.public_id, t.type, t.status, t.outlet_from, t.outlet_to, t.created_at, t.received_at FROM transfers t';
  if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
  $sql .= ' ORDER BY t.id DESC LIMIT ' . (int)$limit;
  $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { $err = $e->getMessage(); }
?>
<div class="card mb-3"><div class="card-header">Filters</div><div class="card-body">
  <form class="row gy-2 gx-3 align-items-end" method="get">
    <div class="col-auto">
      <label class="form-label">Since date</label>
      <input type="date" name="since_date" class="form-control" value="<?php echo h($since); ?>">
      <div class="form-text">YYYY-MM-DD</div>
    </div>
    <div class="col-auto">
      <label class="form-label">Type</label>
      <select name="type" class="form-select">
        <?php $types=['','ST','JT','IT','STAFF','RT']; foreach ($types as $t): ?>
          <option value="<?php echo h($t); ?>" <?php echo $type===$t?'selected':''; ?>><?php echo $t===''?'(any)':$t; ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Synonyms supported (e.g., STOCK=ST, STAFF=IT)</div>
    </div>
    <div class="col-auto">
      <label class="form-label">Limit</label>
      <input type="number" min="1" max="500" name="limit" class="form-control" value="<?php echo (int)$limit; ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-primary" type="submit">Apply</button>
    </div>
  </form>
</div></div>

<?php if ($err): ?><div class="alert alert-warning">DB error: <?php echo h($err); ?></div><?php endif; ?>
<?php if (!$rows): ?><div class="text-muted">No transfers match your filters.</div><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr>
    <th>ID</th><th>Public</th><th>Type</th><th>Status</th><th>From → To</th><th>Created</th><th>Received</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="kv"><a href="<?php echo SVC_BASE.'/public/transfer.inspect.php?id='.(int)$r['id']; ?>" target="_blank" rel="noopener"><?php echo (int)$r['id']; ?></a></td>
      <td class="kv"><?php echo h($r['public_id']); ?></td>
      <td><span class="badge bg-secondary"><?php echo h(strtoupper((string)$r['type'])); ?></span></td>
      <td><?php echo h((string)$r['status']); ?></td>
      <td class="kv text-muted"><?php echo h((string)$r['outlet_from']); ?> → <?php echo h((string)$r['outlet_to']); ?></td>
      <td class="kv text-muted"><?php echo h((string)$r['created_at']); ?></td>
      <td class="kv text-muted"><?php echo h((string)($r['received_at'] ?? '')); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php require __DIR__.'/footer.php';
