<x-filament-panels::page>
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
        
        <x-filament::tabs.item 
            wire:click="$set('activeTab', 'direct')"
            :active="$activeTab === 'direct'"
        >
            Direct Install
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($activeTab === 'installed')
        {{-- Installed Mods Tab --}}
        <div class="mt-6 space-y-4">
            @if (empty($installedMods))
                <div class="text-center py-12 text-gray-500">
                    {{ trans('factorio-mod-installer::factorio-mod-installer.installed.empty') }}
                </div>
            @else
                {{-- Stats and Actions Bar --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                        <span>
                            <strong class="text-gray-900 dark:text-gray-100">{{ count($installedMods) }}</strong> mods installed
                        </span>
                        @php
                            $updatesAvailable = count(array_filter($installedMods, fn($mod) => !empty($mod['update_available'])));
                        @endphp
                        @if ($updatesAvailable > 0)
                            <span class="flex items-center gap-1.5 text-success-600 dark:text-success-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                </svg>
                                <strong>{{ $updatesAvailable }}</strong> update{{ $updatesAvailable > 1 ? 's' : '' }} available
                            </span>
                        @endif
                    </div>
                    @if ($updatesAvailable > 0)
                        <x-filament::button
                            size="sm"
                            wire:click="updateAllMods"
                            color="success"
                        >
                            Update All
                        </x-filament::button>
                    @endif
                </div>

                {{-- Mods Table --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Mod Name</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-32">Version</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-32">
                                    <div class="flex items-center justify-center gap-2">
                                        <span>Enabled</span>
                                        <x-filament::input.checkbox 
                                            wire:model.live="allModsEnabled"
                                            wire:change="toggleAllMods"
                                        />
                                    </div>
                                </th>
                                <th class="text-right py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-40">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($installedMods as $mod)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                                    <td class="py-3 px-4">
                                        <div class="flex flex-col gap-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">{{ $mod['title'] ?? $mod['name'] }}</span>
                                                @if ($mod['name'] === 'base')
                                                    <x-filament::badge color="primary" size="xs">Base Game</x-filament::badge>
                                                @endif
                                            </div>
                                            @if (!empty($mod['summary']))
                                                <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1">{{ $mod['summary'] }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        @if (!empty($mod['version']))
                                            <x-filament::badge size="xs" color="gray">v{{ $mod['version'] }}</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        @if ($mod['name'] !== 'base')
                                            <x-filament::input.checkbox 
                                                wire:model.live="modStates.{{ $mod['name'] }}"
                                                wire:change="toggleMod('{{ $mod['name'] }}')"
                                            />
                                        @else
                                            <span class="text-xs text-gray-500 dark:text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        @if ($mod['name'] !== 'base')
                                            <div class="flex items-center justify-end gap-2">
                                                @if (!empty($mod['update_available']))
                                                    <x-filament::button
                                                        size="xs"
                                                        wire:click="updateMod('{{ $mod['name'] }}')"
                                                        color="success"
                                                        icon="heroicon-o-arrow-down-tray"
                                                    >
                                                        Update
                                                    </x-filament::button>
                                                @endif
                                                <x-filament::icon-button
                                                    icon="heroicon-o-trash"
                                                    color="danger"
                                                    size="sm"
                                                    wire:click="removeMod('{{ $mod['name'] }}')"
                                                    wire:confirm="Are you sure you want to remove this mod?"
                                                />
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @elseif ($activeTab === 'browse')
        {{-- Browse Mods Tab --}}
        <div class="space-y-4 mt-6">
            {{-- Cache Stats --}}
            @if (!empty($cacheStats) && !empty($cacheStats['has_cache']))
                <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                    @if (!empty($cacheStats['total_mods_in_cache']))
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                            </svg>
                            {{ number_format($cacheStats['total_mods_in_cache']) }} mods cached
                        </span>
                    @endif
                    @if (!empty($cacheStats['last_refresh']))
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Last update: {{ \Carbon\Carbon::parse($cacheStats['last_refresh'])->diffForHumans() }}
                        </span>
                    @endif
                </div>
            @endif

            {{-- Search and Actions Bar --}}
            <div class="flex gap-2">
                <div class="flex-1">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="search"
                            wire:model.live.debounce.500ms="searchQuery"
                            placeholder="{{ trans('factorio-mod-installer::factorio-mod-installer.browse.search_placeholder') }}"
                        />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button
                    wire:click="refreshMods"
                    size="sm"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                >
                    {{ trans('factorio-mod-installer::factorio-mod-installer.browse.refresh') }}
                </x-filament::button>
            </div>

            {{-- Category Filters --}}
            <div class="flex gap-2 flex-wrap">
                <x-filament::button
                    size="xs"
                    wire:click="setCategory(null)"
                    :color="$selectedCategory === null ? 'primary' : 'gray'"
                >
                    {{ trans('factorio-mod-installer::factorio-mod-installer.categories.all') }}
                </x-filament::button>
                @foreach(['gameplay', 'content', 'tweaks', 'utilities', 'scenarios', 'mod-packs', 'localizations', 'internal'] as $category)
                    <x-filament::button
                        size="xs"
                        wire:click="setCategory('{{ $category }}')"
                        :color="$selectedCategory === $category ? 'primary' : 'gray'"
                    >
                        {{ trans('factorio-mod-installer::factorio-mod-installer.categories.' . $category) }}
                    </x-filament::button>
                @endforeach
            </div>

            {{-- Mod List --}}
            @if ($loading)
                <div class="text-center py-12">
                    <x-filament::loading-indicator class="h-8 w-8" />
                </div>
            @elseif (empty($browseMods))
                <div class="text-center py-12 text-gray-500 dark:text-gray-400">
                    {{ trans('factorio-mod-installer::factorio-mod-installer.browse.no_results') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-16"></th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Mod Name</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-24">Version</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-32">Category</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-32">Author</th>
                                <th class="text-right py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-32">Downloads</th>
                                <th class="text-right py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 w-48">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($browseMods as $mod)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                                    <td class="py-3 px-4">
                                        @if (!empty($mod['thumbnail']) && is_string($mod['thumbnail']))
                                            <img src="{{ str_starts_with($mod['thumbnail'], 'http') ? $mod['thumbnail'] : 'https://mods.factorio.com' . $mod['thumbnail'] }}" 
                                                 alt="{{ $mod['title'] ?? $mod['name'] }}"
                                                 class="w-12 h-12 object-cover rounded"
                                                 loading="lazy"
                                                 onerror="this.style.display='none'"
                                            />
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex flex-col gap-1">
                                            <span class="font-medium">{{ $mod['title'] ?? $mod['name'] }}</span>
                                            @if (!empty($mod['summary']))
                                                <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-2">{{ $mod['summary'] }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        @if (!empty($mod['latest_release']['version']) && is_string($mod['latest_release']['version']))
                                            <x-filament::badge size="xs" color="gray">
                                                v{{ $mod['latest_release']['version'] }}
                                            </x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        @if (!empty($mod['category']) && is_string($mod['category']))
                                            <x-filament::badge size="xs" color="info">
                                                {{ ucfirst($mod['category']) }}
                                            </x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        @if (!empty($mod['owner']) && is_string($mod['owner']))
                                            <span class="text-xs">{{ $mod['owner'] }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        @if (isset($mod['downloads_count']))
                                            <span class="text-xs">{{ number_format($mod['downloads_count']) }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-filament::button
                                                size="xs"
                                                wire:click="showModDetails('{{ $mod['name'] }}')"
                                                color="gray"
                                            >
                                                Details
                                            </x-filament::button>
                                            <x-filament::button
                                                size="xs"
                                                wire:click="installMod('{{ $mod['name'] }}')"
                                                :disabled="in_array($mod['name'], array_column($installedMods, 'name'))"
                                            >
                                                {{ in_array($mod['name'], array_column($installedMods, 'name')) 
                                                    ? trans('factorio-mod-installer::factorio-mod-installer.browse.installed')
                                                    : trans('factorio-mod-installer::factorio-mod-installer.browse.install') }}
                                            </x-filament::button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @elseif ($activeTab === 'direct')
        {{-- Direct Install Tab --}}
        <div class="mt-6 space-y-6">
            {{-- Instructions --}}
            <div class="space-y-3">
                <h3 class="text-base font-semibold">How to find the mod name</h3>
                <ol class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <li>1. Go to <a href="https://mods.factorio.com" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline">mods.factorio.com</a></li>
                    <li>2. Find the mod you want to install</li>
                    <li>3. Copy the mod name from the URL</li>
                </ol>
            </div>

            {{-- Example URL --}}
            <div class="space-y-2">
                <p class="text-sm font-medium">Example URL:</p>
                <div class="font-mono text-sm bg-black/30 dark:bg-white/5 border border-gray-200 dark:border-gray-700 rounded px-4 py-3">
                    <span class="text-gray-500 dark:text-gray-500">https://mods.factorio.com/mod/</span><span class="text-primary-600 dark:text-primary-400 font-semibold">5dim_core</span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-500">
                    → Use <span class="font-mono bg-black/30 dark:bg-white/5 px-2 py-0.5 rounded">5dim_core</span> as the mod name
                </p>
            </div>

            {{-- Installation Form --}}
            <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                <label class="block text-sm font-medium mb-3">Mod Name</label>
                <div class="flex gap-3">
                    <div class="flex-1">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="text"
                                wire:model="directInstallModName"
                                placeholder="e.g., 5dim_core"
                            />
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button
                        wire:click="installModByName"
                        icon="heroicon-o-arrow-down-tray"
                    >
                        Install Mod
                    </x-filament::button>
                </div>
            </div>

            {{-- Important Notes --}}
            <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                <h4 class="font-medium mb-3">Important Notes</h4>
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <li class="flex items-start gap-2">
                        <span class="text-primary-600 dark:text-primary-400">•</span>
                        <span>Mod names are <strong class="text-gray-900 dark:text-gray-100">case-sensitive</strong></span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary-600 dark:text-primary-400">•</span>
                        <span>Use underscores, not spaces</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary-600 dark:text-primary-400">•</span>
                        <span>Latest compatible version is installed</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary-600 dark:text-primary-400">•</span>
                        <span>Dependencies are installed automatically</span>
                    </li>
                </ul>
            </div>
        </div>
    @endif

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

    {{-- Mod Details Modal --}}
    <x-filament::modal id="mod-details-modal" width="3xl" :visible="$showDetailsModal">
        @if($selectedModDetails)
            <x-slot name="heading">
                {{ $selectedModDetails['title'] ?? $selectedModDetails['name'] }}
            </x-slot>

            <div class="space-y-4">
                {{-- Mod info --}}
                <div class="flex gap-3">
                    @if (!empty($selectedModDetails['thumbnail']) && is_string($selectedModDetails['thumbnail']))
                        <img src="{{ str_starts_with($selectedModDetails['thumbnail'], 'http') ? $selectedModDetails['thumbnail'] : 'https://mods.factorio.com' . $selectedModDetails['thumbnail'] }}" 
                             alt="{{ $selectedModDetails['title'] ?? '' }}"
                             class="w-20 h-20 object-cover rounded"
                             onerror="this.style.display='none'"
                        />
                    @endif
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-2">
                            @if (!empty($selectedModDetails['owner']) && is_string($selectedModDetails['owner']))
                                <span class="text-sm text-gray-600 dark:text-gray-400">{{ $selectedModDetails['owner'] }}</span>
                            @endif
                            @if (!empty($selectedModDetails['category']) && is_string($selectedModDetails['category']))
                                <x-filament::badge size="xs" color="info">
                                    {{ ucfirst($selectedModDetails['category']) }}
                                </x-filament::badge>
                            @endif
                            @if (!empty($selectedModDetails['latest_release']['version']) && is_string($selectedModDetails['latest_release']['version']))
                                <x-filament::badge size="xs" color="gray">
                                    {{ $selectedModDetails['latest_release']['version'] }}
                                </x-filament::badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ number_format($selectedModDetails['downloads_count'] ?? 0) }} downloads</span>
                            @if (isset($selectedModDetails['score']) && is_numeric($selectedModDetails['score']))
                                <span>{{ number_format($selectedModDetails['score'], 1) }} rating</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Description --}}
                @if (!empty($selectedModDetails['description']))
                    <div>
                        <h4 class="text-sm font-medium mb-2">{{ trans('factorio-mod-installer::factorio-mod-installer.details.description') }}</h4>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ is_array($selectedModDetails['description']) ? '' : $selectedModDetails['description'] }}
                        </p>
                    </div>
                @endif

                {{-- Dependencies --}}
                @if (!empty($selectedModDetails['dependencies']) && is_array($selectedModDetails['dependencies']) && count($selectedModDetails['dependencies']) > 0)
                    <div>
                        <h4 class="text-sm font-medium mb-2">{{ trans('factorio-mod-installer::factorio-mod-installer.details.dependencies') }}</h4>
                        <div class="space-y-1">
                            @foreach ($selectedModDetails['dependencies'] as $dep)
                                @if (is_array($dep) && !empty($dep['name']))
                                    <div class="text-xs flex items-center gap-2 text-gray-600 dark:text-gray-400">
                                        @if (($dep['type'] ?? '') === 'required')
                                            <span class="text-red-500">●</span>
                                        @else
                                            <span class="text-yellow-500">○</span>
                                        @endif
                                        <span>{{ $dep['name'] }}</span>
                                        @if (!empty($dep['version']))
                                            <span class="text-gray-400">{{ $dep['version'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Changelog --}}
                @if (!empty($selectedModDetails['changelog']) && is_string($selectedModDetails['changelog']))
                    <div>
                        <h4 class="text-sm font-medium mb-2">{{ trans('factorio-mod-installer::factorio-mod-installer.details.changelog') }}</h4>
                        <div class="text-xs text-gray-600 dark:text-gray-400 max-h-48 overflow-y-auto">
                            <pre class="whitespace-pre-wrap font-mono">{{ $selectedModDetails['changelog'] }}</pre>
                        </div>
                    </div>
                @endif

                {{-- Links --}}
                <div class="flex gap-2 pt-2">
                    @if (!empty($selectedModDetails['homepage']) && is_string($selectedModDetails['homepage']))
                        <x-filament::button
                            tag="a"
                            href="{{ $selectedModDetails['homepage'] }}"
                            target="_blank"
                            size="xs"
                            color="gray"
                        >
                            Homepage
                        </x-filament::button>
                    @endif
                    @php
                        $sourceUrl = null;
                        if (!empty($selectedModDetails['source_url']) && is_string($selectedModDetails['source_url'])) {
                            $sourceUrl = $selectedModDetails['source_url'];
                        } elseif (!empty($selectedModDetails['github_path']) && is_string($selectedModDetails['github_path'])) {
                            $sourceUrl = 'https://github.com/' . $selectedModDetails['github_path'];
                        }
                    @endphp
                    @if ($sourceUrl)
                        <x-filament::button
                            tag="a"
                            href="{{ $sourceUrl }}"
                            target="_blank"
                            size="xs"
                            color="gray"
                        >
                            GitHub
                        </x-filament::button>
                    @endif
                </div>
            </div>

            <x-slot name="footerActions">
                <x-filament::button
                    wire:click="installModFromDetails"
                    size="sm"
                    :disabled="in_array($selectedModDetails['name'], array_column($installedMods, 'name'))"
                >
                    {{ in_array($selectedModDetails['name'], array_column($installedMods, 'name')) 
                        ? trans('factorio-mod-installer::factorio-mod-installer.browse.installed')
                        : trans('factorio-mod-installer::factorio-mod-installer.browse.install') }}
                </x-filament::button>
                
                <x-filament::button
                    size="sm"
                    color="gray"
                    wire:click="closeModDetails"
                >
                    {{ trans('factorio-mod-installer::factorio-mod-installer.modal.close') }}
                </x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>

</x-filament-panels::page>
