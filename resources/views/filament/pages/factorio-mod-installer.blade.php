<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Tab Navigation --}}
        <x-filament::tabs>
            <x-filament::tabs.item 
                wire:click="$set('activeTab', 'installed')"
                :active="$activeTab === 'installed'"
            >
                {{ trans('factorio-mod-installer::factorio-mod-installer.tabs.installed') }}
            </x-filament::tabs.item>
            
            <x-filament::tabs.item 
                wire:click="$set('activeTab', 'browse')"
                :active="$activeTab === 'browse'"
            >
                {{ trans('factorio-mod-installer::factorio-mod-installer.tabs.browse') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        {{-- Content Area --}}
        <div>
            @if ($activeTab === 'installed')
                {{-- Installed Mods Tab --}}
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <h2 class="text-xl font-semibold">
                            {{ trans('factorio-mod-installer::factorio-mod-installer.installed.title') }}
                        </h2>
                        <div class="flex gap-2">
                            @if (count($modsWithUpdates) > 0)
                                <x-filament::button wire:click="updateAllMods" color="success">
                                    Update All ({{ count($modsWithUpdates) }})
                                </x-filament::button>
                            @endif
                            <x-filament::button wire:click="openAddModal">
                                {{ trans('factorio-mod-installer::factorio-mod-installer.installed.add_mod') }}
                            </x-filament::button>
                        </div>
                    </div>

                    @if (empty($installedMods))
                        <div class="text-center py-12 text-gray-500">
                            {{ trans('factorio-mod-installer::factorio-mod-installer.installed.empty') }}
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach ($installedMods as $mod)
                                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <h3 class="font-semibold">{{ $mod['title'] ?? $mod['name'] }}</h3>
                                                @if (!empty($mod['version']))
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">v{{ $mod['version'] }}</span>
                                                @endif
                                                @if (!empty($mod['update_available']))
                                                    <span class="text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 px-2 py-0.5 rounded">
                                                        Update available
                                                    </span>
                                                @endif
                                            </div>
                                            @if (!empty($mod['summary']))
                                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $mod['summary'] }}</p>
                                            @endif
                                        </div>
                                        @if ($mod['name'] !== 'base')
                                            <div class="flex items-center gap-2">
                                                @if (!empty($mod['update_available']))
                                                    <x-filament::button
                                                        size="sm"
                                                        wire:click="updateMod('{{ $mod['name'] }}')"
                                                        color="success"
                                                    >
                                                        Update
                                                    </x-filament::button>
                                                @endif
                                                <x-filament::input.checkbox 
                                                    wire:model.live="modStates.{{ $mod['name'] }}"
                                                    wire:change="toggleMod('{{ $mod['name'] }}')"
                                                />
                                                <x-filament::icon-button
                                                    icon="heroicon-o-trash"
                                                    color="danger"
                                                    wire:click="removeMod('{{ $mod['name'] }}')"
                                                    wire:confirm="Are you sure you want to remove this mod?"
                                                />
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                {{-- Browse Mods Tab --}}
                <div class="space-y-4">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="search"
                            wire:model.live.debounce.500ms="searchQuery"
                            placeholder="{{ trans('factorio-mod-installer::factorio-mod-installer.browse.search_placeholder') }}"
                        />
                    </x-filament::input.wrapper>

                    @if ($loading)
                        <div class="text-center py-12">
                            <x-filament::loading-indicator class="h-8 w-8" />
                        </div>
                    @elseif (empty($browseMods))
                        <div class="text-center py-12 text-gray-500">
                            {{ trans('factorio-mod-installer::factorio-mod-installer.browse.no_results') }}
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach ($browseMods as $mod)
                                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex-1 min-w-0">
                                            <h3 class="font-semibold text-base mb-1.5 break-words">{{ $mod['title'] ?? $mod['name'] }}</h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2 line-clamp-3">
                                                {{ $mod['summary'] ?? '' }}
                                            </p>
                                            <span class="text-xs text-gray-500 inline-block">
                                                {{ number_format($mod['downloads_count'] ?? 0) }} {{ trans('factorio-mod-installer::factorio-mod-installer.browse.downloads') }}
                                            </span>
                                        </div>
                                        <div class="flex-shrink-0 self-center">
                                            <x-filament::button
                                                size="sm"
                                                wire:click="installMod('{{ $mod['name'] }}')"
                                                :disabled="in_array($mod['name'], array_column($installedMods, 'name'))"
                                            >
                                                {{ in_array($mod['name'], array_column($installedMods, 'name')) 
                                                    ? trans('factorio-mod-installer::factorio-mod-installer.browse.installed')
                                                    : trans('factorio-mod-installer::factorio-mod-installer.browse.install') }}
                                            </x-filament::button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Add Mod Modal --}}
    <x-filament::modal id="add-mod-modal" width="md" :visible="$showAddModal">
        <x-slot name="heading">
            {{ trans('factorio-mod-installer::factorio-mod-installer.modal.add_title') }}
        </x-slot>

        <div class="space-y-4">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model="newModName"
                    placeholder="{{ trans('factorio-mod-installer::factorio-mod-installer.modal.mod_name_placeholder') }}"
                    autofocus
                />
            </x-filament::input.wrapper>
        </div>

        <x-slot name="footerActions">
            <x-filament::button
                wire:click="addMod"
                :disabled="empty($newModName)"
            >
                {{ trans('factorio-mod-installer::factorio-mod-installer.modal.add') }}
            </x-filament::button>
            
            <x-filament::button
                color="gray"
                wire:click="closeAddModal"
            >
                {{ trans('factorio-mod-installer::factorio-mod-installer.modal.cancel') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
