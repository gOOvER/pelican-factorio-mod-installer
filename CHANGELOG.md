# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.0]: https://github.com/gOOvER/pelican-factorio-mod-installer/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gOOvER/pelican-factorio-mod-installer/releases/tag/v1.0.0
