<?php

namespace gOOvER\FactorioModInstaller\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use gOOvER\FactorioModInstaller\Services\FactorioModPortalService;
use gOOvER\FactorioModInstaller\Services\ModListService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;

class FactorioModInstaller extends Page implements Forms\Contracts\HasForms
{
    use BlockAccessInConflict;
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $slug = 'factorio-mods';

    protected string $view = 'factorio-mod-installer::filament.pages.factorio-mod-installer';

    public static function getNavigationIcon(): ?string
    {
        return 'tabler-puzzle';
    }

    public static function getNavigationLabel(): string
    {
        return 'Factorio Mods';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Factorio';
    }

    public function getTitle(): string
    {
        return 'Factorio Mod Installer';
    }

    public static function getNavigationSort(): ?int
    {
        return 100;
    }

    protected function getHeaderActions(): array
    {
        if (!class_exists(ListFiles::class)) {
            return [];
        }

        $modsFolder = trim((string) config('factorio-mod-installer.mods_directory', 'mods'), '/') ?: 'mods';

        return [
            Action::make('open_folder')
                ->label('Mods Folder')
                ->icon('tabler-folder-open')
                ->color('gray')
                ->url(fn () => ListFiles::getUrl(['path' => $modsFolder]), true),
        ];
    }

    public string $activeTab = 'installed';
    public array $installedMods = [];
    public array $browseMods = [];
    public array $modStates = [];
    public string $searchQuery = '';
    public bool $loading = false;
    public bool $showAddModal = false;
    public string $newModName = '';
    public ?string $factorioVersion = null;
    public array $modsWithUpdates = [];
    public ?string $selectedCategory = null;
    public bool $showDetailsModal = false;
    public ?array $selectedModDetails = null;
    public array $cacheStats = [];
    public string $directInstallModName = '';
    public bool $allModsEnabled = true;
    public array $availableVersions = [];
    public ?string $selectedVersion = null;
    public bool $loadingVersions = false;
    public bool $validatingModName = false;
    public ?bool $modNameValid = null;
    public ?array $modDetailsPreview = null;
    public bool $showModDetails = false;
    public bool $isExperimental = false;

    // Filter & Search Properties
    public string $installedModsSearch = '';
    public string $installedModsFilter = 'all'; // all, enabled, disabled, updates
    public string $installedModsSort = 'name'; // name, version, status, updates

