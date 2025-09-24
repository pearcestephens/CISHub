<?php declare(strict_types=1);
$title='Lightspeed Queue — Tools'; $active='tools'; require __DIR__.'/header.php';
$base = SVC_BASE;
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header">SQL Migrations</div><div class="card-body">
      <p><a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/migrations.php'); ?>" target="_blank" rel="noopener">Open Migrations Tool</a></p>
      <p class="text-muted">Place .sql files in the pending folder shown in the tool. Apply is gated for safety.</p>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Utilities & Stats</div><div class="card-body">
      <p>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/verify.php'); ?>" target="_blank" rel="noopener">Verify</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/output.php'); ?>" target="_blank" rel="noopener">Last Output</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/stats.events.php'); ?>" target="_blank" rel="noopener">Event Counts</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/stats.transfers.php'); ?>" target="_blank" rel="noopener">Recent Transfers</a>
  <a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/transfer.receive.test.php'); ?>" target="_blank" rel="noopener">Receive Tester</a>
  <a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/inventory.quick_qty.test.php'); ?>" target="_blank" rel="noopener">Quick Qty Tester</a>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/transfer.inspect.php'); ?>" target="_blank" rel="noopener">Inspect Transfer</a>
      </p>
      <iframe src="<?php echo h($base.'/stats.pipeline.php'); ?>" style="width:100%;height:280px;border:1px solid #dee2e6;border-radius:4px"></iframe>
      <div class="mt-3"></div>
      <iframe src="<?php echo h($base.'/stats.inventory_quick.php'); ?>" style="width:100%;height:280px;border:1px solid #dee2e6;border-radius:4px"></iframe>
    </div></div>
  </div>
</div>
<?php require __DIR__.'/footer.php';
