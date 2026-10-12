# Content Freeze Mode — v0.3.0 (staging prototype)

## New in 0.3
- Checks the plugin's Version header on GitHub's `main` branch every six hours through WordPress's plugin update checks. Newer versions appear in **Plugins** and **Dashboard → Updates**, with the standard **Update now** action.
- Downloads updates from this repository's `main` ZIP and preserves the installed plugin directory name. GitHub/network failures retry after 15 minutes and do not block normal plugin use.
- Use **Dashboard → Updates → Check again** to bypass the GitHub cache. Only users allowed to update plugins can force this check.
- To publish an update, increase both the plugin header version and `CFM_Content_Freeze::VERSION`, then push the complete working plugin to `main`. Install 0.3.0 manually once to enable future GitHub updates. Checks contact `raw.githubusercontent.com`; updates download from GitHub.

Upload `content-freeze-mode.zip` via **Plugins → Add New → Upload Plugin**. Activate, then go to **Settings → ❄ Content Freeze**. Only users with `manage_options` (normally administrators) can view/save configuration. On first activation, freeze is enabled for posts and pages. Existing preferences remain in place on reactivation.

## New in 0.2
- Enable/disable the freeze without deactivating the plugin.
- Automatic thaw after a configurable number of hours; displayed in WordPress's local timezone. WP-Cron is scheduled, and freeze checks also stop denying writes once expiry has passed even if cron has not run. The plugin updates its saved state on the next `init` request.
- Freezes custom taxonomies, categories and tags associated with a frozen content type. **Note:** shared taxonomies are frozen globally, including if used by an otherwise unfrozen type.
- Protects post metadata/custom fields via WordPress metadata filters and post mutations via `wp_insert_post_data`, plus capability checks and standard REST content routes.
- When posts or pages are frozen, also freezes reusable/synced blocks (`wp_block`), site templates (`wp_template`), template parts (`wp_template_part`), and navigation (`wp_navigation`).
- More protection for standard taxonomy REST mutation routes and select core AJAX mutations.

## Important security/coverage limits
**Not a complete write firewall or database read-only mode.** WordPress has no universal pre-write event for arbitrary plugin SQL or custom endpoints. A custom plugin can bypass these guards using direct SQL, custom database tables, filesystem writes, external APIs or unconventional request handlers. WordPress AJAX and custom REST endpoints are only covered if they use the standard content/taxonomy/metadata APIs or one of the explicitly guarded routes. Some block theme Global Styles (`wp_global_styles`) and theme/plugin configuration paths remain out of scope. Comprehensive WooCommerce and Gravity Forms settings protection needs integration-specific testing; settings-screen switches remain *best effort*. This plugin does not prevent WP-CLI or direct DB changes if they bypass the WordPress APIs.

**Operational considerations:** WooCommerce checkout/orders and Gravity Forms entries should remain writable. Test shared-taxonomy effects, editors, roles, metadata, custom fields and scheduled publications on a nonproduction copy. WordPress post capability primitives are shared across post types in many installations, so freezing one type may make another type noneditable in the UI. Some plugins bypass metadata filters. Direct media attachments and file writes are not frozen by default.

## Test checklist
1. As administrator and editor: try post/page create, classic edit, Gutenberg edit, Quick Edit, bulk action, trash, delete, autosave and scheduled publishing.
2. Try categories, tags and custom taxonomy creation/update/delete via admin and REST; try custom fields through standard WP APIs.
3. Try editing synced patterns, templates, template parts and navigation through Site Editor.
4. Test custom post types, custom REST routes and custom AJAX actions in the actual plugin stack; document any bypasses.
5. Confirm WooCommerce checkout, Gravity Forms submissions and other necessary operational writes still work.
6. Set an expiration (e.g. one hour); confirm frozen before and mutable after, including if WP-Cron was delayed.
7. Confirm only administrators can reach/save Settings → Content Freeze. Attempt direct settings POST with editor credentials and an invalid nonce.

**Emergency thaw:** uncheck Freeze enabled on the settings screen, deactivate the plugin, or rename its directory by SFTP/SSH. Deactivation retains options and settings.
