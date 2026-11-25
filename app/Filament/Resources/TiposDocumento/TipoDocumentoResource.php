<?php

namespace App\Filament\Resources\TiposDocumento;

use App\Filament\Resources\TiposDocumento\Pages\CreateTipoDocumento;
use App\Filament\Resources\TiposDocumento\Pages\EditTipoDocumento;
use App\Filament\Resources\TiposDocumento\Pages\ListTiposDocumento;
use App\Filament\Resources\TiposDocumento\Schemas\TipoDocumentoForm;
use App\Filament\Resources\TiposDocumento\Tables\TiposDocumentoTable;
use App\Models\TipoDocumento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Catalogo de tipos de documento (solo administrador: ver TipoDocumentoPolicy). */
class TipoDocumentoResource extends Resource
{
    protected static ?string $model = TipoDocumento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'tipos-documento';

    protected static ?string $modelLabel = 'tipo de documento';

    protected static ?string $pluralModelLabel = 'tipos de documento';

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function form(Schema $schema): Schema
    {
        return TipoDocumentoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TiposDocumentoTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTiposDocumento::route('/'),
            'create' => CreateTipoDocumento::route('/create'),
            'edit' => EditTipoDocumento::route('/{record}/edit'),
        ];
    }
}
