<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Por seguridad, debe cambiar la clave inicial antes de continuar.
    </p>

    <form wire:submit="guardar" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">Guardar clave</x-filament::button>
    </form>
</x-filament-panels::page>
