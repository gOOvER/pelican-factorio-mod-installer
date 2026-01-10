<?php

namespace gOOvER\FactorioModInstaller\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FactorioModPortalService
{
    private Client $client;
    private const BASE_URL = 'https://mods.factorio.com';
    private const API_BASE = '/api/mods';

    // Cache durations
    private const CACHE_MOD_LIST = 30 * 60; // 30 minutes for mod list
    private const CACHE_MOD_DETAILS = 6 * 60 * 60; // 6 hours for mod details
    private const CACHE_SEARCH_RESULTS = 15 * 60; // 15 minutes for search

    // Pagination settings
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Pelican-Factorio-Mod-Installer/1.0',
            ],
        ]);
    }

    /**
     * Get paginated mods from the Factorio Mod Portal
     * 
     * @param int $page Page number (1-based)
     * @param int $pageSize Number of results per page (max 100)
     * @param string|null $category Filter by category
     * @param string $sortBy Sort field (name, created_at, updated_at, downloads_count)
     * @param string $sortOrder Sort order (asc, desc)
     * @return array
     */
    public function getMods(
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        ?string $category = null,
        string $sortBy = 'downloads_count',
        string $sortOrder = 'desc'
    ): array {
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE);
        $cacheKey = "factorio_mods_page_{$page}_{$pageSize}_{$category}_{$sortBy}_{$sortOrder}";

        return Cache::remember($cacheKey, self::CACHE_MOD_LIST, function () use ($page, $pageSize, $category, $sortBy, $sortOrder) {
            try {
                $params = [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'hide_deprecated' => 'true',
                ];

                $response = $this->client->get(self::API_BASE, [
                    'query' => $params,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $results = $data['results'] ?? [];

                // Filter by category if specified
                if ($category) {
                    $results = array_filter($results, function ($mod) use ($category) {
                        return strtolower($mod['category'] ?? '') === strtolower($category);
                    });
                    $results = array_values($results); // Re-index array
                }

                // Sort results
                $results = $this->sortResults($results, $sortBy, $sortOrder);

                return [
                    'pagination' => $data['pagination'] ?? [
                        'count' => 0,
                        'page' => $page,
                        'page_count' => 0,
                        'page_size' => $pageSize,
                    ],
                    'results' => $results,
                ];
            } catch (GuzzleException $e) {
                Log::error('Error fetching Factorio mods: ' . $e->getMessage());
                return $this->emptyResult($page, $pageSize);
            }
        });
    }

    /**
     * Search for mods on the Factorio Mod Portal
     * Uses client-side filtering on cached pages for better performance
     *
     * @param string $query Search query
     * @param string|null $category Filter by category
     * @param string $sortBy Sort field
     * @param string $sortOrder Sort order
     * @param int $limit Max results to return
     * @return array
     */
    public function searchMods(
        string $query,
        ?string $category = null,
        string $sortBy = 'downloads_count',
        string $sortOrder = 'desc',
        int $limit = 100
    ): array {
        if (strlen($query) < 2) {
            return $this->emptyResult(1, $limit);
        }

        $cacheKey = "factorio_search_" . md5("{$query}_{$category}_{$sortBy}_{$sortOrder}_{$limit}");

        return Cache::remember($cacheKey, self::CACHE_SEARCH_RESULTS, function () use ($query, $category, $sortBy, $sortOrder, $limit) {
            $allResults = [];
            $queryLower = strtolower($query);
            $page = 1;
            $maxPages = 20; // Limit search to first 20 pages (2000 mods) for performance
            
            try {
                do {
                    $response = $this->client->get(self::API_BASE, [
                        'query' => [
                            'page' => $page,
                            'page_size' => self::MAX_PAGE_SIZE,
                            'hide_deprecated' => 'true',
                        ],
                    ]);

                    $data = json_decode($response->getBody()->getContents(), true);
                    $results = $data['results'] ?? [];
                    
                    // Filter matching mods
                    $matching = array_filter($results, function ($mod) use ($queryLower, $category) {
                        // Category filter
                        if ($category && strtolower($mod['category'] ?? '') !== strtolower($category)) {
                            return false;
                        }
                        
                        // Text search in multiple fields
                        $searchableText = strtolower(implode(' ', [
                            $mod['title'] ?? '',
                            $mod['name'] ?? '',
                            $mod['summary'] ?? '',
                            $mod['owner'] ?? '',
                        ]));
                        
                        return str_contains($searchableText, $queryLower);
                    });
                    
                    $allResults = array_merge($allResults, $matching);
                    
                    // Stop if we have enough results
                    if (count($allResults) >= $limit) {
                        break;
                    }
                    
                    $pagination = $data['pagination'] ?? [];
                    $page++;
                    
                } while ($page <= ($pagination['page_count'] ?? 1) && $page <= $maxPages);
                
                // Sort and limit results
                $allResults = $this->sortResults($allResults, $sortBy, $sortOrder);
                $allResults = array_slice($allResults, 0, $limit);

                return [
                    'pagination' => [
                        'count' => count($allResults),
                        'page' => 1,
                        'page_count' => 1,
                        'page_size' => count($allResults),
                    ],
                    'results' => $allResults,
                ];
            } catch (GuzzleException $e) {
                Log::error('Error searching Factorio mods: ' . $e->getMessage());
                return $this->emptyResult(1, $limit);
            }
        });
    }

    /**
     * Get popular mods (sorted by downloads)
     */
    public function getPopularMods(int $page = 1, int $pageSize = 50): array
    {
        return $this->getMods($page, $pageSize, null, 'downloads_count', 'desc');
    }

    /**
     * Get mods by category
     */
    public function getModsByCategory(string $category, int $page = 1, int $pageSize = 50): array
    {
        return $this->getMods($page, $pageSize, $category, 'downloads_count', 'desc');
    }

    /**
     * Get recently updated mods
     */
    public function getRecentMods(int $page = 1, int $pageSize = 50): array
    {
        return $this->getMods($page, $pageSize, null, 'updated_at', 'desc');
    }

    /**
     * Get newest mods
     */
    public function getNewMods(int $page = 1, int $pageSize = 50): array
    {
        return $this->getMods($page, $pageSize, null, 'created_at', 'desc');
    }

    /**
     * Sort results array by field
     */
    private function sortResults(array $results, string $sortBy, string $sortOrder): array
    {
        usort($results, function ($a, $b) use ($sortBy, $sortOrder) {
            $aValue = $a[$sortBy] ?? ($sortBy === 'downloads_count' ? 0 : '');
            $bValue = $b[$sortBy] ?? ($sortBy === 'downloads_count' ? 0 : '');

            // Numeric comparison for downloads_count
            if ($sortBy === 'downloads_count') {
                $aValue = (int) $aValue;
                $bValue = (int) $bValue;
            }

            $comparison = is_numeric($aValue) 
                ? $aValue <=> $bValue 
                : strcasecmp((string) $aValue, (string) $bValue);

            return $sortOrder === 'asc' ? $comparison : -$comparison;
        });

        return $results;
    }

    /**
     * Return an empty result structure
     */
    private function emptyResult(int $page, int $pageSize): array
    {
        return [
            'pagination' => [
                'count' => 0,
                'page' => $page,
                'page_count' => 0,
                'page_size' => $pageSize,
            ],
            'results' => [],
        ];
    }

    /**
     * Get detailed information about a specific mod
     *
     * @param string $modName The mod's machine-readable name
     * @param bool $full Whether to fetch full details
     * @return array|null
     */
    public function getModDetails(string $modName, bool $full = false): ?array
    {
        $endpoint = $full ? "full" : "";
        $cacheKey = "factorio_mod_{$modName}" . ($full ? "_full" : "");

        return Cache::remember($cacheKey, self::CACHE_MOD_DETAILS, function () use ($modName, $endpoint) {
            try {
                $url = self::API_BASE . "/{$modName}" . ($endpoint ? "/{$endpoint}" : "");
                $response = $this->client->get($url);

                return json_decode($response->getBody()->getContents(), true);
            } catch (GuzzleException $e) {
                Log::error("Error fetching Factorio mod details for {$modName}: " . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Get the latest release information for a mod
     *
     * @param string $modName The mod's machine-readable name
     * @return array|null
     */
    public function getLatestRelease(string $modName): ?array
    {
        $modDetails = $this->getModDetails($modName, false);

        if (!$modDetails || !isset($modDetails['releases'])) {
            return null;
        }

        // Releases are NOT always sorted correctly, find the newest by date
        $releases = $modDetails['releases'];
        if (empty($releases)) {
            return null;
        }
        
        // Sort by released_at timestamp descending to get the newest
        usort($releases, function($a, $b) {
            $timeA = strtotime($a['released_at'] ?? '1970-01-01');
            $timeB = strtotime($b['released_at'] ?? '1970-01-01');
            return $timeB <=> $timeA; // Descending order (newest first)
        });
        
        return $releases[0];
    }

    /**
     * Get a specific release version of a mod
     *
     * @param string $modName The mod's machine-readable name
     * @param string $version The version to retrieve
     * @return array|null
     */
    public function getModRelease(string $modName, string $version): ?array
    {
        $modDetails = $this->getModDetails($modName, false);

        if (!$modDetails || !isset($modDetails['releases'])) {
            return null;
        }

        // Find the specific version
        foreach ($modDetails['releases'] as $release) {
            if ($release['version'] === $version) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Get download URL for a specific mod version
     *
     * @param string $downloadPath The download_url from the release info
     * @param string|null $username Factorio username (optional for authentication)
     * @param string|null $token Factorio API token (optional for authentication)
     * @return string
     */
    public function getDownloadUrl(string $downloadPath, ?string $username = null, ?string $token = null): string
    {
        $url = self::BASE_URL . $downloadPath;

        if ($username && $token) {
            $url .= "?username=" . urlencode($username) . "&token=" . urlencode($token);
        }

        return $url;
    }

    /**
     * Download a mod file
     *
     * @param string $downloadPath The download_url from the release info
     * @param string $destinationPath Local path where to save the mod
     * @param string|null $username Factorio username (optional)
     * @param string|null $token Factorio API token (optional)
     * @return bool
     */
    public function downloadMod(
        string $downloadPath,
        string $destinationPath,
        ?string $username = null,
        ?string $token = null
    ): bool {
        try {
            $url = $downloadPath;
            if ($username && $token) {
                $url .= "?username=" . urlencode($username) . "&token=" . urlencode($token);
            }

            $response = $this->client->get($url, [
                'sink' => $destinationPath,
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error("Error downloading Factorio mod: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cached data
     */
    public function clearCache(): void
    {
        // Clear specific cache patterns
        $patterns = ['factorio_mods_', 'factorio_mod_', 'factorio_search_'];
        
        foreach ($patterns as $pattern) {
            // Note: This requires cache driver that supports tags or pattern deletion
            // For file/database cache, this clears all cache
        }
        
        Cache::flush();
        Cache::put('factorio_cache_last_refresh', now(), 60 * 60 * 24 * 30);
    }

    /**
     * Get available categories from the Factorio Mod Portal
     */
    public function getCategories(): array
    {
        return [
            'content' => ['name' => 'Content', 'color' => 'primary'],
            'overhaul' => ['name' => 'Overhaul', 'color' => 'danger'],
            'tweaks' => ['name' => 'Tweaks', 'color' => 'success'],
            'utilities' => ['name' => 'Utilities', 'color' => 'warning'],
            'scenarios' => ['name' => 'Scenarios', 'color' => 'info'],
            'mod-packs' => ['name' => 'Mod Packs', 'color' => 'gray'],
            'localizations' => ['name' => 'Localizations', 'color' => 'gray'],
            'internal' => ['name' => 'Internal', 'color' => 'gray'],
        ];
    }

    /**
     * Get cache information (simplified)
     */
    public function getCacheInfo(): array
    {
        $lastRefresh = Cache::get('factorio_cache_last_refresh');
        
        return [
            'last_refresh' => $lastRefresh,
            'cache_duration_mods' => self::CACHE_MOD_LIST / 60 . ' minutes',
            'cache_duration_details' => self::CACHE_MOD_DETAILS / 3600 . ' hours',
            'cache_duration_search' => self::CACHE_SEARCH_RESULTS / 60 . ' minutes',
        ];
    }
}
