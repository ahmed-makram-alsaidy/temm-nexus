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

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    /** 0.6.0 Phase B: the destination is "Activity" (audit §B1); the audit
     * trail vocabulary stays in filters and detail views. */
    protected static ?string $pluralModelLabel = 'Activity';

    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string
    {
        return __('nav.activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.activity');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AdminAuditEntry::query()->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')->label(__('labels.time'))->dateTime()->sortable(),
                TextColumn::make('owner.email')->label(__('labels.owner'))->placeholder('system')->searchable(),
                TextColumn::make('project_slug')->label(__('labels.project'))->badge()->searchable(),
                // 0.6.0 Phase A (§A6/§A5): human action labels on the primary
                // surface; the raw verb stays in the detail drawer and filter.
                TextColumn::make('action')
                    ->label(__('nav.activity'))
                    ->badge()
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => \App\Support\ActivityHumanizer::humanize($state))
                    ->tooltip(fn (AdminAuditEntry $record): string => $record->action),
                TextColumn::make('target_type')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('target_id')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('owner_user_id')->label(__('labels.actor'))
                    ->relationship('owner', 'email')->searchable()->preload(),
                SelectFilter::make('action')->options(array_combine(AdminAuditEntry::ACTIONS, AdminAuditEntry::ACTIONS)),
                SelectFilter::make('project_slug')->label(__('labels.project'))
                    ->options(fn () => \App\Models\Project::pluck('slug', 'slug')->all()),
                SelectFilter::make('target_type')->label(__('labels.resource'))
                    ->options(fn () => AdminAuditEntry::query()->distinct()
                        ->whereNotNull('target_type')->pluck('target_type', 'target_type')->all()),
                Filter::make('created_at')->label(__('labels.time'))
                    ->schema([
                        DatePicker::make('from')->label(__('labels.from')),
                        DatePicker::make('until')->label(__('labels.until')),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->recordActions([
                Action::make('view_entry')->label(__('labels.detail'))->icon('heroicon-o-eye')
                    ->slideOver()->modalSubmitAction(false)->modalCancelActionLabel(__('common.close'))
                    ->modalContent(fn (AdminAuditEntry $record) => new HtmlString(
                        '<dl class="cp-kv">'
                        .'<dt>'.e(__('labels.time')).'</dt><dd><code dir="ltr">'.e($record->created_at?->toDateTimeString() ?? '—').'</code></dd>'
                        .'<dt>'.e(__('labels.actor')).'</dt><dd><code dir="ltr">'.e($record->owner?->email ?? __('labels.aud_system')).'</code></dd>'
                        .'<dt>'.e(__('labels.project')).'</dt><dd>'.e($record->project_slug ?? '—').'</dd>'
                        .'<dt>'.e(__('nav.activity')).'</dt><dd>'.e(\App\Support\ActivityHumanizer::humanize($record->action)).' <code style="opacity:.65" dir="ltr">('.e($record->action).')</code></dd>'
                        .'<dt>'.e(__('labels.resource')).'</dt><dd><code dir="ltr">'.e(trim(($record->target_type ?? '').' '.($record->target_id ?? '')) ?: '—').'</code></dd>'
                        .'<dt>IP</dt><dd><code dir="ltr">'.e($record->ip ?? '—').'</code></dd>'
                        .'</dl>'
                        .'<h4 style="margin:.75rem 0 .25rem">'.e(__('labels.aud_metadata')).'</h4>'
                        .'<div class="cp-code">'.e(json_encode($record->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</div>'
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('labels.aud_empty_title'))
            ->emptyStateDescription(__('labels.aud_empty_body'));
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
