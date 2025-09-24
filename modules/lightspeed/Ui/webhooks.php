<?php declare(strict_types=1);
$title='Lightspeed Queue — Webhooks'; $active='webhooks'; require __DIR__.'/header.php';
$base = SVC_BASE;
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Webhook Statistics</div><div class="card-body">
      <p><a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/webhook.stats.php'); ?>" target="_blank" rel="noopener">Open Webhook Stats</a></p>
      <iframe src="<?php echo h($base.'/webhook.stats.php'); ?>" style="width:100%;height:360px;border:1px solid #dee2e6;border-radius:4px"></iframe>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Recent Webhook Events</div><div class="card-body">
      <p><a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/webhook.events.php'); ?>" target="_blank" rel="noopener">Open Webhook Events</a></p>
      <iframe src="<?php echo h($base.'/webhook.events.php'); ?>" style="width:100%;height:360px;border:1px solid #dee2e6;border-radius:4px"></iframe>
    </div></div>
  </div>
</div>
<?php require __DIR__.'/footer.php';
