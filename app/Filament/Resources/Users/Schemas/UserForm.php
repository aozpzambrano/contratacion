<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del usuario')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Correo institucional')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('cedula')
                        ->label('Cédula')
                        ->numeric()
                        ->length(10)
                        ->unique(ignoreRecord: true),
                    TextInput::make('cargo')
                        ->label('Cargo')
                        ->maxLength(120),
                    TextInput::make('unidad')
                        ->label('Unidad / Dirección')
                        ->maxLength(120),
                    TextInput::make('password')
                        ->label('Contraseña')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create'),
                    Toggle::make('activo')
                        ->label('Activo')
                        ->helperText('Un usuario inactivo no puede ingresar ni ejercer permisos. No se elimina para conservar la trazabilidad.')
                        ->default(true),
                ]),
            Section::make('Roles')
                ->description('Los permisos de cada rol se administran en Roles.')
                ->schema([
                    CheckboxList::make('roles')
                        ->label('Roles asignados')
                        ->relationship('roles', 'nombre', fn ($query) => $query->where('roles.activo', true)->orderBy('roles.nombre'))
                        ->columns(2)
                        ->bulkToggleable(false),
                ]),
        ]);
    }
}
