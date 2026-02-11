<x-filament-panels::page>
    {{-- Kleiner Loading Indicator statt dem riesigen Standard --}}
    <style>
        .fi-page-loading-indicator { display: none !important; }
    </style>
    
    {{-- Tab Navigation with Factorio Version --}}
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

        {{-- Spacer to push version to the right --}}
        <div class="flex-1"></div>

        {{-- Factorio Version Display (inside tabs container) --}}
        <div class="flex items-center gap-2 px-3">
            @if ($factorioVersion)
                <svg class="w-4 h-4 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                </svg>
                <span class="text-sm text-gray-400">Factorio</span>
                <x-filament::badge color="{{ $isExperimental ?? false ? 'warning' : 'success' }}" size="sm">
                    v{{ $factorioVersion }}{{ ($isExperimental ?? false) ? ' (Exp)' : '' }}
                </x-filament::badge>
            @else
                <x-filament::badge color="gray" size="sm">
                    Version unknown
                </x-filament::badge>
            @endif
        </div>
    </x-filament::tabs>

    @if ($activeTab === 'installed')
        {{-- Installed Mods Tab --}}
        <div class="mt-6 space-y-4">
            @if (empty($installedMods))
                <x-filament::section>
                    <div class="text-center py-12">
                        <p class="text-gray-400 text-lg">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.empty') }}</p>
                    </div>
                </x-filament::section>
            @else
                {{-- ═══════════════════════════════════════════════════════════════
                     FILTER BAR (Sticky)
                     Components: SearchInput, Tabs, SortDropdown, BatchActions
                ═══════════════════════════════════════════════════════════════ --}}
                <div class="sticky top-0 z-10 -mx-4 px-4 py-3 bg-gray-900/95 backdrop-blur border-b border-gray-700">
                    <div class="flex flex-wrap items-center gap-4">
                        {{-- SearchInput --}}
                        <div class="flex-1 min-w-[200px] max-w-sm">
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    wire:model.live.debounce.300ms="installedModsSearch"
                                    placeholder="{{ trans('factorio-mod-installer::factorio-mod-installer.installed.search_placeholder') }}"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        {{-- Tabs (All, Enabled, Disabled, Updates) --}}
                        <x-filament::tabs>
                            <x-filament::tabs.item 
                                wire:click="$set('installedModsFilter', 'all')"
                                :active="$installedModsFilter === 'all'"
                            >
                                {{ trans('factorio-mod-installer::factorio-mod-installer.installed.filter_all') }}
                            </x-filament::tabs.item>
                            <x-filament::tabs.item 
                                wire:click="$set('installedModsFilter', 'enabled')"
                                :active="$installedModsFilter === 'enabled'"
                            >
                                {{ trans('factorio-mod-installer::factorio-mod-installer.installed.filter_enabled') }}
                            </x-filament::tabs.item>
                            <x-filament::tabs.item 
                                wire:click="$set('installedModsFilter', 'disabled')"
                                :active="$installedModsFilter === 'disabled'"
                            >
                                {{ trans('factorio-mod-installer::factorio-mod-installer.installed.filter_disabled') }}
                            </x-filament::tabs.item>
                            @php $updateCount = count(array_filter($installedMods, fn($m) => !empty($m['update_available']))); @endphp
                            <x-filament::tabs.item 
                                wire:click="$set('installedModsFilter', 'updates')"
                                :active="$installedModsFilter === 'updates'"
                                :badge="$updateCount > 0 ? $updateCount : null"
                                badge-color="warning"
                            >
                                {{ trans('factorio-mod-installer::factorio-mod-installer.installed.filter_updates') }}
                            </x-filament::tabs.item>
                        </x-filament::tabs>

                        {{-- SortDropdown (Name, Status, UpdatedAt) --}}
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="installedModsSort">
                                <option value="name">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.sort_name') }}</option>
                                <option value="status">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.sort_status') }}</option>
                                <option value="updates">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.filter_updates') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>

                        {{-- BatchActions (Check for Updates, Update All) --}}
                        <x-filament::button
                            wire:click="checkForUpdates"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            :disabled="$loading"
                        >
                            {{ trans('factorio-mod-installer::factorio-mod-installer.installed.check_updates') }}
                        </x-filament::button>
                        
                        @if ($updateCount > 0)
                            <x-filament::button
                                wire:click="updateAllMods"
                                wire:confirm="{{ trans('factorio-mod-installer::factorio-mod-installer.installed.update_all_confirm', ['count' => $updateCount]) }}"
                                color="primary"
                                size="sm"
                                icon="heroicon-o-arrow-up-tray"
                            >
                                {{ trans('factorio-mod-installer::factorio-mod-installer.installed.update_all') }} ({{ $updateCount }})
                            </x-filament::button>
                        @endif
                    </div>
                </div>

                {{-- ═══════════════════════════════════════════════════════════════
                     MOD LIST
                ═══════════════════════════════════════════════════════════════ --}}
                <div class="space-y-4 mt-4">
                    @php
                        $filteredMods = $this->filteredInstalledMods;
                    @endphp
                    
                    @if (empty($filteredMods))
                        <div class="rounded-lg border border-gray-700 bg-gray-800/50 p-6 text-center">
                            <p class="text-gray-500">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.no_mods_found') }}</p>
                        </div>
                    @else
                        @foreach ($filteredMods as $mod)
                        {{-- ═══════════════════════════════════════════════════════
                             MOD CARD - Filament Section Style
                             Gleicher Stil wie die "Install Mod" Box
                        ═══════════════════════════════════════════════════════ --}}
                        <x-filament::section>
                            {{-- ═══ HEADER: Modname + Version + Statusbadge ═══ --}}
                            <x-slot name="heading">
                                <div class="flex items-center justify-between w-full">
                                    <div class="flex items-center gap-3">
                                        {{-- Thumbnail (nur wenn vorhanden) --}}
                                        @if (!empty($mod['thumbnail']) && is_string($mod['thumbnail']))
                                            <img 
                                                src="{{ str_starts_with($mod['thumbnail'], 'http') ? $mod['thumbnail'] : 'https://mods.factorio.com' . $mod['thumbnail'] }}" 
                                                alt="{{ $mod['title'] ?? $mod['name'] }}"
                                                class="w-8 h-8 rounded object-cover flex-shrink-0"
                                                loading="lazy"
                                            />
                                        @endif
                                        <span>{{ $mod['title'] ?? $mod['name'] }}</span>
                                        @if (!empty($mod['version']))
                                            <div style="margin-left: 2.5rem;">
                                                <x-filament::badge color="gray" size="sm">
                                                    v{{ $mod['version'] }}
                                                </x-filament::badge>
                                            </div>
                                        @endif
                                    </div>
                                    
                                    {{-- StatusBadge (klickbar für Toggle) - RECHTS --}}
                                    <div class="flex items-center gap-2">
                                        @if ($mod['name'] === 'base')
                                            <x-filament::badge color="info">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.base_game') }}</x-filament::badge>
                                        @elseif (!empty($mod['update_available']))
                                            <x-filament::badge color="warning" icon="heroicon-o-arrow-path">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.update') }}</x-filament::badge>
                                            <button wire:click="toggleMod('{{ $mod['name'] }}')" class="transition-opacity hover:opacity-80">
                                                @if (!empty($modStates[$mod['name']]))
                                                    <x-filament::badge color="success" icon="heroicon-o-check-circle">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.enabled') }}</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" icon="heroicon-o-x-circle">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.disabled') }}</x-filament::badge>
                                                @endif
                                            </button>
                                        @else
                                            <button wire:click="toggleMod('{{ $mod['name'] }}')" class="transition-opacity hover:opacity-80">
                                                @if (!empty($modStates[$mod['name']]))
                                                    <x-filament::badge color="success" icon="heroicon-o-check-circle">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.enabled') }}</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="danger" icon="heroicon-o-x-circle">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.disabled') }}</x-filament::badge>
                                                @endif
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </x-slot>
                            
                            {{-- ═══ BODY: Beschreibung + Update-Info ═══ --}}
                            @if (!empty($mod['summary']))
                                <p class="text-sm text-gray-400 leading-relaxed line-clamp-2 mb-4">{{ $mod['summary'] }}</p>
                            @endif
                            
                            {{-- Update Info (nur wenn Update verfügbar) --}}
                            @if (!empty($mod['update_available']))
                                <div class="flex items-center flex-wrap gap-2 mb-4">
                                    <span class="text-xs text-gray-500">{{ trans('factorio-mod-installer::factorio-mod-installer.installed.update_available') }}:</span>
                                    <x-filament::badge color="warning" size="sm">
                                        v{{ $mod['update_available'] }}
                                    </x-filament::badge>
                                    <a 
                                        href="https://mods.factorio.com/mod/{{ $mod['name'] }}/changelog" 
                                        target="_blank"
                                        class="text-xs text-primary-400 hover:text-primary-300 hover:underline flex items-center gap-1"
                                    >
                                        <x-heroicon-o-document-text class="w-3.5 h-3.5" />
                                        {{ trans('factorio-mod-installer::factorio-mod-installer.installed.changelog') }}
                                    </a>
                                </div>
                            @endif
                            
                            {{-- ═══ FOOTER: Buttons ═══ --}}
                            @if ($mod['name'] !== 'base')
                                <div class="flex items-center gap-3 pt-4 border-t border-gray-700">
                                    {{-- Update Button (Primary wenn Update verfügbar) --}}
                                    @if (!empty($mod['update_available']))
                                        <x-filament::button
                                            wire:click="updateMod('{{ $mod['name'] }}')"
                                            color="success"
                                            size="sm"
                                            icon="heroicon-o-arrow-down-tray"
                                        >
                                            {{ trans('factorio-mod-installer::factorio-mod-installer.installed.update') }}
                                        </x-filament::button>
                                    @endif
                                    
                                    {{-- Portal Link --}}
                                    <x-filament::button
                                        tag="a"
                                        href="https://mods.factorio.com/mod/{{ $mod['name'] }}"
                                        target="_blank"
                                        color="warning"
                                        size="sm"
                                        icon="heroicon-o-globe-alt"
                                        title="{{ trans('factorio-mod-installer::factorio-mod-installer.installed.portal') }}"
                                    >
                                        {{ trans('factorio-mod-installer::factorio-mod-installer.installed.portal') }}
                                    </x-filament::button>
                                    
                                    {{-- Delete Button --}}
                                    <x-filament::button
                                        wire:click="removeMod('{{ $mod['name'] }}')"
                                        wire:confirm="{{ __('factorio-mod-installer::factorio-mod-installer.confirm_delete', ['name' => $mod['title'] ?? $mod['name']]) }}"
                                        color="danger"
                                        size="sm"
                                        icon="heroicon-o-trash"
                                    >
                                        {{ __('factorio-mod-installer::factorio-mod-installer.delete') }}
                                    </x-filament::button>
                                </div>
                            @endif
                        </x-filament::section>
                        @endforeach
                    @endif
                </div>
            @endif
        </div>
    @elseif ($activeTab === 'browse')
        {{-- Browse Mods Tab --}}
        <div class="space-y-6 mt-6">
            {{-- Search and Actions Bar --}}
            <div class="flex gap-4 items-center">
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
                    icon="heroicon-o-square-3-stack-3d"
                >
                    {{ trans('factorio-mod-installer::factorio-mod-installer.categories.all') }}
                </x-filament::button>
                @php
                    $categoryIcons = [
                        'gameplay' => 'heroicon-o-play',
                        'content' => 'heroicon-o-cube',
                        'tweaks' => 'heroicon-o-wrench',
                        'utilities' => 'heroicon-o-wrench',
                        'scenarios' => 'heroicon-o-square-2-stack',
                        'mod-packs' => 'heroicon-o-archive-box',
                        'localizations' => 'heroicon-o-language',
                        'internal' => 'heroicon-o-cog',
                    ];
                @endphp
                @foreach(['gameplay', 'content', 'tweaks', 'utilities', 'scenarios', 'mod-packs', 'localizations', 'internal'] as $category)
                    <x-filament::button
                        size="xs"
                        wire:click="setCategory('{{ $category }}')"
                        :color="$selectedCategory === $category ? 'primary' : 'gray'"
                        :icon="$categoryIcons[$category] ?? 'heroicon-o-tag'"
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
                {{-- Card Grid Layout --}}
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    @foreach ($browseMods as $mod)
                        <x-filament::section>
                            <x-slot name="heading">
                                <div class="flex items-center gap-3">
                                    {{-- Thumbnail --}}
                                    @if (!empty($mod['thumbnail']) && is_string($mod['thumbnail']))
                                        <img 
                                            src="{{ str_starts_with($mod['thumbnail'], 'http') ? $mod['thumbnail'] : 'https://mods.factorio.com' . $mod['thumbnail'] }}" 
                                            alt="{{ $mod['title'] ?? $mod['name'] }}"
                                            class="w-10 h-10 rounded object-cover flex-shrink-0"
                                            loading="lazy"
                                            onerror="this.style.display='none'"
                                        />
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <span class="font-semibold text-white truncate block">{{ $mod['title'] ?? $mod['name'] }}</span>
                                        @if (!empty($mod['owner']) && is_string($mod['owner']))
                                            <span class="text-xs text-gray-500">by {{ $mod['owner'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </x-slot>
                            
                            {{-- Meta Badges Row --}}
                            <div class="flex items-center flex-wrap gap-2 mb-4">
                                {{-- Downloads Badge --}}
                                @if (isset($mod['downloads_count']))
                                    <x-filament::badge size="sm" color="gray" icon="heroicon-o-arrow-down-tray">
                                        {{ number_format($mod['downloads_count']) }}
                                    </x-filament::badge>
                                @endif
                                
                                {{-- Category Badge --}}
                                @if (!empty($mod['category']) && is_string($mod['category']))
                                    @php
                                        $categoryKey = strtolower(str_replace(' ', '-', $mod['category']));
                                        $translationKey = 'factorio-mod-installer::factorio-mod-installer.categories.' . $categoryKey;
                                        $translated = trans($translationKey);
                                        $displayCategory = ($translated === $translationKey) 
                                            ? ucfirst(str_replace('-', ' ', $mod['category'])) 
                                            : $translated;
                                        if (strtolower($mod['category']) === 'no-category') {
                                            $displayCategory = 'Miscellaneous';
                                        }
                                    @endphp
                                    <x-filament::badge size="sm" color="info">
                                        {{ $displayCategory }}
                                    </x-filament::badge>
                                @endif
                                
                                {{-- Version Badge --}}
                                @if (!empty($mod['latest_release']['version']) && is_string($mod['latest_release']['version']))
                                    <x-filament::badge size="sm" color="gray">
                                        v{{ $mod['latest_release']['version'] }}
                                    </x-filament::badge>
                                @endif
                            </div>
                            
                            {{-- Description (max 2 Zeilen) --}}
                            @if (!empty($mod['summary']))
                                <p class="text-sm text-gray-400 line-clamp-2 mb-6" title="{{ $mod['summary'] }}">
                                    {{ $mod['summary'] }}
                                </p>
                            @else
                                <div class="mb-6"></div>
                            @endif
                            
                            {{-- Action Buttons --}}
                            <div class="flex items-center gap-3 pt-4 border-t border-gray-700">
                                @php
                                    $isInstalled = in_array($mod['name'], array_column($installedMods, 'name'));
                                @endphp
                                
                                {{-- Install Button (Primary) --}}
                                <x-filament::button
                                    size="sm"
                                    wire:click="installMod('{{ $mod['name'] }}')"
                                    :color="$isInstalled ? 'success' : 'warning'"
                                    :disabled="$isInstalled"
                                    icon="{{ $isInstalled ? 'heroicon-o-check' : 'heroicon-o-arrow-down-tray' }}"
                                >
                                    {{ $isInstalled 
                                        ? trans('factorio-mod-installer::factorio-mod-installer.browse.installed')
                                        : trans('factorio-mod-installer::factorio-mod-installer.browse.install') }}
                                </x-filament::button>
                                
                                {{-- Details Button (Secondary) - ruft openModDetails auf --}}
                                <x-filament::button
                                    size="sm"
                                    wire:click="openModDetails('{{ $mod['name'] }}')"
                                    color="gray"
                                    outlined
                                    icon="heroicon-o-information-circle"
                                >
                                    Details
                                </x-filament::button>
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif ($activeTab === 'direct')
        {{-- Direct Install Tab with Filament Forms --}}
        <div class="mt-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Left Column: Form --}}
                <div>
                    {{ $this->form }}
                    
                    {{-- Important Notes --}}
                    <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-6">
                        <div class="flex items-center gap-2 mb-3">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h4 class="font-medium">Important Notes</h4>
                        </div>
                        <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <li class="flex items-start gap-2">
                                <span class="text-warning-600 dark:text-warning-400">⚠</span>
                                <span>Mod names are <strong class="text-gray-900 dark:text-gray-100">case-sensitive</strong></span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-info-600 dark:text-info-400">−</span>
                                <span>Use underscores, not spaces (e.g., <code class="px-1 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-xs font-mono">my_mod</code>)</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-success-600 dark:text-success-400">↓</span>
                                <span>Latest compatible version installed automatically</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="text-primary-600 dark:text-primary-400">🧩</span>
                                <span>Dependencies installed automatically</span>
                            </li>
                        </ul>
                    </div>
                </div>

                {{-- Right Column: Instructions --}}
                <div class="space-y-6">
                    {{-- Instructions Card --}}
                    <div class="relative overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm">
                        {{-- Header mit Gradient --}}
                        <div class="bg-gradient-to-r from-blue-50 to-purple-50 dark:from-gray-800 dark:to-gray-800 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-white dark:bg-gray-700 rounded-lg shadow-sm">
                                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">How to find the mod name</h3>
                            </div>
                        </div>

                        {{-- Instructions Content --}}
                        <div class="px-6 py-5">
                            <ol class="space-y-3 text-sm">
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-bold">1</span>
                                    <span class="text-gray-700 dark:text-gray-300 pt-0.5">Go to <a href="https://mods.factorio.com" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium hover:underline">mods.factorio.com<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a></span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-bold">2</span>
                                    <span class="text-gray-700 dark:text-gray-300 pt-0.5">Find the mod you want to install</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-bold">3</span>
                                    <span class="text-gray-700 dark:text-gray-300 pt-0.5">Copy the mod name from the URL</span>
                                </li>
                            </ol>

                            {{-- Example URL Section --}}
                            <div class="mt-6 p-4 bg-gradient-to-br from-gray-50 to-blue-50/30 dark:from-gray-800/50 dark:to-blue-900/10 rounded-lg border border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2 mb-3">
                                    <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                                    </svg>
                                    <p class="text-xs font-semibold text-gray-900 dark:text-white uppercase tracking-wide">Example URL:</p>
                                </div>
                                <div class="font-mono text-sm bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg px-4 py-3 shadow-sm">
                                    <span class="text-gray-500 dark:text-gray-400">https://mods.factorio.com/mod/</span><span class="text-blue-600 dark:text-blue-400 font-bold">space-exploration</span>
                                </div>
                                <div class="flex items-center gap-2 mt-3">
                                    <svg class="w-4 h-4 text-green-600 dark:text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <p class="text-xs text-gray-600 dark:text-gray-400">
                                        Use <span class="font-mono bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-1 rounded font-semibold">space-exploration</span> as the mod name
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
                icon="heroicon-o-plus"
            >
                {{ trans('factorio-mod-installer::factorio-mod-installer.modal.add') }}
            </x-filament::button>
            
            <x-filament::button
                color="gray"
                wire:click="closeAddModal"
                icon="heroicon-o-x-mark"
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
                            icon="heroicon-o-globe-alt"
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
                            icon="heroicon-o-code-bracket"
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
                    :icon="in_array($selectedModDetails['name'], array_column($installedMods, 'name')) ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-down-tray'"
                >
                    {{ in_array($selectedModDetails['name'], array_column($installedMods, 'name')) 
                        ? trans('factorio-mod-installer::factorio-mod-installer.browse.installed')
                        : trans('factorio-mod-installer::factorio-mod-installer.browse.install') }}
                </x-filament::button>
                
                <x-filament::button
                    size="sm"
                    color="gray"
                    wire:click="closeModDetails"
                    icon="heroicon-o-x-mark"
                >
                    {{ trans('factorio-mod-installer::factorio-mod-installer.modal.close') }}
                </x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>

</x-filament-panels::page>
