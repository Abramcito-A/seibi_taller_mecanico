@extends('layouts.app')

@section('title', 'Corregir recepción')

@section('content')
    <x-ui.page-header title="Corregir recepción" :description="$orden->folio.' · '.$orden->vehiculo->marca.' '.$orden->vehiculo->modelo">
        <x-slot:actions><x-ui.back-button :fallback="route('ordenes.show', $orden)" /></x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel class="mb-5" title="Cliente y vehículo" description="Los cambios de recepción solo están disponibles mientras la orden siga recibida." padding="p-4 sm:p-5">
        <form method="GET" action="{{ route('ordenes.edit', $orden) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
            <x-ui.input name="q" label="Buscar cliente por teléfono" :value="$search" placeholder="Teléfono (ej. 871 576 6792), nombre o correo" />
            <x-ui.select name="cliente_id" label="Cliente">
                @foreach ($clientes as $cliente)
                    <option value="{{ $cliente->id_cliente }}" {{ $selectedClient && $selectedClient->id_cliente === $cliente->id_cliente ? 'selected' : '' }}>{{ $cliente->apellido }}, {{ $cliente->nombre }} · {{ $cliente->telefono }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" variant="secondary">Cargar vehículos</x-ui.button>
        </form>
    </x-ui.panel>

    @if ($selectedClient)
        <x-ui.card class="mx-auto max-w-4xl">
            <form method="POST" action="{{ route('ordenes.update', $orden) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="id_cliente" value="{{ $selectedClient->id_cliente }}">
                <div class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-ui.select name="id_vehiculo" label="Vehículo" required>
                            @foreach ($selectedClient->vehiculos as $vehiculo)
                                <option value="{{ $vehiculo->id_vehiculo }}" {{ (string) old('id_vehiculo', $orden->id_vehiculo) === (string) $vehiculo->id_vehiculo ? 'selected' : '' }}>{{ $vehiculo->marca }} {{ $vehiculo->modelo }} · {{ $vehiculo->anio }}{{ $vehiculo->placas ? ' · '.$vehiculo->placas : '' }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    <div class="sm:col-span-2 sm:max-w-sm"><x-ui.input name="fecha_ingreso" label="Fecha y hora de ingreso" type="datetime-local" :value="old('fecha_ingreso', $orden->fecha_ingreso->format('Y-m-d\TH:i'))" required /></div>
                    <div class="sm:col-span-2"><x-ui.textarea name="descripcion_problema" label="Falla o servicio reportado" :value="old('descripcion_problema', $orden->descripcion_problema)" rows="4" required maxlength="5000" /></div>
                    <div class="sm:col-span-2"><x-ui.textarea name="observaciones" label="Observaciones de recepción" :value="old('observaciones', $orden->observaciones)" rows="3" maxlength="5000" /></div>
                    <fieldset class="space-y-2 sm:col-span-2">
                        <legend class="block text-sm font-semibold text-seibi-ink">Fotografías guardadas <span class="font-normal text-slate-500">({{ $receptionPhotos->count() }})</span></legend>
                        @if ($receptionPhotos->isEmpty())
                            <p class="text-xs text-slate-500">Esta recepción aún no tiene fotografías.</p>
                        @else
                            <p class="text-xs text-slate-500">Se conservan al guardar. Marca «Quitar» solo en las que quieras eliminar.</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($receptionPhotos as $photo)
                                    @php $marked = in_array((string) $photo->id_evidencia, array_map('strval', (array) old('quitar_fotografias', [])), true); @endphp
                                    <label class="group block cursor-pointer overflow-hidden rounded-lg border border-seibi-border bg-white has-[:checked]:border-status-error has-[:checked]:opacity-60">
                                        <img src="{{ route('ordenes.evidencias.show', [$orden, $photo]) }}" alt="{{ $photo->nombre_archivo }}" loading="lazy" class="aspect-square w-full object-cover">
                                        <span class="flex items-center gap-2 p-2 text-xs">
                                            <input type="checkbox" name="quitar_fotografias[]" value="{{ $photo->id_evidencia }}" @checked($marked) class="size-4">
                                            <span class="font-semibold text-status-error">Quitar</span>
                                            <span class="min-w-0 flex-1 truncate text-slate-500">{{ $photo->nombre_archivo }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </fieldset>
                    <div class="space-y-2 sm:col-span-2">
                        <label for="fotografias" class="block text-sm font-semibold text-seibi-ink">Agregar fotografías <span class="font-normal text-slate-500">(opcional)</span></label>
                        <input id="fotografias" name="fotografias[]" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="fotografias-ayuda" class="ui-input h-auto min-h-12 cursor-pointer py-2 file:mr-3 file:rounded-md file:border-0 file:bg-seibi-workspace file:px-3 file:py-2 file:text-sm file:font-semibold file:text-seibi-navy">
                        <p id="fotografias-ayuda" class="text-xs leading-5 text-slate-500">JPEG, PNG o WebP · máximo 5 MB por imagen · hasta {{ $maxPhotos }} fotografías en total contando las guardadas.</p>
                        @error('fotografias')<p class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                        @error('fotografias.*')<p class="text-xs font-medium text-status-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex flex-col-reverse justify-end gap-3 border-t border-seibi-border pt-5 sm:flex-row">
                    <x-ui.button :href="route('ordenes.show', ['orden' => $orden, 'seccion' => 'recepcion'])" variant="neutral">Cancelar</x-ui.button>
                    <x-ui.button type="submit">Guardar cambios</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
@endsection