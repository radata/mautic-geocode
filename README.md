# Mautic Geocoder Plugin

Mautic 7.x plugin that automatically geocodes contact addresses into latitude/longitude coordinates using [PDOK Locatieserver](https://api.pdok.nl/bzk/locatieserver/search/v3_1/ui/) (Dutch addresses) and [Nominatim/OpenStreetMap](https://nominatim.openstreetmap.org/) (international fallback).

## Features

- Adds `latitude` and `longitude` custom fields to contacts on install
- Automatic geocoding when contacts are created or updated
- PDOK Locatieserver for Dutch addresses (free, no API key, official BAG data)
- Nominatim/OSM as fallback for non-Dutch addresses
- Mautic REST API automatically accepts lat/lng on contact create/update
- CLI batch command for geocoding existing contacts
- Configurable: provider selection, rate limiting, overwrite policy
- Loop prevention on contact save events

## Requirements

- Mautic 7.x (Docker FPM image)
- PHP 8.1+

No API keys required. Both PDOK and Nominatim are free public services.

## Installation

### Via Composer (Docker)

Ensure the composer and npm directories exist with correct permissions:

```bash
docker exec --user root mautic_web mkdir -p /var/www/.composer/cache
docker exec --user root mautic_web chown -R www-data:www-data /var/www/.composer
docker exec --user root mautic_web mkdir -p /var/www/.npm
docker exec --user root mautic_web chown -R www-data:www-data /var/www/.npm
```

Allow dev packages (only needed once per Mautic installation):

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config minimum-stability dev
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config prefer-stable true
```

Add the GitHub repository and install the plugin:

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config repositories.mautic-geocode vcs \
  https://github.com/radata/mautic-geocode --no-interaction
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer require hollandworx/mautic-geocoder:dev-main \
  -W --no-interaction --ignore-platform-req=ext-gd
```

Update to the latest version:

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer update hollandworx/mautic-geocoder \
  -W --no-interaction --ignore-platform-req=ext-gd
```

### Post-Installation

Clear cache, reload plugins, then enable in UI:

```bash
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

1. Go to **Settings > Plugins > Geocoder - Address to Lat/Lng**
2. Set **Published** to **Yes**
3. Configure features (see Configuration below)
4. The `latitude` and `longitude` fields are created automatically on install

## Configuration

In the plugin settings (Features tab):

| Field | Description | Default |
|---|---|---|
| **Enable automatic geocoding** | Geocode addresses on contact create/update | Enabled |
| **Primary geocoding provider** | PDOK (Dutch) or Nominatim (International) | PDOK |
| **Use Nominatim for non-Dutch addresses** | Fall back to Nominatim when PDOK is primary | Enabled |
| **Nominatim User-Agent** | Required by Nominatim usage policy | `MauticGeocoder/1.0` |
| **Rate limit (milliseconds)** | Delay between API calls in batch mode | `1100` |
| **Overwrite existing coordinates** | Re-geocode when address changes | Disabled |
| **Dutch country values** | Country values routed to PDOK | `Netherlands,Nederland,NL,` |

## Usage

### Automatic Geocoding

When enabled, the plugin listens to contact save events. If a contact has address fields (`address1`, `city`, `zipcode`, `country`) and no coordinates, it geocodes automatically.

- Dutch addresses (country = Netherlands/Nederland/NL/empty) use PDOK
- Other addresses fall back to Nominatim

### API Upload with Coordinates

Once installed, the Mautic API accepts `latitude` and `longitude` directly:

```bash
curl -X POST https://your-mautic.com/api/contacts/new \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "firstname=Jimmy" \
  -d "lastname=Van Drongelen" \
  -d "address1=Lotusbloemweg 88" \
  -d "zipcode=1338 ZD" \
  -d "city=Almere" \
  -d "country=Netherlands" \
  -d "latitude=52.38744000" \
  -d "longitude=5.28760000"
```

If you provide lat/lng via the API, the plugin will not overwrite them (unless "Overwrite existing" is enabled).

### Batch Geocoding (CLI)

Geocode existing contacts that are missing coordinates:

```bash
# Dry run - see what would be geocoded
docker exec --user www-data --workdir /var/www/html mautic_web \
  php bin/console mautic:contacts:geocode --dry-run --limit=10

# Geocode in batches of 100
docker exec --user www-data --workdir /var/www/html mautic_web \
  php bin/console mautic:contacts:geocode --batch=100

# Force re-geocode all contacts (including those with coordinates)
docker exec --user www-data --workdir /var/www/html mautic_web \
  php bin/console mautic:contacts:geocode --force --limit=500
```

Options:

| Option | Description | Default |
|---|---|---|
| `--batch`, `-b` | Contacts per batch | `100` |
| `--limit`, `-l` | Max contacts to process (0 = all) | `0` |
| `--force`, `-f` | Re-geocode contacts with existing coordinates | Off |
| `--dry-run` | Preview without changes | Off |

## Plugin Structure

```
plugins/MauticGeocoderBundle/
├── Config/
│   ├── config.php                        # Plugin metadata
│   └── services.php                      # Autowired service registration
├── DependencyInjection/
│   └── MauticGeocoderExtension.php       # Loads services.php
├── Integration/
│   └── GeocoderIntegration.php           # Settings UI
├── EventListener/
│   ├── PluginSubscriber.php              # Creates custom fields on install
│   └── LeadSubscriber.php                # Auto-geocodes on contact save
├── Service/
│   ├── ProviderInterface.php             # Geocoding provider contract
│   ├── GeocoderService.php               # Provider selection & rate limiting
│   ├── PdokProvider.php                  # PDOK Locatieserver API
│   └── NominatimProvider.php             # Nominatim/OSM API
├── Helper/
│   └── FieldInstaller.php                # Creates latitude/longitude fields
├── Command/
│   └── GeocodeContactsCommand.php        # CLI batch geocoding
├── Translations/en_US/messages.ini
├── MauticGeocoderBundle.php              # Bundle class
└── composer.json
```

## Geocoding Providers

### PDOK Locatieserver (Primary)

- **Coverage**: Netherlands only (official BAG/Kadaster data)
- **Cost**: Free, no API key
- **Accuracy**: Excellent for Dutch postal codes and addresses
- **Query format**: `{zipcode} {housenumber} {city}`
- **API**: `https://api.pdok.nl/bzk/locatieserver/search/v3_1/free`

### Nominatim / OpenStreetMap (Fallback)

- **Coverage**: Worldwide
- **Cost**: Free (1 request/second limit)
- **Accuracy**: Good (community-maintained data)
- **Query format**: `{address}, {city}, {zipcode}, {country}`
- **API**: `https://nominatim.openstreetmap.org/search`
- **Requires**: User-Agent header (configurable in settings)

## Uninstall

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer remove hollandworx/mautic-geocoder -W --no-interaction
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config --unset repositories.mautic-geocode
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

Note: The `latitude` and `longitude` custom fields will remain after uninstall. Remove them manually via **Settings > Custom Fields** if desired.

## License

MIT - see [LICENSE](LICENSE) for details.
