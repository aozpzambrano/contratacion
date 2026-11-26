<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Cambio obligatorio de la clave inicial en el primer ingreso. */
class CambiarClave extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'cambiar-clave';

    protected static ?string $title = 'Cambiar clave';

    protected string $view = 'filament.pages.cambiar-clave';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $usuario = auth()->user();

        return $schema
            ->components([
                TextInput::make('clave')
                    ->label('Nueva clave')
                    ->password()->revealable()
                    ->required()
                    ->minLength(8)
                    ->confirmed()
                    ->rule(fn () => function (string $atributo, $valor, \Closure $fallar) use ($usuario) {
                        if ($usuario && (Hash::check((string) $valor, $usuario->password) || Str::before((string) $usuario->email, '@') === $valor)) {
                            $fallar('La nueva clave debe ser distinta de la clave inicial.');
                        }
                    }),
                TextInput::make('clave_confirmation')
                    ->label('Confirmar nueva clave')
                    ->password()->revealable()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function guardar(): void
    {
        $datos = $this->form->getState();
        $usuario = auth()->user();

        $usuario->password = $datos['clave']; // el modelo la cifra
        $usuario->debe_cambiar_clave = false;
        $usuario->save();

        // mantiene abierta esta sesion (AuthenticateSession compara el hash de la clave)
        if (request()->hasSession()) {
            request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $usuario->getAuthPassword()]);
        }

        Notification::make()->title('Clave actualizada')->success()->send();
        $this->redirect(Filament::getUrl());
    }
}
