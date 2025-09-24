<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/OAuthClient.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\Config;
use Queue\Lightspeed\OAuthClient;

Http::commonJsonHeaders();

function mask_tail(?string $v, int $keep=4){ if(!$v) return null; $len=strlen($v); return ($len<=$keep)?str_repeat('•',$len):str_repeat('•',$len-$keep).substr($v,-$keep); }

try {
  $prefix = (string)(Config::get('vend_domain_prefix','') ?? Config::get('vend.domain_prefix','') ?? '');
  $apiBase= (string)(Config::get('vend.api_base','') ?? '');
  $tok    = (string)(Config::get('vend_access_token','') ?? '');
  $exp    = (int)(Config::get('vend_token_expires_at',0) ?? 0);

  // compute token endpoint using current prefix (don’t throw if empty)
  $tokenEndpoint = ($prefix!=='')
    ? sprintf('https://%s.retail.lightspeed.app/api/1.0/token',$prefix)
    : null;

  Http::respond(true, [
    'vend_domain_prefix' => $prefix,
    'vend.api_base'      => $apiBase,
    'token_endpoint'     => $tokenEndpoint,  // expected OAuth URL
    'access_token_set'   => $tok !== '',
    'access_token_tail'  => mask_tail($tok, 6),
    'expires_at'         => $exp ?: null,
    'expires_in'         => $exp ? max(0, $exp - time()) : null
  ]);
} catch (\Throwable $e) {
  Http::error('vendor_snapshot_failed', $e->getMessage(), null, 500);
}
