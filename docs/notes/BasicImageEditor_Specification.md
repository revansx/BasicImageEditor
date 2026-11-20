# BasicImageEditor – Extension Spec (v1)

Nov 10, 2026 · Richard Evans · revised Nov 11, 2026

## Summary

BasicImageEditor lets a wiki user make basic edits to an already-uploaded JPG or PNG from its File: page, without downloading and re-uploading it. Editing happens in the browser; the result is saved as a new version of the same file through MediaWiki's standard upload API.

The extension adds no server-side image processing and no new database tables. MediaWiki's existing file history provides undo: every earlier version stays available and can be reverted to.

The extension does not take part in the wiki's upload workflow. Files reach the wiki however that wiki already handles uploads (Special:Upload, UploadWizard, a bot or anything else), and the extension is used only afterwards, on files already there. It does not change the upload form or upload process and requires no configuration change to the wiki or its web server.

## Scope

Version 1 covers six editing tools on JPG and PNG files, started from the File: page only.

**In scope**

- Crop, rotate in 90° steps, flip horizontally and vertically, straighten, brightness, contrast
- Save as a new version of the same file (default)
- Save as a new file (secondary option)
- Metadata stripped from the saved image

**Out of scope for v1**

- Any change to the wiki's upload workflow, upload form or upload configuration
- Resize. MediaWiki already scales images at display time.
- Other formats: GIF, SVG, TIFF, WebP, HEIC, PDF
- Files from remote repositories such as Wikimedia Commons via InstantCommons
- Editing from VisualEditor or during upload
- Mobile layout and touch support
- Filters, drawing, text, annotations, redaction
- An option to keep metadata

## Requirements and compatibility

The extension targets MediaWiki 1.43 LTS and the current stable release, and must not depend on any particular skin.

| Area | Requirement |
| --- | --- |
| MediaWiki | 1.43 LTS and the current stable release at the time of the 1.0.0 release; both are tested |
| PHP | Whatever the supported MediaWiki releases require (8.1 or later for 1.43) |
| Skins | Skin-independent; tested on Chameleon and Vector 2022 |
| Browsers | Current desktop Chrome, Firefox, Edge and Safari |
| Mobile | Not supported |
| File formats | JPG and PNG |
| Typical input | Phone camera photos and phone screenshots |
| Server dependencies | None beyond MediaWiki itself, with uploads enabled |
| Wiki configuration | No changes required, including none to the upload setup or to the web server serving images |
| Other extensions | None required |

## User flow

The user goes from the File: page to the editor and back in one round trip.

1. On an eligible File: page, the user clicks the "Edit image" tab.
2. The editor opens on `Special:BasicImageEditor/<file name>` and loads the current version of the file at full resolution.
3. The user applies any combination of tools. A live preview shows the result. "Reset" discards all changes.
4. The user clicks "Save". A dialog shows an editable summary, pre-filled from the tools used, and the choice between "New version of this file" (default) and "New file".
5. The extension uploads the result. On success the user is returned to the File: page, which shows the new version.
6. On failure the editor stays open with the edits intact and shows the reason.

"Cancel" returns to the File: page. If there are unsaved changes, the browser asks for confirmation first.

## Permissions and eligibility

The "Edit image" tab appears only when the user could overwrite the file through the normal upload form. The extension adds no user rights of its own.

All of these must be true:

- Uploads are enabled and the wiki is not read-only.
- The user is logged in, not blocked, and has the `upload` and `edit` rights.
- The user has `reupload`, or has `reupload-own` and uploaded the current version of the file. This is core MediaWiki's rule, which looks at the latest version, not the original upload.
- The File: page is not protected against upload for this user.
- The file is stored in the local repository, not a remote one.
- The file is a JPG or PNG, judged by its detected MIME type.
- The image is within the configured pixel limit.

The rights, block and protection checks call core MediaWiki's own upload permission checks (`UploadBase::isAllowed()`, `UploadBase::userCanReUpload()` and the `edit` and `upload` title permissions) instead of reimplementing them, so the extension always agrees with core. The checks use `probablyCan()` for the tab and `definitelyCan()` on the special page, not `authorizeWrite()`, so viewing a File: page never counts against upload rate limits.

