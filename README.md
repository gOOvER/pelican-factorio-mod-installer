# Factorio Mod Installer Plugin

A Pelican Panel plugin for managing Factorio mods directly from the server panel.

## Features

- **View Installed Mods** - See all mods currently installed on your server
- **Add Mods** - Add mods by entering their name from the Factorio Mod Portal
- **Remove Mods** - Remove mods from your server
- **Browse Mod Portal** - Search and browse mods from the Factorio Mod Portal directly in the panel
- **One-Click Install** - Add mods to your server directly from the mod browser
- **Mod Details** - View version, downloads count, and descriptions for each mod
- **Enable/Disable Mods** - Toggle mods on/off without removing them

## Requirements

Your server egg must have one of the following:

- Feature: `factorio_mod_installer`
- Tag: `factorio`

## Installation

### Via Panel Frontend (Recommended)

1. Go to your Pelican Panel admin area
2. Navigate to Plugins
3. Click the "Import" button
4. Upload the plugin zip file
5. The plugin will be automatically installed and enabled

### Manual Installation

1. Download and extract the plugin files
2. Place the `factorio-mod-installer` folder in your Pelican Panel's `plugins` directory (`/var/www/pelican/plugins` by default)
3. Run `php artisan migrate` to apply any database migrations (if applicable)
4. The plugin will automatically be discovered and loaded

### Server Egg Configuration

For the plugin to appear on Factorio servers, add one of the following to your server egg:

- Add `factorio` or `factorio-mod-installer` as a tag
- Add `factorio_mod_installer` to the egg features array

Example in egg configuration:
```json
{
  "tags": ["factorio"],
  "features": ["factorio_mod_installer"]
}
```

## Supported Languages

The plugin includes translations for:
- English (`en`)
- German (`de`)

The plugin automatically uses your Pelican Panel's language setting. If your panel language is not available, it will automatically fall back to English.

## Configuration

The plugin automatically works with Factorio's standard mod structure:

- **Mods Directory**: `<server_root>/mods/`
- **Mod List File**: `<server_root>/mods/mod-list.json`

The plugin will automatically create these if they don't exist.

## How It Works

Factorio servers use a `mod-list.json` file where mods are listed:

```json
{
  "mods": [
    {
      "name": "base",
      "enabled": true
    },
    {
      "name": "helmod",
      "enabled": true
    }
  ]
}
```

This plugin provides a user-friendly interface to manage this configuration and automatically downloads mods from the Factorio Mod Portal.

## Mod Portal Integration

