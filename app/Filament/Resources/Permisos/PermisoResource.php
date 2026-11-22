<?php

namespace App\Filament\Resources\Permisos;

use App\Filament\Resources\Permisos\Pages\ListPermisos;
use App\Filament\Resources\Permisos\Tables\PermisosTable;
use App\Models\Permiso;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Solo consulta: los permisos son un contrato con el codigo y se cargan por migracion. */
class PermisoResource extends Resource
{
    protected static ?string $model = Permiso::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'permisos';

    protected static ?string $modelLabel = 'permiso';

    protected static ?string $pluralModelLabel = 'permisos';

    protected static ?string $recordTitleAttribute = 'codigo';

    public static function table(Table $table): Table
    {
        return PermisosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermisos::route('/'),
        ];
    }
}
