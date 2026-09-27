<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ManageProjectPermissions;
use App\Filament\Resources\Projects\Pages\ManageProjectRoles;
use App\Filament\Resources\Projects\Pages\ManageProjectUsers;
use App\Filament\Resources\Projects\Pages\ProjectAuthSecurity;
use App\Filament\Resources\Projects\Pages\ProjectApi;
use App\Filament\Resources\Projects\Pages\ProjectApiKeys;
use App\Filament\Resources\Projects\Pages\ProjectConnect;
use App\Filament\Resources\Projects\Pages\ProjectFunctionEditor;
use App\Filament\Resources\Projects\Pages\ProjectFunctions;
use App\Filament\Resources\Projects\Pages\ProjectFunctionTester;
use App\Filament\Resources\Projects\Pages\ProjectInfrastructure;
use App\Filament\Resources\Projects\Pages\ProjectSecrets;
use App\Filament\Resources\Projects\Pages\ProjectWebhooks;
use App\Filament\Resources\Projects\Pages\ProjectBackups;
use App\Filament\Resources\Projects\Pages\ProjectDatabase;
use App\Filament\Resources\Projects\Pages\ProjectConnections;
use App\Filament\Resources\Projects\Pages\ProjectDbAdvanced;
use App\Filament\Resources\Projects\Pages\ProjectDbFunctions;
use App\Filament\Resources\Projects\Pages\ProjectDbHealth;
use App\Filament\Resources\Projects\Pages\ProjectErd;
use App\Filament\Resources\Projects\Pages\ProjectLogs;
use App\Filament\Resources\Projects\Pages\ProjectMigrations;
use App\Filament\Resources\Projects\Pages\ProjectMonitoring;
use App\Filament\Resources\Projects\Pages\ProjectQueues;
use App\Filament\Resources\Projects\Pages\ProjectRealtime;
use App\Filament\Resources\Projects\Pages\ProjectScheduler;
use App\Filament\Resources\Projects\Pages\ProjectSessions;
use App\Filament\Resources\Projects\Pages\ProjectSettings;
use App\Filament\Resources\Projects\Pages\ProjectSqlEditor;
use App\Filament\Resources\Projects\Pages\ProjectStorage;
use App\Filament\Resources\Projects\Pages\ProjectTableRecords;
use App\Filament\Resources\Projects\Pages\ProjectTableSchema;
use App\Filament\Resources\Projects\Pages\ProjectClientRepository;
use App\Filament\Resources\Projects\Pages\ProjectCopilot;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\Pages\ProjectEnvironments;
use App\Filament\Resources\Projects\Pages\ProjectMigrationCenter;
use App\Filament\Resources\Projects\Pages\ProjectReadiness;
use App\Filament\Resources\Projects\Pages\ProjectResources;
use App\Filament\Resources\Projects\Pages\ProjectSchemaDiff;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Projects';

    protected static string|\UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
            'overview' => ViewProject::route('/{record}'),
            'users' => ManageProjectUsers::route('/{record}/users'),
            'auth-security' => ProjectAuthSecurity::route('/{record}/auth-security'),
            'roles' => ManageProjectRoles::route('/{record}/roles'),
            'permissions' => ManageProjectPermissions::route('/{record}/permissions'),
            'sessions' => ProjectSessions::route('/{record}/sessions'),
            'database' => ProjectDatabase::route('/{record}/database'),
            'sql' => ProjectSqlEditor::route('/{record}/sql'),
            'db-functions' => ProjectDbFunctions::route('/{record}/db-functions'),
            'db-advanced' => ProjectDbAdvanced::route('/{record}/db-advanced'),
            'erd' => ProjectErd::route('/{record}/erd'),
            'migrations' => ProjectMigrations::route('/{record}/migrations'),
            'records' => ProjectTableRecords::route('/{record}/records'),
            'table-schema' => ProjectTableSchema::route('/{record}/schema'),
            'db-health' => ProjectDbHealth::route('/{record}/db-health'),
            'storage' => ProjectStorage::route('/{record}/storage'),
            'api' => ProjectApi::route('/{record}/api'),
            'connect' => ProjectConnect::route('/{record}/connect'),
            'keys' => ProjectApiKeys::route('/{record}/keys'),
            'functions' => ProjectFunctions::route('/{record}/functions'),
            'webhooks' => ProjectWebhooks::route('/{record}/webhooks'),
            'function-editor' => ProjectFunctionEditor::route('/{record}/functions/{fn}'),
            'function-tester' => ProjectFunctionTester::route('/{record}/functions/{fn}/test'),
            'realtime' => ProjectRealtime::route('/{record}/realtime'),
            'queues' => ProjectQueues::route('/{record}/queues'),
            'scheduler' => ProjectScheduler::route('/{record}/scheduler'),
            'logs' => ProjectLogs::route('/{record}/logs'),
            'backups' => ProjectBackups::route('/{record}/backups'),
            'monitoring' => ProjectMonitoring::route('/{record}/monitoring'),
            'secrets' => ProjectSecrets::route('/{record}/secrets'),
            'connections' => ProjectConnections::route('/{record}/connections'),
            'infrastructure' => ProjectInfrastructure::route('/{record}/infrastructure'),
            'settings' => ProjectSettings::route('/{record}/settings'),

            // Phase 24 — Platform Productization.
            'environments' => ProjectEnvironments::route('/{record}/environments'),
            'migration-center' => ProjectMigrationCenter::route('/{record}/migration-center'),
            'schema-diff' => ProjectSchemaDiff::route('/{record}/schema-diff'),
            'resources' => ProjectResources::route('/{record}/resources'),
            'readiness' => ProjectReadiness::route('/{record}/readiness'),

            // Phase 25 — Supabase + AI Migration Copilot.
            'client-repository' => ProjectClientRepository::route('/{record}/client-repository'),
            'copilot' => ProjectCopilot::route('/{record}/copilot'),
        ];
    }
}
