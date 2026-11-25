<?php

namespace App\Filament\Resources\TiposDocumento\Schemas;

use App\Models\Fase;
use App\Models\Rol;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class TipoDocumentoForm
{
    public const FORMATOS = [
        'pdf' => 'PDF',
        'doc' => 'Word (.doc)',
        'docx' => 'Word (.docx)',
        'xls' => 'Excel (.xls)',
        'xlsx' => 'Excel (.xlsx)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tipo de documento')
                ->columns(2)
                ->schema([
                    TextInput::make('codigo')
                        ->label('Código')
                        ->helperText('Mayúsculas, números y guion bajo. No cambia después de creado.')
                        ->required()
                        ->maxLength(80)
                        ->regex('/^[A-Z0-9_]+$/')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    TextInput::make('nombre')->label('Nombre')->required()->maxLength(200),
                    Select::make('fase_id')
                        ->label('Fase')
                        ->options(fn () => Fase::orderBy('orden')->pluck('nombre', 'id'))
                        ->helperText('El tipo solo se ofrece al cargar documentos en esta fase. Vacío = cualquier fase.')
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('etapa_id', null)),
                    Select::make('etapa_id')
                        ->label('Etapa')
                        ->options(fn ($get) => DB::table('etapas')
                            ->when($get('fase_id'), fn ($q, $fase) => $q->where('fase_id', $fase))
                            ->orderBy('orden')->pluck('nombre', 'id')),
                    Select::make('rol_generador_id')
                        ->label('Rol que carga el documento')
                        ->options(fn () => Rol::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id'))
                        ->helperText('Solo los usuarios con este rol ven el tipo al cargar. Vacío = sin restricción por rol.'),
                    Select::make('rol_revisor_id')
                        ->label('Rol que revisa')
                        ->options(fn () => Rol::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id')),
                ]),
            Section::make('Flujo del documento')
                ->columns(2)
                ->schema([
                    Toggle::make('requiere_revision')->label('Requiere revisión')->default(true),
                    Toggle::make('requiere_aprobacion')->label('Requiere aprobación')->default(true),
                    Toggle::make('requiere_legalizacion')
                        ->label('Requiere legalización')
                        ->helperText('Se legaliza cargando el PDF firmado electrónicamente.'),
                    Toggle::make('permite_multiples')
                        ->label('Permite varios documentos por proceso')
                        ->helperText('Apagado: un solo documento por proceso; las correcciones son versiones nuevas.'),
                    Toggle::make('editable_tras_devolucion')
                        ->label('La unidad lo puede modificar si Compras devuelve el proceso ya legalizado')
                        ->helperText('Permite cargar, versionar o eliminar este documento cuando el proceso está en "Para revisión".'),
                    Toggle::make('activo')->label('Activo')->default(true),
                ]),
            Section::make('Archivos')
                ->columns(2)
                ->schema([
                    CheckboxList::make('extensiones_permitidas')
                        ->label('Formatos permitidos')
                        ->options(self::FORMATOS)
                        ->columns(3)
                        ->required()
                        ->default(['pdf', 'doc', 'docx', 'xls', 'xlsx'])
                        ->formatStateUsing(fn ($state) => is_string($state) ? array_filter(explode(',', $state)) : $state)
                        ->dehydrateStateUsing(fn ($state) => implode(',', (array) $state)),
                    TextInput::make('tamano_maximo_mb')
                        ->label('Tamaño máximo (MB)')
                        ->helperText('Se aplica el menor entre este valor y el parámetro general «tamano_maximo_documento_mb».')
                        ->numeric()->integer()->minValue(1)->maxValue(100)
                        ->default(20)->required(),
                ]),
        ]);
    }
}