The special page repeats these checks and shows an explanation if one fails, so a pasted link cannot bypass them. The save itself goes through the core upload API, which enforces rights and protection again on the server.

"Save as new file" needs only the `upload` right. In v1 it is still offered only on files where the tab is shown.

## Editing tools

All six tools work on a live preview and are applied to the full-resolution image only at save time.

| Tool | Behaviour | Controls |
| --- | --- | --- |
| Crop | Free-form rectangle, with optional fixed ratios (original, 1:1, 4:3, 16:9) | Drag handles; ratio selector |
| Rotate | 90° steps, left and right | Two buttons |
| Flip | Horizontal and vertical | Two buttons |
| Straighten | Fine rotation from −45° to +45° in 0.1° steps; the crop box shrinks so no empty corners are saved | Slider with a grid overlay |
| Brightness | −100 to +100, 0 = unchanged | Slider |
| Contrast | −100 to +100, 0 = unchanged | Slider |

**Processing order**

The user can use the tools in any order, but the image is always processed in this fixed order, both for the preview and at save time:

1. EXIF orientation (applied on load)
2. Flip
3. Rotate in 90° steps
4. Straighten
5. Crop
6. Brightness
7. Contrast

The crop rectangle is defined on the image after steps 1–4, so it stays on the area the user selected.

**Straighten**

Cropper.js rotates the image but does not keep empty corners out of the crop. Whenever the rotation, flip or crop ratio changes, the extension sets the crop box to the largest rectangle of the chosen ratio that fits inside the rotated image, centred. Moving or resizing the crop box past the image edge is undone, so no empty corners can be saved.

**Flip**

Flips act along the screen's axes, as the user sees the image, also after rotating. Internally a flip is stored in the image's own frame and applied before rotation, so a flip after a rotation also reverses the rotation's direction; the straighten slider changes sign accordingly.

**Brightness and contrast**

Each setting is applied per colour channel, brightness first: brightness adds up to ±128 to each value; contrast multiplies the distance from mid-grey (128) by a factor from about 0.33 at −100 to about 2.97 at +100. Alpha is unchanged.

**General behaviour**

- "Reset" returns to the image as loaded. There is no step-by-step undo in v1.
- "Save" is disabled until at least one tool has changed something, so an untouched image is never re-encoded.
- All controls are reachable by keyboard and carry labels for screen readers. With the image area focused, the arrow keys move the crop box and Shift with the arrow keys resizes it.

## Saving

Saving overwrites the file with a new version by default; the previous version stays in the file history.

**Save targets**

- *New version of this file* (default): uploaded under the same name. The file description page is not changed.
- *New file*: the user enters a new name with the same extension. The new description page gets a verbatim copy of the source file's description page content, so its description, licence templates and categories carry over, followed by the line `Derived from [[:File:<source name>]]`. If the name is already taken, the save is refused and the user picks another name; an existing file is never overwritten from this option.

**Output format**

- The format always matches the source: JPG stays JPG, PNG stays PNG.
- JPG is encoded at the configured quality (default 92). PNG is lossless.
- PNG transparency is kept. JPG has none, so corners exposed by straightening are always cropped away.
- Output is in the sRGB colour space.

**Metadata**

- The saved image contains no EXIF, GPS, IPTC or XMP data. This is always on in v1.
- Orientation stored in EXIF is applied to the pixels on load, so the saved image is upright without it.
- Earlier versions in the file history keep their metadata. Removing it there requires an administrator to delete those versions.

**Edit summary**

- Pre-filled from the tools used, followed by a note naming the extension, for example "Cropped, straightened, brightness adjusted (edited with BasicImageEditor)". This applies to both a new version and a new file. The user can edit all of it, including removing the note.
- The upload carries the change tag `basicimageeditor`, so these uploads can be filtered in logs and recent changes.

**Conflicts**

- The editor records the file's version timestamp on load. Before saving it checks again.
- If the file has changed in between, the user chooses between overwriting anyway, saving as a new file, or cancelling.
- A small window remains between this check and the upload, because the upload API has no edit-conflict parameter for files. This is accepted for v1.

**Limits and errors**

- The result is uploaded in chunks, so PHP's request size limits do not apply. The limit that applies is MediaWiki's maximum upload size for file uploads.
- If the result exceeds that limit, the save is refused before uploading, with a message stating the limit.
- Upload errors and warnings from MediaWiki are shown as returned. The editor state is kept so the user can retry.

