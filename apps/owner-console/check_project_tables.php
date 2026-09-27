<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$project = \App\Models\Project::find(10);
$connName = \App\Services\ControlPlane\ProjectConnectionManager::connection($project);
$conn = DB::connection($connName);
$tables = $conn->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name;");
foreach ($tables as $t) {
    echo $t->table_name . "\n";
}