<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rol')
                ->columns(2)
                ->schema([
                    TextInput::make('codigo')
                        ->label('Código')
                        ->helperText('Mayúsculas, números y guion bajo. No cambia después de creado.')
                        ->required()
                        ->maxLength(50)
                        ->regex('/^[A-Z0-9_]+$/')
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120),
                    Textarea::make('descripcion')
                        ->label('Descripción')
                        ->columnSpanFull(),
                    Toggle::make('activo')
                        ->label('Activo')
                        ->default(true),
                ]),
            Section::make('Fases en las que opera')
                ->description('Un permiso solo se ejerce en las fases del rol.')
                ->schema([
                    CheckboxList::make('fases')
                        ->label('Fases')
                        ->relationship('fases', 'nombre', fn ($query) => $query->orderBy('fases.orden'))
                        ->columns(3),
                ]),
            Section::make('Permisos')
                ->schema([
                    CheckboxList::make('permisos')
                        ->label('Permisos del rol')
                        ->relationship('permisos', 'codigo', fn ($query) => $query->where('permisos.activo', true)->orderBy('permisos.codigo'))
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(3),
                ]),
        ]);
    }
}
