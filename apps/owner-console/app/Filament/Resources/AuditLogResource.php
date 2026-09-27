<?php

namespace App\Filament\Resources;

use App\Models\AdminAuditEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Immutable-style admin audit trail: search + filters, NO create/edit/delete.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Audit Log';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $pluralModelLabel = 'Audit Log';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AdminAuditEntry::query()->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime()->sortable(),
                TextColumn::make('owner.email')->label('Owner')->placeholder('system')->searchable(),
                TextColumn::make('project_slug')->label('Project')->badge()->searchable(),
                TextColumn::make('action')->badge()->searchable(),
                TextColumn::make('target_type')->placeholder('—')->toggleable(),
                TextColumn::make('target_id')->placeholder('—')->toggleable(),
                TextColumn::make('ip')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('owner_user_id')->label('Actor')
                    ->relationship('owner', 'email')->searchable()->preload(),
                SelectFilter::make('action')->options(array_combine(AdminAuditEntry::ACTIONS, AdminAuditEntry::ACTIONS)),
                SelectFilter::make('project_slug')->label('Project')
                    ->options(fn () => \App\Models\Project::pluck('slug', 'slug')->all()),
                SelectFilter::make('target_type')->label('Resource')
                    ->options(fn () => AdminAuditEntry::query()->distinct()
                        ->whereNotNull('target_type')->pluck('target_type', 'target_type')->all()),
                Filter::make('created_at')->label('Time')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->recordActions([
                Action::make('view_entry')->label('Detail')->icon('heroicon-o-eye')
                    ->slideOver()->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AdminAuditEntry $record) => new HtmlString(
                        '<dl class="cp-kv">'
                        .'<dt>Time</dt><dd>'.e($record->created_at?->toDateTimeString() ?? '—').'</dd>'
                        .'<dt>Actor</dt><dd>'.e($record->owner?->email ?? 'system').'</dd>'
                        .'<dt>Project</dt><dd>'.e($record->project_slug ?? '—').'</dd>'
                        .'<dt>Action</dt><dd>'.e($record->action).'</dd>'
                        .'<dt>Resource</dt><dd>'.e(trim(($record->target_type ?? '').' '.($record->target_id ?? '')) ?: '—').'</dd>'
                        .'<dt>IP</dt><dd>'.e($record->ip ?? '—').'</dd>'
                        .'</dl>'
                        .'<h4 style="margin:.75rem 0 .25rem">Metadata</h4>'
                        .'<div class="cp-code">'.e(json_encode($record->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</div>'
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No audit entries yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\AuditLogList::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
