<?php
declare(strict_types=1);

/**
 * assets/services/queue/output.php
 *
 * Secure directory snapshotter for text files with optional minify+redact.
 * API (GET):
 *   dir                = relative directory (default ".")
 *   ext                = csv of extensions to include (e.g. "php,js")
 *   name               = substring filter on filename/relative path
 *   search             = substring filter on file *content* (after minify/redact)
 *   include_hidden     = 0|1 include dotfiles (default 0)
 *   follow_symlinks    = 0|1 (default 0)
 *   minify             = 0|1 (default 1)
 *   redact             = 0|1 (default 1)   // redacts obvious secrets/tokens
 *   max_bytes          = per-file returned content cap (default 200_000, hard 2_000_000)
 *   skip               = csv of extra regex path segments to skip (appended to defaults)
 *
 * Response JSON keeps your existing contract:
 *   ok, base_dir, dir, filters{...}, index{dir=>[files...]}, files[], total_files, total_bytes
 */

//////////////////// Global headers ////////////////////
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header('Content-Type: application/json; charset=utf-8');

//////////////////// Config ////////////////////////////
const BASE_DIR      = __DIR__;
const DEFAULT_MAXB  = 200_000;
const HARD_MAXB     = 2_000_000;

const TEXT_EXT = [
  'php','phpt','phtml','html','htm','css','scss','less','js','mjs','ts','tsx',
  'json','yml','yaml','xml','md','txt','ini','conf','env','log','sql','csv'
];
const DEFAULT_SKIP = [
  '#/(vendor|node_modules|\.git|\.idea|\.vscode)(/|$)#i',
  '#/storage/framework/cache(/|$)#i'
];

//////////////////// Small utils ///////////////////////
function jexit(array $payload, int $code = 200): never {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}
function norm_path(string $p): string {
  $p = str_replace('\\', '/', $p);
  $p = preg_replace('#/+#', '/', $p);
  return rtrim($p, '/');
}
function secure_join(string $base, string $rel): string {
  $baseReal = realpath($base) ?: $base;
  $target   = norm_path($baseReal . '/' . ltrim($rel, '/'));
  $real     = realpath($target);
  if ($real === false) {
    // Path may not exist yet (e.g., empty folder). Use normalized target.
    $real = $target;
  }
  $baseN = norm_path($baseReal) . '/';
  $realN = norm_path($real) . '/';
  if (strncmp($realN, $baseN, strlen($baseN)) !== 0) {
    jexit(['ok'=>false,'error'=>'Path escapes base directory','rel'=>$rel], 400);
  }
  return rtrim($real, '/');
}
function is_text_ext(?string $ext): bool {
  return $ext !== null && in_array(strtolower($ext), TEXT_EXT, true);
}
function looks_text_file(string $path, ?string $ext): bool {
  if (is_text_ext($ext)) return true;
  $fh = @fopen($path, 'rb');
  if (!$fh) return false;
  $chunk = @fread($fh, 2048);
  @fclose($fh);
  if ($chunk === false) return false;
  if (strpos($chunk, "\0") !== false) return false;
  $len = strlen($chunk);
  if ($len === 0) return true;
  $printable = 0;
  for ($i=0; $i<$len; $i++) {
    $c = ord($chunk[$i]);
    if ($c === 9 || $c === 10 || $c === 13 || ($c >= 32 && $c <= 126) || $c >= 128) $printable++;
  }
  return ($printable / $len) > 0.70;
}

