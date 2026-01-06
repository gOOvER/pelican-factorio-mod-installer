<?php

namespace gOOvER\FactorioModInstaller\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use gOOvER\FactorioModInstaller\Services\FactorioModPortalService;

class FactorioModController
{
    private FactorioModPortalService $modPortalService;
    private const MOD_LIST_FILE = 'mod-list.json';
    private const MODS_DIR = 'mods';
    private $server;

    public function __construct(FactorioModPortalService $modPortalService)
    {
        $this->modPortalService = $modPortalService;
    }

    /**
     * Get the server's mod directory path
     */
    private function getModsPath(): string
    {
        return $this->server->directory . '/' . self::MODS_DIR;
    }

    /**
     * Get the mod-list.json file path
     */
    private function getModListPath(): string
    {
        return $this->getModsPath() . '/' . self::MOD_LIST_FILE;
    }

    /**
     * Read the mod-list.json file
     */
    private function readModList(): array
    {
        $path = $this->getModListPath();

        if (!file_exists($path)) {
            // Create default mod-list.json if it doesn't exist
            $defaultModList = [
                'mods' => [
                    [
                        'name' => 'base',
                        'enabled' => true,
                    ],
                ],
            ];
            $this->writeModList($defaultModList);
            return $defaultModList;
        }

        $content = file_get_contents($path);
        return json_decode($content, true) ?? ['mods' => []];
    }

    /**
     * Write to the mod-list.json file
     */
    private function writeModList(array $data): bool
    {
        $path = $this->getModListPath();
        $modsDir = $this->getModsPath();

        // Ensure mods directory exists
        if (!is_dir($modsDir)) {
            mkdir($modsDir, 0755, true);
        }

        return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    /**
     * List all installed mods
     */
    public function index(Request $request): JsonResponse
    {
        $this->server = $request->route('server');
        
        try {
            $modList = $this->readModList();

            // Enrich mod data with information from the portal
            $mods = array_map(function ($mod) {
                if ($mod['name'] === 'base') {
                    return $mod;
                }

                $details = $this->modPortalService->getModDetails($mod['name']);
                if ($details) {
                    $mod['title'] = $details['title'] ?? $mod['name'];
                    $mod['summary'] = $details['summary'] ?? '';
                    $mod['downloads_count'] = $details['downloads_count'] ?? 0;
                    $mod['thumbnail'] = isset($details['thumbnail']) 
                        ? 'https://assets-mod.factorio.com' . $details['thumbnail'] 
                        : null;
                }

                return $mod;
            }, $modList['mods'] ?? []);

            return \response()->json(['mods' => $mods]);
        } catch (\Exception $e) {
            Log::error('Error listing Factorio mods: ' . $e->getMessage());
            return \response()->json(['error' => 'Failed to list mods'], 500);
        }
    }

    /**
     * Search/browse mods from the Factorio Mod Portal
     */
    public function browse(Request $request): JsonResponse
    {
        $this->server = $request->route('server');
        
        $query = $request->input('query');
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 25);
        $sortBy = $request->input('sort', 'downloads_count');
        $sortOrder = $request->input('sort_order', 'desc');

        try {
            $result = $this->modPortalService->searchMods($query, $page, $pageSize, $sortBy, $sortOrder);

            // Add thumbnail full URLs
            if (isset($result['results'])) {
                $result['results'] = array_map(function ($mod) {
                    if (isset($mod['thumbnail'])) {
                        $mod['thumbnail'] = 'https://assets-mod.factorio.com' . $mod['thumbnail'];
                    }
                    return $mod;
                }, $result['results']);
            }

            return \response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error browsing Factorio mods: ' . $e->getMessage());
            return \response()->json(['error' => 'Failed to browse mods'], 500);
        }
    }

    /**
     * Get details for a specific mod
     */
    public function show(Request $request, string $modName): JsonResponse
    {
        $this->server = $request->route('server');
        
        try {
            $details = $this->modPortalService->getModDetails($modName, true);

            if (!$details) {
                return \response()->json(['error' => 'Mod not found'], 404);
            }

            // Add thumbnail full URL
            if (isset($details['thumbnail'])) {
                $details['thumbnail'] = 'https://assets-mod.factorio.com' . $details['thumbnail'];
            }

            return \response()->json($details);
        } catch (\Exception $e) {
            Log::error("Error fetching Factorio mod details for {$modName}: " . $e->getMessage());
            return \response()->json(['error' => 'Failed to fetch mod details'], 500);
        }
    }

