# BasicImageEditor

A MediaWiki extension for making basic edits to an already-uploaded JPG or PNG from its File: page: crop, rotate, flip, straighten, brightness and contrast. Editing happens in the browser, and the result is saved as a new version of the same file (or, optionally, as a new file) through MediaWiki's standard upload API.

The extension does not change the wiki's upload workflow and needs no configuration.

> **Status:** beta (0.1.0). All six editing tools and saving are implemented and pass the acceptance tests on MediaWiki 1.43 LTS. Release 1.0.0 will follow testing on the current stable MediaWiki release.

## Requirements

- MediaWiki 1.43 LTS or the current stable release
- Uploads enabled (`$wgEnableUploads = true;`)

## Installation

1. Put the extension in `extensions/BasicImageEditor`.
2. Add this line to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'BasicImageEditor' );
   ```

## Configuration

All settings are optional.

| Setting | Default | Purpose |
| --- | --- | --- |
| `$wgBasicImageEditorJpegQuality` | `92` | JPG encoding quality, 1 to 100 |
| `$wgBasicImageEditorMaxPixels` | `50000000` | Largest image, in pixels, that can be opened; larger files show no tab |
| `$wgBasicImageEditorAllowSaveAsNew` | `true` | Whether "New file" is offered in the save dialog |

Who may use the editor is controlled by MediaWiki's existing `upload`, `reupload` and `reupload-own` rights.

## Development

```sh
npm install && npm test             # JavaScript lint, message checks and QUnit tests (in Node)
composer install && composer lint   # PHP syntax check and file permissions
composer phpcs                      # PHP code style
```

`composer test` runs the PHP checks plus PHPUnit, so it needs the extension to sit in the `extensions/` folder of a MediaWiki installation (or `MW_INSTALL_PATH` set). PHPUnit can also be run from MediaWiki core:

```sh
composer phpunit:entrypoint -- extensions/BasicImageEditor/tests/phpunit
```

`make ci` runs the whole suite in Docker, the same way GitHub Actions does on every push and pull request (MediaWiki 1.43 and 1.46). It uses the `build` submodule (docker-compose-ci). `make composer-phan` runs Phan static analysis.

A headless-browser walkthrough of the editor against a development wiki is described in `tests/e2e/README.md`.

The design is described in `docs/notes/BasicImageEditor_Specification.md`.

## License

GPL-2.0-or-later. See `COPYING`.
