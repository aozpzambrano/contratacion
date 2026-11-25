<?php

namespace App\Filament\Resources\RequisitosEnvio;

use App\Filament\Resources\RequisitosEnvio\Pages\CreateRequisitoTransicion;
use App\Filament\Resources\RequisitosEnvio\Pages\EditRequisitoTransicion;
use App\Filament\Resources\RequisitosEnvio\Pages\ListRequisitosTransicion;
use App\Models\EstadoDocumento;
use App\Models\RequisitoTransicion;
use App\Models\TipoDocumento;
use App\Models\TransicionProceso;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Documentos obligatorios para ejecutar una transicion del proceso (p. ej. para "Enviar a revision").
 * Solo administrador (ver RequisitoTransicionPolicy). La base los valida en aplicar_transicion_proceso().
 */
class RequisitoTransicionResource extends Resource
{
    protected static ?string $model = RequisitoTransicion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'documentos-obligatorios';

    protected static ?string $modelLabel = 'documento obligatorio';

    protected static ?string $pluralModelLabel = 'documentos obligatorios';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Documento obligatorio')
                ->columns(1)
                ->schema([
                    Select::make('transicion_id')
                        ->label('Acción del proceso')
                        ->options(fn () => TransicionProceso::with(['estadoOrigen', 'estadoDestino'])
                            ->where('activo', true)->orderBy('id')->get()
                            ->mapWithKeys(fn (TransicionProceso $t) => [
                                $t->id => "{$t->nombre} ({$t->estadoOrigen?->nombre} → {$t->estadoDestino?->nombre})",
                            ]))
                        ->searchable()
                        ->required()
                        ->live(),
                    Select::make('tipo_documento_id')
                        ->label('Tipo de documento exigido')
                        ->options(fn () => TipoDocumento::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id'))
                        ->searchable()
                        ->required()
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('transicion_id', $get('transicion_id')),
                        ),
                    Select::make('estado_documento_minimo_id')
                        ->label('Estado mínimo del documento')
                        ->options(fn () => EstadoDocumento::where('activo', true)->orderBy('orden')->pluck('nombre', 'id'))
                        ->helperText('Para enviar a revisión basta «Borrador» (con su archivo cargado). El documento debe estar en este estado o en uno posterior.')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['transicion.estadoOrigen', 'transicion.estadoDestino', 'tipoDocumento', 'estadoMinimo']))
            ->columns([
                TextColumn::make('transicion.nombre')->label('Acción')
                    ->description(fn (RequisitoTransicion $r) => "{$r->transicion?->estadoOrigen?->nombre} → {$r->transicion?->estadoDestino?->nombre}"),
                TextColumn::make('tipoDocumento.nombre')->label('Documento exigido'),
                TextColumn::make('estadoMinimo.nombre')->label('Estado mínimo')->badge(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('transicion_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRequisitosTransicion::route('/'),
            'create' => CreateRequisitoTransicion::route('/create'),
            'edit' => EditRequisitoTransicion::route('/{record}/edit'),
        ];
    }
}
