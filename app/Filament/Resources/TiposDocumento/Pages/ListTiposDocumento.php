<?php

namespace App\Filament\Resources\TiposDocumento\Pages;

use App\Filament\Resources\TiposDocumento\TipoDocumentoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTiposDocumento extends ListRecords
{
    protected static string $resource = TipoDocumentoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
