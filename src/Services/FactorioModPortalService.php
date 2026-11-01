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
    private const ASSETS_BASE_URL = 'https://assets-mod.factorio.com';
    private const LATEST_RELEASES_URL = 'https://factorio.com/api/latest-releases';
    private const API_BASE = '/api/mods';

    // Cache durations (in seconds)
    private const CACHE_MOD_LIST = 30 * 60; // 30 minutes for mod list / catalog
    private const CACHE_MOD_DETAILS = 6 * 60 * 60; // 6 hours for mod details
    private const CACHE_SEARCH_RESULTS = 15 * 60; // 15 minutes for search
    private const CACHE_LATEST_VERSION = 60 * 60; // 1 hour for latest Factorio version

    // Pagination settings
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    // Built-in Factorio & Space Age expansion mods that are not hosted on the Mod Portal
    public const BUILTIN_MODS = ['base', 'elevated-rails', 'quality', 'space-age'];

    public function __construct()
    {
        $baseUrl = rtrim((string) config('factorio-mod-installer.api_base_url', self::BASE_URL), '/');

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Pelican-Factorio-Mod-Installer/1.3.0',
            ],
        ]);
    }

    /**
     * Get the current cache version prefix to allow instant cache invalidation without Cache::flush()
     */
    private function getCachePrefix(): string
    {
        $version = (int) Cache::get('factorio_mod_portal_cache_version', 1);
        return "factorio_v{$version}_";
    }

    /**
     * Normalize a full Factorio version (e.g. "2.1.24" or "2.0.76") to its major version ("2.1" or "2.0").
     * Per Factorio API docs, Factorio major versions consist of the first two numbers (major.minor).
     */
    public function normalizeFactorioMajorVersion(?string $version): string
    {
        if (!$version) {
            return '2.1';
        }

        if (preg_match('/(\d+\.\d+)/', $version, $matches)) {
            return $matches[1];
        }

        return '2.1';
    }

    /**
     * Check whether a mod release's factorio_version is compatible with the server's Factorio version.
     * Per https://lua-api.factorio.com/latest/auxiliary/mod-structure.html#factorio_version:
     * - Mods can only be compatible with one major version (first two numbers, e.g. "2.0" or "2.1").
     * - Missing factorio_version defaults to "0.12".
     * - Exception: mods with factorio_version "0.18" can also be loaded in "1.0".
     */
    public function isVersionCompatible(?string $modFactorioVersion, ?string $serverFactorioVersion): bool
    {
        $modMajor = $modFactorioVersion ? $this->normalizeFactorioMajorVersion($modFactorioVersion) : '0.12';
        $serverMajor = $this->normalizeFactorioMajorVersion($serverFactorioVersion);

        if ($modMajor === $serverMajor) {
            return true;
        }

        if ($serverMajor === '1.0' && $modMajor === '0.18') {
            return true;
        }

        return false;
    }

    /**
     * Fetch the latest stable or experimental Factorio version from factorio.com/api/latest-releases
     */
    public function getLatestFactorioVersion(bool $experimental = false): string
    {
        $branch = $experimental ? 'experimental' : 'stable';
        $cacheKey = $this->getCachePrefix() . "latest_factorio_release_{$branch}";

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->client->get(self::LATEST_RELEASES_URL, [
                'timeout' => 5,
                'connect_timeout' => 5,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $headlessVersion = $data[$branch]['headless']
                ?? $data['stable']['headless']
                ?? null;

            if (is_string($headlessVersion) && preg_match('/^(\d+\.\d+)/', $headlessVersion, $matches)) {
                $majorVersion = $matches[1];
                Cache::put($cacheKey, $majorVersion, self::CACHE_LATEST_VERSION);
                return $majorVersion;
            }
        } catch (\Exception $e) {
            Log::warning('Could not fetch latest Factorio release version: ' . $e->getMessage());
        }

        return '2.1';
    }

    /**
     * Normalize a mod's thumbnail path to a full assets-mod.factorio.com URL.
     * Per Factorio Mod Portal API docs: relative paths must be prepended with "https://assets-mod.factorio.com",
     * and "/assets/.thumb.png" indicates no thumbnail.
     */
    public function normalizeThumbnailUrl(?string $thumbnail): ?string
    {
        if (!$thumbnail || !is_string($thumbnail) || $thumbnail === '/assets/.thumb.png') {
            return null;
        }

        if (str_starts_with($thumbnail, 'http://') || str_starts_with($thumbnail, 'https://')) {
            return $thumbnail;
        }

        return self::ASSETS_BASE_URL . '/' . ltrim($thumbnail, '/');
    }

    /**
     * Normalize a mod entry returned from the API (fixing thumbnail URLs, source_url, etc.)
     */
    private function normalizeModEntry(array $mod): array
    {
        $mod['thumbnail'] = $this->normalizeThumbnailUrl($mod['thumbnail'] ?? null);

        if (empty($mod['source_url']) && !empty($mod['github_path']) && is_string($mod['github_path'])) {
            $ghPath = ltrim($mod['github_path'], '/');
            if (!str_starts_with($ghPath, 'http://') && !str_starts_with($ghPath, 'https://')) {
                $mod['source_url'] = 'https://github.com/' . $ghPath;
            } else {
                $mod['source_url'] = $ghPath;
            }
        }

        return $mod;
    }

    /**
     * Fetch and cache the compatible mod catalog for a specific Factorio major version
     * using the official `version` and `hide_deprecated=true` API parameters.
     */
    private function getModCatalog(?string $factorioVersion = null): ?array
    {
        $majorVersion = $this->normalizeFactorioMajorVersion($factorioVersion);
        $cacheKey = $this->getCachePrefix() . "catalog_{$majorVersion}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get(self::API_BASE, [
                'query' => [
                    'page_size' => 'max',
                    'hide_deprecated' => 'true',
                    'version' => $majorVersion,
                ],
                'timeout' => 25,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
                return null;
            }

            $catalog = [];
            foreach ($data['results'] as $mod) {
                if (!is_array($mod) || empty($mod['name'])) {
                    continue;
                }

                // Keep only the fields needed for browsing and searching to keep cache size compact
                $latestRelease = $mod['latest_release'] ?? null;
                $catalog[] = [
                    'name' => $mod['name'],
                    'title' => $mod['title'] ?? $mod['name'],
                    'owner' => $mod['owner'] ?? '',
                    'summary' => $mod['summary'] ?? '',
                    'downloads_count' => (int) ($mod['downloads_count'] ?? 0),
                    'category' => $mod['category'] ?? 'no-category',
                    'score' => $mod['score'] ?? 0,
                    'thumbnail' => $this->normalizeThumbnailUrl($mod['thumbnail'] ?? null),
                    'latest_release' => $latestRelease ? [
                        'version' => $latestRelease['version'] ?? null,
                        'released_at' => $latestRelease['released_at'] ?? null,
                        'download_url' => $latestRelease['download_url'] ?? null,
                        'file_name' => $latestRelease['file_name'] ?? null,
                        'info_json' => [
                            'factorio_version' => $latestRelease['info_json']['factorio_version'] ?? $majorVersion,
                        ],
                    ] : null,
                ];
            }

            // Pre-sort by downloads_count descending (most common sort order)
            usort($catalog, fn (array $a, array $b) => $b['downloads_count'] <=> $a['downloads_count']);

            $ttl = (int) config('factorio-mod-installer.cache_search_results', self::CACHE_MOD_LIST);
            Cache::put($cacheKey, $catalog, $ttl);

            return $catalog;
        } catch (GuzzleException $e) {
            Log::error('Error fetching Factorio mod catalog: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get paginated mods from the Factorio Mod Portal
     */
    public function getMods(
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        ?string $category = null,
        string $sortBy = 'downloads_count',
        string $sortOrder = 'desc',
        ?string $factorioVersion = null
    ): array {
        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), self::MAX_PAGE_SIZE);
        $majorVersion = $this->normalizeFactorioMajorVersion($factorioVersion);

        $catalog = $this->getModCatalog($majorVersion);

        if (is_array($catalog)) {
            $results = $catalog;

            if ($category) {
                $categoryLower = strtolower($category);
                $results = array_values(array_filter($results, function (array $mod) use ($categoryLower) {
                    return strtolower($mod['category'] ?? '') === $categoryLower;
                }));
            }

            if ($sortBy !== 'downloads_count' || $sortOrder !== 'desc') {
                $results = $this->sortResults($results, $sortBy, $sortOrder);
            }

            $totalCount = count($results);
            $offset = ($page - 1) * $pageSize;
            $pagedResults = array_slice($results, $offset, $pageSize);

            return [
                'pagination' => [
                    'count' => $totalCount,
                    'page' => $page,
                    'page_count' => (int) ceil($totalCount / $pageSize),
                    'page_size' => $pageSize,
                ],
                'results' => $pagedResults,
            ];
        }

        // Fallback to single page query if full catalog failed
        try {
            $apiSort = in_array($sortBy, ['name', 'created_at', 'updated_at'], true) ? $sortBy : 'updated_at';
            $response = $this->client->get(self::API_BASE, [
                'query' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'hide_deprecated' => 'true',
                    'version' => $majorVersion,
                    'sort' => $apiSort,
                    'sort_order' => $sortOrder === 'asc' ? 'asc' : 'desc',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $results = array_map([$this, 'normalizeModEntry'], $data['results'] ?? []);

            if ($category) {
                $results = array_values(array_filter($results, function ($mod) use ($category) {
                    return strtolower($mod['category'] ?? '') === strtolower($category);
                }));
            }

            $results = $this->sortResults($results, $sortBy, $sortOrder);

            return [
                'pagination' => $data['pagination'] ?? [
                    'count' => count($results),
                    'page' => $page,
                    'page_count' => 1,
                    'page_size' => $pageSize,
                ],
                'results' => $results,
            ];
        } catch (GuzzleException $e) {
            Log::error('Error fetching Factorio mods: ' . $e->getMessage());
            return $this->emptyResult($page, $pageSize);
        }
    }

    /**
     * Search for mods on the Factorio Mod Portal
     */
    public function searchMods(
        string $query,
        ?string $category = null,
        string $sortBy = 'downloads_count',
        string $sortOrder = 'desc',
        int $limit = 100,
        ?string $factorioVersion = null
    ): array {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return $this->emptyResult(1, $limit);
        }

        $majorVersion = $this->normalizeFactorioMajorVersion($factorioVersion);
        $cacheKey = $this->getCachePrefix() . 'search_' . md5("{$query}_{$category}_{$sortBy}_{$sortOrder}_{$limit}_{$majorVersion}");

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $catalog = $this->getModCatalog($majorVersion);
        if (!is_array($catalog)) {
            return $this->emptyResult(1, $limit);
        }

        $queryLower = mb_strtolower($query);
        $categoryLower = $category ? strtolower($category) : null;

        $matching = array_filter($catalog, function (array $mod) use ($queryLower, $categoryLower) {
            if ($categoryLower && strtolower($mod['category'] ?? '') !== $categoryLower) {
                return false;
            }

            $searchableText = mb_strtolower(implode(' ', [
                $mod['title'] ?? '',
                $mod['name'] ?? '',
                $mod['summary'] ?? '',
                $mod['owner'] ?? '',
            ]));

            return str_contains($searchableText, $queryLower);
        });

        $matching = array_values($matching);
        $matching = $this->sortResults($matching, $sortBy, $sortOrder);
        $matching = array_slice($matching, 0, $limit);

        $result = [
            'pagination' => [
                'count' => count($matching),
                'page' => 1,
                'page_count' => 1,
                'page_size' => count($matching),
            ],
            'results' => $matching,
        ];

        $ttl = (int) config('factorio-mod-installer.cache_search_results', self::CACHE_SEARCH_RESULTS);
        Cache::put($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * Fetch multiple mods at once using the official `namelist` parameter on /api/mods.
     * Returns an associative array keyed by mod name, including each mod's `releases` array.
     *
     * @param array<int, string> $modNames
     * @return array<string, array<string, mixed>>
     */
    public function getModsByNames(array $modNames): array
    {
        $modNames = array_values(array_unique(array_filter($modNames, fn ($n) => is_string($n) && $n !== '' && !in_array($n, self::BUILTIN_MODS, true))));
        if (empty($modNames)) {
            return [];
        }

        $results = [];
        $missing = [];
        $prefix = $this->getCachePrefix();

        foreach ($modNames as $name) {
            $cached = Cache::get($prefix . "mod_{$name}");
            if (is_array($cached)) {
                $results[$name] = $cached;
            } else {
                $missing[] = $name;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        // Fetch missing mods in chunks of 50 via namelist parameter
        $ttl = (int) config('factorio-mod-installer.cache_mod_details', self::CACHE_MOD_DETAILS);
        foreach (array_chunk($missing, 50) as $chunk) {
            try {
                $response = $this->client->get(self::API_BASE, [
                    'query' => [
                        'namelist' => implode(',', $chunk),
                        'page_size' => 'max',
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                foreach ($data['results'] ?? [] as $mod) {
                    if (is_array($mod) && !empty($mod['name'])) {
                        $normalized = $this->normalizeModEntry($mod);
                        Cache::put($prefix . "mod_{$mod['name']}", $normalized, $ttl);
                        $results[$mod['name']] = $normalized;
                    }
                }
            } catch (GuzzleException $e) {
                Log::error('Error fetching Factorio mods via namelist: ' . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Get popular mods (sorted by downloads)
     */
    public function getPopularMods(int $page = 1, int $pageSize = 50, ?string $factorioVersion = null): array
    {
        return $this->getMods($page, $pageSize, null, 'downloads_count', 'desc', $factorioVersion);
    }

    /**
     * Get mods by category
     */
    public function getModsByCategory(string $category, int $page = 1, int $pageSize = 50, ?string $factorioVersion = null): array
    {
        return $this->getMods($page, $pageSize, $category, 'downloads_count', 'desc', $factorioVersion);
    }

    /**
     * Get recently updated mods
     */
    public function getRecentMods(int $page = 1, int $pageSize = 50, ?string $factorioVersion = null): array
    {
        return $this->getMods($page, $pageSize, null, 'updated_at', 'desc', $factorioVersion);
    }

    /**
     * Get newest mods
     */
    public function getNewMods(int $page = 1, int $pageSize = 50, ?string $factorioVersion = null): array
    {
        return $this->getMods($page, $pageSize, null, 'created_at', 'desc', $factorioVersion);
    }

    /**
     * Sort results array by field
     */
    private function sortResults(array $results, string $sortBy, string $sortOrder): array
    {
        usort($results, function ($a, $b) use ($sortBy, $sortOrder) {
            if ($sortBy === 'updated_at' || $sortBy === 'released_at') {
                $aValue = $a['updated_at'] ?? $a['latest_release']['released_at'] ?? '';
                $bValue = $b['updated_at'] ?? $b['latest_release']['released_at'] ?? '';
            } else {
                $aValue = $a[$sortBy] ?? ($sortBy === 'downloads_count' ? 0 : '');
                $bValue = $b[$sortBy] ?? ($sortBy === 'downloads_count' ? 0 : '');
            }

            if ($sortBy === 'downloads_count') {
                $aValue = (int) $aValue;
                $bValue = (int) $bValue;
            }

            $comparison = is_numeric($aValue) && is_numeric($bValue)
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
     * Get detailed information about a specific mod.
     * Does NOT cache API failures/errors.
     *
     * @param string $modName The mod's machine-readable name
     * @param bool $full Whether to fetch full details (/api/mods/{name}/full)
     * @return array|null
     */
    public function getModDetails(string $modName, bool $full = false): ?array
    {
        $modName = trim($modName);
        if ($modName === '' || in_array($modName, self::BUILTIN_MODS, true)) {
            return null;
        }

        $cacheKey = $this->getCachePrefix() . "mod_{$modName}" . ($full ? '_full' : '');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $encodedName = rawurlencode($modName);
            $url = self::API_BASE . "/{$encodedName}" . ($full ? '/full' : '');
            $response = $this->client->get($url);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data) || isset($data['message'])) {
                return null;
            }

            $normalized = $this->normalizeModEntry($data);
            $ttl = (int) config('factorio-mod-installer.cache_mod_details', self::CACHE_MOD_DETAILS);
            Cache::put($cacheKey, $normalized, $ttl);

            return $normalized;
        } catch (GuzzleException $e) {
            Log::error("Error fetching Factorio mod details for {$modName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sort a mod's releases array newest-first by released_at timestamp
     */
    public function sortReleasesDesc(array $releases): array
    {
        usort($releases, function ($a, $b) {
            $timeA = strtotime($a['released_at'] ?? '1970-01-01');
            $timeB = strtotime($b['released_at'] ?? '1970-01-01');
            return $timeB <=> $timeA;
        });

        return $releases;
    }

    /**
     * Get the latest release information for a mod, optionally filtered by Factorio major version compatibility
     *
     * @param string $modName The mod's machine-readable name
     * @param string|null $factorioVersion Optional Factorio version to filter compatible releases
     * @param bool $full Whether to fetch full release info (including dependencies)
     * @return array|null
     */
    public function getLatestRelease(string $modName, ?string $factorioVersion = null, bool $full = false): ?array
    {
        $modDetails = $this->getModDetails($modName, $full);

        if (!$modDetails || empty($modDetails['releases']) || !is_array($modDetails['releases'])) {
            return null;
        }

        $releases = $this->sortReleasesDesc($modDetails['releases']);

        if ($factorioVersion !== null) {
            foreach ($releases as $release) {
                $releaseFactorioVersion = $release['info_json']['factorio_version'] ?? null;
                if ($this->isVersionCompatible($releaseFactorioVersion, $factorioVersion)) {
                    return $release;
                }
            }
            return null;
        }

        return $releases[0] ?? null;
    }

    /**
     * Get a specific release version of a mod
     *
     * @param string $modName The mod's machine-readable name
     * @param string $version The version to retrieve
     * @param bool $full Whether to include full info_json (with dependencies)
     * @return array|null
     */
    public function getModRelease(string $modName, string $version, bool $full = false): ?array
    {
        $modDetails = $this->getModDetails($modName, $full);

        if (!$modDetails || empty($modDetails['releases']) || !is_array($modDetails['releases'])) {
            return null;
        }

        foreach ($modDetails['releases'] as $release) {
            if (($release['version'] ?? null) === $version) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Parse a Factorio dependency string according to the official Factorio 2.0 specification:
     * https://lua-api.factorio.com/latest/auxiliary/mod-structure.html#dependencies
     *
     * Prefixes:
     * - `!`   -> incompatible
     * - `(?)` -> hidden-optional
     * - `?`   -> optional
     * - `+`   -> recommended (optional for auto-install, highlighted in UI)
     * - `~`   -> required-no-order (hard requirement that does not affect load order)
     * - none  -> required (hard requirement)
     *
     * @return array{name: string, type: string, is_required: bool, is_optional: bool, is_incompatible: bool, operator: string|null, version: string|null}|null
     */
    public function parseDependencyString(string $depString): ?array
    {
        $depString = trim($depString);
        if ($depString === '') {
            return null;
        }

        $type = 'required';
        if (str_starts_with($depString, '(?)')) {
            $type = 'hidden-optional';
            $depString = trim(substr($depString, 3));
        } elseif (str_starts_with($depString, '?!')) {
            $type = 'hidden-optional';
            $depString = trim(substr($depString, 2));
        } elseif (str_starts_with($depString, '?')) {
            $type = 'optional';
            $depString = trim(substr($depString, 1));
        } elseif (str_starts_with($depString, '!')) {
            $type = 'incompatible';
            $depString = trim(substr($depString, 1));
        } elseif (str_starts_with($depString, '+')) {
            $type = 'recommended';
            $depString = trim(substr($depString, 1));
        } elseif (str_starts_with($depString, '~')) {
            // In Factorio, `~` is a hard dependency that does not affect load order!
            $type = 'required';
            $depString = trim(substr($depString, 1));
        }

        $operator = null;
        $version = null;

        if (preg_match('/^([^\s>=<]+?)\s*(?:(<=|>=|=|<|>)\s*(\d+\.\d+\.\d+(?:\.\d+)?))?$/', $depString, $matches)) {
            $name = trim($matches[1]);
            $operator = !empty($matches[2]) ? $matches[2] : null;
            $version = !empty($matches[3]) ? $matches[3] : null;
        } else {
            $name = $depString;
        }

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'type' => $type,
            'is_required' => $type === 'required',
            'is_optional' => in_array($type, ['optional', 'hidden-optional', 'recommended'], true),
            'is_incompatible' => $type === 'incompatible',
            'operator' => $operator,
            'version' => $version ? ($operator ? "{$operator} {$version}" : $version) : null,
            'raw_version' => $version,
        ];
    }

    /**
     * Get download URL for a specific mod version
     */
    public function getDownloadUrl(string $downloadPath, ?string $username = null, ?string $token = null): string
    {
        $baseUrl = rtrim((string) config('factorio-mod-installer.api_base_url', self::BASE_URL), '/');
        $url = $baseUrl . '/' . ltrim($downloadPath, '/');

        if ($username && $token) {
            $url .= '?username=' . rawurlencode($username) . '&token=' . rawurlencode($token);
        }

        return $url;
    }

    /**
     * Download a mod file to a local destination path
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
                $url .= '?username=' . rawurlencode($username) . '&token=' . rawurlencode($token);
            }

            $response = $this->client->get($url, [
                'sink' => $destinationPath,
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Error downloading Factorio mod: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cached data for this plugin ONLY (without flushing the entire Pelican Panel cache)
     */
    public function clearCache(): void
    {
        $currentVersion = (int) Cache::get('factorio_mod_portal_cache_version', 1);
        Cache::put('factorio_mod_portal_cache_version', $currentVersion + 1, 60 * 60 * 24 * 365);
        Cache::put('factorio_cache_last_refresh', now(), 60 * 60 * 24 * 30);
    }

    /**
     * Get official categories from the Factorio Mod Portal API
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
     * Get cache information
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
