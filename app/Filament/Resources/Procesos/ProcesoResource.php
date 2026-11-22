<?php

namespace App\Filament\Resources\Procesos;

use App\Filament\Resources\Procesos\Pages\CreateProceso;
use App\Filament\Resources\Procesos\Pages\EditProceso;
use App\Filament\Resources\Procesos\Pages\ListProcesos;
use App\Filament\Resources\Procesos\Pages\ViewProceso;
use App\Filament\Resources\Procesos\Schemas\ProcesoForm;
use App\Filament\Resources\Procesos\Schemas\ProcesoInfolist;
use App\Filament\Resources\Procesos\Tables\ProcesosTable;
use App\Models\Proceso;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProcesoResource extends Resource
{
    protected static ?string $model = Proceso::class;

    protected static ?string $slug = 'procesos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Contratación';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'proceso';

    protected static ?string $pluralModelLabel = 'procesos';

    protected static ?string $recordTitleAttribute = 'descripcion_producto';

    /** Cada usuario ve solo los procesos que le corresponden (ver Proceso::scopeVisiblesPara). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visiblesPara(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return ProcesoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProcesoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProcesosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProcesos::route('/'),
            'create' => CreateProceso::route('/create'),
            'view' => ViewProceso::route('/{record}'),
            'edit' => EditProceso::route('/{record}/edit'),
        ];
    }
}
