# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] - 2026-01-10

### Fixed
- **Update Check Compatibility**: Updates are now only shown if the new mod version is compatible with the server's Factorio version
  - Previously, updates were shown even when the new version required a different Factorio version
  - Now only compatible releases are considered when checking for updates

## [1.2.0] - 2026-01-10

### Added
- **Factorio Version Display**: Server's Factorio version is now shown in the tab bar
  - Shows green badge for stable versions, orange badge for experimental builds
  - Displays "Version unknown" if version cannot be detected
- **Complete Localization**: All UI text is now translatable
  - Added 20+ new translation keys for installed mods tab
  - Filter tabs (All, Enabled, Disabled, Updates)
  - Sort options (Name, Status, Updates)
  - Action buttons (Update, Portal, Delete)
  - Status badges (Enabled, Disabled, Base Game, Update)
  - Confirmation dialogs and messages

### Changed
- **Complete UI Overhaul**: Redesigned Browse Mods and Installed Mods tabs
  - Browse Mods now uses modern card grid layout (1/2/3 columns responsive)
  - Each mod displayed as Filament Section card with thumbnail, title, badges
  - Downloads badge with icon, category badge with color coding, version badge
  - Install button (warning color) and Details button (gray outlined) per card
  - Improved spacing and visual hierarchy throughout
- **Installed Mods Tab**: Enhanced with filter bar and better mod cards
  - Sticky filter bar with search, filter tabs, sort dropdown
  - Mod cards show thumbnail, version badge, status badges
  - Update info section with changelog link when updates available
  - Cleaner button layout in card footer
- **API Service Optimization**: Complete rewrite of `FactorioModPortalService`
  - New `getMods()` method for paginated mod fetching with caching (30 min)
  - Optimized `searchMods()` now limited to 20 pages (2000 mods) instead of 50 pages
  - Added `getModsByCategory()` for direct category filtering
  - Added `getRecentMods()` and `getNewMods()` helper methods
  - Improved sorting with dedicated `sortResults()` helper
  - Cleaner code structure with `emptyResult()` helper
  - Added `getCategories()` method returning all available categories with colors
  - Replaced `getCacheStats()` with simpler `getCacheInfo()`
- **Browse Mods Loading**: Now uses category-aware API methods
  - Direct category filtering via API instead of client-side filtering
  - Better performance when browsing by category
- **UI Polish**:
  - Removed oversized inbox icon from "No mods installed" message
  - Tabs and Factorio version now share the same container

### Fixed
- **Missing Translations**: Fixed "Update verfügbar" showing German text in English locale
  - All hardcoded German text replaced with proper translation calls
- **Translation Keys**: Added missing keys for filter/sort dropdowns

### Technical
- **Cache Durations**:
  - Mod list: 30 minutes (was 15 minutes for search)
  - Mod details: 6 hours (unchanged)
  - Search results: 15 minutes (unchanged)
- **API Limits**:
  - Default page size: 50 mods
  - Max page size: 100 mods
  - Search limit: 20 pages (2000 mods max)
  - Results per search: 100 mods max

## [1.1.2] - 2026-01-09

### Fixed
- **Mod Update Function**: Fixed critical issues preventing mods from being updated correctly
  - Added version compatibility check before updating to prevent incompatible mod versions
  - Improved error handling when deleting old mod files - updates now abort if old files cannot be deleted
  - Fixed issue where multiple mod versions could exist simultaneously
  - Enhanced `updateAllMods()` to use same logic as single mod updates
  - Better error reporting showing which mods updated successfully and which failed
- **Mod Removal**: Improved `removeMod()` to delete ALL files of a mod (in case multiple versions exist)
- **Logging**: Added detailed logging throughout update process for better error diagnosis
- **User Notifications**: More informative notifications including version numbers and specific error messages

### Changed
- Update process now stops and shows error if old mod files cannot be deleted (instead of continuing silently)
- `updateAllMods()` now reports detailed results including failed mods

## [1.1.1] - 2026-01-08

### Fixed
- **Navigation Visibility**: Plugin is now correctly hidden from servers without required tags or features
  - Fixed `canAccess()` method returning true for all servers
  - Plugin now only appears when egg name/tags contain "factorio" or features include "factorio_mod_installer"

## [1.1.0] - 2026-01-08

### Added
- **Direct Install Tab**: New dedicated tab for installing mods directly by name
  - Step-by-step instructions for finding mod names
  - Visual example with URL format
  - Important notes section with installation guidelines
- **Enable All/Disable All**: Checkbox in table header to toggle all mods at once
- **Update All Button**: Single button to update all mods with available updates
- **Cache Statistics**: Display cache info with formatted numbers and relative timestamps
  - Total cached mods count
  - Cache age with human-readable format
- **Statistics Bar**: Shows total installed mods and available updates count
- **Table-based Layout**: Complete redesign of mod listings
  - Installed Mods: 4-column table (Mod Name, Version, Enabled, Actions)
  - Browse Mods: 7-column table (Thumbnail, Mod Name, Version, Category, Author, Downloads, Actions)
  - Proper semantic HTML structure with thead/tbody
  - Hover effects and better readability
- **Category Filtering**: All categories filter option in Browse Mods tab

### Changed
- **Complete UI Overhaul**: Redesigned entire interface to match official Pelican Panel plugins
  - Modern card-based layout with proper spacing
  - Improved Dark Mode support throughout
  - Better visual hierarchy with borders and dividers
  - Consistent use of Filament components
- **Mod Display**: Converted from list-based to professional table layout
  - Better column organization
  - Improved action button placement
  - Cleaner mod information display
- **Search Functionality**: Moved to more prominent position with better styling
- **Modal Dialogs**: Improved Add Mod and Mod Details modals

### Removed
- **Non-compliant Code**: Removed code violating Pelican Panel standards
  - Manual service provider loading
  - `routes.php` file (routes now properly registered in ServiceProvider)
  - `Http/Controllers` directory (logic moved to Livewire components)
- **Redundant Status Column**: Removed from Installed Mods table (update info shown in actions)

### Fixed
- **"Class Log not found" Error**: Fixed by using proper `Illuminate\Support\Facades\Log` import
- **Blade Syntax Errors**: Multiple cleanup passes to remove duplicate code fragments
- **Dark Mode Issues**: Fixed contrast and visibility issues in dark theme
- **Indentation Issues**: Corrected mod list indentation and alignment
- **Cache Display**: Fixed cache statistics to use correct property names

### Technical
- **Pelican Panel Compliance**: Plugin now fully complies with Pelican Panel standards
  - Uses FilamentPHP auto-discovery
  - Proper Livewire component structure
  - No manual loading required
- **Backend Methods**:
  - Added `toggleAllMods()` method to enable/disable all mods
  - Improved mod state management
  - Better error handling throughout

## [1.0.0] - Initial Release

### Added
- Initial plugin release for Pelican Panel
- Browse and install mods from Factorio Mod Portal
- Manage installed mods (enable/disable/remove)
- Search and filter functionality
- Mod details view
- Integration with Factorio Mod Portal API
- Support for mod dependencies
- Multi-language support (English, German)

[1.1.1]: https://github.com/gOOvER/pelican-factorio-mod-installer/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/gOOvER/pelican-factorio-mod-installer/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gOOvER/pelican-factorio-mod-installer/releases/tag/v1.0.0
