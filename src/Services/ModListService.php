<?php

namespace gOOvER\FactorioModInstaller\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Log;

class ModListService
{
    private const MOD_LIST_FILE = 'mod-list.json';
    private const MODS_DIR = 'mods';

    public function __construct(
        private DaemonFileRepository $fileRepository,
        private FactorioModPortalService $modPortalService
    ) {
    }

    /**
     * Get the server's mod directory path
     */
    private function getModsPath(): string
    {
        return 'mods';
    }

    /**
     * Get the mod-list.json file path
     */
    private function getModListPath(): string
    {
        return 'mods/mod-list.json';
    }

    /**
     * Read the mod-list.json file
     */
    public function readModList(Server $server): array
    {
        $path = $this->getModListPath();

        try {
            $this->fileRepository->setServer($server);
            
            // Try to read the file
            $content = $this->fileRepository->getContent($path);
            $data = json_decode($content, true);
            
            if ($data && isset($data['mods'])) {
                return $data;
            }
            
            // File is empty or invalid, return default
            return [
                'mods' => [
                    [
                        'name' => 'base',
                        'enabled' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            // File doesn't exist or can't be read, return default
            Log::debug('mod-list.json not found or unreadable, returning default: ' . $e->getMessage());
            return [
                'mods' => [
                    [
                        'name' => 'base',
                        'enabled' => true,
                    ],
                ],
            ];
        }
    }

    /**
     * Get list of mod files in the mods directory
     *
     * @param Server $server
     * @return array
     */
    public function getModFiles(Server $server): array
    {
        try {
            $this->fileRepository->setServer($server);
            $files = $this->fileRepository->getDirectory('mods');
            
            // The getDirectory returns an array of file objects with 'name' property
            $zipFiles = [];
            foreach ($files as $file) {
                if (is_array($file) && isset($file['name']) && str_ends_with($file['name'], '.zip')) {
                    $zipFiles[] = $file['name'];
                } elseif (is_string($file) && str_ends_with($file, '.zip')) {
                    $zipFiles[] = $file;
                }
            }
            
            Log::info("Found mod files: " . implode(', ', $zipFiles));
            return $zipFiles;
        } catch (\Exception $e) {
            Log::warning("Could not list mod files: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Write to the mod-list.json file
     */
    public function writeModList(Server $server, array $data): bool
    {
        $path = $this->getModListPath();

        try {
            $this->fileRepository->setServer($server);
            
            // Ensure mods directory exists
            try {
                $this->fileRepository->createDirectory('mods', '/');
            } catch (\Exception $e) {
                // Directory might already exist, that's okay
                Log::debug('mods directory might already exist: ' . $e->getMessage());
            }
            
            // Write the file
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            Log::info("Writing mod-list.json with " . count($data['mods'] ?? []) . " mods. Content: " . substr($content, 0, 500));
            
            $this->fileRepository->putContent($path, $content);
            
            Log::info("Successfully wrote mod-list.json to {$path}");
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to write mod-list.json via Wings: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Remove a mod file from the mods directory
     *
     * @param Server $server
     * @param string $fileName
     * @return bool
     */
    public function removeModFile(Server $server, string $fileName): bool
    {
        try {
            $this->fileRepository->setServer($server);
            $this->fileRepository->deleteFiles('mods', [$fileName]);
            Log::info("Deleted mod file: {$fileName}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to delete mod file {$fileName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get mod info.json from a mod ZIP file
     *
     * @param Server $server
     * @param string $modName
     * @return array|null Returns mod info or null if not found
     */
    public function getModInfo(Server $server, string $modName): ?array
    {
        try {
            $modFiles = $this->getModFiles($server);
            $modPrefix = $modName . '_';
            
            // Find the mod file
            $modFile = null;
            foreach ($modFiles as $file) {
                if (str_starts_with($file, $modPrefix)) {
                    $modFile = $file;
                    break;
                }
            }
            
            if (!$modFile) {
                Log::warning("No mod file found for {$modName}");
                return null;
            }
            
            // Try to read info.json from the ZIP
            // Note: We can't easily read ZIP contents via Wings API, so we'll try to fetch from portal
            // This is a limitation - in a real implementation, we'd download and inspect the ZIP
            
            // Fallback: Get version from filename
            if (preg_match('/' . preg_quote($modName, '/') . '_(\d+\.\d+\.\d+(?:\.\d+)?)\.zip$/', $modFile, $matches)) {
                return [
                    'name' => $modName,
                    'version' => $matches[1],
                    'title' => $modName,
                    'factorio_version' => null, // Unknown without reading ZIP
                ];
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("Error getting mod info for {$modName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Install mod with dependencies recursively
     */
    public function installModWithDependencies(Server $server, string $modName, bool $enabled = true, ?string $version = null, array &$installed = []): array
    {
        $results = ['installed' => [], 'skipped' => [], 'failed' => []];
        
        // Prevent circular dependencies
        if (in_array($modName, $installed)) {
            $results['skipped'][] = $modName;
            return $results;
        }
        
        try {
            // Get mod details to check dependencies
            $modDetails = $this->modPortalService->getModDetails($modName, true);
            if (!$modDetails || empty($modDetails['releases'])) {
                $results['failed'][] = $modName;
                return $results;
            }
            
            // Get the release (specified version or latest)
            $release = null;
            if ($version) {
                foreach ($modDetails['releases'] as $r) {
                    if ($r['version'] === $version) {
                        $release = $r;
                        break;
                    }
                }
            } else {
                // Sort by date and get newest
                $releases = $modDetails['releases'];
                usort($releases, function($a, $b) {
                    return strtotime($b['released_at'] ?? '1970-01-01') <=> strtotime($a['released_at'] ?? '1970-01-01');
                });
                $release = $releases[0] ?? null;
            }
            
            if (!$release) {
                $results['failed'][] = $modName;
                return $results;
            }
            
            // Parse and install dependencies first
            $dependencies = $release['info_json']['dependencies'] ?? [];
            foreach ($dependencies as $depString) {
                if (!is_string($depString)) continue;
                
                // Parse dependency: "? mod-name >= 1.0.0" or "! mod-name"
                $depString = trim($depString);
                $isOptional = str_starts_with($depString, '?') || str_starts_with($depString, '~');
                $isIncompatible = str_starts_with($depString, '!');
                
                // Skip optional and incompatible dependencies
                if ($isOptional || $isIncompatible) {
                    continue;
                }
                
                // Remove prefix and extract name
                $depString = ltrim($depString, '?!~ ');
                if (preg_match('/^([^\s>=<]+)/', $depString, $matches)) {
                    $depName = $matches[1];
                    
                    // Skip base mod
                    if ($depName === 'base') continue;
                    
                    // Check if already installed
                    $modList = $this->readModList($server);
                    $alreadyInstalled = false;
                    foreach ($modList['mods'] as $mod) {
                        if ($mod['name'] === $depName) {
                            $alreadyInstalled = true;
                            break;
                        }
                    }
                    
                    if (!$alreadyInstalled) {
                        Log::info("Installing dependency: {$depName} for {$modName}");
                        $depResults = $this->installModWithDependencies($server, $depName, true, null, $installed);
                        
                        // Merge results
                        $results['installed'] = array_merge($results['installed'], $depResults['installed']);
                        $results['skipped'] = array_merge($results['skipped'], $depResults['skipped']);
                        $results['failed'] = array_merge($results['failed'], $depResults['failed']);
                    }
                }
            }
            
            // Now install the mod itself
            if ($this->addMod($server, $modName, $enabled, $version)) {
                $results['installed'][] = $modName;
                $installed[] = $modName;
            } else {
                $results['failed'][] = $modName;
            }
            
        } catch (\Exception $e) {
            Log::error("Error installing {$modName} with dependencies: " . $e->getMessage());
            $results['failed'][] = $modName;
        }
        
        return $results;
    }

    /**
     * Add or update a mod in the mod list and download the mod file
     */
    public function addMod(Server $server, string $modName, bool $enabled = true, ?string $version = null): bool
    {
        try {
            // First, get the mod details and download the mod file
            if ($version) {
                // Get specific version
                $release = $this->modPortalService->getModRelease($modName, $version);
            } else {
                // Get latest release
                $release = $this->modPortalService->getLatestRelease($modName);
            }
            
            if (!$release) {
                Log::error("Could not find release info for mod: {$modName}" . ($version ? " version {$version}" : ""));
                return false;
            }
            
            $downloadUrl = $release['download_url'] ?? null;
            $fileName = $release['file_name'] ?? null;
            
            if (!$downloadUrl || !$fileName) {
                Log::error("Missing download URL or filename for mod: {$modName}");
                return false;
            }
            
            // Get Factorio credentials from server-settings.json
            $username = null;
            $token = null;
            
            try {
                $settingsContent = $this->fileRepository->setServer($server)->getContent('/data/server-settings.json');
                $settings = json_decode($settingsContent, true);
                
                if ($settings) {
                    $username = $settings['username'] ?? null;
                    $token = $settings['token'] ?? null;
                    
                    if ($username && $token) {
                        Log::info("Found credentials in server-settings.json: username={$username}");
                    } else {
                        Log::warning("server-settings.json found but username or token is empty");
                    }
                } else {
                    Log::warning("Could not parse server-settings.json");
                }
            } catch (\Exception $e) {
                Log::warning("Could not read server-settings.json: " . $e->getMessage());
            }
            
            // Build full download URL with authentication if available
            $fullDownloadUrl = 'https://mods.factorio.com' . $downloadUrl;
            if ($username && $token) {
                $fullDownloadUrl .= '?username=' . urlencode($username) . '&token=' . urlencode($token);
                Log::info("Downloading mod {$modName} ({$fileName}) with authentication");
            } else {
                Log::warning("Downloading mod {$modName} ({$fileName}) without authentication - credentials not found in server-settings.json");
            }
            
            // Download the mod file with Guzzle (because Wings pull() can't follow Factorio redirects)
            try {
                Log::info("Downloading from: {$fullDownloadUrl}");
                
                // Use Guzzle to download the file
                $client = new \GuzzleHttp\Client(['timeout' => 120]);
                $response = $client->get($fullDownloadUrl);
                
                if ($response->getStatusCode() !== 200) {
                    throw new \Exception("Failed to download mod, HTTP status: " . $response->getStatusCode());
                }
                
                $modContent = $response->getBody()->getContents();
                Log::info("Downloaded " . strlen($modContent) . " bytes");
                
                // Upload to server using Wings putContent
                $this->fileRepository->setServer($server)->putContent("mods/{$fileName}", $modContent);
                Log::info("Successfully uploaded mod file {$fileName} to mods/ directory");
                
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // 403 Forbidden - authentication required
                if ($e->getResponse()->getStatusCode() === 403) {
                    Log::warning("Mod download requires authentication. Adding to mod-list.json only - Factorio will download on next start.");
                    // Continue to add mod to mod-list.json
                } else {
                    throw $e;
                }
            } catch (\Exception $e) {
                Log::error("Failed to download/upload mod {$modName}: " . $e->getMessage());
                Log::warning("Adding mod to mod-list.json anyway - Factorio will attempt download on next start");
                // Continue to add mod to mod-list.json even if download fails
            }
            
            // Now add/update the mod in mod-list.json
            $modList = $this->readModList($server);
            
            // Check if mod already exists
            $modExists = false;
            foreach ($modList['mods'] as &$mod) {
                if ($mod['name'] === $modName) {
                    $mod['enabled'] = $enabled;
                    $modExists = true;
                    break;
                }
            }
            
            // Add new mod if it doesn't exist
            if (!$modExists) {
                $modList['mods'][] = [
                    'name' => $modName,
                    'enabled' => $enabled,
                ];
            }
            
            return $this->writeModList($server, $modList);
        } catch (\Exception $e) {
            Log::error("Error adding mod {$modName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a mod from the mod list and delete the mod file
     */
    public function removeMod(Server $server, string $modName): bool
    {
        try {
            Log::info("Attempting to remove mod: {$modName}");
            
            // Remove from mod-list.json first
            $modList = $this->readModList($server);
            $originalCount = count($modList['mods']);
            
            // Filter out the mod
            $modList['mods'] = array_values(array_filter($modList['mods'], function($mod) use ($modName) {
                return $mod['name'] !== $modName;
            }));
            
            $newCount = count($modList['mods']);
            Log::info("Mod list count: {$originalCount} -> {$newCount}");
            
            if ($originalCount === $newCount) {
                Log::warning("Mod {$modName} not found in mod-list.json");
                return false;
            }
            
            // Write updated mod list
            if (!$this->writeModList($server, $modList)) {
                Log::error("Failed to write mod-list.json after removing {$modName}");
                return false;
            }
            
            Log::info("Successfully removed {$modName} from mod-list.json");
            
            // Try to delete all mod files for this mod (in case multiple versions exist)
            try {
                $modFiles = $this->getModFiles($server);
                $modFilePrefix = $modName . '_';
                $deletedFiles = [];
                
                foreach ($modFiles as $file) {
                    if (str_starts_with($file, $modFilePrefix)) {
                        try {
                            $this->fileRepository->setServer($server)->deleteFiles('mods', [$file]);
                            $deletedFiles[] = $file;
                            Log::info("Deleted mod file: {$file}");
                        } catch (\Exception $e) {
                            Log::warning("Could not delete mod file {$file}: " . $e->getMessage());
                        }
                    }
                }
                
                if (empty($deletedFiles)) {
                    Log::info("No mod files found to delete for {$modName} (mod might have been added to list only)");
                }
            } catch (\Exception $e) {
                Log::warning("Error during mod file deletion: " . $e->getMessage());
                // This is OK - mod might have been added without download
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Error removing mod {$modName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle a mod's enabled state
     */
    public function toggleMod(Server $server, string $modName): bool
    {
        $modList = $this->readModList($server);
        
        Log::info("toggleMod called for '{$modName}', current mod-list.json has " . count($modList['mods']) . " mods");
        
        // Find the mod in the list
        $found = false;
        $newState = null;
        foreach ($modList['mods'] as &$mod) {
            if ($mod['name'] === $modName) {
                $oldState = $mod['enabled'] ?? true;
                $mod['enabled'] = !$oldState;
                $newState = $mod['enabled'];
                $found = true;
                Log::info("Found mod '{$modName}' in mod-list.json, toggling from " . ($oldState ? 'enabled' : 'disabled') . " to " . ($newState ? 'enabled' : 'disabled'));
                break;
            }
        }
        unset($mod); // Break the reference
        
        // If mod not found in mod-list.json but exists as a file, add it
        if (!$found) {
            $modFiles = $this->getModFiles($server);
            $modPrefix = $modName . '_';
            
            Log::info("Mod '{$modName}' not found in mod-list.json, checking " . count($modFiles) . " mod files");
            
            // Check if the mod file exists
            $modFileExists = false;
            foreach ($modFiles as $file) {
                if (str_starts_with($file, $modPrefix)) {
                    $modFileExists = true;
                    Log::info("Found mod file: {$file}");
                    break;
                }
            }
            
            // If file exists, add it to mod-list.json with enabled state
            if ($modFileExists) {
                $modList['mods'][] = [
                    'name' => $modName,
                    'enabled' => true, // Set to enabled since user wants to toggle it
                ];
                $newState = true;
                Log::info("Added missing mod '{$modName}' to mod-list.json with enabled=true");
            } else {
                Log::warning("Mod '{$modName}' not found in mod-list.json and no mod file exists");
                return false;
            }
        }

        $writeResult = $this->writeModList($server, $modList);
        Log::info("writeModList returned: " . ($writeResult ? 'true' : 'false') . " for mod '{$modName}' with state " . ($newState ? 'enabled' : 'disabled'));
        
        return $writeResult;
    }

    /**
     * Get all mods from the mod list
     * Merges mods from mod-list.json with physically installed mod files
     * Automatically syncs missing mods to mod-list.json
     */
    public function getMods(Server $server): array
    {
        $modList = $this->readModList($server);
        $modsFromList = $modList['mods'] ?? [];
        
        // Get physically installed mod files
        $modFiles = $this->getModFiles($server);
        
        // Create a map of mods from mod-list.json
        $modMap = [];
        foreach ($modsFromList as $mod) {
            $modMap[$mod['name']] = $mod;
        }
        
        // Track if we need to update mod-list.json
        $needsUpdate = false;
        
        // Parse mod files and add missing mods
        foreach ($modFiles as $file) {
            // Extract mod name from filename: modname_version.zip
            if (preg_match('/^(.+?)_\d+\.\d+\.\d+(?:\.\d+)?\.zip$/', $file, $matches)) {
                $modName = $matches[1];
                
                // Skip default mods
                $defaultMods = ['base', 'elevated-rails', 'quality', 'space-age'];
                if (in_array($modName, $defaultMods)) {
                    continue;
                }
                
                // If mod not in mod-list.json, add it with default state (disabled)
                if (!isset($modMap[$modName])) {
                    $modMap[$modName] = [
                        'name' => $modName,
                        'enabled' => false, // Default to disabled for safety
                    ];
                    $needsUpdate = true;
                    Log::info("Found installed mod '{$modName}' not in mod-list.json, adding with enabled=false");
                }
            }
        }
        
        // If we found new mods, update mod-list.json to keep it in sync
        if ($needsUpdate) {
            $modList['mods'] = array_values($modMap);
            if ($this->writeModList($server, $modList)) {
                Log::info("Successfully synced " . count($modMap) . " mods to mod-list.json");
            } else {
                Log::error("Failed to sync mods to mod-list.json");
            }
        }
        
        return array_values($modMap);
    }
}
