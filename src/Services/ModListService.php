<?php

namespace gOOvER\FactorioModInstaller\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ModListService
{
    private const MOD_LIST_FILE = 'mod-list.json';
    private const MODS_DIR = 'mods';
    private const DOWNLOAD_TIMEOUT = 120;

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
        return trim((string) config('factorio-mod-installer.mods_directory', self::MODS_DIR), '/') ?: self::MODS_DIR;
    }

    /**
     * Get the mod-list.json file path
     */
    private function getModListPath(): string
    {
        $file = ltrim((string) config('factorio-mod-installer.mod_list_file', self::MOD_LIST_FILE), '/') ?: self::MOD_LIST_FILE;
        return $this->getModsPath() . '/' . $file;
    }

    /**
     * Validate a filename to prevent path traversal attacks
     *
     * @throws \InvalidArgumentException
     */
    public function validateFilename(string $filename): string
    {
        if (
            $filename === ''
            || $filename === '.'
            || str_contains($filename, "\0")
            || str_contains($filename, '..')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || !str_ends_with(strtolower($filename), '.zip')
        ) {
            throw new \InvalidArgumentException('Invalid mod filename detected.');
        }

        return basename($filename);
    }

    /**
     * Read the mod-list.json file
     */
    public function readModList(Server $server): array
    {
        $path = $this->getModListPath();

        try {
            $this->fileRepository->setServer($server);

            $content = $this->fileRepository->getContent($path);
            $data = json_decode($content, true);

            if (is_array($data) && isset($data['mods']) && is_array($data['mods'])) {
                return $data;
            }

            return $this->defaultModList();
        } catch (\Exception $e) {
            Log::debug('mod-list.json not found or unreadable, returning default: ' . $e->getMessage());
            return $this->defaultModList();
        }
    }

    private function defaultModList(): array
    {
        return [
            'mods' => [
                [
                    'name' => 'base',
                    'enabled' => true,
                ],
            ],
        ];
    }

    /**
     * Get list of mod files in the mods directory
     */
    public function getModFiles(Server $server): array
    {
        try {
            $this->fileRepository->setServer($server);
            $files = $this->fileRepository->getDirectory($this->getModsPath());

            if (!is_array($files) || isset($files['error'])) {
                return [];
            }

            $zipFiles = [];
            foreach ($files as $file) {
                if (is_array($file) && isset($file['name']) && is_string($file['name']) && str_ends_with($file['name'], '.zip')) {
                    $zipFiles[] = $file['name'];
                } elseif (is_string($file) && str_ends_with($file, '.zip')) {
                    $zipFiles[] = $file;
                }
            }

            return $zipFiles;
        } catch (\Exception $e) {
            Log::debug('Could not list mod files: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Write to the mod-list.json file
     */
    public function writeModList(Server $server, array $data): bool
    {
        $path = $this->getModListPath();
        $modsDir = $this->getModsPath();

        try {
            $this->fileRepository->setServer($server);

            try {
                $this->fileRepository->createDirectory($modsDir, '/');
            } catch (\Exception $e) {
                Log::debug('mods directory might already exist: ' . $e->getMessage());
            }

            $content = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $response = $this->fileRepository->putContent($path, $content);

            if (is_object($response) && method_exists($response, 'failed') && $response->failed()) {
                Log::error('Wings returned failure status when writing mod-list.json');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to write mod-list.json via Wings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a mod file from the mods directory
     */
    public function removeModFile(Server $server, string $fileName): bool
    {
        try {
            $safeFileName = $this->validateFilename($fileName);
            $this->fileRepository->setServer($server);
            $this->fileRepository->deleteFiles($this->getModsPath(), [$safeFileName]);
            Log::info("Deleted mod file: {$safeFileName}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to delete mod file {$fileName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract installed version for a mod from a list of mod ZIP filenames
     */
    public function extractInstalledVersionFromFiles(string $modName, array $modFiles): ?string
    {
        $modPrefix = $modName . '_';
        foreach ($modFiles as $file) {
            if (str_starts_with($file, $modPrefix)) {
                if (preg_match('/^' . preg_quote($modName, '/') . '_(\d+\.\d+\.\d+(?:\.\d+)?)\.zip$/', $file, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    /**
     * Get mod info from a mod ZIP filename
     */
    public function getModInfo(Server $server, string $modName, ?array $modFiles = null): ?array
    {
        try {
            $modFiles ??= $this->getModFiles($server);
            $version = $this->extractInstalledVersionFromFiles($modName, $modFiles);

            if ($version !== null) {
                return [
                    'name' => $modName,
                    'version' => $version,
                    'title' => $modName,
                    'factorio_version' => null,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error getting mod info for {$modName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Install mod with dependencies recursively according to the Factorio 2.0/2.1 dependency specification
     */
    public function installModWithDependencies(
        Server $server,
        string $modName,
        bool $enabled = true,
        ?string $version = null,
        array &$installed = [],
        ?string $factorioVersion = null
    ): array {
        $results = ['installed' => [], 'skipped' => [], 'failed' => []];

        // Skip built-in Factorio / Space Age expansion mods
        if (in_array($modName, FactorioModPortalService::BUILTIN_MODS, true)) {
            $results['skipped'][] = $modName;
            return $results;
        }

        // Prevent circular dependencies
        if (in_array($modName, $installed, true)) {
            $results['skipped'][] = $modName;
            return $results;
        }

        // Mark as visited immediately to avoid infinite recursion on cyclic dependency graphs
        $installed[] = $modName;

        try {
            $modDetails = $this->modPortalService->getModDetails($modName, true);
            if (!$modDetails || empty($modDetails['releases']) || !is_array($modDetails['releases'])) {
                $results['failed'][] = $modName;
                return $results;
            }

            $releases = $this->modPortalService->sortReleasesDesc($modDetails['releases']);

            // Select the requested version, or the latest release compatible with the server's Factorio version
            $release = null;
            if ($version) {
                foreach ($releases as $r) {
                    if (($r['version'] ?? null) === $version) {
                        $release = $r;
                        break;
                    }
                }
            } elseif ($factorioVersion) {
                foreach ($releases as $r) {
                    $relFactorioVer = $r['info_json']['factorio_version'] ?? null;
                    if ($this->modPortalService->isVersionCompatible($relFactorioVer, $factorioVersion)) {
                        $release = $r;
                        break;
                    }
                }
            } else {
                $release = $releases[0] ?? null;
            }

            if (!$release) {
                $results['failed'][] = $modName;
                return $results;
            }

            $selectedVersion = $release['version'] ?? $version;
            $effectiveFactorioVersion = $factorioVersion
                ?? ($release['info_json']['factorio_version'] ?? null);

            // Read current mod list once to check already installed dependencies
            $modList = $this->readModList($server);
            $installedModNames = array_column($modList['mods'] ?? [], 'name');

            // Parse and install required dependencies first
            $dependencies = $release['info_json']['dependencies'] ?? [];
            if (is_array($dependencies)) {
                foreach ($dependencies as $depString) {
                    if (!is_string($depString)) {
                        continue;
                    }

                    $parsed = $this->modPortalService->parseDependencyString($depString);
                    if (!$parsed || !$parsed['is_required']) {
                        // Skip optional (`?`), hidden-optional (`(?)`), recommended (`+`), and incompatible (`!`)
                        continue;
                    }

                    $depName = $parsed['name'];

                    // Skip built-in Factorio and Space Age DLC mods (`base`, `elevated-rails`, `quality`, `space-age`)
                    if (in_array($depName, FactorioModPortalService::BUILTIN_MODS, true)) {
                        continue;
                    }

                    if (!in_array($depName, $installedModNames, true) && !in_array($depName, $installed, true)) {
                        Log::info("Installing required dependency: {$depName} for {$modName}");
                        $depResults = $this->installModWithDependencies(
                            $server,
                            $depName,
                            true,
                            null,
                            $installed,
                            $effectiveFactorioVersion
                        );

                        $results['installed'] = array_merge($results['installed'], $depResults['installed']);
                        $results['skipped'] = array_merge($results['skipped'], $depResults['skipped']);
                        $results['failed'] = array_merge($results['failed'], $depResults['failed']);
                    }
                }
            }

            // Now install the mod itself
            if ($this->addMod($server, $modName, $enabled, $selectedVersion, $factorioVersion)) {
                $results['installed'][] = $modName;
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
     * Resolve Factorio credentials from server-settings.json or server environment variables
     *
     * @return array{username: string|null, token: string|null}
     */
    private function getFactorioCredentials(Server $server): array
    {
        $username = null;
        $token = null;

        $candidatePaths = ['/data/server-settings.json', 'data/server-settings.json', 'server-settings.json'];
        foreach ($candidatePaths as $settingsPath) {
            try {
                $settingsContent = $this->fileRepository->setServer($server)->getContent($settingsPath);
                $settings = json_decode($settingsContent, true);

                if (is_array($settings)) {
                    $username = !empty($settings['username']) ? trim((string) $settings['username']) : null;
                    $token = !empty($settings['token']) ? trim((string) $settings['token']) : null;

                    if ($username && $token) {
                        return ['username' => $username, 'token' => $token];
                    }
                }
            } catch (\Exception) {
                // Continue checking next candidate path
            }
        }

        // Fallback: check server environment variables if defined on the Egg
        try {
            $server->loadMissing('variables');
            foreach ($server->variables as $variable) {
                $envName = strtoupper((string) $variable->env_variable);
                $val = $variable->server_value ?? $variable->variable_value ?? null;
                if (!$val) {
                    continue;
                }
                if (in_array($envName, ['FACTORIO_USERNAME', 'USERNAME'], true) && !$username) {
                    $username = trim((string) $val);
                } elseif (in_array($envName, ['FACTORIO_TOKEN', 'TOKEN'], true) && !$token) {
                    $token = trim((string) $val);
                }
            }
        } catch (\Exception) {
            // Ignore variable resolution errors
        }

        return ['username' => $username, 'token' => $token];
    }

    /**
     * Download a mod file to the server's mods directory.
     * Uses Wings' `/files/pull` with `foreground: true` & `file_name` first (zero Panel RAM usage),
     * falling back to Guzzle streaming if needed.
     *
     * @throws \RuntimeException
     */
    private function downloadModFileToServer(Server $server, string $fullDownloadUrl, string $safeFileName): void
    {
        $modsDir = $this->getModsPath();

        try {
            $this->fileRepository->setServer($server)->createDirectory($modsDir, '/');
        } catch (\Exception) {
            // Directory likely already exists
        }

        // 1. Try Wings foreground pull first (Pelican reference pattern from minecraft-modrinth)
        try {
            $this->fileRepository
                ->setServer($server)
                ->getHttpClient()
                ->timeout(max((int) config('panel.guzzle.timeout', 30), self::DOWNLOAD_TIMEOUT))
                ->post("/api/servers/{$server->uuid}/files/pull", [
                    'url' => $fullDownloadUrl,
                    'root' => $modsDir,
                    'file_name' => $safeFileName,
                    'foreground' => true,
                ]);

            if (in_array($safeFileName, $this->getModFiles($server), true)) {
                Log::info("Successfully pulled mod file {$safeFileName} directly via Wings");
                return;
            }
        } catch (\Exception $pullException) {
            Log::debug("Wings direct pull failed for {$safeFileName}, falling back to Panel download: " . $pullException->getMessage());
        }

        // 2. Fallback: Download via Guzzle into a temporary stream (avoiding in-memory string exhaustion) and upload
        $client = new \GuzzleHttp\Client([
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        $response = $client->get($fullDownloadUrl);
        $statusCode = $response->getStatusCode();

        if ($statusCode === 403 || $statusCode === 401) {
            throw new \RuntimeException(
                'Factorio Mod Portal rejected the download (HTTP ' . $statusCode . '). '
                . 'Please configure a valid Factorio username and token in server-settings.json.'
            );
        }

        if ($statusCode !== 200) {
            throw new \RuntimeException("Failed to download mod file {$safeFileName} (HTTP status {$statusCode}).");
        }

        // Verify we did not receive an HTML login page instead of a ZIP archive
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'text/html')) {
            throw new \RuntimeException(
                'Factorio Mod Portal redirected to login page. Please configure a valid Factorio username and token in server-settings.json.'
            );
        }

        $modContent = $response->getBody()->getContents();
        if (strlen($modContent) < 4 || substr($modContent, 0, 2) !== 'PK') {
            throw new \RuntimeException("Downloaded file for {$safeFileName} is not a valid ZIP archive.");
        }

        $putResponse = $this->fileRepository->setServer($server)->putContent("{$modsDir}/{$safeFileName}", $modContent);
        if (is_object($putResponse) && method_exists($putResponse, 'failed') && $putResponse->failed()) {
            throw new \RuntimeException("Failed to write {$safeFileName} to server via Wings.");
        }

        Log::info("Successfully uploaded mod file {$safeFileName} to {$modsDir}/ directory");
    }

    /**
     * Add or update a mod in the mod list and download the mod file
     */
    public function addMod(
        Server $server,
        string $modName,
        bool $enabled = true,
        ?string $version = null,
        ?string $factorioVersion = null
    ): bool {
        try {
            if ($version) {
                $release = $this->modPortalService->getModRelease($modName, $version);
            } else {
                $release = $this->modPortalService->getLatestRelease($modName, $factorioVersion);
            }

            if (!$release) {
                Log::error("Could not find compatible release info for mod: {$modName}" . ($version ? " version {$version}" : ''));
                return false;
            }

            $downloadUrl = $release['download_url'] ?? null;
            $fileName = $release['file_name'] ?? null;

            if (!$downloadUrl || !$fileName) {
                Log::error("Missing download URL or filename for mod: {$modName}");
                return false;
            }

            $safeFileName = $this->validateFilename($fileName);
            $credentials = $this->getFactorioCredentials($server);
            $username = $credentials['username'];
            $token = $credentials['token'];

            $fullDownloadUrl = $this->modPortalService->getDownloadUrl($downloadUrl, $username, $token);

            // Download the mod file to the server (throws RuntimeException on auth/network failure)
            $this->downloadModFileToServer($server, $fullDownloadUrl, $safeFileName);

            // Update mod-list.json atomically using Cache::lock
            return Cache::lock("factorio_modlist:{$server->id}", 10)->block(5, function () use ($server, $modName, $enabled) {
                $modList = $this->readModList($server);

                $modExists = false;
                foreach ($modList['mods'] as &$mod) {
                    if (($mod['name'] ?? null) === $modName) {
                        $mod['enabled'] = $enabled;
                        $modExists = true;
                        break;
                    }
                }
                unset($mod);

                if (!$modExists) {
                    $modList['mods'][] = [
                        'name' => $modName,
                        'enabled' => $enabled,
                    ];
                }

                return $this->writeModList($server, $modList);
            }) === true;
        } catch (\Exception $e) {
            Log::error("Error adding mod {$modName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a mod from the mod list and delete all matching mod files
     */
    public function removeMod(Server $server, string $modName): bool
    {
        if (in_array($modName, FactorioModPortalService::BUILTIN_MODS, true)) {
            return false;
        }

        try {
            $removedFromList = Cache::lock("factorio_modlist:{$server->id}", 10)->block(5, function () use ($server, $modName) {
                $modList = $this->readModList($server);
                $originalCount = count($modList['mods']);

                $modList['mods'] = array_values(array_filter($modList['mods'], function ($mod) use ($modName) {
                    return ($mod['name'] ?? null) !== $modName;
                }));

                if (count($modList['mods']) === $originalCount) {
                    return false;
                }

                return $this->writeModList($server, $modList);
            });

            // Also delete all physical ZIP files for this mod
            $modFiles = $this->getModFiles($server);
            $modFilePrefix = $modName . '_';
            $filesToDelete = [];

            foreach ($modFiles as $file) {
                if (str_starts_with($file, $modFilePrefix) && preg_match('/^' . preg_quote($modName, '/') . '_\d+\.\d+\.\d+(?:\.\d+)?\.zip$/', $file)) {
                    $filesToDelete[] = $this->validateFilename($file);
                }
            }

            if (!empty($filesToDelete)) {
                try {
                    $this->fileRepository->setServer($server)->deleteFiles($this->getModsPath(), $filesToDelete);
                    Log::info('Deleted mod files: ' . implode(', ', $filesToDelete));
                } catch (\Exception $e) {
                    Log::warning("Could not delete mod files for {$modName}: " . $e->getMessage());
                }
            }

            return (bool) $removedFromList || !empty($filesToDelete);
        } catch (\Exception $e) {
            Log::error("Error removing mod {$modName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle a mod's enabled state atomically
     */
    public function toggleMod(Server $server, string $modName): bool
    {
        try {
            return Cache::lock("factorio_modlist:{$server->id}", 10)->block(5, function () use ($server, $modName) {
                $modList = $this->readModList($server);

                $found = false;
                foreach ($modList['mods'] as &$mod) {
                    if (($mod['name'] ?? null) === $modName) {
                        $oldState = $mod['enabled'] ?? true;
                        $mod['enabled'] = !$oldState;
                        $found = true;
                        break;
                    }
                }
                unset($mod);

                if (!$found) {
                    $modFiles = $this->getModFiles($server);
                    if ($this->extractInstalledVersionFromFiles($modName, $modFiles) !== null) {
                        $modList['mods'][] = [
                            'name' => $modName,
                            'enabled' => true,
                        ];
                    } else {
                        return false;
                    }
                }

                return $this->writeModList($server, $modList);
            }) === true;
        } catch (\Exception $e) {
            Log::error("Error toggling mod {$modName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set the enabled state for all non-builtin mods in a single write operation
     *
     * @return int Number of mods whose state was changed
     */
    public function setAllModsState(Server $server, bool $targetState): int
    {
        try {
            $changedCount = 0;

            $success = Cache::lock("factorio_modlist:{$server->id}", 10)->block(5, function () use ($server, $targetState, &$changedCount) {
                $modList = $this->readModList($server);

                foreach ($modList['mods'] as &$mod) {
                    $name = $mod['name'] ?? '';
                    if ($name === '' || in_array($name, FactorioModPortalService::BUILTIN_MODS, true)) {
                        continue;
                    }

                    $currentState = (bool) ($mod['enabled'] ?? true);
                    if ($currentState !== $targetState) {
                        $mod['enabled'] = $targetState;
                        $changedCount++;
                    }
                }
                unset($mod);

                if ($changedCount === 0) {
                    return true;
                }

                return $this->writeModList($server, $modList);
            });

            return $success ? $changedCount : 0;
        } catch (\Exception $e) {
            Log::error('Error setting all mods state: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get all mods from the mod list, merging mod-list.json with physically installed mod files
     */
    public function getMods(Server $server, ?array $modFiles = null): array
    {
        $modList = $this->readModList($server);
        $modsFromList = $modList['mods'] ?? [];

        $modFiles ??= $this->getModFiles($server);

        $modMap = [];
        foreach ($modsFromList as $mod) {
            if (is_array($mod) && !empty($mod['name'])) {
                $modMap[$mod['name']] = $mod;
            }
        }

        $needsUpdate = false;

        foreach ($modFiles as $file) {
            if (preg_match('/^(.+?)_\d+\.\d+\.\d+(?:\.\d+)?\.zip$/', $file, $matches)) {
                $modName = $matches[1];

                if (in_array($modName, FactorioModPortalService::BUILTIN_MODS, true)) {
                    continue;
                }

                if (!isset($modMap[$modName])) {
                    $modMap[$modName] = [
                        'name' => $modName,
                        'enabled' => false,
                    ];
                    $needsUpdate = true;
                    Log::info("Found installed mod '{$modName}' not in mod-list.json, adding with enabled=false");
                }
            }
        }

        if ($needsUpdate) {
            $modList['mods'] = array_values($modMap);
            $this->writeModList($server, $modList);
        }

        return array_values($modMap);
    }
}