    /**
     * Install a mod
     */
    public function store(Request $request): JsonResponse
    {
        $this->server = $request->route('server');
        
        $request->validate([
            'mod_name' => 'required|string',
        ]);

        $modName = $request->input('mod_name');

        try {
            // Check if mod exists on the portal
            $modDetails = $this->modPortalService->getModDetails($modName);
            if (!$modDetails) {
                return \response()->json(['error' => 'Mod not found on Factorio Mod Portal'], 404);
            }

            // Get latest release
            $latestRelease = $this->modPortalService->getLatestRelease($modName);
            if (!$latestRelease) {
                return \response()->json(['error' => 'No releases found for this mod'], 404);
            }

            // Download the mod
            $downloadPath = $latestRelease['download_url'];
            $fileName = $latestRelease['file_name'];
            $destinationPath = $this->getModsPath() . '/' . $fileName;

            // Ensure mods directory exists
            $modsDir = $this->getModsPath();
            if (!is_dir($modsDir)) {
                mkdir($modsDir, 0755, true);
            }

            $success = $this->modPortalService->downloadMod(
                $downloadPath,
                $destinationPath
            );

            if (!$success) {
                return \response()->json(['error' => 'Failed to download mod'], 500);
            }

            // Update mod-list.json
            $modList = $this->readModList();
            
            // Check if mod already exists
            $existingIndex = null;
            foreach ($modList['mods'] as $index => $mod) {
                if ($mod['name'] === $modName) {
                    $existingIndex = $index;
                    break;
                }
            }

            $modEntry = [
                'name' => $modName,
                'enabled' => true,
            ];

            if ($existingIndex !== null) {
                // Update existing entry
                $modList['mods'][$existingIndex] = $modEntry;
            } else {
                // Add new entry
                $modList['mods'][] = $modEntry;
            }

            $this->writeModList($modList);

            return \response()->json([
                'message' => 'Mod installed successfully',
                'mod' => array_merge($modEntry, [
                    'title' => $modDetails['title'] ?? $modName,
                    'version' => $latestRelease['version'],
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error("Error installing Factorio mod {$modName}: " . $e->getMessage());
            return \response()->json(['error' => 'Failed to install mod'], 500);
        }
    }

    /**
     * Remove a mod
     */
    public function destroy(Request $request, string $modName): JsonResponse
    {
        $this->server = $request->route('server');
        
        try {
            // Don't allow removing base mod
            if ($modName === 'base') {
                return \response()->json(['error' => 'Cannot remove base mod'], 400);
            }

            // Update mod-list.json
            $modList = $this->readModList();
            $modList['mods'] = array_values(array_filter($modList['mods'], function ($mod) use ($modName) {
                return $mod['name'] !== $modName;
            }));

            $this->writeModList($modList);

            // Remove mod files (all versions)
            $modsDir = $this->getModsPath();
            $pattern = $modsDir . '/' . $modName . '_*.zip';
            
            foreach (glob($pattern) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            return \response()->json(['message' => 'Mod removed successfully']);
        } catch (\Exception $e) {
            Log::error("Error removing Factorio mod {$modName}: " . $e->getMessage());
            return \response()->json(['error' => 'Failed to remove mod'], 500);
        }
    }

    /**
     * Toggle mod enabled status
     */
    public function toggle(Request $request, string $modName): JsonResponse
    {
        $this->server = $request->route('server');
        
        try {
            $modList = $this->readModList();

            foreach ($modList['mods'] as &$mod) {
                if ($mod['name'] === $modName) {
                    $mod['enabled'] = !($mod['enabled'] ?? true);
                    $this->writeModList($modList);
                    
                    return \response()->json([
                        'message' => 'Mod status updated',
                        'enabled' => $mod['enabled'],
                    ]);
                }
            }

            return \response()->json(['error' => 'Mod not found'], 404);
        } catch (\Exception $e) {
            Log::error("Error toggling Factorio mod {$modName}: " . $e->getMessage());
            return \response()->json(['error' => 'Failed to toggle mod status'], 500);
        }
    }
}
