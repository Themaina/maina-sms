# Contributing

Thanks for helping. Issues and pull requests are welcome.

1. Fork the repository and branch from `main`.
2. Keep changes general: no site-specific names, domains, phone numbers or currencies in code. Put anything site-specific behind a setting.
3. Run `php -l` on changed files (CI does this on PHP 8.2 to 8.5) and run the tests if the plugin has them.
4. Add a line to `CHANGELOG.md` under "Unreleased".
5. Open a pull request describing what changed and how you tested it on a Botble site.

## Releasing (maintainers)

1. Move the "Unreleased" notes in `CHANGELOG.md` under the new version.
2. Bump `version` in `plugin.json`.
3. Create a GitHub release with tag `X.Y.Z` (same as `plugin.json`). The release workflow checks the version, builds the zip and attaches it. The Botble Marketplace picks up the new release.