    public function mount(): void
    {
        $this->loadFactorioVersion();
        $this->loadInstalledMods();
        $this->loadCacheStats();

        if ($this->activeTab === 'browse') {
            $this->loadBrowseMods();
        }
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Install Mod')
                    ->description('Enter the mod name to install from Factorio Mod Portal')
                    ->schema([
                        TextInput::make('directInstallModName')
                            ->label('Mod Name')
                            ->placeholder('e.g., space-exploration')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state) {
                                $this->directInstallModName = (string) $state;
                                $this->validateModName();
                            })
                            ->helperText(fn () => $this->getModNameHelperText())
                            ->suffixIcon(fn () => $this->getModValidationIcon())
                            ->suffixIconColor(fn () => $this->getModValidationColor())
                            ->extraInputAttributes([
                                'x-data' => '{}',
                                'x-on:drop.prevent' => "
                                    const text = \$event.dataTransfer.getData('text');
                                    const match = text.match(/mods\.factorio\.com\/mod\/([^\/\?#]+)/);
                                    if (match) {
                                        \$wire.set('directInstallModName', decodeURIComponent(match[1]));
                                        \$wire.call('validateModName');
                                    }
                                ",
                                'x-on:dragover.prevent' => '',
                            ]),

                        Select::make('selectedVersion')
                            ->label('Version (optional)')
                            ->placeholder('Latest compatible version')
                            ->options(fn () => $this->getVersionOptions())
                            ->visible(fn () => !empty($this->availableVersions))
                            ->live()
                            ->afterStateUpdated(function ($state) {
                                $this->selectedVersion = $state;
                            })
                            ->helperText(fn () => count($this->availableVersions) . ' version(s) available'),

                        Placeholder::make('modDetailsPreview')
                            ->label('Mod Details')
                            ->content(fn () => $this->formatModDetailsHtml())
                            ->visible(fn () => $this->showModDetails && $this->modDetailsPreview),

                        Actions::make([
                            Action::make('exclude_findVersions')
                                ->label('Find Versions')
                                ->color('gray')
                                ->disabled(fn () => $this->modNameValid === false || empty($this->directInstallModName))
                                ->action(fn () => $this->loadAvailableVersions()),

                            Action::make('exclude_installMod')
                                ->label(fn () => 'Install Mod' . ($this->selectedVersion ? " (v{$this->selectedVersion})" : ''))
                                ->color('success')
                                ->size('lg')
                                ->disabled(fn () => $this->modNameValid === false || empty($this->directInstallModName))
                                ->action(fn () => $this->installModByName()),
                        ])->fullWidth(),
                    ]),
            ]);
    }

    protected function getModNameHelperText(): ?string
    {
        if ($this->modNameValid === false) {
            return 'Mod not found on Factorio Mod Portal';
        }
        if ($this->validatingModName) {
            return 'Validating...';
        }
        return 'Drag & drop a mod URL or enter the mod name manually';
    }

    protected function getModValidationIcon(): ?string
    {
        if ($this->modNameValid === true) {
            return 'heroicon-o-check-circle';
        }
        if ($this->modNameValid === false) {
            return 'heroicon-o-x-circle';
        }
        return null;
    }

    protected function getModValidationColor(): ?string
    {
        if ($this->modNameValid === true) {
            return 'success';
        }
        if ($this->modNameValid === false) {
            return 'danger';
        }
        return null;
    }

    protected function getVersionOptions(): array
    {
        $options = [];
        foreach ($this->availableVersions as $version) {
            $label = "v{$version['version']} (Factorio {$version['factorio_version']})";
            if ($version['is_compatible']) {
                $label .= ' ✓';
            } else {
                $label .= ' ⚠ Incompatible';
            }
            if ($version['released_at']) {
                $label .= ' - ' . \Carbon\Carbon::parse($version['released_at'])->format('Y-m-d');
            }
            $options[$version['version']] = $label;
        }
        return $options;
    }

    protected function isSafeHttpUrl(?string $url): bool
    {
        if (!$url || !is_string($url)) {
            return false;
        }

        return (bool) preg_match('/^https?:\/\/[^\s"\'<>]+$/i', trim($url));
    }

    protected function formatModDetailsHtml(): HtmlString|string
    {
        if (empty($this->modDetailsPreview)) {
            return '';
        }

        $details = $this->modDetailsPreview;
        $title = e((string) ($details['title'] ?? $details['name'] ?? ''));
        $owner = e((string) ($details['owner'] ?? ''));
        $category = !empty($details['category']) ? e(ucfirst((string) $details['category'])) : '';
        $downloads = number_format((int) ($details['downloads_count'] ?? 0));
        $summary = !empty($details['summary']) ? e((string) $details['summary']) : '';
        $modPortalUrl = 'https://mods.factorio.com/mod/' . rawurlencode((string) ($details['name'] ?? ''));

        $homepageUrl = $details['homepage'] ?? null;
        if (!$this->isSafeHttpUrl($homepageUrl)) {
            $homepageUrl = null;
        }

        $sourceUrl = $details['source_url'] ?? null;
        if (!$sourceUrl && !empty($details['github_path']) && is_string($details['github_path'])) {
            $sourceUrl = 'https://github.com/' . ltrim($details['github_path'], '/');
        }
        if (!$this->isSafeHttpUrl($sourceUrl)) {
            $sourceUrl = null;
        }

        $html = '<div class="relative overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm">';
        $html .= '<div class="bg-gradient-to-r from-primary-50 to-info-50 dark:from-gray-800 dark:to-gray-800 px-6 py-4 border-b border-gray-200 dark:border-gray-700">';
        $html .= '<div class="flex items-start justify-between gap-4">';

        $html .= '<div class="flex-1 min-w-0">';
        $html .= '<h4 class="text-lg font-bold text-gray-900 dark:text-white mb-1 truncate">' . $title . '</h4>';
        $html .= '<div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">';
        $html .= '<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>';
        $html .= '<span class="font-medium">' . $owner . '</span>';
        if ($category !== '') {
            $html .= '<span class="text-gray-400 dark:text-gray-600">•</span>';
            $html .= '<span class="px-2 py-0.5 bg-white dark:bg-gray-700 rounded-full text-xs font-medium">' . $category . '</span>';
        }
        $html .= '</div></div>';

        $html .= '<div class="flex-shrink-0">';
        $html .= '<div class="flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<svg class="w-4 h-4 text-success-600 dark:text-success-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';
        $html .= '<span class="text-sm font-semibold text-gray-900 dark:text-white">' . $downloads . '</span>';
        $html .= '</div></div>';

        $html .= '</div></div>';

        if ($summary !== '') {
            $html .= '<div class="px-6 py-4">';
            $html .= '<p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">' . $summary . '</p>';
            $html .= '</div>';
        }

        $html .= '<div class="px-6 py-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">';
        $html .= '<div class="flex flex-wrap items-center gap-2">';

        if ($homepageUrl) {
            $html .= '<a href="' . e($homepageUrl) . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm transition-all duration-200">';
            $html .= '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>';
            $html .= 'Homepage</a>';
        }

        if ($sourceUrl) {
            $html .= '<a href="' . e($sourceUrl) . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-900 dark:text-white bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 rounded-lg shadow-sm transition-all duration-200">';
            $html .= '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>';
            $html .= 'Source</a>';
        }

        $html .= '<a href="' . e($modPortalUrl) . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-lg shadow-sm transition-all duration-200">';
        $html .= '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
        $html .= 'Mod Portal</a>';

        $html .= '</div></div></div>';

        return new HtmlString($html);
    }

    #[Computed]
    public function filteredInstalledMods(): array
    {
        $mods = $this->installedMods;

        if (!empty($this->installedModsSearch)) {
            $search = mb_strtolower($this->installedModsSearch);
            $mods = array_filter($mods, function ($mod) use ($search) {
                $title = mb_strtolower($mod['title'] ?? $mod['name']);
                $name = mb_strtolower($mod['name']);
                $summary = mb_strtolower($mod['summary'] ?? '');
                return str_contains($title, $search) || str_contains($name, $search) || str_contains($summary, $search);
            });
        }

        switch ($this->installedModsFilter) {
            case 'enabled':
                $mods = array_filter($mods, fn ($mod) => !empty($this->modStates[$mod['name']]));
                break;
            case 'disabled':
                $mods = array_filter($mods, fn ($mod) => empty($this->modStates[$mod['name']]) && $mod['name'] !== 'base');
                break;
            case 'updates':
                $mods = array_filter($mods, fn ($mod) => !empty($mod['update_available']));
                break;
        }

        usort($mods, function ($a, $b) {
            switch ($this->installedModsSort) {
                case 'version':
                    return version_compare($b['version'] ?? '0', $a['version'] ?? '0');
                case 'status':
                    $aEnabled = !empty($this->modStates[$a['name']]) ? 1 : 0;
                    $bEnabled = !empty($this->modStates[$b['name']]) ? 1 : 0;
                    return $bEnabled <=> $aEnabled;
                case 'updates':
                    $aUpdate = !empty($a['update_available']) ? 1 : 0;
                    $bUpdate = !empty($b['update_available']) ? 1 : 0;
                    if ($aUpdate !== $bUpdate) {
                        return $bUpdate <=> $aUpdate;
                    }
                    return strcasecmp($a['title'] ?? $a['name'], $b['title'] ?? $b['name']);
                default:
                    return strcasecmp($a['title'] ?? $a['name'], $b['title'] ?? $b['name']);
            }
        });

        return array_values($mods);
    }

    public function loadFactorioVersion(): void
    {
        $modPortalService = app(FactorioModPortalService::class);

        try {
            /** @var Server $server */
            $server = Filament::getTenant();

            $variable = $server->variables()
                ->where('env_variable', 'FACTORIO_VERSION')
                ->first();

            $rawVersion = $variable?->server_value ?? $variable?->variable_value ?? 'latest';
            $rawVersionLower = strtolower(trim((string) $rawVersion));

            if ($rawVersionLower === '' || $rawVersionLower === 'latest' || $rawVersionLower === 'stable') {
                $this->isExperimental = false;
                $this->factorioVersion = $modPortalService->getLatestFactorioVersion(false);
                return;
            }

            if (str_contains($rawVersionLower, 'experimental')) {
                $this->isExperimental = true;
                if (preg_match('/(\d+\.\d+)/', $rawVersion, $matches)) {
                    $this->factorioVersion = $matches[1];
                } else {
                    $this->factorioVersion = $modPortalService->getLatestFactorioVersion(true);
                }
                return;
            }

            $this->isExperimental = false;
            $this->factorioVersion = $modPortalService->normalizeFactorioMajorVersion($rawVersion);
        } catch (\Exception $e) {
            $this->isExperimental = false;
            $this->factorioVersion = $modPortalService->getLatestFactorioVersion(false);
        }
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'browse' && empty($this->browseMods)) {
            $this->loadBrowseMods();
        }
    }

    public function updatedSearchQuery(): void
    {
        if ($this->activeTab === 'browse') {
            $this->loadBrowseMods();
        }
    }

    public function setCategory(?string $category): void
    {
        $this->selectedCategory = $category;
        $this->loadBrowseMods();
    }

    public function refreshMods(): void
    {
        try {
            $service = app(FactorioModPortalService::class);
            $service->clearCache();

            $this->loadBrowseMods();

            Notification::make()
                ->title('Mod list refreshed')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error refreshing mods')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function openModDetails(string $modName): void
    {
        try {
            $service = app(FactorioModPortalService::class);
            $modDetails = $service->getModDetails($modName, true);

            if ($modDetails) {
                $releases = !empty($modDetails['releases']) && is_array($modDetails['releases'])
                    ? $service->sortReleasesDesc($modDetails['releases'])
                    : [];

                // Select latest compatible release for this server (or newest overall if none matches)
                $latestRelease = null;
                foreach ($releases as $rel) {
                    if ($this->isVersionCompatible($rel['info_json']['factorio_version'] ?? null, $this->factorioVersion)) {
                        $latestRelease = $rel;
                        break;
                    }
                }
                $latestRelease ??= ($releases[0] ?? null);

                if ($latestRelease && isset($latestRelease['info_json']['dependencies']) && is_array($latestRelease['info_json']['dependencies'])) {
                    $modDetails['dependencies'] = $this->parseDependencies($latestRelease['info_json']['dependencies']);
                }

                $modDetails['latest_release'] = $latestRelease;
                $this->selectedModDetails = $modDetails;
                $this->showDetailsModal = true;

                $this->dispatch('open-modal', id: 'mod-details-modal');
            } else {
                throw new \Exception('Mod details not found');
            }
        } catch (\Exception $e) {
            Log::error('Error in openModDetails: ' . $e->getMessage());

            Notification::make()
                ->title('Error loading mod details')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function closeModDetails(): void
    {
        $this->showDetailsModal = false;
        $this->selectedModDetails = null;
        $this->dispatch('close-modal', id: 'mod-details-modal');
    }

    public function installModFromDetails(): void
    {
        if ($this->selectedModDetails) {
            $this->installMod($this->selectedModDetails['name']);
            $this->closeModDetails();
        }
    }

    private function parseDependencies(array $dependencies): array
    {
        $parsed = [];
        $service = app(FactorioModPortalService::class);

        foreach ($dependencies as $dep) {
            if (!is_string($dep)) {
                continue;
            }

            $item = $service->parseDependencyString($dep);
            if ($item && $item['name'] !== 'base') {
                $parsed[] = [
                    'name' => $item['name'],
                    'type' => $item['type'],
                    'version' => $item['version'],
                ];
            }
        }

        return $parsed;
    }

    public function loadInstalledMods(): void
    {
        $this->loading = true;

        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);

            // 1. Read physical mod files once (avoids N+1 Wings daemon directory calls)
            $modFiles = $modListService->getModFiles($server);
            $allMods = $modListService->getMods($server, $modFiles);

            // Filter out built-in Factorio and Space Age DLC mods
            $filteredMods = array_values(array_filter($allMods, function ($mod) {
                return !in_array($mod['name'] ?? '', FactorioModPortalService::BUILTIN_MODS, true);
            }));

            // 2. Fetch portal details for all installed mods in bulk via `namelist` (avoids N+1 Mod Portal requests)
            $modNames = array_column($filteredMods, 'name');
            $portalModsMap = $modPortalService->getModsByNames($modNames);

            $this->installedMods = [];
            $this->modsWithUpdates = [];

            foreach ($filteredMods as $mod) {
                $modName = $mod['name'];
                $installedVersion = $modListService->extractInstalledVersionFromFiles($modName, $modFiles);
                $mod['version'] = $installedVersion ?? 'Unknown';
                $mod['update_available'] = false;
                $mod['latest_version_display'] = null;

                $modDetails = $portalModsMap[$modName] ?? null;
                if ($modDetails) {
                    $mod['title'] = $modDetails['title'] ?? $modName;
                    $mod['summary'] = $modDetails['summary'] ?? '';
                    $mod['thumbnail'] = $modDetails['thumbnail'] ?? null;

                    $releases = $modDetails['releases'] ?? [];
                    if (!empty($releases) && is_array($releases)) {
                        $releases = $modPortalService->sortReleasesDesc($releases);

                        $latestCompatibleRelease = null;
                        foreach ($releases as $release) {
                            $releaseFactorioVersion = $release['info_json']['factorio_version'] ?? null;
                            if ($this->isVersionCompatible($releaseFactorioVersion)) {
                                $latestCompatibleRelease = $release;
                                break;
                            }
                        }

                        $mod['latest_version'] = $latestCompatibleRelease['version'] ?? null;

                        if ($installedVersion && $mod['latest_version'] && version_compare($mod['latest_version'], $installedVersion, '>')) {
                            $mod['update_available'] = $mod['latest_version'];
                            $mod['latest_version_display'] = $mod['latest_version'];
                            $this->modsWithUpdates[] = $modName;
                        }
                    }
                }

                $this->installedMods[] = $mod;
            }

            $this->modStates = [];
            foreach ($this->installedMods as $mod) {
                $this->modStates[$mod['name']] = (bool) ($mod['enabled'] ?? true);
            }
        } catch (\Exception $e) {
            Log::error('Error loading installed Factorio mods: ' . $e->getMessage());
            $this->installedMods = [];
        } finally {
            $this->loading = false;
        }
    }

    public function loadCacheStats(): void
    {
        $service = app(FactorioModPortalService::class);
        $this->cacheStats = $service->getCacheInfo();
    }

    public function checkForUpdates(): void
    {
        $this->loading = true;

        try {
            $modPortalService = app(FactorioModPortalService::class);
            $modPortalService->clearCache();

            $this->loadInstalledMods();

            $updateCount = count(array_filter($this->installedMods, fn ($m) => !empty($m['update_available'])));

            if ($updateCount > 0) {
                Notification::make()
                    ->title('Updates verfügbar')
                    ->body("Es wurden {$updateCount} Updates für installierte Mods gefunden.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Keine Updates verfügbar')
                    ->body('Alle installierten Mods sind auf dem neuesten Stand.')
                    ->info()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Fehler beim Suchen nach Updates')
                ->body($e->getMessage())
                ->danger()
                ->send();

            Log::error('Error checking for mod updates: ' . $e->getMessage());
        } finally {
            $this->loading = false;
        }
    }

    public function validateModName(): void
    {
        $this->directInstallModName = trim($this->directInstallModName);
        if ($this->directInstallModName === '') {
            $this->modNameValid = null;
            $this->modDetailsPreview = null;
            $this->showModDetails = false;
            return;
        }

        $this->validatingModName = true;
        $this->modNameValid = null;

        try {
            $service = app(FactorioModPortalService::class);
            $modDetails = $service->getModDetails($this->directInstallModName, true);

            if ($modDetails) {
                $this->modNameValid = true;
                $this->modDetailsPreview = [
                    'name' => $modDetails['name'],
                    'title' => $modDetails['title'] ?? $modDetails['name'],
                    'summary' => $modDetails['summary'] ?? '',
                    'owner' => $modDetails['owner'] ?? '',
                    'downloads_count' => $modDetails['downloads_count'] ?? 0,
                    'category' => $modDetails['category'] ?? '',
                    'homepage' => $modDetails['homepage'] ?? null,
                    'source_url' => $modDetails['source_url'] ?? null,
                    'github_path' => $modDetails['github_path'] ?? null,
                ];
                $this->showModDetails = true;
            } else {
                $this->modNameValid = false;
                $this->modDetailsPreview = null;
                $this->showModDetails = false;
            }
        } catch (\Exception $e) {
            $this->modNameValid = false;
            $this->modDetailsPreview = null;
            $this->showModDetails = false;
        } finally {
            $this->validatingModName = false;
        }
    }

    public function loadAvailableVersions(): void
    {
        if (empty($this->directInstallModName)) {
            $this->availableVersions = [];
            $this->selectedVersion = null;
            return;
        }

        if ($this->modNameValid === null) {
            $this->validateModName();
        }

        $this->loadingVersions = true;

        try {
            $service = app(FactorioModPortalService::class);
            $modDetails = $service->getModDetails($this->directInstallModName, true);

            if (!$modDetails || empty($modDetails['releases']) || !is_array($modDetails['releases'])) {
                $this->availableVersions = [];
                $this->selectedVersion = null;
                return;
            }

            $releases = $service->sortReleasesDesc($modDetails['releases']);

            $this->availableVersions = array_map(function ($release) {
                $factorioVersion = $release['info_json']['factorio_version'] ?? '0.12';
                $isCompatible = $this->isVersionCompatible($factorioVersion);

                return [
                    'version' => $release['version'],
                    'factorio_version' => $factorioVersion,
                    'released_at' => $release['released_at'] ?? null,
                    'is_compatible' => $isCompatible,
                ];
            }, $releases);

            $this->selectedVersion = null;
            foreach ($this->availableVersions as $version) {
                if ($version['is_compatible']) {
                    $this->selectedVersion = $version['version'];
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error loading versions: ' . $e->getMessage());
            $this->availableVersions = [];
            $this->selectedVersion = null;
        } finally {
            $this->loadingVersions = false;
        }
    }

    public function installModByName(): void
    {
        if (empty($this->directInstallModName)) {
            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.mod_name_required'))
                ->danger()
                ->send();
            return;
        }

        try {
            $versionToInstall = $this->selectedVersion;

            if (!$versionToInstall) {
                $service = app(FactorioModPortalService::class);
                $modDetails = $service->getModDetails($this->directInstallModName, true);

                if (!$modDetails || empty($modDetails['releases'])) {
                    Notification::make()
                        ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.mod_not_found'))
                        ->body('Use the exact mod name from the URL: https://mods.factorio.com/mod/ModName → "ModName"')
                        ->danger()
                        ->send();
                    return;
                }

                $latestRelease = $service->getLatestRelease($this->directInstallModName, $this->factorioVersion, true);

                if (!$latestRelease) {
                    Notification::make()
                        ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.no_compatible_version'))
                        ->danger()
                        ->send();
                    return;
                }

                $versionToInstall = $latestRelease['version'];
            }

            $this->installMod($this->directInstallModName, $versionToInstall);
            $this->directInstallModName = '';
            $this->selectedVersion = null;
            $this->availableVersions = [];
            $this->modNameValid = null;
            $this->modDetailsPreview = null;
            $this->showModDetails = false;
        } catch (\Exception $e) {
            Log::error('Error installing mod by name: ' . $e->getMessage());
            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function loadBrowseMods(): void
    {
        $this->loading = true;

        try {
            $service = app(FactorioModPortalService::class);

            if ($this->searchQuery) {
                $result = $service->searchMods(
                    $this->searchQuery,
                    $this->selectedCategory,
                    'downloads_count',
                    'desc',
                    100,
                    $this->factorioVersion
                );
            } elseif ($this->selectedCategory) {
                $result = $service->getModsByCategory($this->selectedCategory, 1, 100, $this->factorioVersion);
            } else {
                $result = $service->getPopularMods(1, 100, $this->factorioVersion);
            }

            $allMods = $result['results'] ?? [];

            $this->browseMods = array_values(array_filter($allMods, function ($mod) {
                $latestRelease = $mod['latest_release'] ?? null;
                if (!$latestRelease) {
                    return false;
                }

                $factorioVersion = $latestRelease['info_json']['factorio_version'] ?? null;
                return $this->isVersionCompatible($factorioVersion, $this->factorioVersion);
            }));
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error browsing mods')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->loading = false;
        }
    }

    private function isVersionCompatible(?string $requiredVersion, ?string $serverVersion = null): bool
    {
        $serverVersion ??= ($this->factorioVersion ?? '2.1');
        return app(FactorioModPortalService::class)->isVersionCompatible($requiredVersion, $serverVersion);
    }

    public function installMod(string $modName, ?string $version = null): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $server->loadMissing('variables');

            $modListService = app(ModListService::class);

            if ($this->isExperimental) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.experimental_warning'))
                    ->body(__('factorio-mod-installer::factorio-mod-installer.notifications.experimental_warning_body'))
                    ->warning()
                    ->send();
            }

            $installed = [];
            $results = $modListService->installModWithDependencies(
                $server,
                $modName,
                true,
                $version,
                $installed,
                $this->factorioVersion
            );

            $installedCount = count($results['installed']);
            $failedCount = count($results['failed']);

            if ($installedCount > 0) {
                $message = $installedCount === 1
                    ? __('factorio-mod-installer::factorio-mod-installer.notifications.installed')
                    : __('factorio-mod-installer::factorio-mod-installer.notifications.installed_with_deps', ['count' => $installedCount]);

                $body = null;
                if ($installedCount > 1) {
                    $body = __('factorio-mod-installer::factorio-mod-installer.notifications.installed_mods') . ': ' . implode(', ', $results['installed']);
                }

                Notification::make()
                    ->title($message)
                    ->body($body)
                    ->success()
                    ->send();
            }

            if ($failedCount > 0) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.some_failed'))
                    ->body(__('factorio-mod-installer::factorio-mod-installer.notifications.failed_mods') . ': ' . implode(', ', $results['failed']) . '. Please check that Factorio username and token are set in server-settings.json.')
                    ->warning()
                    ->send();
            }

            if ($installedCount === 0 && $failedCount === 0) {
                throw new \Exception('Failed to install mod. Please verify Factorio credentials in server-settings.json.');
            }

            $this->loadInstalledMods();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function toggleMod(string $modName): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);

            if ($modListService->toggleMod($server, $modName)) {
                $this->loadInstalledMods();
                $newState = $this->modStates[$modName] ?? false;
                $statusText = $newState ? 'enabled' : 'disabled';

                $issues = [];
                if ($newState) {
                    try {
                        $modDetails = $modPortalService->getModDetails($modName, true);
                        if ($modDetails && !empty($modDetails['releases'])) {
                            $installedVersion = null;
                            foreach ($this->installedMods as $im) {
                                if ($im['name'] === $modName && ($im['version'] ?? 'Unknown') !== 'Unknown') {
                                    $installedVersion = $im['version'];
                                    break;
                                }
                            }

                            if ($installedVersion) {
                                foreach ($modDetails['releases'] as $release) {
                                    if (($release['version'] ?? null) === $installedVersion) {
                                        $requiredFactorioVersion = $release['info_json']['factorio_version'] ?? null;
                                        if ($requiredFactorioVersion && $this->factorioVersion) {
                                            if (!$this->isVersionCompatible($requiredFactorioVersion, $this->factorioVersion)) {
                                                $issues[] = "❌ Version incompatible: requires Factorio {$requiredFactorioVersion}, server runs {$this->factorioVersion}";
                                            }
                                        }

                                        $dependencies = $release['info_json']['dependencies'] ?? [];
                                        $installedModNames = array_column($this->installedMods, 'name');

                                        $missingDeps = [];
                                        $disabledDeps = [];

                                        foreach ($dependencies as $depString) {
                                            if (!is_string($depString)) {
                                                continue;
                                            }

                                            $parsed = $modPortalService->parseDependencyString($depString);
                                            if (!$parsed || !$parsed['is_required']) {
                                                continue;
                                            }

                                            $depName = $parsed['name'];
                                            if (in_array($depName, FactorioModPortalService::BUILTIN_MODS, true)) {
                                                continue;
                                            }

                                            if (!in_array($depName, $installedModNames, true)) {
                                                $missingDeps[] = $depName;
                                            } elseif (empty($this->modStates[$depName])) {
                                                $disabledDeps[] = $depName;
                                            }
                                        }

                                        if (!empty($missingDeps)) {
                                            $issues[] = '❌ Missing dependencies: ' . implode(', ', $missingDeps);
                                        }

                                        if (!empty($disabledDeps)) {
                                            $issues[] = '⚠️ Disabled dependencies: ' . implode(', ', $disabledDeps);
                                        }

                                        break;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Could not check mod compatibility: ' . $e->getMessage());
                    }
                }

                $notif = Notification::make()
                    ->title("Mod {$statusText}");

                if (!empty($issues)) {
                    $notif->body(implode("\n", $issues))
                        ->warning()
                        ->persistent();
                } else {
                    $notif->success();
                }

                $notif->send();
            } else {
                throw new \Exception('Failed to toggle mod');
            }
        } catch (\Exception $e) {
            Log::error('Toggle mod failed: ' . $e->getMessage());
            Notification::make()
                ->title('Error toggling mod')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function toggleAllMods(): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);

            $modifiableMods = array_filter($this->installedMods, fn ($mod) => !in_array($mod['name'], FactorioModPortalService::BUILTIN_MODS, true));
            $allEnabled = count(array_filter($modifiableMods, fn ($mod) => ($this->modStates[$mod['name']] ?? true) === true)) === count($modifiableMods);

            $targetState = !$allEnabled;
            $successCount = $modListService->setAllModsState($server, $targetState);

            $this->loadInstalledMods();

            Notification::make()
                ->title($targetState ? 'All mods enabled' : 'All mods disabled')
                ->body("{$successCount} mod(s) updated")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error toggling mods')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function openAddModal(): void
    {
        $this->newModName = '';
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
        $this->newModName = '';
    }

    public function addMod(): void
    {
        $this->validate([
            'newModName' => 'required|string|min:1',
        ]);

        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);

            if ($modListService->addMod($server, trim($this->newModName), true, null, $this->factorioVersion)) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.messages.mod_added'))
                    ->success()
                    ->send();

                $this->closeAddModal();
                $this->loadInstalledMods();
            } else {
                throw new \Exception('Failed to download or add mod. Verify mod name and credentials in server-settings.json.');
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.messages.error_adding_mod'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function updateMod(string $modName): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);

            $latestRelease = $modPortalService->getLatestRelease($modName, $this->factorioVersion);
            if (!$latestRelease || empty($latestRelease['version'])) {
                throw new \Exception('No compatible version found for Factorio ' . ($this->factorioVersion ?? 'unknown'));
            }

            $latestVersion = $latestRelease['version'];
            $modFiles = $modListService->getModFiles($server);
            $modFilePrefix = $modName . '_';

            // Install the latest version first before deleting older version files
            if (!$modListService->addMod($server, $modName, true, $latestVersion, $this->factorioVersion)) {
                throw new \Exception('Failed to download or install updated mod');
            }

            $newFileName = $latestRelease['file_name'] ?? "{$modName}_{$latestVersion}.zip";
            foreach ($modFiles as $file) {
                if ($file !== $newFileName && str_starts_with($file, $modFilePrefix)) {
                    $modListService->removeModFile($server, $file);
                }
            }

            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.updated'))
                ->body("Updated to version {$latestVersion}")
                ->success()
                ->send();

            $this->loadInstalledMods();
        } catch (\Exception $e) {
            Log::error("Error updating mod {$modName}: " . $e->getMessage());
            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function updateAllMods(): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);

            if (empty($this->modsWithUpdates)) {
                Notification::make()
                    ->title('No mods to update')
                    ->info()
                    ->send();
                return;
            }

            $updatedCount = 0;
            $failedMods = [];
            $modFiles = $modListService->getModFiles($server);

            foreach ($this->modsWithUpdates as $modName) {
                try {
                    $latestRelease = $modPortalService->getLatestRelease($modName, $this->factorioVersion);
                    if (!$latestRelease || empty($latestRelease['version'])) {
                        throw new \Exception('No compatible version found');
                    }

                    $latestVersion = $latestRelease['version'];
                    $newFileName = $latestRelease['file_name'] ?? "{$modName}_{$latestVersion}.zip";

                    if ($modListService->addMod($server, $modName, true, $latestVersion, $this->factorioVersion)) {
                        $modFilePrefix = $modName . '_';
                        foreach ($modFiles as $file) {
                            if ($file !== $newFileName && str_starts_with($file, $modFilePrefix)) {
                                $modListService->removeModFile($server, $file);
                            }
                        }
                        $updatedCount++;
                    } else {
                        throw new \Exception('Failed to install updated mod');
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to update {$modName}: " . $e->getMessage());
                    $failedMods[] = $modName;
                }
            }

            $message = "Updated {$updatedCount} mod(s)";
            if (!empty($failedMods)) {
                $message .= ' (Failed: ' . implode(', ', $failedMods) . ')';
            }

            Notification::make()
                ->title($message)
                ->success($updatedCount > 0)
                ->warning($updatedCount === 0 && !empty($failedMods))
                ->send();

            $this->loadInstalledMods();
        } catch (\Exception $e) {
            Log::error('Error in updateAllMods: ' . $e->getMessage());
            Notification::make()
                ->title('Error updating mods')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function removeMod(string $modName): void
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);

            if ($modListService->removeMod($server, $modName)) {
                Notification::make()
                    ->title('Mod removed successfully')
                    ->success()
                    ->send();

                $this->loadInstalledMods();
            } else {
                throw new \Exception('Failed to remove mod from mod-list.json');
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error removing mod')
                ->danger()
                ->send();
        }
    }

    public static function canAccess(): bool
    {
        if (!parent::canAccess()) {
            return false;
        }

        /** @var Server|null $server */
        $server = Filament::getTenant();
        if (!$server) {
            return false;
        }

        $server->loadMissing('egg');
        $egg = $server->egg;
        if (!$egg) {
            return false;
        }

        if ($egg->name && str_contains(strtolower($egg->name), 'factorio')) {
            return true;
        }

        if ($egg->tags) {
            if (is_array($egg->tags)) {
                foreach ($egg->tags as $tag) {
                    if (is_string($tag) && str_contains(strtolower($tag), 'factorio')) {
                        return true;
                    }
                }
            } elseif (is_string($egg->tags) && str_contains(strtolower($egg->tags), 'factorio')) {
                return true;
            }
        }

        if ($egg->features && is_array($egg->features)) {
            if (in_array('factorio_mod_installer', $egg->features, true)) {
                return true;
            }
        }

        return false;
    }
}
