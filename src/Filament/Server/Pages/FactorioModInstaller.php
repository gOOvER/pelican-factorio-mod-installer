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
use Livewire\Attributes\Computed;

class FactorioModInstaller extends Page
{
    use BlockAccessInConflict;

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

    public function mount(): void
    {
        $this->loadInstalledMods();
        $this->loadFactorioVersion();
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
                        
                        // Get installed version from releases
                        $releases = $modDetails['releases'] ?? [];
                        if (!empty($releases)) {
                            $latestRelease = $releases[0];
                            $mod['latest_version'] = $latestRelease['version'] ?? null;
                            
                            // Try to find current installed version from file
                            // For now, assume latest compatible version
                            $mod['version'] = $mod['latest_version'] ?? 'Unknown';
                            
                            // Check if update available (compare with all releases)
                            $mod['update_available'] = false;
                            if ($mod['latest_version']) {
                                // Simple check: if there are newer releases, mark as update available
                                // This is a simplified version - in reality you'd parse file names
                                $mod['update_available'] = count($releases) > 1;
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

    public function loadBrowseMods(): void
    {
        $this->loading = true;
        
        try {
            $service = app(FactorioModPortalService::class);
            
            if ($this->searchQuery) {
                $result = $service->searchMods($this->searchQuery, 1, 100);
            } else {
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

    private function isVersionCompatible(string $requiredVersion, string $serverVersion): bool
    {
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

    public function installMod(string $modName): void
    {
        try {
            /** @var \App\Models\Server $server */
            $server = Filament::getTenant();
            
            // Load server variables for authentication
            $server->load('variables');
            
            $modListService = app(ModListService::class);
            
            if ($modListService->addMod($server, $modName, true)) {
                Notification::make()
                    ->title('Mod added successfully')
                    ->success()
                    ->send();
                    
                $this->loadInstalledMods();
                $this->loadBrowseMods();
            } else {
                throw new \Exception('Failed to add mod to mod-list.json. Please ensure the server has been started at least once.');
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error installing mod')
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
            
            // Remove old version and install new one
            if ($modListService->removeMod($server, $modName)) {
                if ($modListService->addMod($server, $modName, true)) {
                    Notification::make()
                        ->title('Mod updated successfully')
                        ->success()
                        ->send();
                        
                    $this->loadInstalledMods();
                } else {
                    throw new \Exception('Failed to install updated mod');
                }
            } else {
                throw new \Exception('Failed to remove old mod version');
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error updating mod')
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
            
            $updatedCount = 0;
            foreach ($this->modsWithUpdates as $modName) {
                try {
                    if ($modListService->removeMod($server, $modName)) {
                        if ($modListService->addMod($server, $modName, true)) {
                            $updatedCount++;
                        }
                    }
                } catch (\Exception $e) {
                    // Continue with other mods
                }
            }
            
            Notification::make()
                ->title("Updated {$updatedCount} mod(s)")
                ->success()
                ->send();
                
            $this->loadInstalledMods();
        } catch (\Exception $e) {
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

        return parent::canAccess();
    }
}