The plugin integrates with the [Factorio Mod Portal API](https://wiki.factorio.com/Mod_portal_API) to:

- Fetch mod details (name, version, downloads, description)
- Search for mods by name
- Browse popular mods
- Display mod thumbnails and descriptions
- Download mod files directly to the server

Mod data is cached to improve performance:

- Individual mod details: 6 hours
- Mod portal search results: 15 minutes

## API Endpoints

The plugin provides the following REST API endpoints:

### List Installed Mods
```
GET /api/client/servers/{server}/factorio-mods
```

### Browse Mod Portal
```
GET /api/client/servers/{server}/factorio-mods/browse?query={search}&page={page}
```

### Get Mod Details
```
GET /api/client/servers/{server}/factorio-mods/{modName}
```

### Install Mod
```
POST /api/client/servers/{server}/factorio-mods
Body: { "mod_name": "helmod" }
```

### Remove Mod
```
DELETE /api/client/servers/{server}/factorio-mods/{modName}
```

### Toggle Mod Status
```
PATCH /api/client/servers/{server}/factorio-mods/{modName}/toggle
```

## Usage

### Installing a Mod

1. Navigate to your Factorio server in Pelican Panel
2. Open the "Factorio Mod Installer" tab
3. Click "Browse Mods" to search the mod portal
4. Click "Install" on any mod you want to add
5. The mod will be automatically downloaded and added to your server

Alternatively:

1. Click "Add Mod" in the Installed Mods tab
2. Enter the exact mod name (e.g., "helmod", "FNEI")
3. Click "Add" to download and install

### Removing a Mod

1. Go to the "Installed Mods" tab
2. Click the trash icon next to the mod you want to remove
3. Confirm the removal
4. The mod file and entry will be removed from your server

### Enabling/Disabling Mods

1. Go to the "Installed Mods" tab
2. Toggle the "Enabled" checkbox for any mod
3. The mod will remain installed but won't load in-game when disabled

## Troubleshooting

### Mod not found
- Verify the exact mod name on [mods.factorio.com](https://mods.factorio.com)
- Mod names are case-sensitive

### Download fails
- Check that the server has write permissions to the `mods/` directory
- Ensure the server has internet access to download from the Factorio Mod Portal

### Mods not loading in-game
- Make sure the server is restarted after installing/removing mods
- Check that mods are enabled in the `mod-list.json`
- Verify mod compatibility with your Factorio version

## Technical Details

### File Structure

```
factorio-mod-installer/
├── config/
│   └── factorio-mod-installer.php      # Plugin configuration
├── lang/
│   ├── en.json                         # English translations
│   └── de.json                         # German translations
├── resources/
│   └── views/
│       └── filament/
│           └── pages/
│               └── factorio-mod-installer.blade.php
├── src/
│   ├── Http/
│   │   └── Controllers/
│   │       └── FactorioModController.php    # API controller
│   ├── Pages/
│   │   └── FactorioModInstaller.php         # Filament page
│   ├── Providers/
│   │   └── FactorioModInstallerServiceProvider.php
│   ├── Services/
│   │   └── FactorioModPortalService.php     # Mod portal integration
│   ├── FactorioModInstallerPlugin.php       # Main plugin class
│   └── routes.php                           # API routes
├── plugin.json                              # Plugin metadata
├── update.json                              # Update information
└── README.md                                # This file
```

### Dependencies

- **Backend**: Laravel/PHP (Pelican Panel framework)
- **Frontend**: Blade templates with Filament components
- **API**: Factorio Mod Portal API
- **PHP**: >= 8.2
- **Pelican Panel**: >= 1.0 (beta)

### Caching

The plugin uses Laravel's caching system to minimize API calls:

- Mod details are cached for 6 hours
- Search results are cached for 15 minutes
- Cache can be cleared if needed

## Development

### Creating Updates

This plugin follows Pelican's plugin structure:

1. Main plugin class: `FactorioModInstallerPlugin.php` - Implements `Filament\Contracts\Plugin`
2. Service Provider: `FactorioModInstallerServiceProvider.php` - Auto-registered
3. Filament Page: `Pages/FactorioModInstaller.php` - Registered in the server panel
4. API Controller: `Http/Controllers/FactorioModController.php` - Handles API requests
5. Service Layer: `Services/FactorioModPortalService.php` - Factorio API integration

### Publishing

1. Update version in `plugin.json`
2. Update version and download URL in `update.json`
3. Remove the `meta` section from `plugin.json` if present
4. Zip the entire plugin folder
5. Create a GitHub release with the version tag (e.g., `v1.0.0`)
6. Upload the zip file to the release
7. Commit the updated `update.json` to the repository

The plugin includes automatic update checking via the `update_url` in `plugin.json`. When you release a new version:
- Update the version number in both `plugin.json` and `update.json`
- Create a GitHub release with the same version tag
- The download URL in `update.json` should point to the release zip file

## Contributing

Feel free to submit issues and enhancement requests!

## License

This plugin is provided as-is for use with Pelican Panel.

## Credits

- Inspired by the [Arma Reforger Workshop Plugin](https://github.com/pelican-dev/plugins/tree/main/arma-reforger-workshop)
- Uses the [Factorio Mod Portal API](https://wiki.factorio.com/Mod_portal_API)

## Support

For issues and questions:
- Check the Pelican Panel documentation
- Review the Factorio Mod Portal API documentation
- Submit an issue on the plugin repository
