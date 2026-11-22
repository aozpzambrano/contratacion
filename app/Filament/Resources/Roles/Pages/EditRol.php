<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RolResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRol extends EditRecord
{
    protected static string $resource = RolResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