**After saving**

- MediaWiki regenerates thumbnails for the new version itself.
- The user is returned to the File: page with a forced refresh of the displayed image.

## Technical design

The extension is a thin PHP layer for the tab, the special pages and the eligibility checks, plus a JavaScript editor that does all image work in the browser.

**Server side (PHP)**

- `extension.json` with manifest version 2, loaded by `wfLoadExtension( 'BasicImageEditor' )`, declaring `"requires": { "MediaWiki": ">= 1.43.0" }` and Richard Evans as author.
- A handler for the `SkinTemplateNavigation::Universal` hook adds the "Edit image" tab to File: pages. Using the standard hook is what keeps it skin-independent.
- `SpecialBasicImageEditor` renders the editor container and passes file details to the front end: name, source URL, MIME type, dimensions, version timestamp, upload size limit, and the source description page's wikitext for "New file".
- `SpecialBasicImageEditorFile`, an unlisted special page, serves the bytes of the current version of a file from the wiki's own domain. It is read-only, checks that the user may read the file page, and sends the file through MediaWiki's own file streaming with private caching. The editor always loads the image through it, so pixel access works even on wikis that serve images from another domain, with no CORS headers or other configuration.
- An eligibility service holds the checks from "Permissions and eligibility" and is used by the hook and both special pages.
- Handlers for `ListDefinedTags` and `ChangeTagsListActive` register the change tag, and a handler for `ChangeTagsAllowedAdd` allows users to apply it through the upload API.
- No API module, no database schema, no maintenance scripts.

**Client side (JavaScript)**

- One ResourceLoader module, loaded only on the editor special page.
- Interface built with Codex, the component library shipped with MediaWiki 1.43.
- Cropper.js 1.x (1.7.0, MIT licence), bundled in `resources/lib/cropperjs/`, provides crop, rotation, flip and straighten. It runs with `checkOrientation: false`, because browsers already apply EXIF orientation when drawing the image and Cropper would otherwise rotate it a second time.
- Brightness and contrast use the extension's own pixel arithmetic. The live preview runs the same code on a downscaled copy of the image, so the preview always matches the saved result.
- The image is loaded through `SpecialBasicImageEditorFile` and exported with `canvas.toBlob()` in the source format.
- Saving uses chunked upload through `mw.Api`, after the conflict check via `prop=imageinfo`:
  - *New version*: `ignorewarnings` is set, since overwriting is intended.
  - *New file*: `ignorewarnings` is not set. An `exists` warning refuses the save and asks for another name. Other warnings, such as a duplicate of an existing file, are shown and the user can continue or cancel.

**Repository layout**

```
BasicImageEditor/
  extension.json
  includes/        Hooks, SpecialBasicImageEditor, SpecialBasicImageEditorFile, EligibilityChecker
  resources/       editor module, styles
  resources/lib/   Cropper.js
  i18n/            en.json, qqq.json
  tests/           phpunit/, node-qunit/, e2e/
  README.md, COPYING
```

**Licence and translation**

- GPL-2.0-or-later, the same as MediaWiki core.
- All interface text is in message files so it can be translated through translatewiki.net.

## Configuration

The extension works with no configuration; three optional settings in `LocalSettings.php` adjust it.

| Setting | Default | Purpose |
| --- | --- | --- |
| `$wgBasicImageEditorJpegQuality` | `92` | JPG encoding quality, 1 to 100 |
| `$wgBasicImageEditorMaxPixels` | `50000000` | Largest image, in pixels, that can be opened; larger files show no tab |
| `$wgBasicImageEditorAllowSaveAsNew` | `true` | Whether "New file" is offered in the save dialog |

Who may use the editor is controlled by MediaWiki's existing `upload`, `reupload` and `reupload-own` rights.

## Risks and known limitations

None of these block v1, but each needs to be stated in the documentation.

