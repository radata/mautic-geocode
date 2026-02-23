# Mautic Geocoder Plugin

Mautic 7.x plugin that automatically geocodes contact addresses into latitude/longitude coordinates using [PDOK Locatieserver](https://api.pdok.nl/bzk/locatieserver/search/v3_1/ui/) (Dutch addresses) and [Nominatim/OpenStreetMap](https://nominatim.openstreetmap.org/) (international fallback).

## Features

- Adds custom fields to contacts on install: coordinates, address details, municipality/province codes
- Automatic geocoding when contacts are created or updated
- PDOK Locatieserver for Dutch addresses (free, no API key, official BAG data)
- Fills address fields from PDOK response: street, city, state, house number, municipality, province
- Nominatim/OSM as fallback for non-Dutch addresses
- Mautic REST API automatically accepts lat/lng on contact create/update
- CLI batch command for geocoding existing contacts
- **PDOK Lookup Card** on contact edit/new pages: postal code + house number + addition → search button fills all fields
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
  composer clear-cache && \
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer update hollandworx/mautic-geocoder -W --no-interaction --ignore-platform-req=ext-gd
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload

docker exec --user www-data --workdir /var/www/html mautic_web   php bin/console mautic:contacts:geocode --dry-run --limit=1

```

Fix browserslist deprecation warning (optional, run once after install):

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  npx update-browserslist-db@latest
```

### Versioning & Field Creation

Mautic only triggers the plugin's `ON_PLUGIN_UPDATE` event (which creates new custom fields) when the version in `Config/config.php` changes. **When adding new custom fields, always bump the version number** — otherwise `mautic:plugins:reload` will report "0 updated" and skip field creation.

```php
// Config/config.php
'version' => '1.2.0',  // bump this when adding fields
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
4. Custom fields are created automatically on install (see Custom Fields below)

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

## Custom Fields

### Contact Fields

| Field | Alias | Type | Visible | Source |
|---|---|---|---|---|
| House Number | `house_number` | text | Yes | Input / PDOK `huisnummer` |
| House Number Addition | `house_number_addition` | text | Yes | Input / PDOK `huisletter` |
| Street Name | `straatnaam` | text | No | PDOK `straatnaam` |
| Municipality Code | `gemeente_code` | text | No | PDOK `gemeentecode` |
| Municipality Name | `gemeente_naam` | text | No | PDOK `gemeentenaam` |
| Province Code | `provincie_code` | text | No | PDOK `provinciecode` |
| Latitude | `latitude` | number | No | Geocoded |
| Longitude | `longitude` | number | No | Geocoded |

### Company Fields

| Field | Alias | Type | Visible | Source |
|---|---|---|---|---|
| Company House Number | `companyhouse_number` | text | Yes | Input / PDOK `huisnummer` |
| Company House Number Addition | `companyhouse_number_addition` | text | Yes | Input / PDOK `huisletter` |
| Company Street Name | `companystraatnaam` | text | No | PDOK `straatnaam` |
| Company Municipality Code | `companygemeente_code` | text | No | PDOK `gemeentecode` |
| Company Municipality Name | `companygemeente_naam` | text | No | PDOK `gemeentenaam` |
| Company Province Code | `companyprovincie_code` | text | No | PDOK `provinciecode` |
| Company Latitude | `companylatitude` | number | No | Geocoded |
| Company Longitude | `companylongitude` | number | No | Geocoded |

Additionally, the plugin fills these **core Mautic fields** from PDOK results:

| Contact Field | Company Field | PDOK Source |
|---|---|---|
| `address1` | `companyaddress1` | `straatnaam` + `huisnummer` + `huisletter` (e.g. "Tappersweg 8D") |
| `city` | `companycity` | `woonplaatsnaam` |
| `state` | `companystate` | `provincienaam` (e.g. "Noord-Holland") |

Hidden fields can be made visible via **Settings > Custom Fields** in Mautic.

## Usage

### PDOK Lookup Card (Contact & Company Forms)

When editing or creating a contact or company, a **PDOK address lookup card** appears above the address fields. Enter a postal code, house number, and optional addition, then click the search button (or press Enter).

The card queries the PDOK Locatieserver and fills **all** address and coordinate fields in one shot:
- Core fields: `address1`/`companyaddress1`, `city`/`companycity`, `state`/`companystate`, `zipcode`/`companyzipcode`, `country`/`companycountry`
- Coordinates: `latitude`/`companylatitude`, `longitude`/`companylongitude`
- Detail fields: `house_number`, `straatnaam`, `gemeente_code`, `gemeente_naam`, `provincie_code` (and company equivalents)

A green confirmation card shows the resolved address with municipality and province. Click "Opnieuw zoeken" to search again.

The lookup card works independently of the auto-geocode setting — it calls the PDOK API directly from the browser (no server proxy needed). When you save the contact after a lookup, the auto-geocoder will see that coordinates already exist and skip re-geocoding.

### Automatic Geocoding

When enabled, the plugin listens to contact save events. If a contact has address fields (`address1`, `city`, `zipcode`, `country`) and no coordinates, it geocodes automatically. For Dutch addresses, it also fills all address detail fields from the PDOK response.

- Dutch addresses (country = Netherlands/Nederland/NL/empty) use PDOK
- Other addresses fall back to Nominatim (coordinates only, no address details)

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
│   ├── LeadSubscriber.php                # Auto-geocodes on contact save
│   └── PdokLookupSubscriber.php          # Injects PDOK lookup card on contact form
├── Service/
│   ├── ProviderInterface.php             # Geocoding provider contract
│   ├── GeocoderService.php               # Provider selection & rate limiting
│   ├── PdokProvider.php                  # PDOK Locatieserver API
│   └── NominatimProvider.php             # Nominatim/OSM API
├── Helper/
│   └── FieldInstaller.php                # Creates custom fields (coords, address details)
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
- **Returns**: Coordinates + full address details (street, municipality, province codes, etc.)

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

Note: Custom fields (contact: `latitude`, `longitude`, `straatnaam`, `gemeente_code`, `gemeente_naam`, `provincie_code`, `house_number`, `house_number_addition`; company: `companylatitude`, `companylongitude`, `companystraatnaam`, `companygemeente_code`, `companygemeente_naam`, `companyprovincie_code`, `companyhouse_number`, `companyhouse_number_addition`) will remain after uninstall. Remove them manually via **Settings > Custom Fields** if desired.

## Troubleshooting

### Log Files

Mautic logs are date-stamped PHP files:

```
/var/www/html/var/logs/mautic_prod-YYYY-MM-DD.php
```

Check for geocoder entries:

```bash
docker exec mautic_web grep -i geocod /var/www/html/var/logs/mautic_prod-$(date +%Y-%m-%d).php
```

### Plugin enabled but not geocoding

1. **Save feature settings**: Go to Settings > Plugins > Geocoder > **Features** tab and click **Save** (even without changes). Publishing the plugin alone does not persist default feature settings.

2. **Clear cache** after any plugin file changes:
   ```bash
   docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
   docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
   ```

3. **Verify subscriber is registered**:
   ```bash
   docker exec --user www-data --workdir /var/www/html mautic_web \
     php bin/console debug:event-dispatcher mautic.lead_post_save 2>/dev/null | grep -i geocod
   ```

4. **Test PDOK API directly**:
   ```bash
   curl -s "https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q=2031ET+8D+Haarlem&rows=1&fq=type:adres"
   ```

5. **Run batch command** to test the full pipeline:
   ```bash
   docker exec --user www-data --workdir /var/www/html mautic_web \
     php bin/console mautic:contacts:geocode --dry-run --limit=5
   ```

## License

MIT - see [LICENSE](LICENSE) for details.
