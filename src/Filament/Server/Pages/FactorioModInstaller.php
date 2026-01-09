<?php

namespace gOOvER\FactorioModInstaller\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use gOOvER\FactorioModInstaller\Services\FactorioModPortalService;
use gOOvER\FactorioModInstaller\Services\ModListService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;

class FactorioModInstaller extends Page
{
    use BlockAccessInConflict;

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

    public function mount(): void
    {
        $this->loadInstalledMods();
        $this->loadFactorioVersion();
        $this->loadBrowseMods();
        $this->loadCacheStats();
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
                        $this->factorioVersion = $version;
                        return;
                    }
                }
            }
            
            // Default to latest major version
            $this->factorioVersion = '2.0';
        } catch (\Exception $e) {
            $this->factorioVersion = '2.0';
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
                            
                            $latestRelease = $releases[0];
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
                            if ($installedVersion && $mod['latest_version'] && version_compare($mod['latest_version'], $installedVersion, '>')) {
                                $mod['update_available'] = true;
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
        $this->cacheStats = $service->getCacheStats();
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

            // Install the mod
            $this->installMod($this->directInstallModName, $latestRelease['version']);
            $this->directInstallModName = '';
            
            Notification::make()
                ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.installed'))
                ->success()
                ->send();
            
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
                // When searching, use searchMods which will search all pages for queries with 3+ chars
                $result = $service->searchMods($this->searchQuery);
            } else {
                // For popular mods, show 100
                $result = $service->getPopularMods(1, 100);
            }
            
            $allMods = $result['results'] ?? [];
            
            // Filter by category if selected
            if ($this->selectedCategory) {
                $allMods = array_filter($allMods, function($mod) {
                    return ($mod['category'] ?? null) === $this->selectedCategory;
                });
            }
            
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
            
            if ($modListService->addMod($server, $modName, true, $version)) {
                Notification::make()
                    ->title(__('factorio-mod-installer::factorio-mod-installer.notifications.installed'))
                    ->success()
                    ->send();
                    
                $this->loadInstalledMods();
                $this->loadBrowseMods();
            } else {
                throw new \Exception('Failed to add mod to mod-list.json. Please ensure the server has been started at least once.');
            }
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
            
            if ($modListService->toggleMod($server, $modName)) {
                $this->loadInstalledMods();
                Notification::make()
                    ->title('Mod status updated')
                    ->success()
                    ->send();
            } else {
                throw new \Exception('Failed to toggle mod');
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error toggling mod')
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
