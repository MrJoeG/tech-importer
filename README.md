# Tech Importer

WordPress plugin for capitaloftechs.com. Upload a content zip under Tools > Tech Importer, and it creates draft pages and posts with their Elementor layout, Yoast fields and images.

## Releasing a new version
1. Bump `Version:` and `VERSION` in `tech-importer/tech-importer.php`.
2. Build `dist/tech-importer-X.Y.Z.zip`. The folder inside the zip must be `tech-importer/`.
3. Update `update.json`: set `version` and `package`, and add a changelog entry.
4. Push to `main`. Sites check for updates every 6 hours (or right away from Dashboard > Updates > Check again).

## Updates to existing pages (1.0.5)
A zip's manifest.json can include a `changes` list. Each change is reviewed on the Tech Importer screen before anything is saved.
- `{"type": "link", "page": "slug", "find": "exact words", "url": "/target/"}` links the first match that isn't already a link.
- `{"type": "replace", "page": "slug", "find": "exact text", "replace": "new text or HTML", "note": "where", "paste": "fallback text"}` replaces text that appears exactly once.
`page` is the page or post slug, or `home`. Every applied change saves a backup, checks the live page, rolls back on failure, and gets an Undo button.
