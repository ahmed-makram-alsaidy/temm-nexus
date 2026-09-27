<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ControlPlane\ApiStudioService;
use App\Services\ControlPlane\CpAccess;
use Illuminate\Http\Request;

/** Phase 20I generated OpenAPI download (owner/team, snapshot-derived). */
class ProjectOpenApiController extends Controller
{
    public function __invoke(Request $request, Project $project)
    {
        CpAccess::require($request->user(), 'projects.view');

        return response()->json(ApiStudioService::openapi($project))
            ->header('Content-Disposition', 'attachment; filename="'.$project->slug.'-openapi.json"');
    }
}