//////////////////// Minify / Redact ///////////////////
function strip_block_comments(string $s): string { return preg_replace('#/\*.*?\*/#s', '', $s) ?? $s; }
function strip_line_comments(string $s): string {
  $out = [];
  $len = strlen($s);
  $i = 0; $inS = false; $inD = false;
  while ($i < $len) {
    $c = $s[$i];
    $n = $i+1 < $len ? $s[$i+1] : '';
    if (!$inS && !$inD && $c === '/' && $n === '/') { while ($i < $len && $s[$i] !== "\n") $i++; continue; }
    if ($c === "'" && !$inD) { $inS = !$inS; }
    if ($c === '"' && !$inS) { $inD = !$inD; }
    $out[] = $c; $i++;
  }
  return implode('', $out);
}
function min_css(string $s): string { $s = strip_block_comments($s); $s = preg_replace('/\s+/', ' ', $s) ?? $s; $s = preg_replace('/\s*([{};:>,])\s*/', '$1', $s) ?? $s; return trim($s); }
function min_js(string $s): string { $s = strip_block_comments($s); $s = strip_line_comments($s); $s = preg_replace('/\s+/', ' ', $s) ?? $s; $s = preg_replace('/\s*([{};,:()\[\]=<>+\-*\/&|!?])\s*/', '$1', $s) ?? $s; return trim($s); }
function min_json_str(string $s): string { $d = json_decode($s, true); if ($d === null && json_last_error() !== JSON_ERROR_NONE) return trim($s); return json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function min_xml_html(string $s): string { $s = preg_replace('#<!--(?!\[if).*?-->#s', '', $s) ?? $s; $s = preg_replace('/\s+/', ' ', $s) ?? $s; $s = preg_replace('/\s*(<|>)\s*/', '$1', $s) ?? $s; return trim($s); }
function strip_php_comments(string $s): string { $s = strip_block_comments($s); $out = []; $len = strlen($s); $i = 0; $inS = false; $inD = false; while ($i < $len) { $c = $s[$i]; $n = $i+1 < $len ? $s[$i+1] : ''; if (!$inS && !$inD && $c === '/' && $n === '/') { while ($i < $len && $s[$i] !== "\n") $i++; continue; } if ($c === "'" && !$inD) { $inS = !$inS; } if ($c === '"' && !$inS) { $inD = !$inD; } $out[] = $c; $i++; } return implode('', $out); }
function min_php(string $s): string { $s = strip_php_comments($s); $s = preg_replace('/[\t ]+/', ' ', $s) ?? $s; $s = preg_replace('/\s*([{};,:()\[\]=<>+\-*\/&|!?])\s*/', '$1', $s) ?? $s; $s = preg_replace('/\n+/', "\n", $s) ?? $s; return trim($s); }
function min_generic(string $s): string { $s = strip_block_comments($s); $s = preg_replace('/\s+/', ' ', $s) ?? $s; return trim($s); }
function redact_secrets(string $s): string { $s = preg_replace('/(?i)\b(password|passwd|secret|api[_-]?key|token|authorization|auth[_-]?key|private[_-]?key)\s*[:=]\s*(["\']?)[^\s"\']+\2/', '$1=$2REDACTED$2', $s) ?? $s; $s = preg_replace('/(?m)^(?i)([A-Z0-9_]*?(KEY|TOKEN|SECRET|PASSWORD)[A-Z0-9_]*)\s*=\s*.+$/', '$1=REDACTED', $s) ?? $s; $s = preg_replace('/(["\'])[A-Za-z0-9+\/=]{24,}\1/', '$1REDACTED$1', $s) ?? $s; return $s; }
function minify_and_redact(string $ext, string $data, bool $doMinify, bool $doRedact): string { if ($doRedact) $data = redact_secrets($data); if (!$doMinify) return $data; $e = strtolower($ext); if (in_array($e, ['css','scss','less'], true)) return min_css($data); if (in_array($e, ['js','mjs','ts','tsx'], true)) return min_js($data); if ($e === 'json') return min_json_str($data); if (in_array($e, ['xml','html','htm'], true)) return min_xml_html($data); if (in_array($e, ['php','phtml','phpt'], true)) return min_php($data); return min_generic($data); }

//////////////////// Params ////////////////////////////
$relDir         = $_GET['dir'] ?? '.';
$extFilter      = [];
if (!empty($_GET['ext'])) {
  $extFilter = array_filter(array_map('strtolower', array_map('trim', explode(',', (string)$_GET['ext']))));
}
$nameFilter     = isset($_GET['name'])   ? (string)$_GET['name']   : '';
$contentSearch  = isset($_GET['search']) ? (string)$_GET['search'] : '';
$includeHidden  = !empty($_GET['include_hidden']) && ((int)$_GET['include_hidden'] === 1);
$followSymlinks = !empty($_GET['follow_symlinks']) && ((int)$_GET['follow_symlinks'] === 1);
$doMinify       = !isset($_GET['minify']) || (int)$_GET['minify'] === 1;
$doRedact       = !isset($_GET['redact']) || (int)$_GET['redact'] === 1;
$maxBytes       = isset($_GET['max_bytes']) ? (int)$_GET['max_bytes'] : DEFAULT_MAXB;
$maxBytes       = max(8_000, min($maxBytes, HARD_MAXB));
$extraSkip      = [];
if (!empty($_GET['skip'])) {
  foreach (explode(',', (string)$_GET['skip']) as $n) {
    $n = trim($n);
    if ($n !== '') $extraSkip[] = '#/'.preg_quote($n, '#').'(/|$)#i';
  }
}
$skipRes = array_merge(DEFAULT_SKIP, $extraSkip);

//////////////////// Resolve & guard ///////////////////
$root = secure_join(BASE_DIR, $relDir);
if (!is_dir($root)) {
  jexit(['ok'=>false,'error'=>'Not a directory','dir'=>$relDir], 400);
}
$rootNorm = norm_path($root);
$rootLen  = strlen($rootNorm) + 1;

//////////////////// Walk //////////////////////////////
$it = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator(
    $root,
    FilesystemIterator::SKIP_DOTS
  ),
  RecursiveIteratorIterator::SELF_FIRST
);

