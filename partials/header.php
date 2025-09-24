<?php
declare(strict_types=1);
/**
 * Partial: header.php
 * Purpose: Shared header for Queue service dashboards (Bootstrap + base styles)
 * Author: GitHub Copilot
 * Last Modified: 2025-09-21
 */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Queue Dashboard', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link rel="preconnect" href="https://staff.vapeshed.co.nz">
  <meta http-equiv="x-content-type-options" content="nosniff" />
  <meta http-equiv="referrer" content="no-referrer" />
  <meta http-equiv="cache-control" content="no-store" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="referrer" content="no-referrer" />
  <meta name="color-scheme" content="light dark">
  <link rel="stylesheet" href="/assets/services/queue/assets/css/dashboard.css">
</head>
<body>
<div class="container">
