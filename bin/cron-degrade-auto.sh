#!/bin/sh
# Periodically run the degrade auto-evaluator to flip safeguards during incidents.
# Safe to run every minute via cron.

APP_ROOT="/home/master/applications/jcepnzzkmj/public_html/assets/services/queue"
PHP_BIN="php"
URL="${APP_ROOT}/public/degrade.toggle.php"

# Use PHP CLI to invoke the endpoint directly by including the file,
# setting the method and JSON body in the environment.
REQUEST_METHOD=POST PHP_INPUT='{"action":"auto_eval"}' $PHP_BIN -d variables_order=EGPCS -r '
$_SERVER["REQUEST_METHOD"] = "POST";
$in = ["action"=>"auto_eval"];
file_put_contents("php://input", json_encode($in));
require_once getenv("APP_ROOT") . "/public/degrade.toggle.php";
' >/dev/null 2>&1 || true
