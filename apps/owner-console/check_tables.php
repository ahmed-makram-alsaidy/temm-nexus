<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$tables = DB::connection('pgsql')->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'app_versions';");
var_dump($tables);