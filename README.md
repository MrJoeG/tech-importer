# Tech Importer

WordPress plugin for capitaloftechs.com. Upload a content zip under Tools > Tech Importer, and it creates draft pages and posts with their Elementor layout, Yoast fields and images.

## Releasing a new version
1. Bump `Version:` and `VERSION` in `tech-importer/tech-importer.php`.
2. Build `dist/tech-importer-X.Y.Z.zip`. The folder inside the zip must be `tech-importer/`.
3. Update `update.json`: set `version` and `package`, and add a changelog entry.
4. Push to `main`. Sites check for updates every 6 hours (or right away from Dashboard > Updates > Check again).