$index = []; // dir => [files...]
$files = []; // list of file objects
$totalBytes = 0;

foreach ($it as $info) {
  /** @var SplFileInfo $info */
  $path = $info->getPathname();
  if (is_link($path) && !$followSymlinks) continue;

  $rel = substr(norm_path($path), $rootLen);
  if ($rel === false) $rel = $info->getFilename();
  $rel = ltrim($rel, '/');

  // skip patterns
  $subject = '/'.$rel;
  $skip = false;
  foreach ($skipRes as $re) { if (preg_match($re, $subject)) { $skip = true; break; } }
  if ($skip) continue;

  if ($info->isDir()) {
    $dkey = norm_path($rel);
    if (!isset($index[$dkey])) $index[$dkey] = [];
    continue;
  }
  if (!$info->isFile()) continue;

  $base = basename($path);
  if (!$includeHidden && $base !== '' && $base[0] === '.') continue;

  // NEW: name filter on relative path and filename
  if ($nameFilter !== '' && stripos($rel, $nameFilter) === false && stripos($base, $nameFilter) === false) continue;

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if ($extFilter && !in_array($ext, $extFilter, true)) continue;

  // Skip binary files (unless ext is known text)
  if (!looks_text_file($path, $ext)) continue;

  $data = @file_get_contents($path);
  if ($data === false) continue;

  // Normalize to UTF-8 if needed
  $encOk = function_exists('mb_detect_encoding') ? mb_detect_encoding($data, 'UTF-8', true) : true;
  if (!$encOk && function_exists('mb_convert_encoding')) {
    $data = mb_convert_encoding($data, 'UTF-8', 'auto');
  }

  $data = minify_and_redact($ext, $data, $doMinify, $doRedact);

  // Content search (after minify/redact)
  if ($contentSearch !== '' && stripos($data, $contentSearch) === false) continue;

  $size    = strlen($data);
  $trunc   = false;
  if ($size > $maxBytes) {
    $data  = substr($data, 0, $maxBytes);
    $trunc = true;
  }

  $dirKey = norm_path(dirname($rel));
  if ($dirKey === '.') $dirKey = '';
  if (!isset($index[$dirKey])) $index[$dirKey] = [];
  $index[$dirKey][] = $rel;

  $files[] = [
    'path'      => $rel,
    'ext'       => $ext,
    'size'      => $info->getSize(),
    'mtime'     => $info->getMTime(),
    'truncated' => $trunc,
    'content'   => $data,
  ];
  $totalBytes += strlen($data);
}

//////////////////// Sort & build //////////////////////
ksort($index, SORT_STRING);
foreach ($index as $k => &$arr) { sort($arr, SORT_STRING); }
unset($arr);
usort($files, fn($a,$b) => strcmp($a['path'], $b['path']));

//////////////////// Output ///////////////////////////
jexit([
  'ok'          => true,
  'base_dir'    => BASE_DIR,
  'dir'         => $relDir,
  'filters'     => [
    'ext'            => $extFilter,
    'name'           => $nameFilter,
    'search'         => $contentSearch,
    'include_hidden' => $includeHidden,
    'follow_symlinks'=> $followSymlinks,
    'minify'         => $doMinify,
    'redact'         => $doRedact,
    'max_bytes'      => $maxBytes,
    'skip_res'       => $skipRes,
  ],
  'index'       => $index,     // directory -> files[]
  'files'       => $files,     // [{path, ext, size, mtime, truncated, content}]
  'total_files' => count($files),
  'total_bytes' => $totalBytes,
]);
