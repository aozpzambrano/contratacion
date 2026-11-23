<?php

namespace App\Filament\Resources\RequisitosEnvio\Pages;

use App\Filament\Resources\RequisitosEnvio\RequisitoTransicionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRequisitosTransicion extends ListRecords
{
    protected static string $resource = RequisitoTransicionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
