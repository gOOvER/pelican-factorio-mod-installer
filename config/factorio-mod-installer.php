<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Factorio Mod Installer Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the Factorio Mod Installer plugin.
    |
    */

    // Default mods directory relative to server root
    'mods_directory' => \env('FACTORIO_MODS_DIR', 'mods'),

    // Mod list filename
    'mod_list_file' => \env('FACTORIO_MOD_LIST_FILE', 'mod-list.json'),

    // Cache duration for mod details (in seconds)
    'cache_mod_details' => \env('FACTORIO_CACHE_MOD_DETAILS', 6 * 60 * 60), // 6 hours

    // Cache duration for search results (in seconds)
    'cache_search_results' => \env('FACTORIO_CACHE_SEARCH_RESULTS', 15 * 60), // 15 minutes

    // Factorio API base URL
    'api_base_url' => \env('FACTORIO_API_BASE_URL', 'https://mods.factorio.com'),
];
