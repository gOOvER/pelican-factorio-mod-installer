<?php

namespace gOOvER\FactorioModInstaller\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use gOOvER\FactorioModInstaller\Services\FactorioModPortalService;
use gOOvER\FactorioModInstaller\Services\ModListService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;

class FactorioModInstaller extends Page implements Forms\Contracts\HasForms
{
    use BlockAccessInConflict;
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $slug = 'factorio-mods';

    protected string $view = 'factorio-mod-installer::filament.pages.factorio-mod-installer';

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

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
    
    // Filter & Search Properties
    public string $installedModsSearch = '';
    public string $installedModsFilter = 'all'; // all, enabled, disabled, updates
    public string $installedModsSort = 'name'; // name, version, status

    public function mount(): void
    {
        $this->loadInstalledMods();
        $this->loadFactorioVersion();
        $this->loadBrowseMods();
        $this->loadCacheStats();
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
                                $this->directInstallModName = $state;
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
                                        \$wire.set('directInstallModName', match[1]);
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

    protected function formatModDetailsHtml(): \Illuminate\Support\HtmlString|string
    {
        if (empty($this->modDetailsPreview)) {
            return '';
        }

        $details = $this->modDetailsPreview;
        
        // Moderne Card mit Gradient-Border
        $html = "<div class='relative overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm'>";
        
        // Header mit Gradient Background
        $html .= "<div class='bg-gradient-to-r from-primary-50 to-info-50 dark:from-gray-800 dark:to-gray-800 px-6 py-4 border-b border-gray-200 dark:border-gray-700'>";
        $html .= "<div class='flex items-start justify-between gap-4'>";
        
        // Title & Author
        $html .= "<div class='flex-1 min-w-0'>";
        $html .= "<h4 class='text-lg font-bold text-gray-900 dark:text-white mb-1 truncate'>" . htmlspecialchars($details['title']) . "</h4>";
        $html .= "<div class='flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400'>";
        $html .= "<svg class='w-4 h-4 flex-shrink-0' fill='currentColor' viewBox='0 0 20 20'><path fill-rule='evenodd' d='M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z' clip-rule='evenodd'/></svg>";
        $html .= "<span class='font-medium'>" . htmlspecialchars($details['owner']) . "</span>";
        if (!empty($details['category'])) {
            $html .= "<span class='text-gray-400 dark:text-gray-600'>•</span>";
            $html .= "<span class='px-2 py-0.5 bg-white dark:bg-gray-700 rounded-full text-xs font-medium'>" . ucfirst(htmlspecialchars($details['category'])) . "</span>";
        }
        $html .= "</div></div>";
        
        // Downloads Badge
        $html .= "<div class='flex-shrink-0'>";
        $html .= "<div class='flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700'>";
        $html .= "<svg class='w-4 h-4 text-success-600 dark:text-success-400' fill='currentColor' viewBox='0 0 20 20'><path fill-rule='evenodd' d='M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z' clip-rule='evenodd'/></svg>";
        $html .= "<span class='text-sm font-semibold text-gray-900 dark:text-white'>" . number_format($details['downloads_count']) . "</span>";
        $html .= "</div></div>";
        
        $html .= "</div></div>";
        
        // Body mit Description
        if (!empty($details['summary'])) {
            $html .= "<div class='px-6 py-4'>";
            $html .= "<p class='text-sm text-gray-700 dark:text-gray-300 leading-relaxed'>" . htmlspecialchars($details['summary']) . "</p>";
            $html .= "</div>";
        }
        
        // Footer mit Links - Verbesserte Sichtbarkeit
        $html .= "<div class='px-6 py-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700'>";
        $html .= "<div class='flex flex-wrap items-center gap-2'>";
        
        if (!empty($details['homepage'])) {
            $html .= "<a href='" . htmlspecialchars($details['homepage']) . "' target='_blank' rel='noopener' class='inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 rounded-lg shadow-sm hover:shadow transition-all duration-200'>";
            $html .= "<svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14'/></svg>";
            $html .= "Homepage</a>";
        }
        
        if (!empty($details['github_path'])) {
            $html .= "<a href='https://github.com/" . htmlspecialchars($details['github_path']) . "' target='_blank' rel='noopener' class='inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-gray-900 dark:text-white bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 rounded-lg shadow-sm hover:shadow transition-all duration-200'>";
            $html .= "<svg class='w-4 h-4' fill='currentColor' viewBox='0 0 24 24'><path d='M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z'/></svg>";
            $html .= "GitHub</a>";
        }
        
        $html .= "<a href='https://mods.factorio.com/mod/" . htmlspecialchars($details['name']) . "' target='_blank' rel='noopener' class='inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 dark:bg-orange-600 dark:hover:bg-orange-700 rounded-lg shadow-sm hover:shadow transition-all duration-200'>";
        $html .= "<svg class='w-4 h-4' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M13 10V3L4 14h7v7l9-11h-7z'/></svg>";
        $html .= "Mod Portal</a>";
        
        $html .= "</div></div>";
        $html .= "</div>";
        
        return new \Illuminate\Support\HtmlString($html);
    }

    public bool $isExperimental = false;

    #[Computed]
    public function filteredInstalledMods(): array
    {
        $mods = $this->installedMods;
        
        // Search Filter
        if (!empty($this->installedModsSearch)) {
            $search = strtolower($this->installedModsSearch);
            $mods = array_filter($mods, function($mod) use ($search) {
                $title = strtolower($mod['title'] ?? $mod['name']);
                $name = strtolower($mod['name']);
                $summary = strtolower($mod['summary'] ?? '');
                return str_contains($title, $search) || str_contains($name, $search) || str_contains($summary, $search);
            });
        }
        
        // Status Filter
        switch ($this->installedModsFilter) {
            case 'enabled':
                $mods = array_filter($mods, fn($mod) => !empty($this->modStates[$mod['name']]));
                break;
            case 'disabled':
                $mods = array_filter($mods, fn($mod) => empty($this->modStates[$mod['name']]) && $mod['name'] !== 'base');
                break;
            case 'updates':
                $mods = array_filter($mods, fn($mod) => !empty($mod['update_available']));
                break;
        }
        
        // Sorting
        usort($mods, function($a, $b) {
            switch ($this->installedModsSort) {
                case 'version':
                    return version_compare($b['version'] ?? '0', $a['version'] ?? '0');
                case 'status':
                    $aEnabled = !empty($this->modStates[$a['name']]) ? 1 : 0;
                    $bEnabled = !empty($this->modStates[$b['name']]) ? 1 : 0;
                    return $bEnabled - $aEnabled;
                default: // name
                    return strcasecmp($a['title'] ?? $a['name'], $b['title'] ?? $b['name']);
            }
        });
        
        return array_values($mods);
    }

    public function loadFactorioVersion(): void
    {
        try {
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            
            // Try to get version from server variables
            foreach ($server->variables as $variable) {
                if ($variable->env_variable === 'FACTORIO_VERSION') {
                    $version = $variable->server_value ?? $variable->variable_value ?? null;
                    if ($version && $version !== 'latest') {
                        // Check if experimental version
                        if (str_contains(strtolower($version), 'experimental')) {
                            $this->isExperimental = true;
                            // Extract version number from experimental string
                            if (preg_match('/(\d+\.\d+)/', $version, $matches)) {
                                $this->factorioVersion = $matches[1];
                            } else {
                                $this->factorioVersion = '2.0';
                            }
                        } else {
                            $this->factorioVersion = $version;
                            $this->isExperimental = false;
                        }
                        return;
                    }
                }
            }
            
            // Default to latest major version
            $this->factorioVersion = '2.0';
            $this->isExperimental = false;
        } catch (\Exception $e) {
            $this->factorioVersion = '2.0';
            $this->isExperimental = false;
        }
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'browse') {
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
            \Illuminate\Support\Facades\Log::info('Opening mod details for: ' . $modName);
            
            $service = app(FactorioModPortalService::class);
            $modDetails = $service->getModDetails($modName, true);
            
            \Illuminate\Support\Facades\Log::info('Mod details received: ' . ($modDetails ? 'yes' : 'no'));
            
            if ($modDetails) {
                // Parse dependencies from latest release
                $latestRelease = $modDetails['releases'][0] ?? null;
                if ($latestRelease && isset($latestRelease['info_json']['dependencies'])) {
                    $modDetails['dependencies'] = $this->parseDependencies($latestRelease['info_json']['dependencies']);
                }
                
                $modDetails['latest_release'] = $latestRelease;
                $this->selectedModDetails = $modDetails;
                $this->showDetailsModal = true;
                
                \Illuminate\Support\Facades\Log::info('Modal should be visible now');
                
                // Force modal to open
                $this->dispatch('open-modal', id: 'mod-details-modal');
            } else {
                throw new \Exception('Mod details not found');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in openModDetails: ' . $e->getMessage());
            
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
        
        foreach ($dependencies as $dep) {
            if (is_string($dep)) {
                // Parse dependency string: "? mod-name >= 1.0.0" or "! mod-name"
                $type = 'required';
                $name = $dep;
                $version = null;
                
                if (str_starts_with($dep, '?')) {
                    $type = 'optional';
                    $dep = trim(substr($dep, 1));
                } elseif (str_starts_with($dep, '!')) {
                    $type = 'incompatible';
                    $dep = trim(substr($dep, 1));
                } elseif (str_starts_with($dep, '~')) {
                    $type = 'hidden-optional';
                    $dep = trim(substr($dep, 1));
                }
                
                // Extract name and version
                if (preg_match('/^([^\s>=<]+)\s*([>=<]+.*)?$/', $dep, $matches)) {
                    $name = $matches[1];
                    $version = isset($matches[2]) ? trim($matches[2]) : null;
                }
                
                // Skip base mod
                if ($name !== 'base') {
                    $parsed[] = [
                        'name' => $name,
                        'type' => $type,
                        'version' => $version,
                    ];
                }
            }
        }
        
        return $parsed;
    }

    public function loadInstalledMods(): void
    {
        $this->loading = true;
        
        try {
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);
            
            $allMods = $modListService->getMods($server);
            
            // Filter out default mods (base game + Space Age DLC)
            $defaultMods = ['base', 'elevated-rails', 'quality', 'space-age'];
            $filteredMods = array_filter($allMods, function($mod) use ($defaultMods) {
                return !in_array($mod['name'], $defaultMods);
            });
            
            // Enrich mods with version information from API
            $this->installedMods = [];
            $this->modsWithUpdates = [];
            
            foreach ($filteredMods as $mod) {
                try {
                    $modDetails = $modPortalService->getModDetails($mod['name'], false);
                    if ($modDetails) {
                        $mod['title'] = $modDetails['title'] ?? $mod['name'];
                        $mod['summary'] = $modDetails['summary'] ?? '';
                        
                        // Get releases
                        $releases = $modDetails['releases'] ?? [];
                        if (!empty($releases)) {
                            // Sort releases by date to get the actual latest
                            usort($releases, function($a, $b) {
                                $timeA = strtotime($a['released_at'] ?? '1970-01-01');
                                $timeB = strtotime($b['released_at'] ?? '1970-01-01');
                                return $timeB <=> $timeA; // Descending (newest first)
                            });
                            
                            // Find the latest COMPATIBLE release (not just the latest overall)
                            $latestCompatibleRelease = null;
                            foreach ($releases as $release) {
                                $releaseFactorioVersion = $release['info_json']['factorio_version'] ?? '0.0';
                                if ($this->isVersionCompatible($releaseFactorioVersion)) {
                                    $latestCompatibleRelease = $release;
                                    break;
                                }
                            }
                            
                            // If no compatible version found, use null to indicate no update available
                            $latestRelease = $latestCompatibleRelease;
                            $mod['latest_version'] = $latestRelease['version'] ?? null;
                            
                            // Get current installed version from file name if available
                            // Factorio mod files are named: modname_version.zip
                            $installedVersion = null;
                            try {
                                $modFiles = $modListService->getModFiles($server);
                                $modFileName = $mod['name'] . '_';
                                
                                Log::info("Looking for installed version of {$mod['name']}, checking files: " . implode(', ', $modFiles));
                                
                                foreach ($modFiles as $file) {
                                    if (str_starts_with($file, $modFileName)) {
                                        Log::info("Found matching file: {$file}");
                                        // Extract version from filename: modname_1.2.3.zip
                                        // Support both 1.2.3 and 1.2.3.4 version formats
                                        if (preg_match('/' . preg_quote($mod['name'], '/') . '_(\d+\.\d+\.\d+(?:\.\d+)?)\.zip$/', $file, $matches)) {
                                            $installedVersion = $matches[1];
                                            Log::info("Extracted version {$installedVersion} from {$file}");
                                            break;
                                        } else {
                                            Log::warning("File {$file} matched prefix but regex failed");
                                        }
                                    }
                                }
                                
                                if (!$installedVersion) {
                                    Log::warning("Could not determine installed version for {$mod['name']}, will show as Unknown");
                                }
                            } catch (\Exception $e) {
                                Log::error("Error reading mod files for version check: " . $e->getMessage());
                            }
                            
                            $mod['version'] = $installedVersion ?? 'Unknown';
                            
                            // Check if update available by comparing versions
                            $mod['update_available'] = false;
                            $mod['latest_version_display'] = null;
                            if ($installedVersion && $mod['latest_version'] && version_compare($mod['latest_version'], $installedVersion, '>')) {
                                $mod['update_available'] = $mod['latest_version']; // Store the actual version string
                                $mod['latest_version_display'] = $mod['latest_version'];
                            }
                            
                            if ($mod['update_available']) {
                                $this->modsWithUpdates[] = $mod['name'];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // If we can't get mod details, just use the name
                    $mod['version'] = 'Unknown';
                    $mod['update_available'] = false;
                }
                
                $this->installedMods[] = $mod;
            }
            
            // Initialize mod states
            foreach ($this->installedMods as $mod) {
                $this->modStates[$mod['name']] = $mod['enabled'] ?? true;
            }
        } catch (\Exception $e) {
            // Set empty array for new installations
            $this->installedMods = [];
        } finally {
            $this->loading = false;
        }
    }

    public function loadCacheStats(): void
    {
        $service = new FactorioModPortalService();
        $this->cacheStats = $service->getCacheInfo();
    }

    public function checkForUpdates(): void
    {
        $this->loading = true;
        
        try {
            // Clear cache to force fresh data from Mod Portal
            $modPortalService = app(FactorioModPortalService::class);
            $modPortalService->clearCache();
            
            // Reload mods with fresh data
            $this->loadInstalledMods();
            
            $updateCount = count(array_filter($this->installedMods, fn($m) => !empty($m['update_available'])));
            
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
        if (empty($this->directInstallModName)) {
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

        // Validate mod name first if not already validated
        if ($this->modNameValid === null) {
            $this->validateModName();
        }

        $this->loadingVersions = true;
        
        try {
            $service = app(FactorioModPortalService::class);
            $modDetails = $service->getModDetails($this->directInstallModName, true);
            
            if (!$modDetails || empty($modDetails['releases'])) {
                $this->availableVersions = [];
                $this->selectedVersion = null;
                return;
            }
            
            // Sort releases by date (newest first)
            $releases = $modDetails['releases'];
            usort($releases, function($a, $b) {
                return strtotime($b['released_at'] ?? '1970-01-01') <=> strtotime($a['released_at'] ?? '1970-01-01');
            });
            
            // Build version list with compatibility info
            $this->availableVersions = array_map(function($release) {
                $factorioVersion = $release['info_json']['factorio_version'] ?? 'unknown';
                $isCompatible = $this->isVersionCompatible($factorioVersion);
                
                return [
                    'version' => $release['version'],
                    'factorio_version' => $factorioVersion,
                    'released_at' => $release['released_at'] ?? null,
                    'is_compatible' => $isCompatible,
                ];
            }, $releases);
            
            // Auto-select latest compatible version
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
            // Use selected version or find latest compatible
            $versionToInstall = $this->selectedVersion;
            
            if (!$versionToInstall) {
                $service = new FactorioModPortalService();
                $modDetails = $service->getModDetails($this->directInstallModName, true);
                
                if (!$modDetails || !isset($modDetails['releases'])) {
                    Notification::make()
                        ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.mod_not_found'))
                        ->body('Use the exact mod name from the URL: https://mods.factorio.com/mod/ModName → "ModName"')
                        ->danger()
                        ->send();
                    return;
                }

                // Find the latest compatible release
                $latestRelease = null;
                
                // Sort releases by date to get newest first
                usort($modDetails['releases'], function($a, $b) {
                    $timeA = strtotime($a['released_at'] ?? '1970-01-01');
                    $timeB = strtotime($b['released_at'] ?? '1970-01-01');
                    return $timeB <=> $timeA;
                });
                
                foreach ($modDetails['releases'] as $release) {
                    if ($this->isVersionCompatible($release['info_json']['factorio_version'] ?? '0.0')) {
                        $latestRelease = $release;
                        break;
                    }
                }

                if (!$latestRelease) {
                    Notification::make()
                        ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.no_compatible_version'))
                        ->danger()
                        ->send();
                    return;
                }
                
                $versionToInstall = $latestRelease['version'];
            }

            // Install the mod
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
                // Search with optional category filter
                $result = $service->searchMods(
                    $this->searchQuery,
                    $this->selectedCategory,
                    'downloads_count',
                    'desc',
                    100
                );
            } elseif ($this->selectedCategory) {
                // Get mods by category when no search query
                $result = $service->getModsByCategory($this->selectedCategory, 1, 100);
            } else {
                // Show popular mods by default
                $result = $service->getPopularMods(1, 100);
            }
            
            $allMods = $result['results'] ?? [];
            
            // Filter mods by Factorio version compatibility
            $this->browseMods = array_filter($allMods, function($mod) {
                $latestRelease = $mod['latest_release'] ?? null;
                if (!$latestRelease) {
                    return false;
                }
                
                $factorioVersion = $latestRelease['info_json']['factorio_version'] ?? null;
                if (!$factorioVersion) {
                    return true; // Include if no version specified
                }
                
                // Check if mod is compatible with server version
                return $this->isVersionCompatible($factorioVersion, $this->factorioVersion);
            });
            
            // Re-index array after filtering
            $this->browseMods = array_values($this->browseMods);
            
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

    private function isVersionCompatible(string $requiredVersion, ?string $serverVersion = null): bool
    {
        if ($serverVersion === null) {
            $serverVersion = $this->factorioVersion ?? '2.0';
        }
        
        // For experimental versions, only check if mod supports 2.0+
        if ($this->isExperimental) {
            $required = explode('.', $requiredVersion);
            $requiredMajor = (int)($required[0] ?? 0);
            // Accept any mod that supports 2.0 or higher
            return $requiredMajor >= 2;
        }
        
        // Extract major.minor versions
        $required = explode('.', $requiredVersion);
        $server = explode('.', $serverVersion);
        
        $requiredMajor = (int)($required[0] ?? 0);
        $requiredMinor = (int)($required[1] ?? 0);
        $serverMajor = (int)($server[0] ?? 0);
        $serverMinor = (int)($server[1] ?? 0);
        
        // Compatible if major version matches and server minor >= required minor
        return $serverMajor === $requiredMajor && $serverMinor >= $requiredMinor;
    }

    public function installMod(string $modName, ?string $version = null): void
    {
        try {
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            
            // Load server variables for authentication
            $server->load('variables');
            
            $modListService = app(ModListService::class);
            
            // Show warning for experimental versions
            if ($this->isExperimental) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.experimental_warning'))
                    ->body(__('factorio-mod-installer::factorio-mod-installer.notifications.experimental_warning_body'))
                    ->warning()
                    ->send();
            }
            
            // Install with dependencies
            $installed = [];
            $results = $modListService->installModWithDependencies($server, $modName, true, $version, $installed);
            
            // Build notification message
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
                    ->body(__('factorio-mod-installer::factorio-mod-installer.notifications.failed_mods') . ': ' . implode(', ', $results['failed']))
                    ->warning()
                    ->send();
            }
            
            if ($installedCount === 0 && $failedCount === 0) {
                throw new \Exception('Failed to add mod to mod-list.json. Please ensure the server has been started at least once.');
            }
            
            $this->loadInstalledMods();
            $this->loadBrowseMods();
            
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
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);
            
            if ($modListService->toggleMod($server, $modName)) {
                // Verify the change was written
                $updatedMods = $modListService->getMods($server);
                $newState = null;
                foreach ($updatedMods as $mod) {
                    if ($mod['name'] === $modName) {
                        $newState = $mod['enabled'] ?? false;
                        break;
                    }
                }
                
                $this->loadInstalledMods();
                
                $statusText = $newState ? 'enabled' : 'disabled';
                
                // Get mod details to check compatibility and dependencies
                $issues = [];
                if ($newState) { // Only check when enabling
                    try {
                        $modDetails = $modPortalService->getModDetails($modName, true);
                        if ($modDetails && isset($modDetails['releases'])) {
                            // Find the installed version
                            $modFiles = $modListService->getModFiles($server);
                            $installedVersion = null;
                            foreach ($modFiles as $file) {
                                if (preg_match('/' . preg_quote($modName, '/') . '_(\d+\.\d+\.\d+(?:\.\d+)?)\.zip$/', $file, $matches)) {
                                    $installedVersion = $matches[1];
                                    break;
                                }
                            }
                            
                            // Find the release for installed version
                            if ($installedVersion) {
                                foreach ($modDetails['releases'] as $release) {
                                    if ($release['version'] === $installedVersion) {
                                        // Check Factorio version compatibility
                                        $requiredFactorioVersion = $release['info_json']['factorio_version'] ?? null;
                                        if ($requiredFactorioVersion && $this->factorioVersion) {
                                            if (!$this->isVersionCompatible($requiredFactorioVersion, $this->factorioVersion)) {
                                                $issues[] = "❌ Version incompatible: requires Factorio {$requiredFactorioVersion}, server runs {$this->factorioVersion}";
                                            }
                                        }
                                        
                                        // Check for missing dependencies
                                        $dependencies = $release['info_json']['dependencies'] ?? [];
                                        $installedMods = $modListService->getMods($server);
                                        $installedModNames = array_column($installedMods, 'name');
                                        
                                        $missingDeps = [];
                                        $disabledDeps = [];
                                        
                                        foreach ($dependencies as $depString) {
                                            if (!is_string($depString)) continue;
                                            
                                            $depString = trim($depString);
                                            $isOptional = str_starts_with($depString, '?') || str_starts_with($depString, '~');
                                            $isIncompatible = str_starts_with($depString, '!');
                                            
                                            // Skip optional, incompatible, and base mod
                                            if ($isOptional || $isIncompatible) continue;
                                            
                                            $depString = ltrim($depString, '?!~ ');
                                            if (preg_match('/^([^\s>=<]+)/', $depString, $matches)) {
                                                $depName = $matches[1];
                                                if ($depName === 'base') continue;
                                                
                                                // Check if dependency is installed
                                                if (!in_array($depName, $installedModNames)) {
                                                    $missingDeps[] = $depName;
                                                } else {
                                                    // Check if dependency is enabled
                                                    foreach ($installedMods as $mod) {
                                                        if ($mod['name'] === $depName && !($mod['enabled'] ?? false)) {
                                                            $disabledDeps[] = $depName;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        
                                        if (!empty($missingDeps)) {
                                            $issues[] = "❌ Missing dependencies: " . implode(', ', $missingDeps);
                                        }
                                        
                                        if (!empty($disabledDeps)) {
                                            $issues[] = "⚠️ Disabled dependencies: " . implode(', ', $disabledDeps);
                                        }
                                        
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Could not check mod compatibility: " . $e->getMessage());
                    }
                }
                
                $notif = Notification::make()
                    ->title("Mod {$statusText}");
                
                if (!empty($issues)) {
                    $notif->body(implode("\n", $issues))
                        ->warning()
                        ->persistent();
                } else if ($newState) {
                    $notif->success();
                } else {
                    $notif->success();
                }
                
                $notif->send();
            } else {
                throw new \Exception('Failed to toggle mod');
            }
        } catch (\Exception $e) {
            Log::error("Toggle mod failed: " . $e->getMessage());
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
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            
            // Determine target state: if all are enabled, disable all; otherwise enable all
            $modifiableMods = array_filter($this->installedMods, fn($mod) => $mod['name'] !== 'base');
            $allEnabled = count(array_filter($modifiableMods, fn($mod) => ($this->modStates[$mod['name']] ?? true) === true)) === count($modifiableMods);
            
            $targetState = !$allEnabled;
            $successCount = 0;
            
            foreach ($modifiableMods as $mod) {
                $currentState = $this->modStates[$mod['name']] ?? true;
                
                // Only toggle if the current state differs from target state
                if ($currentState !== $targetState) {
                    if ($modListService->toggleMod($server, $mod['name'])) {
                        $successCount++;
                    }
                }
            }
            
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
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            
            if ($modListService->addMod($server, $this->newModName, true)) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.messages.mod_added'))
                    ->success()
                    ->send();
                
                $this->closeAddModal();
                $this->loadInstalledMods();
                $this->loadBrowseMods();
            } else {
                throw new \Exception('Failed to add mod to mod-list.json');
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
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            $modPortalService = app(FactorioModPortalService::class);
            
            // Get the latest version
            $modDetails = $modPortalService->getModDetails($modName, false);
            if (!$modDetails || empty($modDetails['releases'])) {
                throw new \Exception('Could not find mod details');
            }
            
            // Sort releases by date to get newest first
            $releases = $modDetails['releases'];
            usort($releases, function($a, $b) {
                $timeA = strtotime($a['released_at'] ?? '1970-01-01');
                $timeB = strtotime($b['released_at'] ?? '1970-01-01');
                return $timeB <=> $timeA;
            });
            
            // Find the latest compatible release
            $latestRelease = null;
            foreach ($releases as $release) {
                $factorioVersion = $release['info_json']['factorio_version'] ?? null;
                if ($factorioVersion && $this->isVersionCompatible($factorioVersion, $this->factorioVersion)) {
                    $latestRelease = $release;
                    break;
                }
                // If no version specified, accept it
                if (!$factorioVersion) {
                    $latestRelease = $release;
                    break;
                }
            }
            
            if (!$latestRelease) {
                throw new \Exception('No compatible version found for Factorio ' . ($this->factorioVersion ?? 'unknown'));
            }
            
            $latestVersion = $latestRelease['version'] ?? null;
            
            if (!$latestVersion) {
                throw new \Exception('Could not determine latest version');
            }
            
            // Remove old version files BEFORE installing new version
            $oldFilesRemoved = false;
            try {
                $modFiles = $modListService->getModFiles($server);
                $modFilePrefix = $modName . '_';
                
                Log::info("Checking for old versions of {$modName}, found files: " . implode(', ', $modFiles));
                
                foreach ($modFiles as $file) {
                    if (str_starts_with($file, $modFilePrefix)) {
                        Log::info("Removing old version file: {$file}");
                        if ($modListService->removeModFile($server, $file)) {
                            Log::info("Successfully removed {$file}");
                            $oldFilesRemoved = true;
                        } else {
                            Log::error("Failed to remove {$file}");
                            throw new \Exception("Could not delete old mod file: {$file}. Please ensure the server is stopped.");
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error removing old mod files: " . $e->getMessage());
                throw $e; // Don't continue if we can't delete old files
            }
            
            // Install the latest version
            if ($modListService->addMod($server, $modName, true, $latestVersion)) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.updated'))
                    ->body("Updated to version {$latestVersion}")
                    ->success()
                    ->send();
                    
                $this->loadInstalledMods();
                $this->loadBrowseMods();
            } else {
                throw new \Exception('Failed to install updated mod');
            }
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
            /** @var \App\Models\Server $server */
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
            
            foreach ($this->modsWithUpdates as $modName) {
                try {
                    // Get the latest version
                    $modDetails = $modPortalService->getModDetails($modName, false);
                    if (!$modDetails || empty($modDetails['releases'])) {
                        throw new \Exception('Could not find mod details');
                    }
                    
                    // Sort releases by date to get newest first
                    $releases = $modDetails['releases'];
                    usort($releases, function($a, $b) {
                        $timeA = strtotime($a['released_at'] ?? '1970-01-01');
                        $timeB = strtotime($b['released_at'] ?? '1970-01-01');
                        return $timeB <=> $timeA;
                    });
                    
                    // Find the latest compatible release
                    $latestRelease = null;
                    foreach ($releases as $release) {
                        $factorioVersion = $release['info_json']['factorio_version'] ?? null;
                        if ($factorioVersion && $this->isVersionCompatible($factorioVersion, $this->factorioVersion)) {
                            $latestRelease = $release;
                            break;
                        }
                        if (!$factorioVersion) {
                            $latestRelease = $release;
                            break;
                        }
                    }
                    
                    if (!$latestRelease) {
                        throw new \Exception('No compatible version found');
                    }
                    
                    $latestVersion = $latestRelease['version'] ?? null;
                    if (!$latestVersion) {
                        throw new \Exception('Could not determine latest version');
                    }
                    
                    // Remove old version files
                    $modFiles = $modListService->getModFiles($server);
                    $modFilePrefix = $modName . '_';
                    
                    foreach ($modFiles as $file) {
                        if (str_starts_with($file, $modFilePrefix)) {
                            if (!$modListService->removeModFile($server, $file)) {
                                throw new \Exception("Could not delete old file: {$file}");
                            }
                        }
                    }
                    
                    // Install the latest version
                    if ($modListService->addMod($server, $modName, true, $latestVersion)) {
                        $updatedCount++;
                        Log::info("Successfully updated {$modName} to version {$latestVersion}");
                    } else {
                        throw new \Exception('Failed to install updated mod');
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to update {$modName}: " . $e->getMessage());
                    $failedMods[] = $modName;
                    // Continue with other mods
                }
            }
            
            $message = "Updated {$updatedCount} mod(s)";
            if (!empty($failedMods)) {
                $message .= " (Failed: " . implode(', ', $failedMods) . ")";
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
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            $modListService = app(ModListService::class);
            
            if ($modListService->removeMod($server, $modName)) {
                Notification::make()
                    ->title('Mod removed successfully')
                    ->success()
                    ->send();
                    
                $this->loadInstalledMods();
                $this->loadBrowseMods();
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
        /** @var Server|null $server */
        $server = Filament::getTenant();
        
        if (!$server) {
            return false;
        }

        // Check if the server has the factorio tag or feature
        $egg = $server->egg;
        
        if (!$egg) {
            return false;
        }

        // Check for factorio tag in name
        if ($egg->name && str_contains(strtolower($egg->name), 'factorio')) {
            return true;
        }

        // Check for factorio tag (can be string or array)
        if ($egg->tags) {
            if (is_array($egg->tags)) {
                foreach ($egg->tags as $tag) {
                    if (str_contains(strtolower($tag), 'factorio')) {
                        return true;
                    }
                }
            } elseif (is_string($egg->tags)) {
                if (str_contains(strtolower($egg->tags), 'factorio')) {
                    return true;
                }
            }
        }

        // Check for factorio_mod_installer feature
        if ($egg->features && is_array($egg->features)) {
            if (in_array('factorio_mod_installer', $egg->features)) {
                return true;
            }
        }

        return false;
    }
}
