#!/usr/bin/env bash
# 18C/18B isolation spot-checks through the console's own service layer.
set -euo pipefail
echo "--- demo users via console manager"
docker compose exec -T owner-console php artisan tinker --execute='$p=App\Models\Project::where("slug","control-plane-demo")->first(); echo App\Services\ControlPlane\ProjectAuthManager::for($p)->usersQuery()->pluck("email")->implode(",")."\n";' 2>&1 | tail -2
echo "--- template-api users via console manager"
docker compose exec -T owner-console php artisan tinker --execute='$p=App\Models\Project::where("slug","template-api")->first(); echo App\Services\ControlPlane\ProjectAuthManager::for($p)->usersQuery()->pluck("email")->implode(",")."\n";' 2>&1 | tail -2
