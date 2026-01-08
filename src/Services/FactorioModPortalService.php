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
    private const CACHE_MOD_DETAILS = 6 * 60 * 60; // 6 hours
    private const CACHE_SEARCH_RESULTS = 15 * 60; // 15 minutes

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
     * Search for mods on the Factorio Mod Portal
     *
     * @param string|null $query Search query
     * @param int $page Page number
     * @param int $pageSize Number of results per page
     * @param string $sortBy Sort field (name, created_at, updated_at)
     * @param string $sortOrder Sort order (asc, desc)
     * @return array
     */
    public function searchMods(
        ?string $query = null,
        int $page = 1,
        int $pageSize = 25,
        string $sortBy = 'downloads_count',
        string $sortOrder = 'desc'
    ): array {
        $cacheKey = "factorio_mods_search_{$query}_{$page}_{$pageSize}_{$sortBy}_{$sortOrder}";

        return Cache::remember($cacheKey, self::CACHE_SEARCH_RESULTS, function () use ($query, $page, $pageSize, $sortBy, $sortOrder) {
            try {
                // If we have a search query with 3+ characters, fetch all pages to ensure complete results
                if ($query && strlen($query) >= 3) {
                    return $this->searchAllPages($query, $sortBy, $sortOrder);
                }
                
                $params = [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'hide_deprecated' => 'true',
                ];

                // Only add sort parameters if not sorting by downloads (custom)
                if ($sortBy !== 'downloads_count') {
                    $params['sort'] = $sortBy;
                    $params['sort_order'] = $sortOrder;
                }

                $response = $this->client->get(self::API_BASE, [
                    'query' => $params,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                // Sort by downloads if requested (not a native API sort option)
                if ($sortBy === 'downloads_count' && isset($data['results'])) {
                    usort($data['results'], function ($a, $b) use ($sortOrder) {
                        $aDownloads = $a['downloads_count'] ?? 0;
                        $bDownloads = $b['downloads_count'] ?? 0;
                        return $sortOrder === 'asc' 
                            ? $aDownloads <=> $bDownloads 
                            : $bDownloads <=> $aDownloads;
                    });
                }

                return $data;
            } catch (GuzzleException $e) {
                Log::error('Error searching Factorio mods: ' . $e->getMessage());
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
        });
    }

    /**
     * Search all pages for matching mods
     *
     * @param string $query Search query
     * @param string $sortBy Sort field
     * @param string $sortOrder Sort order
     * @return array
     */
    private function searchAllPages(string $query, string $sortBy, string $sortOrder): array
    {
        $allResults = [];
        $page = 1;
        $pageSize = 100; // Max per request
        $queryLower = strtolower($query);
        
        try {
            do {
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
                
                // Filter matching mods - search in multiple fields
                $matching = array_filter($results, function ($mod) use ($queryLower) {
                    $searchableText = strtolower(implode(' ', [
                        $mod['title'] ?? '',
                        $mod['name'] ?? '',
                        $mod['summary'] ?? '',
                        $mod['owner'] ?? '',
                        $mod['category'] ?? '',
                    ]));
                    
                    return str_contains($searchableText, $queryLower);
                });
                
                $allResults = array_merge($allResults, $matching);
                
                $pagination = $data['pagination'] ?? [];
                $page++;
                
                // Stop if we've reached the last page or reasonable limit
                // Limit to 50 pages (5000 mods) for performance - should find most mods
            } while ($page <= ($pagination['page_count'] ?? 1) && $page <= 50);
            
            // Sort results
            if ($sortBy === 'downloads_count') {
                usort($allResults, function ($a, $b) use ($sortOrder) {
                    $aDownloads = $a['downloads_count'] ?? 0;
                    $bDownloads = $b['downloads_count'] ?? 0;
                    return $sortOrder === 'asc' 
                        ? $aDownloads <=> $bDownloads 
                        : $bDownloads <=> $aDownloads;
                });
            }
            
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
            Log::error('Error searching all pages: ' . $e->getMessage());
            return [
                'pagination' => ['count' => 0, 'page' => 1, 'page_count' => 0, 'page_size' => 0],
                'results' => [],
            ];
        }
    }

    /**
     * Get popular mods from the Factorio Mod Portal
     *
     * @param int $page Page number
     * @param int $pageSize Number of results per page
     * @return array
     */
    public function getPopularMods(int $page = 1, int $pageSize = 25): array
    {
        return $this->searchMods(null, $page, $pageSize, 'downloads_count', 'desc');
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
        Cache::flush();
        Cache::put('factorio_cache_last_refresh', now(), 60 * 60 * 24 * 30); // 30 days
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        try {
            $lastRefresh = Cache::get('factorio_cache_last_refresh');
            
            // Try to get the total mod count from the API pagination
            // This gives us the actual total number of mods available
            $totalMods = 0;
            $searchCache = Cache::get('factorio_mods_search__1_100_downloads_count_desc');
            
            if ($searchCache && isset($searchCache['pagination']['count'])) {
                // This is the total count from the API
                $totalMods = $searchCache['pagination']['count'];
            }
            
            // If we have a search cache, use that count instead (it contains more mods)
            foreach (Cache::get('factorio_mods_search_') ?? [] as $key => $value) {
                if (str_starts_with($key, 'factorio_mods_search_') && is_array($value) && isset($value['results'])) {
                    $count = count($value['results']);
                    if ($count > $totalMods) {
                        $totalMods = $count;
                    }
                }
            }
            
            return [
                'total_mods_in_cache' => $totalMods,
                'last_refresh' => $lastRefresh,
                'has_cache' => $totalMods > 0 || $lastRefresh !== null,
            ];
        } catch (\Exception $e) {
            Log::error('Error getting cache stats: ' . $e->getMessage());
            return [
                'total_mods_in_cache' => 0,
                'last_refresh' => null,
                'has_cache' => false,
            ];
        }
    }
}
