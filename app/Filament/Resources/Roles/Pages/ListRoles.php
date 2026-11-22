<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RolResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
