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
            $this->fileRepository->putContent($path, $content);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to write mod-list.json via Wings: ' . $e->getMessage());
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
        
        foreach ($modList['mods'] as &$mod) {
            if ($mod['name'] === $modName) {
                $mod['enabled'] = !($mod['enabled'] ?? true);
                break;
            }
        }

        return $this->writeModList($server, $modList);
    }

    /**
     * Get all mods from the mod list
     */
    public function getMods(Server $server): array
    {
        $modList = $this->readModList($server);
        return $modList['mods'] ?? [];
    }
}