| Item | Effect | Handling in v1 |
| --- | --- | --- |
| JPG re-encoding | Each save loses a little quality; repeated edits add up | High default quality; "Save" disabled when nothing changed; documented |
| PNG file size | The browser writes PNGs as full-colour 8-bit RGBA with modest compression, so palette-based screenshots can grow several times in size | Size checked against the upload limit before saving; documented |
| Overwrite affects every use | A crop changes the image, and often its shape, on all pages that embed it | Save dialog states that all uses change; "New file" offered |
| Copied description | "New file" copies the source page as it is, including templates such as "Own work" or a fixed author that may not fit a derivative | The added "Derived from" line records the source; the user can edit the page after saving; documented |
| Metadata in old versions | The original upload keeps its EXIF and GPS data in the file history | Documented; administrators can delete old versions |
| Colour | Wide-gamut phone photos are converted to sRGB and may look slightly less vivid | Accepted; documented |
| Stale images | Browsers and caching proxies may show the old image for a while after an overwrite | Forced refresh on the File: page; other pages rely on normal cache expiry |
| Very large images | Browsers limit canvas size and memory | Pixel limit setting; clear message above it |
| Files on another domain | Browsers block pixel access to images from another domain without CORS headers | Image loaded through `SpecialBasicImageEditorFile` on the wiki's own domain; no setup needed |
| Chameleon layout | Chameleon places page tabs through its own layout files | Tested early; documented if a layout change is needed |
| Cropper.js 1.x | The library's newer 2.x line is a rewrite; 1.x may stop receiving updates | Library is bundled and isolated behind one module, so it can be replaced |

## Testing and acceptance criteria

Version 1 is done when every item below passes on MediaWiki 1.43 LTS and on the current stable release, each with the Chameleon and Vector 2022 skins.

**Acceptance criteria**

- [ ] The tab appears on an eligible JPG and PNG File: page, and on no other page.
- [ ] The tab is absent for logged-out users, users without `reupload`, protected files, remote files and unsupported formats.
- [ ] Opening the special page directly for an ineligible file shows an explanation, not the editor.
- [ ] Each of the six tools changes the saved image as shown in the preview.
- [ ] A portrait phone photo with EXIF orientation opens upright and saves upright.
- [ ] Saving creates a new version; the old version is listed in the file history and can be reverted to.
- [ ] The saved file contains no EXIF or GPS data.
- [ ] A PNG with transparency keeps it after editing.
- [ ] "New file" creates a separate file and leaves the source untouched.
- [ ] The new file's description page contains the source page's content followed by the "Derived from" line.
- [ ] "New file" with a name that already exists is refused and does not overwrite that file.
- [ ] If the file changes while the editor is open, the conflict dialog appears on save.
- [ ] A result above the upload size limit is refused with a message naming the limit.
- [ ] A failed upload leaves the editor open with edits intact.
- [ ] The upload appears in the upload log with the `basicimageeditor` tag.
- [ ] The editor works on a wiki that serves images from a different domain, with no CORS or other configuration change.
- [ ] A 12-megapixel photo loads, edits and saves in current Chrome, Firefox, Edge and Safari.

**Automated tests**

- PHPUnit: eligibility checks for each condition; tab present or absent per case; the file endpoint refuses users who may not read the file.
- QUnit: brightness and contrast arithmetic; processing order; the straighten crop rectangle; summary text generation; description text for "New file"; conflict detection.
- Code style: MediaWiki's standard PHP and JavaScript lint configurations.

## Publishing

Publishing needs a public GitHub repository and a page on mediawiki.org.

**Decisions**

- Code is hosted on GitHub.
- Richard Evans is named as author and maintainer in `extension.json`.
- v1 supports MediaWiki 1.43 LTS and the current stable release at the time of 1.0.0.

**Publishing checklist**

- [x] Confirm on mediawiki.org that no extension named BasicImageEditor exists. Two web searches found none.
- [x] Create the public GitHub repository with README and COPYING. Published 2026-11-12 at https://github.com/revansx/BasicImageEditor.
- [x] Create `Extension:BasicImageEditor` on mediawiki.org with the extension infobox, installation steps, settings, supported MediaWiki versions and the limitations listed above. Created 2026-11-16; limitations section added 2026-11-20.
- [ ] Tag release 1.0.0.
- [ ] Optionally register with translatewiki.net for translations.

**Candidates for later versions**

- Resize, step-by-step undo, an option to keep metadata, an entry point from VisualEditor, mobile support, more formats.
