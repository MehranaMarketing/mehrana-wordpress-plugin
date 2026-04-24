<?php
/**
 * Plugin Name: Mehrana App Plugin
 * Description: Headless SEO & Optimization Plugin for Mehrana App - Link Building, Image Optimization, GTM, Clarity & More
 * Version: 5.3.0
 * Author: Mehrana Agency
 * Author URI: https://mehrana.agency
 * Text Domain: mehrana-app
 * GitHub Plugin URI: MehranaMarketing/mehrana-wordpress-plugin
 * GitHub Branch: main
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Mehrana_App_Plugin
{

    private $version = '5.3.0';
    private $namespace = 'mehrana/v1';
    private $rate_limit_key = 'map_rate_limit';
    private $max_requests_per_minute = 200;

    // GitHub Updater Config
    private $github_username = 'MehranaMarketing';
    private $github_repo = 'mehrana-wordpress-plugin';
    private $github_plugin_path = 'mehrana-app.php';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // GitHub Auto-Update Hooks
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_github_update']);
        add_filter('plugins_api', [$this, 'github_plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

        // Google Tag Manager Hooks
        add_action('wp_head', [$this, 'inject_gtm_head'], 1);
        add_action('wp_body_open', [$this, 'inject_gtm_body'], 1);

        // Custom Head Code Hook (for Clarity, etc.)
        add_action('wp_head', [$this, 'inject_custom_head_code'], 2);

        // On-Page Studio: JSON-LD schema markup (stored under _mehrana_schema_markup)
        // Runs at priority 5 so it lands in the <head> early, alongside GTM/custom code.
        add_action('wp_head', [$this, 'inject_schema_markup'], 5);

        // Register hooks that need 'init'
        add_action('init', [$this, 'init_actions']);

        // Daily cron that prunes image backups older than 24h
        add_action('mehrana_prune_image_backups', [$this, 'prune_image_backups']);
        register_activation_hook(__FILE__, [__CLASS__, 'schedule_prune_cron']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'unschedule_prune_cron']);

        // Sitemap exclusion hooks — inject Mehrana's excluded post IDs / URLs into every
        // known sitemap provider so the XML output skips them without touching noindex.
        // Rank Math, Yoast, and WordPress core all expose filters for this.
        add_filter('rank_math/sitemap/exclude_posts', [$this, 'filter_sitemap_excluded_ids_csv']);
        add_filter('rank_math/sitemap/excluded_posts', [$this, 'filter_sitemap_excluded_ids_array']);
        add_filter('rank_math/sitemap/exclude_post_ids', [$this, 'filter_sitemap_excluded_ids_array']);
        add_filter('rank_math/sitemap/entry', [$this, 'filter_sitemap_entry_by_url'], 10, 3);
        add_filter('rank_math/sitemap/urls', [$this, 'filter_sitemap_urls_array']);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', [$this, 'filter_sitemap_excluded_ids_array']);
        add_filter('wpseo_sitemap_url', [$this, 'filter_sitemap_entry_by_url'], 10, 2);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_core_sitemap_query_args']);
    }

    public function init_actions()
    {
        // Register [iframe] shortcode for Google Maps
        add_shortcode('iframe', [$this, 'render_iframe_shortcode']);

        // Ensure cron is scheduled (self-heal in case activation hook didn't fire,
        // e.g. plugin updated via git pull rather than reinstall).
        if (!wp_next_scheduled('mehrana_prune_image_backups')) {
            wp_schedule_event(time() + 300, 'hourly', 'mehrana_prune_image_backups');
        }
    }

    public static function schedule_prune_cron()
    {
        if (!wp_next_scheduled('mehrana_prune_image_backups')) {
            wp_schedule_event(time() + 300, 'hourly', 'mehrana_prune_image_backups');
        }
    }

    public static function unschedule_prune_cron()
    {
        $ts = wp_next_scheduled('mehrana_prune_image_backups');
        if ($ts) {
            wp_unschedule_event($ts, 'mehrana_prune_image_backups');
        }
    }

    /**
     * Delete backup files in mehrana-backups older than 24 hours.
     * Runs hourly; each backup gets a 24h grace window for undo.
     */
    public function prune_image_backups()
    {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/mehrana-backups';
        if (!is_dir($backup_dir)) return;

        $cutoff = time() - 86400; // 24 hours
        $deleted = 0;
        foreach (glob($backup_dir . '/*') as $path) {
            if (!is_file($path)) continue;
            $base = basename($path);
            // Skip .htaccess / index.php protections
            if ($base === '.htaccess' || $base === 'index.php') continue;
            if (filemtime($path) < $cutoff) {
                if (@unlink($path)) $deleted++;
            }
        }
        if ($deleted > 0) {
            $this->log("[PRUNE_BACKUPS] Deleted $deleted backup file(s) older than 24h");
        }
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        // Get pages with Elementor data
        register_rest_route($this->namespace, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_pages'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get media library items
        register_rest_route($this->namespace, '/media', [
            'methods' => 'GET',
            'callback' => [$this, 'get_media'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Update page with links
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/apply-links', [
            'methods' => 'POST',
            'callback' => [$this, 'apply_links'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ],
                'keywords' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_array($param);
                    }
                ]
            ],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Scan page for keywords (dry run)
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/scan', [
            'methods' => 'POST',
            'callback' => [$this, 'scan_page'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        register_rest_route($this->namespace, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Debug page content
        register_rest_route($this->namespace, '/debug/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'debug_page'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Search for keyword in page (for debugging)
        register_rest_route($this->namespace, '/search/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'search_keyword'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get existing backlinks in a page
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/links', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_links'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Remove a specific link from page
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/links/(?P<link_id>[a-zA-Z0-9_-]+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'remove_link'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get logs (for debugging)
        register_rest_route($this->namespace, '/logs', [
            'methods' => 'GET',
            'callback' => [$this, 'get_logs'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Replace media with optimized version (creates backup first)
        register_rest_route($this->namespace, '/media/(?P<id>\d+)/replace', [
            'methods' => 'POST',
            'callback' => [$this, 'replace_media'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Stream raw attachment bytes from disk — bypasses Cloudflare Polish
        // so repeat compress cycles start from the real origin file.
        register_rest_route($this->namespace, '/media/(?P<id>\d+)/raw', [
            'methods' => 'GET',
            'callback' => [$this, 'get_raw_media'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Restore media from backup
        register_rest_route($this->namespace, '/media/(?P<id>\d+)/restore', [
            'methods' => 'POST',
            'callback' => [$this, 'restore_media'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Delete backup file
        register_rest_route($this->namespace, '/media/(?P<id>\d+)/delete-backup', [
            'methods' => 'POST',
            'callback' => [$this, 'delete_backup'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Scan content for in-use images with their sizes
        register_rest_route($this->namespace, '/scan-content-images', [
            'methods' => 'GET',
            'callback' => [$this, 'scan_content_images'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Find media by URL
        register_rest_route($this->namespace, '/find-media', [
            'methods' => 'GET',
            'callback' => [$this, 'find_media_by_url'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Find many media by URL in one request (Image Factory v2 reconcile)
        register_rest_route($this->namespace, '/find-media-bulk', [
            'methods' => 'POST',
            'callback' => [$this, 'find_media_bulk'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Update media alt text
        register_rest_route($this->namespace, '/media/(?P<id>\d+)/alt', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_media_alt'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ],
                'alt' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // ===== LOCAL SEO v4 ENDPOINTS =====

        // Clone page from template (for Local SEO page generation)
        register_rest_route($this->namespace, '/pages/clone', [
            'methods' => 'POST',
            'callback' => [$this, 'clone_page'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'template_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ],
                'new_title' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'new_slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title'
                ]
            ]
        ]);

        // Create redirect (301/302)
        register_rest_route($this->namespace, '/redirects', [
            'methods' => 'POST',
            'callback' => [$this, 'create_redirect'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'from_url' => [
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw'
                ],
                'to_url' => [
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw'
                ],
                'type' => [
                    'default' => 301,
                    'validate_callback' => function ($param) {
                        return in_array((int) $param, [301, 302, 307, 308]);
                    }
                ]
            ]
        ]);

        // Update page SEO meta (Rank Math / Yoast)
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/seo', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_page_seo'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);

        // List all public taxonomy terms (categories, tags, product_cat, product_tag, ...)
        register_rest_route($this->namespace, '/terms', [
            'methods' => 'GET',
            'callback' => [$this, 'get_terms'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Update term SEO meta (Rank Math / Yoast)
        register_rest_route($this->namespace, '/terms/(?P<id>\d+)/seo', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_term_seo'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);

        // Get raw page content for editing (used by image deploy)
        register_rest_route($this->namespace, '/pages/content', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'page_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);

        // Upload media (bypass WP auth, use API Key)
        register_rest_route($this->namespace, '/media/upload', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_media'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Update page content, slug, and status (for Content Factory)
        register_rest_route($this->namespace, '/pages/update', [
            'methods' => 'POST',
            'callback' => [$this, 'update_page_content'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'page_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);

        // =============================================
        // LinkLab Endpoints
        // =============================================

        // Get all navigation menus with full item trees
        register_rest_route($this->namespace, '/menus', [
            'methods' => 'GET',
            'callback' => [$this, 'linklab_get_menus'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Update a menu item URL
        register_rest_route($this->namespace, '/menus/items/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'linklab_update_menu_item'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // List all redirects
        register_rest_route($this->namespace, '/redirects', [
            'methods' => 'GET',
            'callback' => [$this, 'linklab_get_redirects'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Update a redirect (id can be rm_123, rp_456, custom_789)
        register_rest_route($this->namespace, '/redirects/(?P<id>[a-zA-Z0-9_]+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'linklab_update_redirect'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Delete a redirect (id can be rm_123, rp_456, custom_789)
        register_rest_route($this->namespace, '/redirects/(?P<id>[a-zA-Z0-9_]+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'linklab_delete_redirect'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get page robots directives
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/robots', [
            'methods' => 'GET',
            'callback' => [$this, 'linklab_get_page_robots'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Set page robots directives
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/robots', [
            'methods' => 'POST',
            'callback' => [$this, 'linklab_set_page_robots'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Search theme files for URLs
        register_rest_route($this->namespace, '/theme/search', [
            'methods' => 'POST',
            'callback' => [$this, 'linklab_search_theme'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Add breadcrumb URL override
        register_rest_route($this->namespace, '/breadcrumb/override', [
            'methods' => 'POST',
            'callback' => [$this, 'linklab_add_breadcrumb_override'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // List breadcrumb overrides
        register_rest_route($this->namespace, '/breadcrumb/overrides', [
            'methods' => 'GET',
            'callback' => [$this, 'linklab_get_breadcrumb_overrides'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Delete breadcrumb override
        register_rest_route($this->namespace, '/breadcrumb/override/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'linklab_delete_breadcrumb_override'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Exclude URL from sitemap
        register_rest_route($this->namespace, '/sitemap/exclude', [
            'methods' => 'POST',
            'callback' => [$this, 'linklab_sitemap_exclude'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Include URL in sitemap (undo exclude)
        register_rest_route($this->namespace, '/sitemap/include', [
            'methods' => 'POST',
            'callback' => [$this, 'linklab_sitemap_include'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // List current sitemap exclusions (post IDs + URLs)
        register_rest_route($this->namespace, '/sitemap/exclusions', [
            'methods' => 'GET',
            'callback' => [$this, 'linklab_sitemap_exclusions'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Verify capabilities
        register_rest_route($this->namespace, '/verify-capabilities', [
            'methods' => 'GET',
            'callback' => [$this, 'linklab_verify_capabilities'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // On-Page Studio: deploy JSON-LD schema markup for a single page.
        // Body: { "schema": [ { "@context": "https://schema.org", "@type": "...", ... }, ... ] }
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/schema', [
            'methods' => 'POST',
            'callback' => [$this, 'update_page_schema'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Read back the stored schema (used by Mehrana app to verify deploy).
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/schema', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_schema'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // ========================================================
        // Schema Rules (template-per-page-type)
        // ========================================================
        // One rule defines a JSON-LD template that auto-applies to every post
        // matching its criteria (post_type, slug pattern, taxonomy, id lists).
        // Templates contain tokens like {{post.title}} that are resolved live
        // at wp_head so 2000 products share one definition with per-post data.
        // Per-page overrides (via POST /pages/{id}/schema) still win over rules.

        // List the active rule set on this site.
        register_rest_route($this->namespace, '/schema/rules', [
            'methods' => 'GET',
            'callback' => [$this, 'list_schema_rules'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Replace the entire active rule set. CRM is source of truth; it ships
        // the full active-rules array, plugin mirrors it. No partial sync —
        // avoids drift between CRM and site.
        register_rest_route($this->namespace, '/schema/rules', [
            'methods' => 'PUT',
            'callback' => [$this, 'replace_schema_rules'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Preview a rule against a specific post without saving. Used by CRM
        // wizard to show rendered JSON before activating.
        // Body: { "template": [...], "post_id": 123 }
        register_rest_route($this->namespace, '/schema/rules/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'preview_schema_rule'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Debug: which rule would match a given post, and resolved output.
        register_rest_route($this->namespace, '/schema/rules/match', [
            'methods' => 'GET',
            'callback' => [$this, 'match_schema_rule_for_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Enumerate public post types so CRM can populate the "match" dropdown.
        register_rest_route($this->namespace, '/schema/post-types', [
            'methods' => 'GET',
            'callback' => [$this, 'list_public_post_types'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Per-rule coverage: sample post IDs and total count for a given match
        // spec. Lets CRM show "N pages matched" without crawling.
        register_rest_route($this->namespace, '/schema/rules/coverage', [
            'methods' => 'POST',
            'callback' => [$this, 'coverage_for_match'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * POST /pages/{id}/schema
     *
     * Accepts a JSON-LD array and stores it under the _mehrana_schema_markup post
     * meta. The wp_head hook below emits one <script type="application/ld+json">
     * block per item on the rendered page.
     */
    public function update_page_schema($request)
    {
        $page_id = intval($request['id']);
        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $body = $request->get_json_params();
        if (!isset($body['schema'])) {
            return new WP_Error('bad_request', 'Missing schema in request body', ['status' => 400]);
        }

        $schema = $body['schema'];
        // Accept either an array of objects or a single object — normalize to array.
        if (is_array($schema) && isset($schema['@context'])) {
            $schema = [$schema];
        }
        if (!is_array($schema)) {
            return new WP_Error('bad_request', 'schema must be an array or object', ['status' => 400]);
        }

        // Basic sanity pass — every entry must be an object with @context.
        foreach ($schema as $entry) {
            if (!is_array($entry) || !isset($entry['@context'])) {
                return new WP_Error('bad_request', 'Each schema entry must include @context', ['status' => 400]);
            }
        }

        // Store as JSON string so wp_head can emit it verbatim without re-encoding.
        update_post_meta($page_id, '_mehrana_schema_markup', wp_json_encode($schema));
        $this->log("[PAGE_SCHEMA] Stored " . count($schema) . " JSON-LD block(s) for page {$page_id}");

        return rest_ensure_response([
            'success' => true,
            'page_id' => $page_id,
            'count' => count($schema),
        ]);
    }

    /**
     * GET /pages/{id}/schema
     *
     * Returns the stored JSON-LD array for a page (empty array if none set).
     */
    public function get_page_schema($request)
    {
        $page_id = intval($request['id']);
        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $raw = get_post_meta($page_id, '_mehrana_schema_markup', true);
        $schema = [];
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $schema = $decoded;
            }
        }

        return rest_ensure_response([
            'page_id' => $page_id,
            'schema' => $schema,
        ]);
    }

    /**
     * wp_head hook — emit JSON-LD markup for the current page.
     *
     * Precedence: per-page override wins; else first matching rule (by
     * priority desc) renders its template with live post data.
     */
    public function inject_schema_markup()
    {
        if (!is_singular()) return;
        $page_id = get_the_ID();
        if (!$page_id) return;

        $blocks = $this->resolve_schema_for_post($page_id);
        if (empty($blocks)) return;

        foreach ($blocks as $entry) {
            if (!is_array($entry)) continue;
            $encoded = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$encoded) continue;
            echo "\n" . '<script type="application/ld+json">' . $encoded . '</script>';
        }
        echo "\n";
    }

    /**
     * Resolve the schema blocks to emit for a given post.
     *
     * Returns an array of JSON-LD objects. Empty array = nothing to emit.
     *
     * Order:
     *   1. Per-page override (_mehrana_schema_markup) — hand-crafted, wins.
     *   2. First matching rule from _mehrana_schema_rules (priority desc).
     *   3. Empty.
     */
    private function resolve_schema_for_post($post_id)
    {
        // 1. Per-page override
        $raw = get_post_meta($post_id, '_mehrana_schema_markup', true);
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) return $decoded;
        }

        // 2. Rule match
        $rules = $this->load_schema_rules();
        if (empty($rules)) return [];

        usort($rules, function ($a, $b) {
            $pa = isset($a['priority']) ? intval($a['priority']) : 10;
            $pb = isset($b['priority']) ? intval($b['priority']) : 10;
            return $pb - $pa;
        });

        foreach ($rules as $rule) {
            if (!isset($rule['match']) || !isset($rule['template'])) continue;
            if ($this->rule_matches_post($rule['match'], $post_id)) {
                return $this->resolve_rule_template($rule['template'], $post_id);
            }
        }

        return [];
    }

    // ============================================================
    // Schema Rules — Storage
    // ============================================================

    /**
     * Load rules from the _mehrana_schema_rules option. Always returns an
     * array — missing/corrupt storage reads as empty.
     */
    private function load_schema_rules()
    {
        $raw = get_option('_mehrana_schema_rules', '');
        if (empty($raw)) return [];
        $decoded = is_array($raw) ? $raw : json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Validate a single rule. Returns array [ok, error_message].
     * Every rule must have id, template (non-empty array of JSON-LD objects
     * with @context), and a match block (can be empty — matches everything).
     */
    private function validate_rule($rule)
    {
        if (!is_array($rule)) return [false, 'rule must be an object'];
        if (empty($rule['id']) || !is_string($rule['id'])) return [false, 'rule.id required'];
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $rule['id'])) return [false, 'rule.id must be alphanumeric/_/-'];
        if (!isset($rule['template']) || !is_array($rule['template']) || empty($rule['template'])) {
            return [false, 'rule.template must be a non-empty array'];
        }
        foreach ($rule['template'] as $entry) {
            if (!is_array($entry) || !isset($entry['@context'])) {
                return [false, 'every template entry must be an object with @context'];
            }
        }
        if (isset($rule['match']) && !is_array($rule['match'])) {
            return [false, 'rule.match must be an object'];
        }
        return [true, null];
    }

    // ============================================================
    // Schema Rules — Endpoints
    // ============================================================

    /**
     * GET /schema/rules
     */
    public function list_schema_rules($request)
    {
        $rules = $this->load_schema_rules();
        return rest_ensure_response([
            'rules' => $rules,
            'count' => count($rules),
        ]);
    }

    /**
     * PUT /schema/rules
     * Body: { "rules": [ {id, name?, priority?, match, template}, ... ] }
     * Replaces the entire active rule set.
     */
    public function replace_schema_rules($request)
    {
        $body = $request->get_json_params();
        if (!isset($body['rules']) || !is_array($body['rules'])) {
            return new WP_Error('bad_request', 'Missing rules array', ['status' => 400]);
        }

        $clean = [];
        $seen_ids = [];
        foreach ($body['rules'] as $rule) {
            list($ok, $err) = $this->validate_rule($rule);
            if (!$ok) {
                return new WP_Error('bad_request', 'Invalid rule: ' . $err, ['status' => 400]);
            }
            if (isset($seen_ids[$rule['id']])) {
                return new WP_Error('bad_request', 'Duplicate rule id: ' . $rule['id'], ['status' => 400]);
            }
            $seen_ids[$rule['id']] = true;

            $clean[] = [
                'id'        => $rule['id'],
                'name'      => isset($rule['name']) ? substr((string)$rule['name'], 0, 200) : $rule['id'],
                'priority'  => isset($rule['priority']) ? intval($rule['priority']) : 10,
                'match'     => isset($rule['match']) && is_array($rule['match']) ? $rule['match'] : new stdClass(),
                'template'  => $rule['template'],
            ];
        }

        update_option('_mehrana_schema_rules', wp_json_encode($clean), false);
        $this->log('[SCHEMA_RULES] Replaced rule set: ' . count($clean) . ' rule(s)');

        return rest_ensure_response([
            'success' => true,
            'count'   => count($clean),
        ]);
    }

    /**
     * POST /schema/rules/preview
     * Body: { "template": [...], "post_id": 123 }
     */
    public function preview_schema_rule($request)
    {
        $body = $request->get_json_params();
        if (!isset($body['template']) || !is_array($body['template'])) {
            return new WP_Error('bad_request', 'Missing template', ['status' => 400]);
        }
        $post_id = isset($body['post_id']) ? intval($body['post_id']) : 0;
        if ($post_id <= 0) {
            return new WP_Error('bad_request', 'Missing post_id', ['status' => 400]);
        }
        if (!get_post($post_id)) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        $resolved = $this->resolve_rule_template($body['template'], $post_id);
        return rest_ensure_response([
            'post_id'  => $post_id,
            'resolved' => $resolved,
        ]);
    }

    /**
     * GET /schema/rules/match?post_id=123
     * Shows precedence: override, matching rule, or nothing. Useful for debug.
     */
    public function match_schema_rule_for_post($request)
    {
        $post_id = isset($request['post_id']) ? intval($request['post_id']) : 0;
        if ($post_id <= 0 || !get_post($post_id)) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        $override = get_post_meta($post_id, '_mehrana_schema_markup', true);
        if (!empty($override)) {
            $decoded = json_decode($override, true);
            return rest_ensure_response([
                'post_id'  => $post_id,
                'source'   => 'override',
                'rule_id'  => null,
                'resolved' => is_array($decoded) ? $decoded : [],
            ]);
        }

        $rules = $this->load_schema_rules();
        usort($rules, function ($a, $b) {
            $pa = isset($a['priority']) ? intval($a['priority']) : 10;
            $pb = isset($b['priority']) ? intval($b['priority']) : 10;
            return $pb - $pa;
        });
        foreach ($rules as $rule) {
            if ($this->rule_matches_post($rule['match'] ?? [], $post_id)) {
                return rest_ensure_response([
                    'post_id'  => $post_id,
                    'source'   => 'rule',
                    'rule_id'  => $rule['id'],
                    'rule_name' => $rule['name'] ?? $rule['id'],
                    'resolved' => $this->resolve_rule_template($rule['template'], $post_id),
                ]);
            }
        }

        return rest_ensure_response([
            'post_id'  => $post_id,
            'source'   => 'none',
            'rule_id'  => null,
            'resolved' => [],
        ]);
    }

    /**
     * GET /schema/post-types
     * Returns the public post types the site uses, for the CRM match dropdown.
     */
    public function list_public_post_types($request)
    {
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']); // meta, not content
        $out = [];
        foreach ($types as $slug => $obj) {
            $count = wp_count_posts($slug);
            $published = isset($count->publish) ? intval($count->publish) : 0;
            $out[] = [
                'slug'      => $slug,
                'label'     => $obj->labels->name ?? $slug,
                'singular'  => $obj->labels->singular_name ?? $slug,
                'published' => $published,
                'has_archive' => !empty($obj->has_archive),
            ];
        }
        return rest_ensure_response(['post_types' => $out]);
    }

    /**
     * POST /schema/rules/coverage
     * Body: { "match": {...} }
     * Returns total matching post count + up to 10 sample {id, title, url}.
     * Lets CRM show "Matches N pages" without scanning its own crawl.
     */
    public function coverage_for_match($request)
    {
        $body = $request->get_json_params();
        $match = isset($body['match']) && is_array($body['match']) ? $body['match'] : [];

        $post_types = !empty($match['post_types']) && is_array($match['post_types'])
            ? array_values(array_filter($match['post_types'], 'is_string'))
            : ['post', 'page'];

        $args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'fields'         => 'ids',
        ];
        if (!empty($match['include_ids']) && is_array($match['include_ids'])) {
            $args['post__in'] = array_map('intval', $match['include_ids']);
        }
        if (!empty($match['exclude_ids']) && is_array($match['exclude_ids'])) {
            $args['post__not_in'] = array_map('intval', $match['exclude_ids']);
        }
        if (!empty($match['taxonomies']) && is_array($match['taxonomies'])) {
            $tax_query = ['relation' => 'AND'];
            foreach ($match['taxonomies'] as $tax => $terms) {
                if (!is_array($terms) || empty($terms)) continue;
                $tax_query[] = [
                    'taxonomy' => (string)$tax,
                    'field'    => 'slug',
                    'terms'    => array_map('strval', $terms),
                ];
            }
            if (count($tax_query) > 1) $args['tax_query'] = $tax_query;
        }

        // Slug patterns can't be pushed down to SQL cleanly, so we fetch a
        // wider sample and filter in PHP. Good enough for coverage hints.
        $slug_patterns = !empty($match['slug_patterns']) && is_array($match['slug_patterns'])
            ? array_filter($match['slug_patterns'], 'is_string')
            : [];

        if (empty($slug_patterns)) {
            $q = new WP_Query($args);
            $total = (int)$q->found_posts;
            $samples = array_map(function ($id) {
                return ['id' => $id, 'title' => get_the_title($id), 'url' => get_permalink($id)];
            }, $q->posts);
            return rest_ensure_response(['total' => $total, 'samples' => $samples]);
        }

        // With slug patterns: widen the fetch, filter, then cap to 10 samples.
        $wide_args = $args;
        $wide_args['posts_per_page'] = 500;
        $q = new WP_Query($wide_args);
        $matched = [];
        $total = 0;
        foreach ($q->posts as $id) {
            $url = get_permalink($id);
            if ($this->url_matches_any_pattern($url, $slug_patterns)) {
                $total++;
                if (count($matched) < 10) {
                    $matched[] = ['id' => $id, 'title' => get_the_title($id), 'url' => $url];
                }
            }
        }
        return rest_ensure_response(['total' => $total, 'samples' => $matched, 'sampled_from' => count($q->posts)]);
    }

    // ============================================================
    // Schema Rules — Matching + Token Resolution
    // ============================================================

    /**
     * Does a post match a rule's match criteria?
     *
     * All defined criteria are AND-joined. Undefined criteria are skipped.
     * An empty match block matches everything.
     */
    private function rule_matches_post($match, $post_id)
    {
        if (!is_array($match)) return false;
        $post = get_post($post_id);
        if (!$post) return false;

        // Explicit exclusion wins
        if (!empty($match['exclude_ids']) && is_array($match['exclude_ids'])) {
            if (in_array(intval($post_id), array_map('intval', $match['exclude_ids']), true)) {
                return false;
            }
        }

        // Explicit include short-circuit
        if (!empty($match['include_ids']) && is_array($match['include_ids'])) {
            if (in_array(intval($post_id), array_map('intval', $match['include_ids']), true)) {
                return true;
            }
        }

        // Post type filter
        if (!empty($match['post_types']) && is_array($match['post_types'])) {
            if (!in_array($post->post_type, $match['post_types'], true)) return false;
        }

        // Slug pattern filter (against permalink)
        if (!empty($match['slug_patterns']) && is_array($match['slug_patterns'])) {
            $url = get_permalink($post_id);
            if (!$this->url_matches_any_pattern($url, $match['slug_patterns'])) return false;
        }

        // Taxonomy filter: must have at least one of the given terms in each taxonomy
        if (!empty($match['taxonomies']) && is_array($match['taxonomies'])) {
            foreach ($match['taxonomies'] as $tax => $terms) {
                if (!is_array($terms) || empty($terms)) continue;
                if (!has_term($terms, (string)$tax, $post_id)) return false;
            }
        }

        return true;
    }

    /**
     * Does a URL match any of the given glob-style patterns?
     *
     * Patterns are matched against the pathname. "*" = any chars (incl. "/"),
     * "?" = single char. Leading "/" optional.
     */
    private function url_matches_any_pattern($url, $patterns)
    {
        if (empty($url)) return false;
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        foreach ($patterns as $pattern) {
            $p = (string)$pattern;
            if ($p === '') continue;
            if ($p[0] !== '/') $p = '/' . $p;
            // fnmatch with FNM_PATHNAME would block "*" from matching "/", which we want.
            // Convert glob to regex: * => .*, ? => .
            $regex = '#^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($p, '#')) . '$#i';
            if (preg_match($regex, $path)) return true;
        }
        return false;
    }

    /**
     * Resolve tokens in a rule template against a post. Returns the template
     * with all {{live.*}} tokens substituted. {{static.*}} tokens are
     * pre-baked by the CRM and pass through unchanged.
     *
     * After resolution, string values that are empty are dropped (removing
     * their key) so the emitted JSON-LD doesn't contain empty "" fields that
     * cause Google validation warnings.
     */
    private function resolve_rule_template($template, $post_id)
    {
        $out = [];
        foreach ($template as $entry) {
            if (!is_array($entry)) continue;
            $resolved = $this->resolve_tokens_recursive($entry, $post_id);
            if (is_array($resolved) && !empty($resolved)) $out[] = $resolved;
        }
        return $out;
    }

    /**
     * Recursively walk a JSON-LD value, substituting tokens in strings.
     * Removes keys whose final string value is empty.
     */
    private function resolve_tokens_recursive($value, $post_id)
    {
        if (is_string($value)) {
            return $this->substitute_tokens($value, $post_id);
        }
        if (is_array($value)) {
            // Preserve associative vs list semantics
            $is_list = array_keys($value) === range(0, count($value) - 1);
            if ($is_list) {
                $out = [];
                foreach ($value as $item) {
                    $r = $this->resolve_tokens_recursive($item, $post_id);
                    if ($r !== '' && $r !== null && !(is_array($r) && empty($r))) $out[] = $r;
                }
                return $out;
            }
            $out = [];
            foreach ($value as $k => $v) {
                $r = $this->resolve_tokens_recursive($v, $post_id);
                // Drop empty strings and empty arrays to keep JSON-LD clean
                if ($r === '' || $r === null) continue;
                if (is_array($r) && empty($r)) continue;
                $out[$k] = $r;
            }
            return $out;
        }
        return $value;
    }

    /**
     * Substitute {{token}} patterns in a string. Each token resolves via
     * get_token_value(); unknown tokens pass through so CRM-baked static
     * tokens (already substituted before upload) are never touched here.
     */
    private function substitute_tokens($str, $post_id)
    {
        if (strpos($str, '{{') === false) return $str;

        // Handle the common case of the whole string being a single token:
        // we want to return native types (numbers, arrays) rather than a
        // stringified version, e.g. breadcrumb is an array.
        if (preg_match('/^\s*\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\s*$/', $str, $m)) {
            $val = $this->get_token_value($m[1], $post_id);
            // Native value (could be array, number, string)
            return $val === null ? '' : $val;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($post_id) {
            $val = $this->get_token_value($m[1], $post_id);
            if (is_array($val) || is_object($val)) return ''; // can't inline structured value
            return $val === null ? '' : (string)$val;
        }, $str);
    }

    /**
     * Resolve a single token name to its value for the given post.
     * Returns null when the token is unknown OR static (CRM pre-bakes static
     * tokens, so if we see one here the rule was shipped unresolved — just
     * return an empty string rather than leaking the placeholder).
     */
    private function get_token_value($token, $post_id)
    {
        // Static tokens should have been baked by CRM. If we see one here
        // (e.g. rule was uploaded unresolved), drop it rather than leaking.
        if (strpos($token, 'static.') === 0) return '';

        // meta.KEY — look up arbitrary post meta
        if (strpos($token, 'meta.') === 0) {
            $key = substr($token, 5);
            if ($key === '') return '';
            $v = get_post_meta($post_id, $key, true);
            return is_scalar($v) ? (string)$v : '';
        }

        // breadcrumb — array of {name, url}
        if ($token === 'breadcrumb') {
            return $this->build_breadcrumb($post_id);
        }

        // post.* family
        if (strpos($token, 'post.') === 0) {
            return $this->get_post_token($token, $post_id);
        }

        // product.* family (WooCommerce)
        if (strpos($token, 'product.') === 0) {
            return $this->get_product_token($token, $post_id);
        }

        return null;
    }

    private function get_post_token($token, $post_id)
    {
        $post = get_post($post_id);
        if (!$post) return '';
        switch ($token) {
            case 'post.id':               return (string)$post_id;
            case 'post.title':            return html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8');
            case 'post.url':              return get_permalink($post_id);
            case 'post.slug':             return $post->post_name;
            case 'post.excerpt':
                $ex = has_excerpt($post_id) ? get_the_excerpt($post_id) : wp_strip_all_tags($post->post_content);
                $ex = trim(preg_replace('/\s+/', ' ', $ex));
                return mb_substr($ex, 0, 300);
            case 'post.featured_image':
                return get_the_post_thumbnail_url($post_id, 'full') ?: '';
            case 'post.date_published':   return get_the_date('c', $post_id);
            case 'post.date_modified':    return get_the_modified_date('c', $post_id);
            case 'post.author.name':
                $u = get_userdata($post->post_author);
                return $u ? $u->display_name : '';
            case 'post.author.url':
                return get_author_posts_url($post->post_author);
        }
        return '';
    }

    private function get_product_token($token, $post_id)
    {
        if (!function_exists('wc_get_product')) return '';
        $product = wc_get_product($post_id);
        if (!$product) return '';

        switch ($token) {
            case 'product.price':
                $p = $product->get_price();
                return $p === '' ? '' : (string)$p;
            case 'product.currency':
                return function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
            case 'product.sku':
                return (string)$product->get_sku();
            case 'product.availability':
                return $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
            case 'product.image':
                $img_id = $product->get_image_id();
                if (!$img_id) return '';
                return wp_get_attachment_image_url($img_id, 'full') ?: '';
            case 'product.rating':
                $r = $product->get_average_rating();
                return $r ? (string)$r : '';
            case 'product.review_count':
                $c = $product->get_review_count();
                return $c ? (string)$c : '';
            case 'product.brand':
                // Try an attribute named 'brand' first, then a 'product_brand' taxonomy.
                $attr = $product->get_attribute('brand');
                if ($attr) return $attr;
                $terms = get_the_terms($post_id, 'product_brand');
                if (!empty($terms) && !is_wp_error($terms)) return $terms[0]->name;
                return '';
            case 'product.gtin':
                foreach (['_gtin', '_ean', '_upc', '_isbn'] as $k) {
                    $v = get_post_meta($post_id, $k, true);
                    if (!empty($v)) return (string)$v;
                }
                return '';
        }
        return '';
    }

    /**
     * Build a simple breadcrumb list for JSON-LD. Walks parent chain for
     * pages / uses primary category for posts. Returns array of
     * {"@type": "ListItem", position, name, item}.
     */
    private function build_breadcrumb($post_id)
    {
        $post = get_post($post_id);
        if (!$post) return [];
        $items = [];
        $home = home_url('/');
        $items[] = ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $home];

        if ($post->post_type === 'page' && $post->post_parent) {
            $chain = [];
            $pid = $post->post_parent;
            while ($pid) {
                $chain[] = $pid;
                $parent = get_post($pid);
                $pid = $parent ? $parent->post_parent : 0;
            }
            $chain = array_reverse($chain);
            foreach ($chain as $pid) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => count($items) + 1,
                    'name'     => get_the_title($pid),
                    'item'     => get_permalink($pid),
                ];
            }
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => count($items) + 1,
            'name'     => get_the_title($post_id),
            'item'     => get_permalink($post_id),
        ];

        return $items;
    }

    /**
     * Debug page content - shows all text content and widget types
     */
    public function debug_page($request)
    {
        $page_id = intval($request['id']);
        $post = get_post($page_id);

        if (!$post) {
            return rest_ensure_response(['error' => 'Post not found']);
        }

        // Check for HTTP redirect option (query param: ?check_http=1)
        $check_http = isset($request['check_http']) && ($request['check_http'] === '1' || $request['check_http'] === 'true');

        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        $has_blocks = has_blocks($post->post_content);
        $is_wpbakery = strpos($post->post_content, '[vc_') !== false;

        // Get redirect status
        $redirect_info = $this->check_redirect($page_id, $check_http);

        $result = [
            'page_id' => $page_id,
            'title' => $post->post_title,
            'post_status' => $post->post_status,
            'content_type' => 'unknown',
            'post_content_length' => strlen($post->post_content),
            'post_content_sample' => substr($post->post_content, 0, 1000),
            'has_elementor' => !empty($elementor_data),
            'has_gutenberg_blocks' => $has_blocks,
            'has_wpbakery' => $is_wpbakery,
            'has_redirect' => $redirect_info['has_redirect'],
            'redirect_url' => $redirect_info['redirect_url'],
            'redirect_source' => $redirect_info['redirect_source'],
        ];

        // Determine content type
        if (!empty($elementor_data)) {
            $result['content_type'] = 'elementor';
            $result['elementor_data_length'] = strlen($elementor_data);
        } elseif ($is_wpbakery) {
            $result['content_type'] = 'wpbakery';
            // Extract WPBakery shortcode structure
            preg_match_all('/\[vc_([a-z_]+)\]/', $post->post_content, $shortcodes);
            $result['wpbakery_shortcodes'] = array_unique($shortcodes[1] ?? []);
        } elseif ($has_blocks) {
            $result['content_type'] = 'gutenberg';
        } else {
            $result['content_type'] = 'classic';
        }

        // Show all meta keys that might contain content
        $all_meta = get_post_meta($page_id);
        $text_meta_keys = [];
        foreach ($all_meta as $key => $values) {
            if (strpos($key, '_') === 0 && strpos($key, 'elementor') === false) {
                continue; // Skip hidden metas except elementor
            }
            foreach ($values as $v) {
                if (is_string($v) && strlen($v) > 50 && !preg_match('/^[a-z0-9]{32}$/', $v)) {
                    $text_meta_keys[$key] = strlen($v);
                }
            }
        }
        $result['content_meta_keys'] = $text_meta_keys;

        // Look for the keyword in content
        if (isset($request['keyword'])) {
            $keyword = $request['keyword'];
            $result['keyword_search'] = [
                'keyword' => $keyword,
                'found_in_post_content' => stripos($post->post_content, $keyword) !== false,
                'position_in_content' => stripos($post->post_content, $keyword)
            ];
        }

        return rest_ensure_response($result);
    }

    /**
     * Search for a keyword in page content - debugging helper
     */
    public function search_keyword($request)
    {
        $page_id = intval($request['id']);
        $body = $request->get_json_params();
        $keyword = isset($body['keyword']) ? $body['keyword'] : '';

        if (empty($keyword)) {
            return rest_ensure_response(['error' => 'keyword required']);
        }

        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        $post = get_post($page_id);

        $results = [
            'page_id' => $page_id,
            'page_title' => $post ? $post->post_title : 'Unknown',
            'keyword' => $keyword,
            'found_in' => [],
            'elementor_raw_sample' => ''
        ];

        // Check post_content first
        if ($post && stripos($post->post_content, $keyword) !== false) {
            $results['found_in'][] = [
                'location' => 'post_content',
                'sample' => substr($post->post_content, max(0, stripos($post->post_content, $keyword) - 50), 150)
            ];
        }

        if (!empty($elementor_data)) {
            // Show first 500 chars of raw elementor data
            $results['elementor_raw_sample'] = substr($elementor_data, 0, 500) . '...';

            // Search in raw elementor data
            if (stripos($elementor_data, $keyword) !== false) {
                $results['found_in'][] = [
                    'location' => 'elementor_raw_data',
                    'position' => stripos($elementor_data, $keyword)
                ];
            }

            // Parse and search recursively
            $data = json_decode($elementor_data, true);
            if (is_array($data)) {
                $this->search_in_array($data, $keyword, '', $results['found_in']);
            }
        }

        $results['total_found'] = count($results['found_in']);

        return rest_ensure_response($results);
    }

    /**
     * Recursively search for keyword in array
     */
    private function search_in_array($arr, $keyword, $path, &$found)
    {
        foreach ($arr as $key => $value) {
            $current_path = $path ? "{$path}.{$key}" : $key;

            if (is_string($value) && stripos($value, $keyword) !== false) {
                $found[] = [
                    'location' => $current_path,
                    'value_length' => strlen($value),
                    'sample' => strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value
                ];
            } elseif (is_array($value)) {
                $this->search_in_array($value, $keyword, $current_path, $found);
            }
        }
    }

    /**
     * Extract all widgets with their content - shows ALL string settings
     */
    private function extract_widgets($elements, $widgets = [])
    {
        foreach ($elements as $element) {
            if (isset($element['widgetType'])) {
                $widget = [
                    'type' => $element['widgetType'],
                    'settings' => []
                ];

                // Extract ALL string settings
                if (isset($element['settings']) && is_array($element['settings'])) {
                    foreach ($element['settings'] as $key => $value) {
                        if (is_string($value) && strlen($value) > 3 && strlen($value) < 500) {
                            $widget['settings'][$key] = $value;
                        }
                    }
                }

                // Only add if has any text settings
                if (!empty($widget['settings'])) {
                    $widgets[] = $widget;
                }
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $widgets = $this->extract_widgets($element['elements'], $widgets);
            }
        }
        return $widgets;
    }

    /**
     * Permission callback with security checks
     * Supports both Application Password (Basic Auth) and API Key authentication
     */
    public function check_permission($request)
    {
        // Check rate limiting
        if (!$this->check_rate_limit()) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please wait.',
                ['status' => 429]
            );
        }

        // Method 1: Check API Key authentication (simpler, no Application Password needed)
        $api_key = get_option('map_api_key', '');
        $request_key = $request->get_header('X-MAP-API-Key');

        if (!empty($api_key) && !empty($request_key) && hash_equals($api_key, $request_key)) {
            // API Key is valid - allow access
            $this->log('Authenticated via API Key');
            return true;
        }

        // Method 2: Fall back to WordPress user authentication (Application Password)
        $user = wp_get_current_user();
        if (!$user || $user->ID === 0) {
            return new WP_Error(
                'rest_not_logged_in',
                'Authentication required. Use API Key header (X-MAP-API-Key) or WordPress Application Password.',
                ['status' => 401]
            );
        }

        // Check capability - must be able to edit pages
        if (!current_user_can('edit_pages')) {
            return new WP_Error(
                'rest_forbidden',
                'You do not have permission to perform this action.',
                ['status' => 403]
            );
        }

        // Check allowed origins (optional - configurable in settings)
        $allowed_origins = get_option('plb_allowed_origins', '');
        if (!empty($allowed_origins)) {
            $origin = $request->get_header('origin');
            $allowed_list = array_map('trim', explode(',', $allowed_origins));
            if (!in_array($origin, $allowed_list)) {
                $this->log('Blocked request from unauthorized origin: ' . $origin);
                return new WP_Error(
                    'rest_forbidden',
                    'Origin not allowed.',
                    ['status' => 403]
                );
            }
        }

        return true;
    }

    /**
     * Rate limiting check - DISABLED FOR TESTING
     */
    private function check_rate_limit()
    {
        $limit = 200; // Requests per minute
        $ip = $_SERVER['REMOTE_ADDR'];
        $transient_key = 'map_rate_limit_' . md5($ip);

        $current = get_transient($transient_key);
        if ($current !== false && $current >= $limit) {
            return false;
        }

        $new_count = ($current === false) ? 1 : $current + 1;
        set_transient($transient_key, $new_count, 60);

        return true;
    }

    /**
     * Check if a post has a redirect configured (Rank Math, Yoast, Redirection plugin, etc.)
     * @param int $post_id The post ID to check
     * @param bool $check_http Whether to also check via HTTP HEAD request (slower but catches all redirects)
     */
    private function check_redirect($post_id, $check_http = false)
    {
        $result = [
            'has_redirect' => false,
            'redirect_url' => null,
            'redirect_source' => null
        ];

        // 1. Check Rank Math Redirection
        $rank_math_redirect = get_post_meta($post_id, 'rank_math_redirection', true);
        if (!empty($rank_math_redirect)) {
            // Rank Math stores as array: ['url_to' => 'target', 'header_code' => 301]
            if (is_array($rank_math_redirect) && isset($rank_math_redirect['url_to'])) {
                $result['has_redirect'] = true;
                $result['redirect_url'] = $rank_math_redirect['url_to'];
                $result['redirect_source'] = 'rank_math';
                return $result;
            } elseif (is_string($rank_math_redirect)) {
                $result['has_redirect'] = true;
                $result['redirect_url'] = $rank_math_redirect;
                $result['redirect_source'] = 'rank_math';
                return $result;
            }
        }

        // 2. Check Rank Math canonical redirect (if different from post URL)
        $rank_math_canonical = get_post_meta($post_id, 'rank_math_canonical_url', true);
        if (!empty($rank_math_canonical)) {
            $post_url = get_permalink($post_id);
            if ($rank_math_canonical !== $post_url) {
                // This might indicate a redirect
                // Don't mark as redirect for now, just check actual redirect meta
            }
        }

        // 3. Check Yoast SEO Redirect
        $yoast_redirect = get_post_meta($post_id, '_yoast_wpseo_redirect', true);
        if (!empty($yoast_redirect)) {
            $result['has_redirect'] = true;
            $result['redirect_url'] = $yoast_redirect;
            $result['redirect_source'] = 'yoast';
            return $result;
        }

        // 4. Check Redirection Plugin (if installed)
        // Redirection plugin stores in its own table, but also may use post meta
        $redirection_url = get_post_meta($post_id, '_redirection_url', true);
        if (!empty($redirection_url)) {
            $result['has_redirect'] = true;
            $result['redirect_url'] = $redirection_url;
            $result['redirect_source'] = 'redirection_plugin';
            return $result;
        }

        // 5. Check Native WordPress Page Redirect
        $wp_redirect = get_post_meta($post_id, '_wp_page_redirect', true);
        if (!empty($wp_redirect)) {
            $result['has_redirect'] = true;
            $result['redirect_url'] = $wp_redirect;
            $result['redirect_source'] = 'wp_native';
            return $result;
        }

        // 6. Check SEO Press redirect
        $seopress_redirect = get_post_meta($post_id, '_seopress_redirections_enabled', true);
        if ($seopress_redirect === '1') {
            $seopress_url = get_post_meta($post_id, '_seopress_redirections_value', true);
            if (!empty($seopress_url)) {
                $result['has_redirect'] = true;
                $result['redirect_url'] = $seopress_url;
                $result['redirect_source'] = 'seopress';
                return $result;
            }
        }

        // 7. HTTP HEAD Request check (if enabled and no meta redirect found)
        if ($check_http) {
            $post_url = get_permalink($post_id);
            if ($post_url) {
                $http_result = $this->check_http_redirect($post_url);
                if ($http_result['has_redirect']) {
                    return $http_result;
                }
            }
        }

        return $result;
    }

    /**
     * Trigger S3 re-upload for WP Offload Media
     * This handles both legacy (post_meta) and modern (as3cf_items table) storage
     * 
     * @param int $attachment_id The attachment ID
     * @param string $local_file_path The local file path
     * @param array $metadata The attachment metadata
     */
    private function trigger_s3_reupload($attachment_id, $local_file_path, $metadata)
    {
        $this->log("[S3_REUPLOAD] Starting for attachment ID: $attachment_id, file: $local_file_path");

        global $wpdb;

        // CRITICAL FIX: WP Offload Media checks current_user_can('upload_files')
        // REST API requests via API Key run as user ID 0 (Guest), so we must switch to an admin
        $current_user_id = get_current_user_id();
        $switched_user = false;

        if ($current_user_id === 0 || !current_user_can('upload_files')) {
            $admin_user = get_users(['role' => 'administrator', 'number' => 1]);
            if (!empty($admin_user)) {
                $admin_id = $admin_user[0]->ID;
                wp_set_current_user($admin_id);
                $switched_user = true;
                $this->log("[S3_REUPLOAD] Switched to Admin User ID: $admin_id for capability check");
            } else {
                $this->log("[S3_REUPLOAD] WARNING: No administrator found. Upload might fail due to permissions.");
            }
        }

        // STEP 1: Clear legacy post_meta (WP Offload Media 1.x)
        delete_post_meta($attachment_id, 'amazonS3_info');
        delete_post_meta($attachment_id, 'as3cf_provider_object');
        $this->log("[S3_REUPLOAD] Cleared legacy post_meta");

        // STEP 2: Clear modern as3cf_items table (WP Offload Media 2.3+)
        $table_name = $wpdb->prefix . 'as3cf_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $deleted = $wpdb->delete($table_name, ['source_id' => $attachment_id], ['%d']);
            $this->log("[S3_REUPLOAD] Deleted $deleted rows from as3cf_items for attachment $attachment_id");
        }


        // STEP 2.5: Try Direct Upload (WP Offload Media 2.6+) - WRAPPED SAFELY
        try {
            if (class_exists('DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item')) {
                global $as3cf;
                $this->log("[S3_REUPLOAD] Attempting DIRECT upload via Media_Library_Item");

                if (isset($as3cf) && is_object($as3cf) && method_exists($as3cf, 'get_item_handler')) {
                    $handler = $as3cf->get_item_handler('upload');
                    $provider = $as3cf->get_storage_provider();

                    if ($handler && $provider) {
                        $item_class = 'DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item';
                        $new_item = new $item_class($provider->get_provider_key_name(), '', $attachment_id);

                        $result = $handler->handle($new_item, ['verify_exists_in_server' => false]);

                        if (!is_wp_error($result)) {
                            $this->log("[S3_REUPLOAD] Direct upload succcessful.");
                        } else {
                            $this->log("[S3_REUPLOAD] Direct upload returned error: " . $result->get_error_message());
                        }
                    } else {
                        $this->log("[S3_REUPLOAD] Could not get upload handler or provider.");
                    }
                } else {
                    $this->log("[S3_REUPLOAD] Global $as3cf not available or invalid.");
                }
            }
        } catch (\Throwable $e) {
            $this->log("[S3_REUPLOAD] Direct upload threw exception (ignoring): " . $e->getMessage());
        }
        // STEP 3: Force regenerate metadata (triggers WP Offload Media hooks)
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // WP Offload Media hooks into 'wp_generate_attachment_metadata' filter
        // We generate clean metadata from the local file
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $local_file_path);

        if (!empty($new_metadata)) {
            // Apply the filter explicitly to be safe, though wp_generate_attachment_metadata does it too
            // The 'create' context is important for some plugins
            $new_metadata = apply_filters('wp_generate_attachment_metadata', $new_metadata, $attachment_id, 'create');

            // Save metadata - this triggers 'updated_post_meta' which WP Offload Media also watches
            wp_update_attachment_metadata($attachment_id, $new_metadata);
            $this->log("[S3_REUPLOAD] Regenerated and updated metadata");
        } else {
            $this->log("[S3_REUPLOAD] WARNING: Failed to regenerate metadata. Using fallback.");
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        // STEP 5: Additional hooks that WP Offload Media might listen to
        do_action('add_attachment', $attachment_id);

        // Trigger update hooks
        do_action('attachment_updated', $attachment_id, get_post($attachment_id), get_post($attachment_id));

        // Check if upload happened by looking for as3cf_items entry
        $check = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE source_id = %d LIMIT 1",
            $attachment_id
        ));

        if ($check) {
            $this->log("[S3_REUPLOAD] SUCCESS! as3cf_items entry created: $check");
        } else {
            $this->log("[S3_REUPLOAD] WARNING: No as3cf_items entry found after upload attempt");
            $this->log("[S3_REUPLOAD] You may need to manually offload this media from WordPress dashboard");
        }

        // Restore user if we switched
        if ($switched_user) {
            wp_set_current_user($current_user_id);
            $this->log("[S3_REUPLOAD] Restored original user ID: $current_user_id");
        }

        $this->log("[S3_REUPLOAD] Completed for attachment $attachment_id");
    }

    /**
     * Check if a URL redirects via HTTP HEAD request
     */
    private function check_http_redirect($url)
    {
        $result = [
            'has_redirect' => false,
            'redirect_url' => null,
            'redirect_source' => 'http'
        ];

        // Use wp_remote_head to make a HEAD request without following redirects
        $response = wp_remote_head($url, [
            'timeout' => 5,
            'redirection' => 0, // Don't follow redirects
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            return $result;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // Check for redirect status codes (301, 302, 303, 307, 308)
        if (in_array($status_code, [301, 302, 303, 307, 308])) {
            $location = wp_remote_retrieve_header($response, 'location');
            if ($location) {
                $result['has_redirect'] = true;
                $result['redirect_url'] = $location;
                $result['redirect_source'] = 'http_' . $status_code;
            }
        }

        return $result;
    }

    /**
     * Get media library items
     */
    public function get_media($request)
    {
        $page = isset($request['page']) ? intval($request['page']) : 1;
        $per_page = isset($request['per_page']) ? intval($request['per_page']) : 100;
        $search = isset($request['search']) ? sanitize_text_field($request['search']) : '';
        $all = isset($request['all']) && ($request['all'] === 'true' || $request['all'] === '1');

        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $all ? -1 : $per_page,  // -1 for all items
            'paged' => $all ? 1 : $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $result = [];


        foreach ($query->posts as $post) {
            $meta = wp_get_attachment_metadata($post->ID);
            $url = wp_get_attachment_url($post->ID);
            $file = get_attached_file($post->ID);
            $alt = get_post_meta($post->ID, '_wp_attachment_image_alt', true);

            // Get parent info
            $parent_id = $post->post_parent;
            $parent_title = '';
            $parent_type = '';
            $parent_url = '';

            if ($parent_id > 0) {
                $parent = get_post($parent_id);
                if ($parent) {
                    $parent_title = $parent->post_title;
                    $parent_type = $parent->post_type;
                    $parent_url = get_permalink($parent_id);
                }
            }

            // Get ACTUAL filesize from URL (not local file which may be outdated after compression)
            // Priority: 1) HTTP HEAD Content-Length, 2) Local file, 3) Metadata
            $actual_filesize = 0;

            if ($url) {
                // Use HTTP HEAD to get real file size from URL (handles S3, CDN, and replaced files)
                $head_response = wp_remote_head($url, [
                    'timeout' => 5,
                    'sslverify' => false
                ]);

                if (!is_wp_error($head_response)) {
                    $content_length = wp_remote_retrieve_header($head_response, 'content-length');
                    if (!empty($content_length) && is_numeric($content_length)) {
                        $actual_filesize = intval($content_length);
                    }
                }
            }

            // Fallback to local file if HEAD failed
            if ($actual_filesize === 0 && file_exists($file)) {
                $actual_filesize = filesize($file);
            }

            // Last fallback to metadata
            if ($actual_filesize === 0 && isset($meta['filesize'])) {
                $actual_filesize = $meta['filesize'];
            }

            $result[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'filename' => basename($file),
                'alt' => $alt,
                'url' => $url,
                'width' => isset($meta['width']) ? $meta['width'] : 0,
                'height' => isset($meta['height']) ? $meta['height'] : 0,
                'filesize' => $actual_filesize,
                'mime_type' => $post->post_mime_type,
                'date' => $post->post_date,
                'sizes' => isset($meta['sizes']) ? $meta['sizes'] : [],
                'parent_id' => $parent_id,
                'parent_title' => $parent_title,
                'parent_type' => $parent_type,
                'parent_url' => $parent_url
            ];
        }

        return rest_ensure_response([
            'media' => $result,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages
        ]);
    }

    /**
     * Stream raw attachment bytes from local disk.
     * Used by the CRM compressor to avoid Cloudflare Polish / CDN transformations
     * re-encoding the source image on each compress cycle.
     */
    public function get_raw_media($request)
    {
        $id = intval($request['id']);
        $attachment = get_post($id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('invalid_id', 'Invalid attachment ID', ['status' => 404]);
        }

        $file = get_attached_file($id);

        // If get_attached_file returned a URL (S3 offload), resolve to local path.
        if ($file && (strpos($file, 'http://') === 0 || strpos($file, 'https://') === 0)) {
            $upload_dir = wp_upload_dir();
            $metadata = wp_get_attachment_metadata($id);
            if (!empty($metadata['file'])) {
                $file = $upload_dir['basedir'] . '/' . $metadata['file'];
            } else {
                $parsed = parse_url($file);
                if (!empty($parsed['path']) && preg_match('/\/wp-content\/uploads\/(.+)$/', $parsed['path'], $m)) {
                    $file = $upload_dir['basedir'] . '/' . $m[1];
                }
            }
        }

        if (!$file || !file_exists($file)) {
            return new WP_Error('not_found', 'Local file missing for attachment ' . $id, ['status' => 404]);
        }

        $mime = $attachment->post_mime_type ?: 'application/octet-stream';
        $size = filesize($file);

        // Stream raw bytes with cache-bust headers.
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Mehrana-Raw: 1');
        }
        readfile($file);
        exit;
    }

    /**
     * Replace media with optimized version (creates backup first)
     * Expects: base64 encoded image data
     * Supports S3 offloaded files via WP Offload Media
     */
    public function replace_media($request)
    {
        // HARDENING: Increase memory limit for image processing
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '300');

        try {
            $id = intval($request['id']);
            $image_data = $request->get_param('image_data'); // base64 encoded
            $mime_type = $request->get_param('mime_type') ?: 'image/webp';

            $this->log("[REPLACE_MEDIA] Called for attachment ID: $id, mime: $mime_type, data_length: " . strlen($image_data ?? ''));

            if (!$image_data) {
                return new WP_Error('missing_data', 'Image data is required', ['status' => 400]);
            }

            // Get attachment
            $attachment = get_post($id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                return new WP_Error('invalid_id', 'Invalid attachment ID', ['status' => 404]);
            }

            // Get current file path (may not exist locally if offloaded to S3)
            $current_file = get_attached_file($id);

            // Get upload directory for creating new/backup files
            $upload_dir = wp_upload_dir();

            // Check if get_attached_file returned a URL instead of a local path (S3 Offload case)
            if ($current_file && (strpos($current_file, 'http://') === 0 || strpos($current_file, 'https://') === 0)) {
                // Extract relative path from URL and construct local path
                $metadata = wp_get_attachment_metadata($id);
                if (!empty($metadata['file'])) {
                    // metadata['file'] contains relative path like '2024/08/filename.jpg'
                    $current_file = $upload_dir['basedir'] . '/' . $metadata['file'];
                } else {
                    // Fallback: extract path from URL
                    $parsed = parse_url($current_file);
                    if (!empty($parsed['path'])) {
                        // Path like /wp-content/uploads/2024/08/filename.jpg
                        if (preg_match('/\/wp-content\/uploads\/(.+)$/', $parsed['path'], $matches)) {
                            $current_file = $upload_dir['basedir'] . '/' . $matches[1];
                        }
                    }
                }
            }

            $file_exists_locally = $current_file && file_exists($current_file);

            // If file doesn't exist locally (S3 offload), ensure directory exists
            if (!$file_exists_locally && $current_file) {
                $dir = dirname($current_file);
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }
            }

            // Create backup directory
            $backup_dir = $upload_dir['basedir'] . '/mehrana-backups';
            if (!file_exists($backup_dir)) {
                wp_mkdir_p($backup_dir);
                // Add .htaccess to protect backups
                file_put_contents($backup_dir . '/.htaccess', 'Options -Indexes');
            }

            // Generate backup filename
            $original_filename = $current_file ? basename($current_file) : 'attachment_' . $id . '.jpg';
            $backup_filename = date('Y-m-d_His') . '_' . $original_filename;
            $backup_path = $backup_dir . '/' . $backup_filename;

            // If file exists locally, create backup; if S3, skip backup (file is in cloud storage)
            $backup_created = false;
            if ($file_exists_locally) {
                if (copy($current_file, $backup_path)) {
                    $backup_created = true;
                }
            }

            // Decode base64 and save new image
            $image_binary = base64_decode($image_data);
            if ($image_binary === false) {
                if ($backup_created)
                    unlink($backup_path);
                return new WP_Error('decode_failed', 'Failed to decode image data', ['status' => 400]);
            }

            // Determine new extension based on mime type
            $new_extension = '';
            if ($mime_type === 'image/webp') {
                $new_extension = 'webp';
            } elseif ($mime_type === 'image/jpeg' || $mime_type === 'image/jpg') {
                $new_extension = 'jpg';
            } elseif ($mime_type === 'image/png') {
                $new_extension = 'png';
            }

            // Determine the new file path
            if ($current_file) {
                $path_info = pathinfo($current_file);
                if ($new_extension && $path_info['extension'] !== $new_extension) {
                    $new_file = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $new_extension;
                } else {
                    $new_file = $current_file;
                }
            } else {
                // Fallback: create path in current year/month folder
                $new_file = $upload_dir['path'] . '/optimized_' . $id . '.' . ($new_extension ?: 'webp');
            }

            // Ensure directory exists with proper permissions
            $new_dir = dirname($new_file);
            if (!file_exists($new_dir)) {
                if (!wp_mkdir_p($new_dir)) {
                    if ($backup_created)
                        unlink($backup_path);
                    return new WP_Error('dir_failed', 'Failed to create directory: ' . $new_dir, ['status' => 500]);
                }
            }

            // Check if directory is writable
            if (!is_writable($new_dir)) {
                if ($backup_created)
                    unlink($backup_path);
                return new WP_Error('dir_not_writable', 'Directory not writable: ' . $new_dir, ['status' => 500]);
            }

            // ATOMIC SWAP STRATEGY
            // 1. Write to a temporary file first
            $temp_file = $new_file . '.tmp';

            $bytes_written = file_put_contents($temp_file, $image_binary);
            if ($bytes_written === false) {
                if ($backup_created)
                    unlink($backup_path);
                $error_info = error_get_last();
                $this->log("[REPLACE_MEDIA] FAILED to write temp file. Path: $temp_file");
                return new WP_Error('write_failed', 'Failed to write temp image: ' . ($error_info['message'] ?? 'Unknown'), ['status' => 500]);
            }

            // 2. Verified write success. Now swap.
            // Try atomic rename first (Linux/Unix)
            // usage of @ to suppress warnings on Windows if target exists
            if (!@rename($temp_file, $new_file)) {
                // Rename failed (likely Windows/IIS where target exists).
                // Fallback: Safe Unlink + Rename
                $this->log("[REPLACE_MEDIA] Atomic rename failed. Trying fallback (Unlink + Rename) for: $new_file");

                if (file_exists($new_file)) {
                    if (!unlink($new_file)) {
                        // Critical failure: Can't delete old file.
                        unlink($temp_file); // Clean up temp
                        if ($backup_created)
                            unlink($backup_path);
                        return new WP_Error('replace_failed', 'Failed to delete original file for replacement.', ['status' => 500]);
                    }
                }

                // Try rename again after unlink
                if (!rename($temp_file, $new_file)) {
                    // Critical failure: Deleted original but can't rename temp! 
                    // Restore from backup immediately if possible
                    if ($backup_created) {
                        copy($backup_path, $current_file); // Emergency restore
                    }
                    unlink($temp_file);
                    return new WP_Error('replace_failed', 'Failed to rename temp file after unlinking original.', ['status' => 500]);
                }
            }

            clearstatcache();
            $this->log("[REPLACE_MEDIA] SUCCESS! Swapped new file into place: $new_file");

            $this->log("[REPLACE_MEDIA] SUCCESS! Wrote $bytes_written bytes to: $new_file");

            // If extension changed, delete old file and update attachment metadata
            $old_url = wp_get_attachment_url($id);
            $old_metadata = wp_get_attachment_metadata($id);

            if ($new_file !== $current_file) {
                // Delete old thumbnails before deleting main file
                if (!empty($old_metadata['sizes'])) {
                    $old_dir = dirname($current_file);
                    foreach ($old_metadata['sizes'] as $size => $size_data) {
                        $thumb_path = $old_dir . '/' . $size_data['file'];
                        if (file_exists($thumb_path)) {
                            unlink($thumb_path);
                        }
                    }
                }

                unlink($current_file);
                update_attached_file($id, $new_file);

                // Update mime type
                wp_update_post([
                    'ID' => $id,
                    'post_mime_type' => $mime_type
                ]);
            } else {
                // Same extension but still need to delete old thumbnails for regeneration
                if (!empty($old_metadata['sizes'])) {
                    $old_dir = dirname($current_file);
                    foreach ($old_metadata['sizes'] as $size => $size_data) {
                        $thumb_path = $old_dir . '/' . $size_data['file'];
                        if (file_exists($thumb_path)) {
                            unlink($thumb_path);
                        }
                    }
                }
            }

            // Regenerate thumbnails
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($id, $new_file);
            wp_update_attachment_metadata($id, $attach_data);

            // Log regeneration result for verification
            $sizes_count = isset($attach_data['sizes']) ? count($attach_data['sizes']) : 0;
            $this->log("[REPLACE_MEDIA] Thumbnails regenerated. Created $sizes_count sizes. Metadata: " . json_encode($attach_data));

            // Handle WP Offload Media re-upload to S3
            // This is the COMPLETE fix for sites using WP Offload Media
            $this->trigger_s3_reupload($id, $new_file, $attach_data);

            // Get backup URL
            $backup_url = $upload_dir['baseurl'] . '/mehrana-backups/' . $backup_filename;

            // Get new URL
            $new_url = wp_get_attachment_url($id);
            $new_metadata = wp_get_attachment_metadata($id);

            // If extension changed, update URLs in post content
            if ($new_file !== $current_file && $old_url) {
                global $wpdb;

                // Get old filename base (without extension)
                $old_path_info = pathinfo($old_url);
                $new_path_info = pathinfo($new_url);
                $old_base = $old_path_info['dirname'] . '/' . $old_path_info['filename'];
                $new_base = $new_path_info['dirname'] . '/' . $new_path_info['filename'];
                $old_ext = '.' . $old_path_info['extension'];
                $new_ext = '.' . $new_path_info['extension'];

                // Replace main URL
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)",
                    $old_url,
                    $new_url
                ));

                // Replace in postmeta (Elementor, etc.)
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)",
                    $old_url,
                    $new_url
                ));

                // Replace thumbnail URLs (e.g., image-300x200.jpg -> image-300x200.webp)
                // We need to match pattern like: old_base-{size}.old_ext -> new_base-{size}.new_ext
                if (!empty($old_metadata['sizes'])) {
                    foreach ($old_metadata['sizes'] as $size => $size_data) {
                        $old_thumb_file = $old_path_info['dirname'] . '/' . pathinfo($size_data['file'], PATHINFO_FILENAME) . $old_ext;
                        $new_thumb_file = $new_path_info['dirname'] . '/' . pathinfo($size_data['file'], PATHINFO_FILENAME) . $new_ext;

                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)",
                            $old_thumb_file,
                            $new_thumb_file
                        ));

                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)",
                            $old_thumb_file,
                            $new_thumb_file
                        ));
                    }
                }
            }

            return rest_ensure_response([
                'success' => true,
                'backup_url' => $backup_url,
                'backup_path' => $backup_path,
                'new_url' => $new_url,
                'new_size' => filesize($new_file),
                'new_width' => $new_metadata['width'] ?? 0,
                'new_height' => $new_metadata['height'] ?? 0
            ]);

        } catch (\Throwable $e) {
            $this->log("[REPLACE_MEDIA] CRITICAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new WP_Error('fatal_error', 'Server Error: ' . $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Check if a URL looks like an image asset by extension.
     * Used to reject page URLs / non-image URLs before running DB queries.
     */
    private function is_image_url($url)
    {
        $path = parse_url(strtok($url, '?'), PHP_URL_PATH);
        if (!$path) return false;
        return (bool) preg_match('/\.(jpe?g|png|webp|gif|avif|svg|ico|bmp|tiff?)$/i', $path);
    }

    /**
     * Produce URL variants that WordPress's attachment_url_to_postid may
     * accept. Handles the most common reasons that resolver fails:
     *   - Query strings (cache busters: ?ver=123, ?timestamp)
     *   - www vs non-www hostname
     *   - http vs https scheme
     *   - Percent-encoded characters (e.g. %E2%80%93 for en-dash)
     */
    private function build_url_variants($url)
    {
        $variants = [];

        // Strip query string first
        $no_query = strtok($url, '?');
        $variants[] = $no_query;

        // URL-decoded (handles %E2%80%93 etc.)
        $decoded = rawurldecode($no_query);
        if ($decoded !== $no_query) $variants[] = $decoded;

        // Toggle www / non-www
        $swap_www = [];
        foreach ($variants as $v) {
            $parsed = @parse_url($v);
            if (empty($parsed['host'])) continue;
            if (strpos($parsed['host'], 'www.') === 0) {
                $swap_www[] = preg_replace('#://www\.#', '://', $v, 1);
            } else {
                $swap_www[] = preg_replace('#://([^/]+)#', '://www.$1', $v, 1);
            }
        }
        $variants = array_merge($variants, $swap_www);

        // Toggle https / http
        $swap_scheme = [];
        foreach ($variants as $v) {
            if (strpos($v, 'https://') === 0) $swap_scheme[] = 'http://' . substr($v, 8);
            elseif (strpos($v, 'http://') === 0) $swap_scheme[] = 'https://' . substr($v, 7);
        }
        $variants = array_merge($variants, $swap_scheme);

        return array_values(array_unique($variants));
    }

    /**
     * Build filename variants by stripping WordPress-generated suffixes.
     * Each returned variant is a candidate `meta_value LIKE '%<name>'`
     * match in _wp_attached_file / as3cf_items / guid lookups.
     */
    private function build_filename_variants($filename)
    {
        $names = [$filename];
        $decoded = rawurldecode($filename);
        if ($decoded !== $filename) $names[] = $decoded;

        $stripped = [];
        foreach ($names as $n) {
            // Strip LiteSpeed / ShortPixel .ext.webp
            $a = preg_replace('/\.(jpe?g|png|gif)\.webp$/i', '.$1', $n);
            // Strip size suffix -WIDTHxHEIGHT
            $b = preg_replace('/-\d+x\d+(\.[^.]+)$/i', '$1', $a);
            // Strip WP editor suffix -e1234567890
            $c = preg_replace('/-e\d+(\.[^.]+)$/i', '$1', $b);
            // Strip -scaled
            $d = preg_replace('/-scaled(\.[^.]+)$/i', '$1', $c);
            // Strip -rotated
            $e = preg_replace('/-rotated(\.[^.]+)$/i', '$1', $d);
            $stripped[] = $a;
            $stripped[] = $b;
            $stripped[] = $c;
            $stripped[] = $d;
            $stripped[] = $e;
        }
        $names = array_merge($names, $stripped);

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * Resolve a URL to a WordPress attachment ID. Shared by find_media_by_url
     * and find_media_bulk. Returns ['id' => int|null, 'reason' => string].
     *
     * Reasons:
     *   wp_core        — resolved via WP's attachment_url_to_postid
     *   postmeta       — matched _wp_attached_file
     *   as3cf          — matched Offload Media table
     *   as3cf_obj      — matched via $as3cf global
     *   posts_guid     — matched wp_posts guid/post_name
     *   not-an-image   — URL has no image extension (page URL, etc.)
     *   not-in-library — exhausted all strategies, attachment doesn't exist
     */
    private function resolve_attachment_id($url)
    {
        if (!$url || !is_string($url)) {
            return ['id' => null, 'reason' => 'invalid-url'];
        }

        // Fast reject: page URLs and other non-image URLs that leaked into
        // the crawl. Saves expensive DB queries.
        if (!$this->is_image_url($url)) {
            return ['id' => null, 'reason' => 'not-an-image'];
        }

        global $wpdb;

        $url_variants = $this->build_url_variants($url);

        // Strategy 1: WP core on each URL variant (authoritative when it works)
        foreach ($url_variants as $v) {
            $id = attachment_url_to_postid($v);
            if ($id) return ['id' => intval($id), 'reason' => 'wp_core'];
        }

        // Derive filename variants for LIKE matches
        $parsed = parse_url(strtok($url, '?'));
        $filename = !empty($parsed['path']) ? basename($parsed['path']) : '';
        if (!$filename) {
            return ['id' => null, 'reason' => 'not-in-library'];
        }
        $name_variants = $this->build_filename_variants($filename);

        // Strategy 2: _wp_attached_file (the most authoritative column in core)
        foreach ($name_variants as $n) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'
                 AND meta_value LIKE %s
                 LIMIT 1",
                '%' . $wpdb->esc_like($n)
            ));
            if ($id) return ['id' => intval($id), 'reason' => 'postmeta'];
        }

        // Strategy 3: as3cf_items (WP Offload Media)
        $as3cf_table = $wpdb->prefix . 'as3cf_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '$as3cf_table'") === $as3cf_table) {
            foreach ($name_variants as $n) {
                $id = $wpdb->get_var($wpdb->prepare(
                    "SELECT source_id FROM $as3cf_table
                     WHERE path LIKE %s OR source_path LIKE %s
                     LIMIT 1",
                    '%' . $wpdb->esc_like($n),
                    '%' . $wpdb->esc_like($n)
                ));
                if ($id) return ['id' => intval($id), 'reason' => 'as3cf'];
            }
        }

        // Strategy 4: $as3cf object's own resolver (handles CDN rewrites)
        global $as3cf;
        if (isset($as3cf) && is_object($as3cf) && method_exists($as3cf, 'get_attachment_id_from_url')) {
            foreach ($url_variants as $v) {
                $id = $as3cf->get_attachment_id_from_url($v);
                if ($id) return ['id' => intval($id), 'reason' => 'as3cf_obj'];
            }
        }

        // Strategy 5: wp_posts by guid / post_name (last resort, least reliable)
        foreach ($name_variants as $n) {
            $basename = pathinfo($n, PATHINFO_FILENAME);
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND (guid LIKE %s OR post_name = %s)
                 LIMIT 1",
                '%' . $wpdb->esc_like($n) . '%',
                $basename
            ));
            if ($id) return ['id' => intval($id), 'reason' => 'posts_guid'];
        }

        return ['id' => null, 'reason' => 'not-in-library'];
    }

    /**
     * Find media ID by URL (single-URL endpoint).
     */
    public function find_media_by_url($request)
    {
        $url = $request->get_param('url');
        if (!$url) {
            return new WP_Error('missing_url', 'URL parameter is required', ['status' => 400]);
        }

        $result = $this->resolve_attachment_id($url);

        if ($result['id']) {
            return rest_ensure_response([
                'success' => true,
                'media_id' => $result['id'],
                'resolver' => $result['reason']
            ]);
        }

        return rest_ensure_response([
            'success' => false,
            'error' => 'Media not found in library',
            'reason' => $result['reason']
        ]);
    }

    /**
     * Resolve multiple URLs in one round-trip (Image Factory v2 reconcile).
     * Body: { urls: string[] }, max 500.
     * Returns: { success: true, results: [{ url, media_id|null, reason }, ...] }.
     */
    public function find_media_bulk($request)
    {
        $urls = $request->get_param('urls');
        if (!is_array($urls) || empty($urls)) {
            return new WP_Error('missing_urls', 'urls must be a non-empty array', ['status' => 400]);
        }
        if (count($urls) > 500) {
            return new WP_Error('too_many_urls', 'Max 500 URLs per request', ['status' => 400]);
        }

        $results = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                $results[] = ['url' => null, 'media_id' => null, 'reason' => 'invalid-url'];
                continue;
            }
            $r = $this->resolve_attachment_id($url);
            $results[] = [
                'url' => $url,
                'media_id' => $r['id'],
                'reason' => $r['reason']
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'count' => count($results),
            'results' => $results
        ]);
    }

    /**
     * Update media alt text
     */
    public function update_media_alt($request)
    {
        $id = intval($request['id']);
        $alt = $request->get_param('alt');
        $sanitized_alt = sanitize_text_field($alt);

        // Validate attachment exists
        $attachment = get_post($id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('invalid_attachment', 'Attachment not found', ['status' => 404]);
        }

        // Update alt text in media library
        update_post_meta($id, '_wp_attachment_image_alt', $sanitized_alt);
        $this->log("[update_media_alt] Updated alt text in media library for attachment $id: $alt");

        // Get the image URL
        $image_url = wp_get_attachment_url($id);
        if (!$image_url) {
            return rest_ensure_response([
                'success' => true,
                'media_id' => $id,
                'alt' => $alt,
                'posts_updated' => 0
            ]);
        }

        // Also get any resized versions of this image
        $metadata = wp_get_attachment_metadata($id);
        $base_url = dirname($image_url);
        $image_urls = [$image_url];

        if ($metadata && isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $size_data) {
                $image_urls[] = $base_url . '/' . $size_data['file'];
            }
        }

        // Get the base filename for matching (handles S3 URLs too)
        $filename = basename($image_url);
        $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);

        // Find all posts containing this image
        global $wpdb;
        $posts_updated = 0;

        // Search for posts containing the image URL or filename
        $like_pattern = '%' . $wpdb->esc_like($filename_without_ext) . '%';
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_content LIKE %s 
             AND post_status IN ('publish', 'draft', 'pending', 'private')
             AND post_type IN ('post', 'page')",
            $like_pattern
        ));

        $this->log("[update_media_alt] Found " . count($posts) . " posts containing image filename: $filename_without_ext");

        foreach ($posts as $post) {
            $content = $post->post_content;
            $updated = false;

            // Pattern to match img tags with this image and any alt attribute
            foreach ($image_urls as $url) {
                // Escape URL for regex
                $escaped_url = preg_quote($url, '/');

                // Match img tags containing this URL and update their alt
                // Pattern: <img ... src="URL" ... alt="anything" ...>
                $pattern = '/(<img[^>]*src=["\']' . $escaped_url . '["\'][^>]*alt=["\'])([^"\']*)(["\']/i';
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, '$1' . esc_attr($sanitized_alt) . '$3', $content);
                    $updated = true;
                }

                // Also handle when alt comes before src
                $pattern2 = '/(<img[^>]*alt=["\'])([^"\']*)(["\']+[^>]*src=["\']' . $escaped_url . '["\'])/i';
                if (preg_match($pattern2, $content)) {
                    $content = preg_replace($pattern2, '$1' . esc_attr($sanitized_alt) . '$3', $content);
                    $updated = true;
                }
            }

            // Also update by wp-image-ID class (more reliable for Gutenberg)
            $wp_image_class = 'wp-image-' . $id;
            $pattern3 = '/(<img[^>]*class=["\'][^"\']*' . preg_quote($wp_image_class, '/') . '[^"\']*["\'][^>]*alt=["\'])([^"\']*)(["\']/i';
            if (preg_match($pattern3, $content)) {
                $content = preg_replace($pattern3, '$1' . esc_attr($sanitized_alt) . '$3', $content);
                $updated = true;
            }

            // Handle alt before class
            $pattern4 = '/(<img[^>]*alt=["\'])([^"\']*)(["\']+[^>]*class=["\'][^"\']*' . preg_quote($wp_image_class, '/') . '[^"\']*["\'])/i';
            if (preg_match($pattern4, $content)) {
                $content = preg_replace($pattern4, '$1' . esc_attr($sanitized_alt) . '$3', $content);
                $updated = true;
            }

            // NEW: Pattern 5 - Handle img tags where alt might have spaces/quotes issues
            // Match wp-image-ID class and replace alt regardless of position
            $pattern5 = '/(<img[^>]*class=["\'][^"\']*' . preg_quote($wp_image_class, '/') . '[^"\']*["\'][^>]*)alt=["\'][^"\']*["\']([^>]*>)/i';
            if (preg_match($pattern5, $content)) {
                $content = preg_replace($pattern5, '$1alt="' . esc_attr($sanitized_alt) . '"$2', $content);
                $updated = true;
            }

            // NEW: Pattern 6 - For Classic Editor: img tags with wp-image-ID but NO alt attribute (add one)
            $pattern6 = '/(<img[^>]*class=["\'][^"\']*' . preg_quote($wp_image_class, '/') . '[^"\']*["\'][^>]*)(\/?>)/i';
            if (preg_match($pattern6, $content) && !preg_match('/alt=["\']/', $content)) {
                // Only add alt if not already present
                $content = preg_replace($pattern6, '$1 alt="' . esc_attr($sanitized_alt) . '" $2', $content);
                $updated = true;
            }

            // NEW: Pattern 7 - Generic img tag with this specific URL (Classic Editor friendly)
            foreach ($image_urls as $url) {
                $escaped_url = preg_quote($url, '/');
                // Replace existing alt in img tag with this src
                $pattern7 = '/(<img[^>]*src=["\']' . $escaped_url . '["\'][^>]*)alt=["\'][^"\']*["\']([^>]*>)/i';
                if (preg_match($pattern7, $content)) {
                    $content = preg_replace($pattern7, '$1alt="' . esc_attr($sanitized_alt) . '"$2', $content);
                    $updated = true;
                }
            }

            if ($updated && $content !== $post->post_content) {
                $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $content],
                    ['ID' => $post->ID]
                );
                $posts_updated++;
                $this->log("[update_media_alt] Updated post ID {$post->ID} with new alt text");

                // Clear post cache
                clean_post_cache($post->ID);
            }
        }

        $this->log("[update_media_alt] Finished. Updated $posts_updated posts");

        return rest_ensure_response([
            'success' => true,
            'media_id' => $id,
            'alt' => $alt,
            'posts_updated' => $posts_updated
        ]);
    }

    /**
     * Restore media from backup
     */
    public function restore_media($request)
    {
        $id = intval($request['id']);
        $backup_filename = $request->get_param('backup_filename');
        $backup_path = $request->get_param('backup_path'); // Also support direct path

        $this->log("[RESTORE_MEDIA] Called for attachment ID: $id, backup: " . ($backup_filename ?: $backup_path));

        // Construct backup path from filename if needed
        if ($backup_filename && !$backup_path) {
            $upload_dir = wp_upload_dir();
            $backup_path = $upload_dir['basedir'] . '/mehrana-backups/' . $backup_filename;
        }

        if (!$backup_path) {
            return new WP_Error('missing_path', 'Backup path or filename is required', ['status' => 400]);
        }

        // Validate backup exists
        if (!file_exists($backup_path)) {
            return new WP_Error('backup_not_found', 'Backup file not found: ' . $backup_path, ['status' => 404]);
        }

        // Get attachment
        $attachment = get_post($id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('invalid_id', 'Invalid attachment ID', ['status' => 404]);
        }

        // Get current file path
        $current_file = get_attached_file($id);

        // Get original extension from backup
        $backup_info = pathinfo($backup_path);
        // Backup filename format: 2024-01-05_143000_originalname.jpg
        // Extract original name by removing date prefix
        $original_name = preg_replace('/^\d{4}-\d{2}-\d{2}_\d{6}_/', '', $backup_info['basename']);
        $original_extension = pathinfo($original_name, PATHINFO_EXTENSION);

        // Restore: copy backup to original location
        $path_info = pathinfo($current_file);
        $restore_path = $path_info['dirname'] . '/' . $original_name;

        if (!copy($backup_path, $restore_path)) {
            return new WP_Error('restore_failed', 'Failed to restore from backup', ['status' => 500]);
        }

        // If current file is different (format changed), delete it
        if ($current_file !== $restore_path && file_exists($current_file)) {
            unlink($current_file);
        }

        // Update attachment file path
        update_attached_file($id, $restore_path);

        // Determine mime type from extension
        $mime_map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif'
        ];
        $mime_type = $mime_map[$original_extension] ?? 'image/jpeg';

        // Update post mime type
        wp_update_post([
            'ID' => $id,
            'post_mime_type' => $mime_type
        ]);

        // Regenerate thumbnails
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($id, $restore_path);
        wp_update_attachment_metadata($id, $attach_data);

        // Delete backup file (cleanup)
        unlink($backup_path);

        // Get restored info
        $new_url = wp_get_attachment_url($id);
        $metadata = wp_get_attachment_metadata($id);

        $this->log("[RESTORE_MEDIA] SUCCESS! Restored $restore_path from backup, size: " . filesize($restore_path));

        return rest_ensure_response([
            'success' => true,
            'restored_url' => $new_url,
            'size' => filesize($restore_path),
            'width' => $metadata['width'] ?? 0,
            'height' => $metadata['height'] ?? 0
        ]);
    }

    /**
     * Delete backup file for an image
     */
    public function delete_backup($request)
    {
        $id = intval($request['id']);
        $backup_filename = $request->get_param('backup_filename');

        if (!$backup_filename) {
            return new WP_Error('missing_data', 'Backup filename is required', ['status' => 400]);
        }

        // Build backup path
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/mehrana-backups';
        $backup_path = $backup_dir . '/' . $backup_filename;

        // Check if backup exists
        if (!file_exists($backup_path)) {
            return new WP_Error('not_found', 'Backup file not found', ['status' => 404]);
        }

        // Delete the backup file
        if (!unlink($backup_path)) {
            return new WP_Error('delete_failed', 'Failed to delete backup file', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Backup deleted successfully'
        ]);
    }

    /**
     * Scan all content for in-use images and their sizes
     * This finds images that are ACTUALLY being loaded on pages
     */
    public function scan_content_images($request)
    {
        // Increase timeout for large sites
        set_time_limit(300);

        $min_size_kb = intval($request->get_param('min_size_kb')) ?: 0;
        $limit = intval($request->get_param('limit')) ?: 500;
        $page_num = intval($request->get_param('page')) ?: 1;
        $per_page = intval($request->get_param('per_page')) ?: 100; // Posts per page, configurable

        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        // Query published content with pagination
        $posts = get_posts([
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page_num,
            'fields' => 'all'
        ]);

        $images = []; // URL => [size, pages, etc]
        $image_pages = []; // URL => [page_id, page_id, ...]

        foreach ($posts as $post) {
            // 1. Extract from post_content (Gutenberg, Classic Editor)
            $content = $post->post_content;

            // 2. Also get Elementor data if exists
            $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
            if ($elementor_data) {
                $content .= ' ' . $elementor_data;
            }

            // 3. Get featured image
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($thumbnail_id) {
                $thumb_url = wp_get_attachment_url($thumbnail_id);
                if ($thumb_url && !isset($images[$thumb_url])) {
                    $images[$thumb_url] = null; // Will get size later
                    $image_pages[$thumb_url] = [];
                }
                if ($thumb_url) {
                    $image_pages[$thumb_url][] = $post->ID;
                }
            }

            // 4. Get WooCommerce product gallery images
            if ($post->post_type === 'product') {
                $gallery_ids = get_post_meta($post->ID, '_product_image_gallery', true);
                if ($gallery_ids) {
                    $gallery_array = explode(',', $gallery_ids);
                    foreach ($gallery_array as $gallery_id) {
                        $gallery_url = wp_get_attachment_url((int) $gallery_id);
                        if ($gallery_url) {
                            if (!isset($images[$gallery_url])) {
                                $images[$gallery_url] = null;
                                $image_pages[$gallery_url] = [];
                            }
                            if (!in_array($post->ID, $image_pages[$gallery_url])) {
                                $image_pages[$gallery_url][] = $post->ID;
                            }
                        }
                    }
                }
            }

            // Extract image URLs using regex
            // Matches: src="...", background-image: url(...), etc.
            $patterns = [
                '/src=["\']([^"\']+\.(jpg|jpeg|png|gif|webp|svg)(\?[^"\']*)?)["\']/i',
                '/data-src=["\']([^"\']+\.(jpg|jpeg|png|gif|webp)(\?[^"\']*)?)["\']/i',
                '/data-lazy-src=["\']([^"\']+\.(jpg|jpeg|png|gif|webp)(\?[^"\']*)?)["\']/i',
                '/srcset=["\']([^"\',\s]+\.(jpg|jpeg|png|gif|webp))/i',
                '/url\(["\']?([^"\')]+\.(jpg|jpeg|png|gif|webp)(\?[^"\')]*)?)["\']?\)/i',
                '/"url":\s*"([^"]+\.(jpg|jpeg|png|gif|webp))"/i',
                '/"image":\s*\{[^}]*"url":\s*"([^"]+)"/i',
                '/"background_image":\s*\{[^}]*"url":\s*"([^"]+)"/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $url) {
                        // Clean URL
                        $url = str_replace(['\\/', '\\u002F'], '/', $url);

                        // Skip external images and data URIs
                        if (strpos($url, 'data:') === 0)
                            continue;

                        // Make absolute if relative
                        if (strpos($url, 'http') !== 0) {
                            $url = home_url($url);
                        }

                        // Only include images from this site
                        $site_url = home_url();
                        if (strpos($url, $site_url) === false && strpos($url, parse_url($site_url, PHP_URL_HOST)) === false) {
                            continue;
                        }

                        if (!isset($images[$url])) {
                            $images[$url] = null;
                            $image_pages[$url] = [];
                        }
                        if (!in_array($post->ID, $image_pages[$url])) {
                            $image_pages[$url][] = $post->ID;
                        }
                    }
                }
            }
        }

        // Now get file sizes for each unique image - NO HEAD REQUESTS to avoid timeouts
        $results = [];
        foreach ($images as $url => $_) {
            $size_bytes = 0;
            $size_kb = 0;
            $real_url = $url;
            $attachment_id = null;

            // 1. Try to find EXACT file on disk first (Most accurate for thumbnails)
            $upload_dir = wp_upload_dir();
            $base_url = $upload_dir['baseurl'];

            // Handle HTTPS/HTTP mismatch in local path resolution
            $check_url = $url;
            if (strpos($base_url, 'https://') === 0 && strpos($check_url, 'http://') === 0) {
                $check_url = str_replace('http://', 'https://', $check_url);
            }

            $local_path = str_replace($base_url, $upload_dir['basedir'], $check_url);

            // If URL doesn't match baseurl (e.g. CDN), try to construct path relative to uploads
            if ($local_path === $check_url) {
                $path_parts = parse_url($url);
                if (isset($path_parts['path'])) {
                    $relative = $path_parts['path'];
                    // Remove /wp-content/uploads/ or just /uploads/
                    if (preg_match('/\/wp-content\/uploads\/(.+)$/', $relative, $m)) {
                        $local_path = $upload_dir['basedir'] . '/' . $m[1];
                    }
                }
            }

            if (file_exists($local_path)) {
                $size_bytes = filesize($local_path);
            }

            // 2. If valid size found, we are good. If not, try Attachment ID (Fallback)
            if (!$size_bytes) {
                // First try: Get attachment ID from URL
                $attachment_id = attachment_url_to_postid($url);
                if (!$attachment_id) {
                    // Try without size suffix (e.g., -1024x768)
                    $clean_url = preg_replace('/-\d+x\d+\./', '.', $url);
                    $attachment_id = attachment_url_to_postid($clean_url);
                }

                if ($attachment_id) {
                    // Try attachment metadata first (fastest)
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    // ONLY use metadata size if we are looking at the main file (no suffix mismatch)
                    // If URL implies thumbnail but we found parent ID, metadata['filesize'] is WRONG for this specific URL
                    $is_likely_thumb = preg_match('/-\d+x\d+\./', $url);

                    if (!$is_likely_thumb && !empty($metadata['filesize'])) {
                        $size_bytes = intval($metadata['filesize']);
                    }

                    // Fallback: try local file from attachment (Main File)
                    if (!$size_bytes && !$is_likely_thumb) {
                        $file_path = get_attached_file($attachment_id);
                        if ($file_path && file_exists($file_path)) {
                            $size_bytes = filesize($file_path);
                        }
                    }
                }
            }

            // 3. SMART CHECK: If file seems "Heavy" (> 100KB), verify with HTTP HEAD
            // This handles cases where Cloudflare/WebP serves a much smaller file than what is on disk
            // We only do this for heavy files to avoid timeout penalties on small files
            $threshold_bytes = 100 * 1024; // 100KB
            if ($size_bytes > $threshold_bytes) {
                $head = wp_remote_head($url, [
                    'timeout' => 3,     // Fast timeout
                    'redirection' => 2,
                    'sslverify' => false
                ]);

                if (!is_wp_error($head)) {
                    $content_length = wp_remote_retrieve_header($head, 'content-length');
                    if ($content_length && intval($content_length) > 0) {
                        $served_size = intval($content_length);
                        // If served size is significantly smaller (e.g. optimized), utilize it
                        if ($served_size < $size_bytes) {
                            $size_bytes = $served_size;
                        }
                    }
                }
            }

            $size_kb = round($size_bytes / 1024, 1);

            // Only include images above threshold (or include all if size unknown)
            if ($size_kb >= $min_size_kb) {
                // Get page info
                $pages_info = [];
                foreach ($image_pages[$url] as $page_id) {
                    $pages_info[] = [
                        'id' => $page_id,
                        'title' => get_the_title($page_id),
                        'url' => get_permalink($page_id),
                        'type' => get_post_type($page_id)
                    ];
                }

                // Get alt text from media library
                $alt_text = '';
                $attachment_id = attachment_url_to_postid($url);
                if (!$attachment_id) {
                    // Try with scaled/resized variations
                    $clean_url = preg_replace('/-\d+x\d+\./', '.', $url);
                    $attachment_id = attachment_url_to_postid($clean_url);
                }
                if ($attachment_id) {
                    $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                    // Get real URL (handles S3 offload)
                    $real_attachment_url = wp_get_attachment_url($attachment_id);
                    if ($real_attachment_url) {
                        $url = $real_attachment_url;
                    }
                }

                $results[] = [
                    'url' => $url,
                    'filename' => basename(parse_url($url, PHP_URL_PATH)),
                    'size_bytes' => $size_bytes,
                    'size_kb' => $size_kb,
                    'alt' => $alt_text,
                    'has_alt' => !empty(trim($alt_text)),
                    'pages' => $pages_info,
                    'page_count' => count($pages_info)
                ];
            }
        }

        // Sort by size (largest first)
        usort($results, function ($a, $b) {
            return $b['size_bytes'] - $a['size_bytes'];
        });

        // Apply limit
        $results = array_slice($results, 0, $limit);

        // Get total posts count for pagination
        $total_posts_query = new WP_Query([
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        $total_posts = $total_posts_query->found_posts;
        $has_more = ($page_num * $per_page) < $total_posts;

        return rest_ensure_response([
            'success' => true,
            'total_scanned' => count($images),
            'images' => $results,
            'posts_scanned' => count($posts),
            'page' => $page_num,
            'per_page' => $per_page,
            'total_posts' => $total_posts,
            'has_more' => $has_more
        ]);
    }

    /**
     * Get all content (pages, posts, landing pages, products, etc)
     */
    public function get_pages($request)
    {
        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');

        // Exclude internal/system types
        $exclude = ['attachment', 'elementor_library', 'elementor_font', 'elementor_icons', 'guest-author'];
        $allowed_types = array_diff(array_values($post_types), $exclude);

        $args = [
            'post_type' => array_values($allowed_types),
            'posts_per_page' => -1, // No limit
            'post_status' => 'publish',
        ];

        $pages = get_posts($args);
        $result = [];
        $debug_types = array_count_values(array_map(function ($p) {
            return $p->post_type;
        }, $pages));

        foreach ($pages as $page) {
            $elementor_data = get_post_meta($page->ID, '_elementor_data', true);

            // Determine page type
            $type = 'page';
            if ($page->post_type === 'post') {
                $type = 'blog';
            } elseif ($page->post_type !== 'page') {
                $type = $page->post_type;
            }

            // Check for redirects
            $redirect_info = $this->check_redirect($page->ID);

            // Page Schema (On-Page Studio): surface stored JSON-LD so the app can
            // verify deploys and show badges without a second API call.
            $schema_raw = get_post_meta($page->ID, '_mehrana_schema_markup', true);
            $schema_markup = null;
            $schema_types = [];
            if (!empty($schema_raw)) {
                $decoded = json_decode($schema_raw, true);
                if (is_array($decoded)) {
                    $schema_markup = $decoded;
                    foreach ($decoded as $entry) {
                        if (is_array($entry) && isset($entry['@type'])) {
                            if (is_array($entry['@type'])) {
                                $schema_types = array_merge($schema_types, $entry['@type']);
                            } else {
                                $schema_types[] = $entry['@type'];
                            }
                        }
                    }
                    $schema_types = array_values(array_unique($schema_types));
                }
            }

            $result[] = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'url' => get_permalink($page->ID),
                'type' => $type,
                'has_elementor' => !empty($elementor_data),
                'has_redirect' => $redirect_info['has_redirect'],
                'redirect_url' => $redirect_info['redirect_url'],
                'elementor_data' => $elementor_data,
                'post_content' => $page->post_content,
                'schema_markup' => $schema_markup,
                'schema_types' => $schema_types,
            ];
        }

        $this->log('Fetched ' . count($result) . ' pages/posts');

        return rest_ensure_response([
            'pages' => $result,
            'debug' => [
                'total_found' => count($result),
                'types_found' => $debug_types,
                'query_args' => $args
            ]
        ]);
    }

    /**
     * Apply links to a page
     */
    public function apply_links($request)
    {
        $page_id = intval($request['id']);
        $keywords = $request['keywords'];

        // Validate page exists (any public post type)
        $page = get_post($page_id);
        if (!$page || $page->post_status !== 'publish') {
            return new WP_Error('invalid_page', 'Content not found', ['status' => 404]);
        }

        $this->log("Applying links to page {$page_id}. Keywords count: " . count($keywords));

        // Get Elementor data
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);

        $is_elementor = !empty($elementor_data);
        $this->log("Elementor Detection for {$page_id}: " . ($is_elementor ? 'YES' : 'NO'));
        if (!$is_elementor) {
            $raw_meta = get_post_meta($page_id, '_elementor_data'); // Get array to see if it exists but empty
            $this->log("Raw _elementor_data meta count: " . (is_array($raw_meta) ? count($raw_meta) : 'Not Array'));
            // Check if user is using Block Editor
            $has_blocks = has_blocks($page->post_content);
            $this->log("Has Gutenberg Blocks: " . ($has_blocks ? 'YES' : 'NO'));
        } else {
            $this->log("Elementor Data Length: " . strlen($elementor_data) . " chars");
        }

        $results = [];

        if ($is_elementor) {
            // Process Elementor Data
            $data = json_decode($elementor_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_json', 'Invalid Elementor data', ['status' => 400]);
            }

            foreach ($keywords as $kw) {
                $keyword = sanitize_text_field($kw['keyword']);
                $target_url = esc_url_raw($kw['target_url']);
                $anchor_id = sanitize_html_class($kw['anchor_id']);
                $only_first = isset($kw['only_first']) ? (bool) $kw['only_first'] : true;

                if (empty($keyword) || empty($target_url))
                    continue;

                $result = $this->process_elements($data, $keyword, $target_url, $anchor_id, $only_first);
                $results[] = [
                    'keyword' => $keyword,
                    'count' => $result['count'],
                    'linked_count' => $result['linked_count'] ?? 0
                ];
            }

            // Save Elementor Data
            $new_data = wp_json_encode($data);
            $update_result = update_post_meta($page_id, '_elementor_data', wp_slash($new_data));

            $this->log("Elementor save result for page {$page_id}: " . ($update_result ? 'SUCCESS' : 'FAILED'));
            $this->log("Data length: " . strlen($new_data) . " bytes");

            // CRITICAL: Trigger Elementor regeneration
            // Method 1: Update edit timestamp to trigger Elementor
            update_post_meta($page_id, '_edit_last', get_current_user_id());
            update_post_meta($page_id, '_edit_lock', time() . ':' . get_current_user_id());

            // Method 2: Clear ALL Elementor caches
            if (class_exists('\Elementor\Plugin')) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                // Also clear this specific page's cache
                delete_post_meta($page_id, '_elementor_css');
                delete_post_meta($page_id, '_elementor_page_assets');
            }

            // Method 3: Touch the post to update modification time
            wp_update_post([
                'ID' => $page_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1)
            ]);

        } else {
            // Process Standard Content + ALL Meta Fields
            // Check if using WPBakery (Visual Composer)
            $is_wpbakery = strpos($page->post_content, '[vc_') !== false || strpos($page->post_content, '[/vc_') !== false;
            $this->log("Processing as STANDARD CONTENT (Elementor not detected or empty)");
            if ($is_wpbakery) {
                $this->log("WPBakery Page Builder detected in post_content");
            }
            $content = $page->post_content;
            $all_skipped = [];
            $total_linked = 0;

            foreach ($keywords as $kw) {
                $keyword = sanitize_text_field($kw['keyword']);
                $target_url = esc_url_raw($kw['target_url']);
                $anchor_id = sanitize_html_class($kw['anchor_id']);
                $only_first = isset($kw['only_first']) ? (bool) $kw['only_first'] : true;

                if (empty($keyword) || empty($target_url))
                    continue;

                // 1. Process main post_content
                $result = $this->replace_keyword($content, $keyword, $target_url, $anchor_id, $only_first && $total_linked === 0);
                $content = $result['text'];
                $total_linked += $result['count'];

                // Collect skipped info
                if (!empty($result['skipped'])) {
                    foreach ($result['skipped'] as $skip) {
                        $skip['keyword'] = $keyword;
                        $skip['location'] = 'post_content';
                        $all_skipped[] = $skip;
                    }
                }

                // 2. Process ALL meta fields that might contain content
                $all_meta = get_post_meta($page_id);
                $content_meta_keys = $this->get_content_meta_keys($all_meta);

                foreach ($content_meta_keys as $meta_key) {
                    $meta_value = get_post_meta($page_id, $meta_key, true);
                    if (empty($meta_value) || !is_string($meta_value))
                        continue;

                    $result = $this->replace_keyword($meta_value, $keyword, $target_url, $anchor_id, $only_first && $total_linked === 0);

                    if ($result['count'] > 0) {
                        update_post_meta($page_id, $meta_key, $result['text']);
                        $total_linked += $result['count'];
                    }

                    if (!empty($result['skipped'])) {
                        foreach ($result['skipped'] as $skip) {
                            $skip['keyword'] = $keyword;
                            $skip['location'] = $meta_key;
                            $all_skipped[] = $skip;
                        }
                    }
                }

                $results[] = [
                    'keyword' => $keyword,
                    'count' => $total_linked,
                    // Note: apply_links primarily cares about applied links, but we could return linked_count if needed
                    // For now, we just stick to applied structure unless we want to track pre-existing during apply?
                    // Let's assume apply returns what it DID.
                ];
            }

            // Save Standard Content
            // Save Standard Content
            $update_result = wp_update_post([
                'ID' => $page_id,
                'post_content' => $content
            ]);

            if (is_wp_error($update_result)) {
                $this->log("Error updating post {$page_id}: " . $update_result->get_error_message());
            } elseif ($update_result === 0) {
                $this->log("Post {$page_id} update returned 0 (no changes or invalid ID)");
            } else {
                $this->log("Post {$page_id} updated successfully. Result ID: " . $update_result);

                // Clear WPBakery/Visual Composer caches if detected
                if ($is_wpbakery) {
                    // Clear WPBakery page cache
                    delete_post_meta($page_id, '_wpb_post_custom_css');
                    delete_post_meta($page_id, '_wpb_shortcodes_custom_css');
                    $this->log("Cleared WPBakery caches for post {$page_id}");
                }

                // Clear all page caches (WP Super Cache, W3TC, WP Fastest Cache, etc.)
                if (function_exists('wp_cache_clear_cache')) {
                    wp_cache_clear_cache();
                }
                if (function_exists('w3tc_flush_post')) {
                    w3tc_flush_post($page_id);
                }
                if (function_exists('wpfc_clear_post_cache_by_id')) {
                    wpfc_clear_post_cache_by_id($page_id);
                }

                // Touch post modification time
                wp_update_post([
                    'ID' => $page_id,
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1)
                ]);
            }
        }

        $this->log("Applied links to page {$page_id}: " . json_encode($results));

        return rest_ensure_response([
            'success' => true,
            'page_id' => $page_id,
            'results' => $results,
            'skipped' => isset($all_skipped) ? $all_skipped : []
        ]);
    }

    /**
     * Process Elementor elements recursively - checks ALL nested settings at any depth
     */
    private function process_elements(&$elements, $keyword, $target_url, $anchor_id, $only_first)
    {
        $total_count = 0;
        $total_linked_count = 0;

        foreach ($elements as &$element) {
            // Process any widget type settings at any depth
            if (isset($element['settings']) && is_array($element['settings'])) {
                // Determine if we should stop based on combined count if only_first is true
                $limit_reached = $only_first && ($total_count + $total_linked_count) > 0;

                $result = $this->process_settings_recursive($element['settings'], $keyword, $target_url, $anchor_id, $only_first && !$limit_reached);
                $total_count += $result['count'];
                $total_linked_count += $result['linked_count'] ?? 0;
            }

            // Process nested elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $limit_reached = $only_first && ($total_count + $total_linked_count) > 0;

                if ($only_first && $limit_reached) {
                    // Skip nested elements if limit reached
                } else {
                    $nested = $this->process_elements($element['elements'], $keyword, $target_url, $anchor_id, $only_first);
                    $total_count += $nested['count'];
                    $total_linked_count += $nested['linked_count'] ?? 0;
                }
            }
        }

        return ['count' => $total_count, 'linked_count' => $total_linked_count];
    }

    /**
     * Process settings - recursive with BLACKLIST
     */
    private function process_settings_recursive(&$data, $keyword, $target_url, $anchor_id, $only_first, $depth = 0)
    {
        $total_count = 0;
        $total_linked_count = 0;

        // Prevent infinite recursion
        if ($depth > 10) {
            return ['count' => 0, 'linked_count' => 0];
        }

        // BLACKLIST: Skip fields that are definitely not content or should not contain HTML links
        // 'alt' is critical to exclude as it breaks images when containing HTML
        $blocked_endings = [
            '_id',
            '_token',
            '_url',
            '_link',
            '_src',
            '_class',
            '_css',
            'color',
            'background',
            '_size',
            '_width',
            '_height',
            'align'
        ];

        $blocked_exact = [
            'id',
            'class',
            'url',
            'link',
            'href',
            'src',
            'alt',
            'icon',
            'image',
            'thumbnail',
            'size',
            'width',
            'height',
            'view',
            'html_tag',
            'target',
            'rel',
            'video_url',
            'external_url'
        ];

        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                // Check if key is blocked
                $is_blocked = false;

                if (is_string($key)) {
                    if (in_array($key, $blocked_exact)) {
                        $is_blocked = true;
                    } else {
                        foreach ($blocked_endings as $ending) {
                            if (substr($key, -strlen($ending)) === $ending) {
                                $is_blocked = true;
                                break;
                            }
                        }
                    }
                }

                if ($is_blocked) {
                    continue;
                }

                if (is_string($value) && strlen($value) > 3) {
                    // Check if contains keyword
                    if (stripos($value, $keyword) !== false) {
                        if ($only_first && ($total_count + $total_linked_count) > 0) {
                            continue;
                        }

                        $result = $this->replace_keyword(
                            $value,
                            $keyword,
                            $target_url,
                            $anchor_id,
                            $only_first
                        );
                        $value = $result['text'];
                        $total_count += $result['count'];
                    }
                } elseif (is_array($value) && !$this->is_media_object($value)) {
                    // Recurse but skip media objects
                    if ($only_first && $total_count > 0) {
                        continue;
                    }
                    $count = $this->process_settings_recursive($value, $keyword, $target_url, $anchor_id, $only_first, $depth + 1);
                    $total_count += $count;
                }
            }
        }

        return $total_count;
    }

    /**
     * Check if an array is a media object (image, icon, etc)
     */
    private function is_media_object($arr)
    {
        // Media objects typically have url, id, size keys
        return isset($arr['url']) || isset($arr['id']) || isset($arr['library']);
    }

    /**
     * Get meta keys that might contain HTML/text content
     * Checks for ACF, Yoast, RankMath, WooCommerce, and custom content fields
     * @param bool $include_seo If true, also returns SEO fields (useful for cleanup/removal)
     */
    private function get_content_meta_keys($all_meta, $include_seo = false)
    {
        $content_keys = [];

        // Known content-bearing meta key patterns
        $include_patterns = [
            // ACF fields (text, textarea, wysiwyg)
            '/^_?[a-z_]+$/i',
            // WooCommerce
            '/_product_/',
            '/^_wc_/',
            // Common content fields
            '/content$/i',
            '/text$/i',
            '/excerpt$/i',
            '/bio$/i',
            '/summary$/i',
        ];

        // Keys to always exclude (not content)
        $exclude_patterns = [
            '/^_edit_/',
            '/^_wp_/',
            '/^_elementor/',
            '/^_oembed/',
            '/^_menu_/',
            '/^_thumbnail/',
            '/_hash$/',
            '/_key$/',
            '/_id$/',
            '/_token$/',
            '/^_transient/',
            '/schema$/i',
            '/json$/i',
            // SEO Fields - NEVER link build in these
            '/_yoast_wpseo_/',
            '/^rank_math_/',
            '/_metadesc$/i',
            '/_title$/i',
        ];

        // If cleaning up ($include_seo = true), DO NOT exclude SEO patterns
        if ($include_seo) {
            // Remove SEO patterns from exclude list
            $exclude_patterns = array_diff($exclude_patterns, [
                '/_yoast_wpseo_/',
                '/^rank_math_/',
                '/_metadesc$/i',
                '/_title$/i',
            ]);
        }

        // Also exclude specific keys
        $exclude_exact = [
            '_edit_lock',
            '_edit_last',
            '_wp_page_template',
            '_thumbnail_id',
            '_wp_trash_meta_time',
            '_wp_trash_meta_status'
        ];

        foreach ($all_meta as $key => $values) {
            // Skip excluded exact matches
            if (in_array($key, $exclude_exact))
                continue;

            // Skip excluded patterns
            $excluded = false;
            foreach ($exclude_patterns as $pattern) {
                if (preg_match($pattern, $key)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded)
                continue;

            // Check if value is a string with potential HTML content
            $value = is_array($values) ? ($values[0] ?? '') : $values;
            if (!is_string($value))
                continue;
            if (strlen($value) < 10)
                continue; // Too short to contain useful content

            // Must look like HTML or plain text content (not JSON/serialized)
            if (preg_match('/^[{\["\']/', $value))
                continue; // Skip JSON-like
            if (preg_match('/^a:\d+:{/', $value))
                continue; // Skip serialized arrays

            // Include if it looks like content
            $content_keys[] = $key;
        }

        return $content_keys;
    }
    /**
     * Scan page for keywords without modifying
     */
    public function scan_page($request)
    {
        $page_id = $request['id'];
        $params = $request->get_json_params();
        $keywords = $params['keywords'] ?? []; // Array of {keyword: string}

        if (empty($keywords)) {
            return new WP_Error('no_keywords', 'No keywords provided', ['status' => 400]);
        }

        $page = get_post($page_id);
        if (!$page) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $content = $page->post_content;
        $results = [];

        foreach ($keywords as $kw_data) {
            $keyword = is_array($kw_data) ? $kw_data['keyword'] : $kw_data;

            // Run dry run
            $scan_result = $this->replace_keyword(
                $content,
                $keyword,
                '', // target_url not needed
                '', // anchor_id not needed
                true, // only_first
                true  // dry_run=true
            );

            if ($scan_result['count'] > 0 || ($scan_result['linked_count'] ?? 0) > 0) {
                $results[] = [
                    'keyword' => $keyword,
                    'count' => $scan_result['count'],
                    'linked_count' => $scan_result['linked_count'] ?? 0
                ];
            }
        }

        return rest_ensure_response([
            'success' => true,
            'page_id' => $page_id,
            'candidates' => $results,
            'debug' => [
                'content_length' => strlen($content),
                'keywords_checked' => count($keywords)
            ]
        ]);
    }

    /**
     * Get all internal links (backlinks) from a page
     * Parses the page content and extracts all <a> tags with their anchor text
     */
    public function get_page_links($request)
    {
        $page_id = intval($request['id']);
        $post = get_post($page_id);

        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $links = [];
        $site_url = home_url();

        // Get rendered content to find all visible links
        $content = apply_filters('the_content', $post->post_content);

        // Also check Elementor data (raw JSON contains link markup)
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        if (!empty($elementor_data)) {
            if (is_string($elementor_data)) {
                $content .= $elementor_data; // Will be parsed by regex
            }
        }

        // Parse links using regex (handles both rendered and raw HTML)
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $index => $match) {
            $url = $match[1];
            $anchor = strip_tags($match[2]);

            // Only include internal links
            if (strpos($url, $site_url) === 0 || strpos($url, '/') === 0) {
                $links[] = [
                    'id' => 'link_' . $index . '_' . md5($url . $anchor),
                    'url' => $url,
                    'anchor' => $anchor,
                    'full_html' => $match[0]
                ];
            }
        }

        return rest_ensure_response([
            'success' => true,
            'page_id' => $page_id,
            'page_url' => get_permalink($page_id),
            'links' => $links
        ]);
    }

    /**
     * Remove a specific link from page content
     * Only removes the <a> tag, keeps the anchor text
     * Fixed: Uses URL+anchor matching instead of fragile index-based IDs
     */
    public function remove_link($request)
    {
        $page_id = intval($request['id']);
        $link_id = $request['link_id'];

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('not_found', 'Page not found', ['status' => 404]);
        }

        $site_url = home_url();
        $modified = false;

        // === STRATEGY 1: REMOVE BY HTML ID (Undo Button) ===
        // Supports removing links created by CRM with lb- IDs
        if (strpos($link_id, 'lb-') === 0) {
            $this->log("Attempting removal by HTML ID: $link_id");
            // Pattern to match specific ID <a ... id="lb-..." ...>...</a>
            $pattern_id = '/<a\s+[^>]*\bid=["\']' . preg_quote($link_id, '/') . '["\'][^>]*>(.*?)<\/a>/is';

            // 1. Post Content
            $new_content_id = preg_replace_callback($pattern_id, function ($match) use (&$modified) {
                $modified = true;
                return strip_tags($match[1]); // Return anchor text
            }, $post->post_content);

            if ($modified) {
                wp_update_post([
                    'ID' => $page_id,
                    'post_content' => $new_content_id
                ]);
                $this->log("Removed link by ID '$link_id' from post_content");
                // Refresh post object for subsequent legacy checks if needed (though we return if modified)
                $post->post_content = $new_content_id;
            }

            // 2. Elementor
            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
                if ($data) {
                    $json_str = json_encode($data);
                    $modified_el = false;
                    // Fix: Elementor JSON has escaped quotes (id=\"...\"), so we need regex to match escaped quotes too
                    // Match id="lb-..." OR id=\"lb-...\"
                    // We use [\\\\]? before quotes to allow optional backslash
                    $pattern_id_el = '/<a\s+[^>]*\bid=[\\\\]?["\']' . preg_quote($link_id, '/') . '[\\\\]?["\'][^>]*>(.*?)<\/a>/is';

                    $new_json_str = preg_replace_callback($pattern_id_el, function ($match) use (&$modified_el) {
                        $modified_el = true;
                        return strip_tags($match[1]);
                    }, $json_str);

                    if ($modified_el) {
                        $data = json_decode($new_json_str, true);
                        update_post_meta($page_id, '_elementor_data', wp_slash(json_encode($data)));
                        $this->log("Removed link by ID '$link_id' from Elementor data");
                        $modified = true;
                    }
                }
            }

            // 3. Meta Fields
            $all_meta = get_post_meta($page_id);
            $content_meta_keys = $this->get_content_meta_keys($all_meta, true);
            foreach ($content_meta_keys as $meta_key) {
                $meta_value = get_post_meta($page_id, $meta_key, true);
                if (empty($meta_value) || !is_string($meta_value))
                    continue;

                $meta_modified = false;
                $new_meta_val = preg_replace_callback($pattern_id, function ($match) use (&$meta_modified) {
                    $meta_modified = true;
                    return strip_tags($match[1]);
                }, $meta_value);

                if ($meta_modified) {
                    update_post_meta($page_id, $meta_key, $new_meta_val);
                    $modified = true;
                    $this->log("Removed link by ID '$link_id' from meta field '$meta_key'");
                }
            }

            if ($modified) {
                return rest_ensure_response([
                    'success' => true,
                    'message' => 'Link removed successfully (by ID)'
                ]);
            }
            $this->log("ID '$link_id' not found, falling back to legacy search...");
        }

        // Use RENDERED content to find link ID (must match get_page_links logic)
        $rendered_content = apply_filters('the_content', $post->post_content);
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);

        // Combined content for finding links (same as get_page_links)
        $combined_content = $rendered_content;
        if (!empty($elementor_data) && is_string($elementor_data)) {
            $combined_content .= $elementor_data;
        }

        // Pattern to match <a> tags
        $pattern = '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is';

        // First, find the target link by ID to get its URL and anchor
        $target_url = null;
        $target_anchor = null;

        preg_match_all($pattern, $combined_content, $matches, PREG_SET_ORDER);
        foreach ($matches as $index => $match) {
            $url = $match[1];
            $anchor = strip_tags($match[2]);
            $is_internal = (strpos($url, $site_url) === 0 || strpos($url, '/') === 0);

            if ($is_internal) {
                // Use $index (position in all matches) - same as get_page_links
                $match_id = 'link_' . $index . '_' . md5($url . $anchor);
                if ($match_id === $link_id) {
                    $target_url = $url;
                    $target_anchor = $anchor;
                    break;
                }
            }
        }

        if ($target_url === null) {
            $this->log("Link not found: $link_id in page $page_id");
            return new WP_Error('not_found', 'Link not found', ['status' => 404]);
        }

        $this->log("Found link to remove: URL=$target_url, Anchor=$target_anchor");

        // Normalize target URL for comparison (remove site_url prefix if present)
        $target_url_path = $target_url;
        if (strpos($target_url, $site_url) === 0) {
            $target_url_path = substr($target_url, strlen($site_url));
        }

        // Log for debugging
        $this->log("Searching in post_content (length: " . strlen($post->post_content) . ")");
        $this->log("Target URL path: $target_url_path, Target anchor: $target_anchor");

        // Debug: Find all links in raw post_content to see what's there
        preg_match_all($pattern, $post->post_content, $raw_matches, PREG_SET_ORDER);
        $this->log("Links found in raw post_content: " . count($raw_matches));

        // Log first 3 links found in raw content
        $debug_count = 0;
        foreach ($raw_matches as $rm) {
            if ($debug_count >= 3)
                break;
            $this->log("Raw link $debug_count: URL=" . $rm[1] . ", Anchor=" . strip_tags($rm[2]));
            $debug_count++;
        }

        // Now remove from post_content using URL+anchor matching
        $new_content = preg_replace_callback($pattern, function ($match) use ($target_url, $target_url_path, $target_anchor, $site_url, &$modified) {
            $url = $match[1];
            $anchor = strip_tags($match[2]);

            // Normalize both URLs to handle http vs https differences
            $normalized_url = preg_replace('#^https?://#', '', $url);
            $normalized_target = preg_replace('#^https?://#', '', $target_url);
            $site_domain = preg_replace('#^https?://#', '', $site_url);

            // Extract path from normalized URL
            $url_path = $url;
            if (strpos($normalized_url, $site_domain) === 0) {
                $url_path = substr($normalized_url, strlen($site_domain));
            } elseif (strpos($url, $site_url) === 0) {
                $url_path = substr($url, strlen($site_url));
            }

            // Match by normalized URL or path (handles http/https and relative URLs)
            $url_matches = ($normalized_url === $normalized_target || $url_path === $target_url_path || $url === $target_url_path);

            if ($url_matches && $anchor === $target_anchor && !$modified) {
                $modified = true;
                return strip_tags($match[2]); // Return just the anchor text without the <a> tag
            }
            return $match[0];
        }, $post->post_content);

        $this->log("Modified after post_content check: " . ($modified ? 'YES' : 'NO'));

        if ($modified) {
            // Update regular content
            wp_update_post([
                'ID' => $page_id,
                'post_content' => $new_content
            ]);
            $this->log("Removed link from post_content in page $page_id");
        } else {
            $this->log("Not found in post_content, trying Elementor data...");
            // Try in Elementor data
            if (!empty($elementor_data)) {
                $this->log("Elementor data exists (length: " . strlen($elementor_data) . ")");
                $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
                if ($data) {
                    $this->log("Elementor JSON parsed, searching for link...");
                    $this->remove_link_by_target($data, $target_url, $target_anchor, $modified);
                    $this->log("After Elementor search, modified: " . ($modified ? 'YES' : 'NO'));
                    if ($modified) {
                        update_post_meta($page_id, '_elementor_data', wp_slash(json_encode($data)));
                        $this->log("Removed link from Elementor data in page $page_id");
                    }
                } else {
                    $this->log("Failed to parse Elementor JSON");
                }
            } else {
                $this->log("No Elementor data found for this page");
            }
        }

        // Also check all meta fields (in case it was injected there by older version)
        if (!$modified) {
            $this->log("Not found in content/elementor, checking meta fields...");
            $all_meta = get_post_meta($page_id);
            // We use the SAME logic as we used to inject, to find where it might be
            // Note: We intentionally don't filter out SEO fields here because we want to CLEAN them
            $content_meta_keys = $this->get_content_meta_keys($all_meta, true);

            foreach ($content_meta_keys as $meta_key) {
                $meta_value = get_post_meta($page_id, $meta_key, true);
                if (empty($meta_value) || !is_string($meta_value))
                    continue;

                $meta_modified = false;
                // Use regex replace logic on meta value
                $new_meta_val = preg_replace_callback($pattern, function ($match) use ($target_url, $target_url_path, $target_anchor, $site_url, &$meta_modified) {
                    $url = $match[1];
                    $anchor = strip_tags($match[2]);

                    // Normalize (reuse match logic)
                    $normalized_url = preg_replace('#^https?://#', '', $url);
                    $normalized_target = preg_replace('#^https?://#', '', $target_url);
                    $site_domain = preg_replace('#^https?://#', '', $site_url);

                    $url_path = $url;
                    if (strpos($normalized_url, $site_domain) === 0) {
                        $url_path = substr($normalized_url, strlen($site_domain));
                    } elseif (strpos($url, $site_url) === 0) {
                        $url_path = substr($url, strlen($site_url));
                    }

                    $url_matches = ($normalized_url === $normalized_target || $url_path === $target_url_path || $url === $target_url_path);

                    if ($url_matches && $anchor === $target_anchor) {
                        $meta_modified = true;
                        return strip_tags($match[2]);
                    }
                    return $match[0];
                }, $meta_value);

                if ($meta_modified) {
                    update_post_meta($page_id, $meta_key, $new_meta_val);
                    $modified = true;
                    $this->log("Removed link from meta field '$meta_key' in page $page_id");
                }
            }
        }

        if (!$modified) {
            return new WP_Error('not_found', 'Link not found in content or meta', ['status' => 404]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Link removed successfully'
        ]);
    }

    /**
     * Remove link by URL and anchor text (more reliable than index-based matching)
     */
    private function remove_link_by_target(&$data, $target_url, $target_anchor, &$modified)
    {
        $pattern = '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is';
        $site_url = home_url();

        // Normalize target URL
        $target_url_path = $target_url;
        if (strpos($target_url, $site_url) === 0) {
            $target_url_path = substr($target_url, strlen($site_url));
        }

        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (is_string($value) && preg_match($pattern, $value)) {
                    $value = preg_replace_callback($pattern, function ($match) use ($target_url, $target_url_path, $target_anchor, $site_url, &$modified) {
                        $url = $match[1];
                        $anchor = strip_tags($match[2]);

                        // Normalize matched URL
                        $url_path = $url;
                        if (strpos($url, $site_url) === 0) {
                            $url_path = substr($url, strlen($site_url));
                        }

                        // Match with normalized URLs
                        if (($url === $target_url || $url_path === $target_url_path || $url === $target_url_path) && $anchor === $target_anchor && !$modified) {
                            $modified = true;
                            return strip_tags($match[2]);
                        }
                        return $match[0];
                    }, $value);
                } elseif (is_array($value)) {
                    $this->remove_link_by_target($value, $target_url, $target_anchor, $modified);
                }
            }
        }
    }

    /**
     * Recursively remove link from Elementor data (legacy, kept for compatibility)
     * @deprecated Use remove_link_by_target instead
     */
    private function remove_link_recursive(&$data, $link_id, &$modified)
    {
        $pattern = '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is';

        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (is_string($value) && preg_match($pattern, $value)) {
                    $value = preg_replace_callback($pattern, function ($match) use ($link_id, &$modified) {
                        $url = $match[1];
                        $anchor = $match[2];
                        $match_id = 'link_' . $GLOBALS['_link_index'] . '_' . md5($url . strip_tags($anchor));
                        $GLOBALS['_link_index']++;

                        if ($match_id === $link_id) {
                            $modified = true;
                            return strip_tags($anchor);
                        }
                        return $match[0];
                    }, $value);
                } elseif (is_array($value)) {
                    $this->remove_link_recursive($value, $link_id, $modified);
                }
            }
        }
    }

    /**
     * Replace keyword with link in text using DOMDocument
     * Robustly handles HTML structure, excluding headings, existing links, etc.
     * @param bool $dry_run If true, only counts potential replacements without modifying text
     */
    private function replace_keyword($text, $keyword, $target_url, $anchor_id, $only_first, $dry_run = false)
    {
        $result = [
            'text' => $text,
            'count' => 0,
            'linked_count' => 0,
            'skipped' => []
        ];

        if (empty($text) || !is_string($text)) {
            return $result;
        }

        // Check if text has any HTML tags
        $has_html = $text !== strip_tags($text);

        // If simple text (no HTML), use simple replacement but safer
        if (!$has_html) {
            // Basic word boundary check for plain text
            $pattern = '/(?<![a-zA-Z\p{L}])(' . preg_quote($keyword, '/') . ')(?![a-zA-Z\p{L}])/iu';
            $count = 0;
            $new_text = preg_replace_callback($pattern, function ($matches) use ($target_url, $anchor_id, $only_first, &$count, $dry_run) {
                if ($only_first && $count > 0)
                    return $matches[0];

                $count++;

                if ($dry_run) {
                    return $matches[0];
                }

                return '<a href="' . esc_url($target_url) . '" id="' . esc_attr($anchor_id) . '" class="map-auto-link">' . $matches[1] . '</a>';
            }, $text);

            $result['text'] = $new_text;
            $result['count'] = $count;
            return $result;
        }

        // Use DOMDocument for HTML
        // Suppress warnings for malformed HTML (common in partial content)
        $dom = new DOMDocument();
        $dom->encoding = 'UTF-8';

        // Use XML declaration to preserve UTF-8 - DO NOT use mb_convert_encoding to HTML-ENTITIES as it breaks non-Latin chars!
        // Wrap in a root element to handle partials correctly
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $text . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Find all text nodes
        $text_nodes = $xpath->query('//text()');

        $count = 0;
        $processed_keyword = mb_strtolower($keyword, 'UTF-8');
        $skipped_nodes = [];

        // Forbidden parent tags (plus Gutenberg block comments are comments, so query('//text()') skips them automatically)
        $forbidden_tags = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'script', 'style', 'textarea', 'pre', 'code', 'button', 'select', 'option'];

        $debug_stats = [
            'nodes_visited' => 0,
            'nodes_with_keyword' => 0,
            'skipped_nodes' => [],
            'regex_matches' => 0
        ];

        foreach ($text_nodes as $node) {
            $debug_stats['nodes_visited']++;
            // Check global limit including existing matches
            if ($only_first && ($count + $result['linked_count']) > 0)
                break;

            $content = $node->nodeValue;
            if (mb_stripos($content, $keyword, 0, 'UTF-8') === false)
                continue;

            $debug_stats['nodes_with_keyword']++;

            // Check ancestry for forbidden tags
            $parent = $node->parentNode;
            $is_forbidden = false;
            while ($parent && $parent->nodeName !== 'div') { // 'div' is our wrapper
                if (in_array(strtolower($parent->nodeName), $forbidden_tags)) {
                    $is_forbidden = true;
                    // If it's an existing link, count it as linked!
                    if (strtolower($parent->nodeName) === 'a') {
                        $result['linked_count']++; // Count as existing link
                    }

                    // Log skip reason
                    $result['skipped'][] = [
                        'reason' => 'in_metadata',
                        'location' => $parent->nodeName,
                        'sample' => substr($content, 0, 60)
                    ];
                    $debug_stats['skipped_nodes'][] = "Skipped in {$parent->nodeName}";
                    break;
                }
                $parent = $parent->parentNode;
            }
            if ($is_forbidden)
                continue;

            // Double check limit after potential increment from existing link
            if ($only_first && ($count + $result['linked_count']) > 0)
                continue;

            // Safe to link here
            // We need to split the text node and insert an element

            // Regex for case-insensitive match with word boundaries
            // Note: DOM text content doesn't have HTML tags, so safe to regex
            $pattern = '/(?<![a-zA-Z\p{L}])(' . preg_quote($keyword, '/') . ')(?![a-zA-Z\p{L}])/iu';

            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                // Determine offset in bytes/chars carefully? 
                // preg_match returns byte offset. PHP strings are byte arrays. DOM nodeValue is UTF-8 string.
                // It's safer to use splitText but that requires index.
                // Alternative: replace the text node with a document fragment containing the link

                $frag = $dom->createDocumentFragment();

                // Split content by keyword
                // Use preg_split to capture the keyword with delimiter to keep it
                $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($parts as $part) {
                    if (mb_strtolower($part, 'UTF-8') === $processed_keyword) {
                        // This is the keyword (or matches it insensitive)
                        // Create link
                        if ($only_first && $count > 0) {
                            $frag->appendChild($dom->createTextNode($part));
                        } else {
                            if ($dry_run) {
                                $frag->appendChild($dom->createTextNode($part));
                                $count++;
                            } else {
                                $link = $dom->createElement('a');
                                $link->setAttribute('href', $target_url);
                                $link->setAttribute('id', $anchor_id);
                                $link->setAttribute('class', 'map-auto-link');
                                $link->nodeValue = $part;
                                $frag->appendChild($link);
                                $count++;
                            }
                        }
                    } else {
                        // Regular text
                        $frag->appendChild($dom->createTextNode($part));
                    }
                }

                $node->parentNode->replaceChild($frag, $node);
            }
        }

        // Save back to HTML
        // Remove the wrapper <div>
        $body = $dom->getElementsByTagName('div')->item(0);
        $new_html = '';
        foreach ($body->childNodes as $child) {
            $new_html .= $dom->saveHTML($child);
        }

        $result['text'] = $new_html;
        $result['count'] = $count;
        // Skipped array is populated during loop

        return $result;
    }

    /**
     * Health check endpoint
     */
    public function health_check($request)
    {
        return rest_ensure_response([
            'status' => 'ok',
            'version' => $this->version,
            'elementor_active' => class_exists('\Elementor\Plugin'),
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Get plugin logs
     */
    public function get_logs()
    {
        $log_dir = WP_CONTENT_DIR . '/uploads/mehrana-logs';
        $log_file = $log_dir . '/app.log';

        // Migrate old log file if it exists
        $old_log = WP_CONTENT_DIR . '/mehrana-app.log';
        if (file_exists($old_log)) {
            if (!is_dir($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            // Protect directory
            if (!file_exists($log_dir . '/.htaccess')) {
                file_put_contents($log_dir . '/.htaccess', "Order deny,allow\nDeny from all");
            }
            if (!file_exists($log_dir . '/index.php')) {
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
            }
            rename($old_log, $log_file);
        }

        if (!file_exists($log_file)) {
            return rest_ensure_response(['logs' => 'No log file found.']);
        }

        $content = file_get_contents($log_file);
        // Split into lines, reverse (newest first), take last 200 entries
        $lines = array_filter(explode("\n", trim($content)));
        $lines = array_reverse($lines);
        $lines = array_slice($lines, 0, 200);
        $content = implode("\n", $lines);

        return rest_ensure_response(['logs' => $content]);
    }

    /**
     * Log activity
     */
    private function log($message)
    {
        if (get_option('map_enable_logging', '1') === '1') {
            $log_dir = WP_CONTENT_DIR . '/uploads/mehrana-logs';
            if (!is_dir($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            // Protect directory from public access
            if (!file_exists($log_dir . '/.htaccess')) {
                file_put_contents($log_dir . '/.htaccess', "Order deny,allow\nDeny from all");
            }
            if (!file_exists($log_dir . '/index.php')) {
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.');
            }

            $log_file = $log_dir . '/app.log';
            // Eastern timezone timestamp
            $tz = new \DateTimeZone('America/New_York');
            $now = new \DateTime('now', $tz);
            $timestamp = $now->format('Y-m-d H:i:s T');
            $user = wp_get_current_user();
            $user_name = $user ? $user->user_login : 'unknown';
            $log_entry = "[{$timestamp}] [{$user_name}] {$message}\n";
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

            // Rotate: keep max 500KB
            if (file_exists($log_file) && filesize($log_file) > 500 * 1024) {
                $lines = file($log_file);
                $lines = array_slice($lines, -2000); // Keep last 2000 lines
                file_put_contents($log_file, implode('', $lines), LOCK_EX);
            }
        }
    }

    /**
     * Admin menu
     */
    public function add_admin_menu()
    {
        add_options_page(
            'Mehrana App Settings',
            'Mehrana App',
            'manage_options',
            'mehrana-app',
            [$this, 'settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        register_setting('map_settings', 'map_allowed_origins');
        register_setting('map_settings', 'map_enable_logging');
        register_setting('map_settings', 'map_api_key');
        register_setting('map_settings', 'map_gtm_id');
        register_setting('map_settings', 'map_custom_head_code');
    }

    /**
     * Inject GTM script into <head>
     */
    public function inject_gtm_head()
    {
        $gtm_id = get_option('map_gtm_id');
        if (empty($gtm_id)) {
            return;
        }
        ?>
        <!-- Google Tag Manager -->
        <script>(fun        ct            io                  n(w, d, s, l, i)                   {
                w[l] = w[l] || []; w[l].push({
                    'gtm.start':
                        new Date().getTime(), event: 'gtm.js'
                }); var f = d.getElementsByTagName(s)[0],
                    j = d.createEle    ment(s), dl = l != 'dataLayer' ? '    &l=' + l : '     '; j.async = true; j.src =
                        'https://www.googletagmanager.com/gtm.js?id=' + i + dl; f.parentNode.insertBefore(j, f);
            }) (window, document, 'script', 'dataLayer', '<?php echo esc_attr($gtm_id); ?>');</script>
        <!-- End Google Tag Manager -->
        <?php
    }

    /**
     * Inject GTM noscript into <body>
     */
    public function inject_gtm_body()
    {
        $gtm_id = get_option('map_gtm_id');
        if (empty($gtm_id)) {
            return;
        }
        ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm_id); ?>" height="0"
                width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        <?php
    }

    /**
     * Inject custom head code (Microsoft Clarity, etc.)
     */
    public function inject_custom_head_code()
    {
        $custom_code = get_option('map_custom_head_code');
        if (empty($custom_code)) {
            return;
        }
        // Output the custom code as-is (already contains script tags)
        echo "\n" . $custom_code . "\n";
    }

    /**
     * Settings page HTML
     */
    public function settings_page()
    {
        ?>
        <style>
            .map-settings-wrap {
                max-width: 900px;
            }

            .map-settings-wrap .map-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 20px;
            }

            .map-settings-wrap .map-header h1 {
                margin: 0;
                font-size: 23px;
                font-weight: 400;
            }

            .map-settings-wrap .map-header .version-badge {
                background: #0073aa;
                color: #fff;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
            }

            .map-settings-wrap .map-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            }

            .map-settings-wrap .map-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
                color: #1e3a5f;
            }

            .map-settings-wrap .map-card h3 {
                margin: 20px 0 10px;
                color: #333;
                font-size: 14px;
            }

            .map-settings-wrap .map-field-row {
                margin-bottom: 20px;
            }

            .map-settings-wrap .map-field-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
                color: #1e3a5f;
            }

            .map-settings-wrap .map-field-row .description {
                color: #666;
                font-size: 13px;
                margin-top: 5px;
            }

            .map-settings-wrap .map-field-row input[type="text"],
            .map-settings-wrap .map-field-row input[type="password"],
            .map-settings-wrap .map-field-row textarea {
                width: 100%;
                max-width: 400px;
            }

            .map-settings-wrap .map-field-row textarea {
                max-width: 100%;
                font-family: 'Courier New', monospace;
                font-size: 12px;
            }

            .map-settings-wrap .map-api-key-wrapper {
                display: flex;
                gap: 8px;
                align-items: center;
                flex-wrap: wrap;
            }

            .map-settings-wrap .map-api-key-wrapper input {
                flex: 1;
                min-width: 200px;
                max-width: 350px;
            }

            .map-settings-wrap .map-toggle-btn {
                padding: 6px 12px;
                background: #f0f0f0;
                border: 1px solid #ccc;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            }

            .map-settings-wrap .map-toggle-btn:hover {
                background: #e0e0e0;
            }

            .map-settings-wrap .map-info-table {
                width: 100%;
                border-collapse: collapse;
            }

            .map-settings-wrap .map-info-table td {
                padding: 10px;
                border-bottom: 1px solid #eee;
                vertical-align: top;
            }

            .map-settings-wrap .map-info-table td:first-child {
                width: 150px;
                font-weight: 600;
                color: #1e3a5f;
            }

            .map-settings-wrap .map-info-table code {
                background: #f5f5f5;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }

            .map-settings-wrap .map-checkbox-label {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
            }

            .map-settings-wrap .map-success-msg {
                background: #d4edda;
                color: #155724;
                padding: 10px 15px;
                border-radius: 4px;
                margin-top: 10px;
            }
        </style>

        <div class="wrap map-settings-wrap">
            <h1 class="map-header">Mehrana App <span class="version-badge">
                    <?php echo esc_html($this->version); ?>
                </span></h1>

            <form method="post" action="options.php">
                <?php settings_fields('map_settings'); ?>

                <!-- Authentication Settings -->
                <div class="map-card">
                    <h2>🔐 Authentication</h2>

                    <div class="map-field-row">
                        <label for="map_api_key">API Key</label>
                        <div class="map-api-key-wrapper">
                            <input type="password" name="map_api_key" id="map_api_key"
                                value="<?php echo esc_attr(get_option('map_api_key')); ?>"
                                placeholder="Click 'Generate Key' to create" />
                            <button type="button" class="map-toggle-btn" onclick="mapToggleApiKey()" id="map_toggle_btn"
                                title="Show/Hide API Key">👁️</button>
                            <button type="button" class="button"
                                onclick="document.getElementById('map_api_key').value = 'map_' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);">🔑
                                Generate Key</button>
                        </div>
                        <p class="description">
                            <strong>Recommended:</strong> Use this API Key for authentication. Send it as
                            <code>X-MAP-API-Key</code> header.<br>
                            No Application Password needed when using API Key!
                        </p>
                    </div>

                    <div class="map-field-row">
                        <label for="map_allowed_origins">Allowed Origins</label>
                        <input type="text" name="map_allowed_origins" id="map_allowed_origins"
                            value="<?php echo esc_attr(get_option('map_allowed_origins')); ?>"
                            placeholder="https://app.example.com, https://crm.example.com" />
                        <p class="description">Comma-separated list of allowed origins. Leave empty to allow all authenticated
                            requests.</p>
                    </div>
                </div>

                <!-- Tracking & Analytics -->
                <div class="map-card">
                    <h2>📊 Tracking & Analytics</h2>

                    <div class="map-field-row">
                        <label for="map_gtm_id">Google Tag Manager ID</label>
                        <input type="text" name="map_gtm_id" id="map_gtm_id"
                            value="<?php echo esc_attr(get_option('map_gtm_id')); ?>" placeholder="GTM-XXXXXXX"
                            style="max-width: 200px;" />
                        <p class="description">
                            Enter your GTM Container ID (e.g., <code>GTM-XXXXXXX</code>).<br>
                            The GTM code will be automatically injected into all pages.
                        </p>
                    </div>

                    <div class="map-field-row">
                        <label for="map_custom_head_code">Custom Head Code</label>
                        <textarea name="map_custom_head_code" id="map_custom_head_code" rows="6"
                            placeholder="<!-- Paste your tracking code here -->"><?php echo esc_textarea(get_option('map_custom_head_code')); ?></textarea>
                        <p class="description">
                            Paste any custom tracking code here (e.g., <strong>Microsoft Clarity</strong>, <strong>Facebook
                                Pixel</strong>, <strong>Hotjar</strong>, etc.).<br>
                            This code will be injected into the <code>&lt;head&gt;</code> of all pages.
                        </p>
                    </div>
                </div>

                <!-- Advanced Settings -->
                <div class="map-card">
                    <h2>⚙️ Advanced Settings</h2>

                    <div class="map-field-row">
                        <label class="map-checkbox-label">
                            <input type="checkbox" name="map_enable_logging" value="1" <?php checked(get_option('map_enable_logging', '1'), '1'); ?> />
                            Enable API Logging
                        </label>
                        <p class="description">Log all API activity to <code>wp-content/uploads/mehrana-logs/app.log</code>
                            (access-protected) for debugging
                            purposes.</p>
                    </div>
                </div>

                <?php submit_button('Save Changes', 'primary', 'submit', true); ?>
            </form>

            <!-- API Information -->
            <div class="map-card">
                <h2>📡 API Information</h2>
                <table class="map-info-table">
                    <tr>
                        <td>Base URL</td>
                        <td><code><?php echo esc_html(rest_url($this->namespace)); ?></code></td>
                    </tr>
                    <tr>
                        <td>Authentication</td>
                        <td>
                            <strong>Option 1 (Recommended):</strong> API Key via <code>X-MAP-API-Key</code> header<br>
                            <strong>Option 2:</strong> WordPress Application Passwords (Basic Auth)
                        </td>
                    </tr>
                    <tr>
                        <td>Endpoints</td>
                        <td>
                            <code>GET /pages</code> — Get all content pages<br>
                            <code>POST /pages/{id}/apply-links</code> — Apply links to a page<br>
                            <code>POST /pages/{id}/scan</code> — Scan page for keywords<br>
                            <code>GET /pages/{id}/links</code> — Get existing backlinks<br>
                            <code>DELETE /pages/{id}/links/{link_id}</code> — Remove a backlink<br>
                            <code>GET /health</code> — Health check
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Plugin Updates -->
            <div class="map-card">
                <h2>🔄 Plugin Updates</h2>
                <table class="map-info-table">
                    <tr>
                        <td>Current Version</td>
                        <td><strong>
                                <?php echo esc_html($this->version); ?>
                            </strong></td>
                    </tr>
                    <tr>
                        <td>Check for Updates</td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('map_check_update', 'map_update_nonce'); ?>
                                <button type="submit" name="map_check_update" class="button button-secondary">
                                    🔄 Check for Updates Now
                                </button>
                            </form>
                            <?php
                            if (isset($_POST['map_check_update']) && wp_verify_nonce($_POST['map_update_nonce'], 'map_check_update')) {
                                // Clear cache
                                delete_transient('mehrana_app_github_release');
                                delete_site_transient('update_plugins');

                                // Force check
                                $debug_info = $this->get_github_release_info(true); // Call with debug flag
                                wp_update_plugins();

                                echo '<div class="map-success-msg" style="margin-top:10px; border-left:4px solid #46b450; padding:10px; background:#fff;">';
                                echo '<strong>✅ Diagnostics Run:</strong><br>';
                                if (isset($debug_info['error'])) {
                                    echo '<span style="color:#d63638">❌ API Error: ' . esc_html($debug_info['error']) . '</span><br>';
                                    if (isset($debug_info['response_code']))
                                        echo 'Response Code: ' . $debug_info['response_code'] . '<br>';
                                    if (isset($debug_info['body']))
                                        echo 'Response Body (excerpt): ' . esc_html(substr($debug_info['body'], 0, 200)) . '...<br>';
                                } elseif (isset($debug_info['tag_name'])) {
                                    echo '<span style="color:#46b450">✅ Found Tag: ' . esc_html($debug_info['tag_name']) . '</span><br>';
                                    echo 'Latest Version: ' . ltrim($debug_info['tag_name'], 'v') . '<br>';
                                    echo 'Your Version: ' . $this->version . '<br>';
                                    if (version_compare($this->version, ltrim($debug_info['tag_name'], 'v'), '<')) {
                                        echo '<strong>🟢 Update Available!</strong> Refresh this page to see it.';
                                    } else {
                                        echo '<strong>⚪ You are on the latest version.</strong>';
                                    }
                                } else {
                                    echo '❓ Unknown Response format.';
                                }
                                echo '</div>';
                            }
                            ?>
                            <p class="description">Click to force check GitHub for plugin updates (bypasses 12-hour cache)</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <script>
            function mapToggleApiKey() {
                var input = document.getElementById('map_api_key');
                var btn = document.getElementById('map_toggle_btn');
                if (input.type === 'password') {
                    input.type = 'text';
                    btn.textContent = '🙈';
                    btn.title = 'Hide API Key';
                } else {
                    input.type = 'password';
                    btn.textContent = '👁️';
                    btn.title = 'Show API Key';
                }
            }
        </script>
        <?php
    }

    /**
     * Check GitHub for plugin updates
     * Adds update info to WordPress transient if new version available
     */
    public function check_for_github_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get plugin data
        $plugin_slug = 'mehrana-app/mehrana-app.php';

        // Check GitHub API for latest release
        $github_response = $this->get_github_release_info();

        if ($github_response && isset($github_response['tag_name'])) {
            $latest_version = ltrim($github_response['tag_name'], 'v');

            // Compare versions
            if (version_compare($this->version, $latest_version, '<')) {
                $transient->response[$plugin_slug] = (object) [
                    'slug' => 'mehrana-app',
                    'plugin' => $plugin_slug,
                    'new_version' => $latest_version,
                    'url' => "https://github.com/{$this->github_username}/{$this->github_repo}",
                    'package' => $this->get_github_download_url($github_response),
                    'tested' => '6.4',
                    'requires_php' => '7.4'
                ];
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for WordPress plugin details popup
     */
    public function github_plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== 'mehrana-app') {
            return $result;
        }

        $github_response = $this->get_github_release_info();

        if (!$github_response) {
            return $result;
        }

        return (object) [
            'name' => 'Mehrana App Plugin',
            'slug' => 'mehrana-app',
            'version' => ltrim($github_response['tag_name'], 'v'),
            'author' => '<a href="https://mehrana.agency">Mehrana Agency</a>',
            'homepage' => "https://github.com/{$this->github_username}/{$this->github_repo}",
            'short_description' => 'Headless SEO & Optimization Plugin for Mehrana App',
            'sections' => [
                'description' => $github_response['body'] ?? 'Link Building, Image Optimization & More',
                'changelog' => $github_response['body'] ?? 'See GitHub releases for changelog'
            ],
            'download_link' => $this->get_github_download_url($github_response),
            'tested' => '6.4',
            'requires_php' => '7.4',
            'last_updated' => $github_response['published_at'] ?? ''
        ];
    }

    /**
     * Handle post-install tasks (rename folder after update)
     */
    public function after_install($response, $hook_extra, $result)
    {
        global $wp_filesystem;

        // Get plugin directory
        $plugin_folder = WP_PLUGIN_DIR . '/mehrana-app/';

        // Move files to correct location if needed
        if (isset($result['destination'])) {
            $wp_filesystem->move($result['destination'], $plugin_folder);
            $result['destination'] = $plugin_folder;
        }

        // Activate plugin
        activate_plugin('mehrana-app/mehrana-app.php');

        return $result;
    }

    /**
     * Get latest release info from GitHub API
     */
    /**
     * Get latest release info from GitHub API
     */
    private function get_github_release_info($debug = false)
    {
        $transient_key = 'mehrana_app_github_release';
        $cached = get_transient($transient_key);

        if ($cached !== false && !$debug) {
            return $cached;
        }

        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            return $debug ? ['error' => $response->get_error_message()] : false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return $debug ? [
                'error' => "HTTP $code",
                'response_code' => $code,
                'body' => wp_remote_retrieve_body($response)
            ] : false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Cache for 12 hours
        set_transient($transient_key, $data, 12 * HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Get download URL for plugin zip from GitHub release
     */
    private function get_github_download_url($release_info)
    {
        // First check for uploaded asset named mehrana-app.zip
        if (!empty($release_info['assets'])) {
            foreach ($release_info['assets'] as $asset) {
                if (strpos($asset['name'], 'mehrana-app') !== false && strpos($asset['name'], '.zip') !== false) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fallback to raw plugin file from repo
        // Note: This won't work for full plugin, need to create release with zip asset
        return "https://github.com/{$this->github_username}/{$this->github_repo}/archive/refs/tags/{$release_info['tag_name']}.zip";
    }

    // ===== LOCAL SEO v4 METHODS =====

    /**
     * Clone a page from template (for Local SEO page generation)
     * Copies Elementor data, post content, and applies string replacements
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function clone_page($request)
    {
        $template_id = intval($request['template_id']);
        $new_title = sanitize_text_field($request['new_title']);
        $new_slug = sanitize_title($request['new_slug']);
        $status = isset($request['status']) ? sanitize_text_field($request['status']) : 'draft';
        $replacements = isset($request['replacements']) ? $request['replacements'] : [];
        $parent_id = isset($request['parent_id']) ? intval($request['parent_id']) : 0;

        // Handle parent_path (e.g., "burnaby/commercial") - resolve to parent_id
        if (!empty($request['parent_path']) && $parent_id === 0) {
            $clean_path = trim($request['parent_path'], '/');
            $segments = array_filter(explode('/', $clean_path));
            $current_parent_id = 0;

            foreach ($segments as $segment) {
                $segment_slug = sanitize_title($segment);

                // Try to find existing page by slug AND parent
                $args = [
                    'name' => $segment_slug,
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'numberposts' => 1,
                    'post_parent' => $current_parent_id
                ];

                $existing_pages = get_posts($args);

                if (!empty($existing_pages)) {
                    $current_parent_id = $existing_pages[0]->ID;
                } else {
                    // Create new parent page if it doesn't exist
                    $new_parent_data = [
                        'post_title' => ucwords(str_replace('-', ' ', $segment)),
                        'post_name' => $segment_slug,
                        'post_status' => 'publish', // Parents are usually published
                        'post_type' => 'page',
                        'post_parent' => $current_parent_id,
                        'post_author' => get_current_user_id() > 0 ? get_current_user_id() : 1
                    ];

                    $new_parent_id = wp_insert_post($new_parent_data);

                    if (!is_wp_error($new_parent_id)) {
                        $this->log("[CLONE_PAGE] Created missing parent: {$segment} (ID: {$new_parent_id})");
                        $current_parent_id = $new_parent_id;
                    } else {
                        $this->log("[CLONE_PAGE] Error creating parent {$segment}: " . $new_parent_id->get_error_message());
                        break;
                    }
                }
            }
            $parent_id = $current_parent_id;
            $this->log("[CLONE_PAGE] Resolved parent_path to ID: {$parent_id}");
        }

        // Validate status
        if (!in_array($status, ['draft', 'publish', 'pending', 'private'])) {
            $status = 'draft';
        }

        // Get template post
        $template = get_post($template_id);
        if (!$template) {
            return new WP_Error('template_not_found', 'Template page not found', ['status' => 404]);
        }

        $this->log("[CLONE_PAGE] Cloning template ID: {$template_id} to: {$new_title} ({$new_slug})");

        // Get template content
        $post_content = $template->post_content;
        $elementor_data = get_post_meta($template_id, '_elementor_data', true);

        // Apply string replacements to content
        if (!empty($replacements) && is_array($replacements)) {
            foreach ($replacements as $search => $replace) {
                $search = (string) $search;
                $replace = (string) $replace;

                // Replace in post_content
                $post_content = str_replace($search, $replace, $post_content);

                // Replace in Elementor data
                if (!empty($elementor_data)) {
                    $elementor_data = str_replace($search, $replace, $elementor_data);
                }
            }
            $this->log("[CLONE_PAGE] Applied " . count($replacements) . " replacements");
        }

        // Create new post
        $new_post_data = [
            'post_title' => $new_title,
            'post_name' => $new_slug,
            'post_content' => $post_content,
            'post_status' => $status,
            'post_type' => $template->post_type,
            'post_parent' => $parent_id,
            'post_author' => get_current_user_id() > 0 ? get_current_user_id() : 1
        ];

        $new_post_id = wp_insert_post($new_post_data, true);

        if (is_wp_error($new_post_id)) {
            $this->log("[CLONE_PAGE] Error creating post: " . $new_post_id->get_error_message());
            return $new_post_id;
        }

        $this->log("[CLONE_PAGE] Created post ID: {$new_post_id}");

        // Copy Elementor data
        if (!empty($elementor_data)) {
            update_post_meta($new_post_id, '_elementor_data', wp_slash($elementor_data));
            update_post_meta($new_post_id, '_elementor_edit_mode', 'builder');
            update_post_meta($new_post_id, '_elementor_version', get_post_meta($template_id, '_elementor_version', true));
            update_post_meta($new_post_id, '_elementor_template_type', get_post_meta($template_id, '_elementor_template_type', true));

            // Copy page template
            $page_template = get_post_meta($template_id, '_wp_page_template', true);
            if (!empty($page_template)) {
                update_post_meta($new_post_id, '_wp_page_template', $page_template);
            }

            $this->log("[CLONE_PAGE] Copied Elementor data and template settings");
        }

        // Copy SEO meta if present (with replacements)
        $seo_meta_keys = [
            'rank_math_title',
            'rank_math_description',
            'rank_math_focus_keyword',
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_focuskw'
        ];

        foreach ($seo_meta_keys as $key) {
            $value = get_post_meta($template_id, $key, true);
            if (!empty($value)) {
                // Apply replacements to SEO fields too
                if (!empty($replacements)) {
                    foreach ($replacements as $search => $replace) {
                        $value = str_replace((string) $search, (string) $replace, $value);
                    }
                }
                update_post_meta($new_post_id, $key, $value);
            }
        }

        $new_url = get_permalink($new_post_id);
        $this->log("[CLONE_PAGE] Complete. New page URL: {$new_url}");

        return rest_ensure_response([
            'success' => true,
            'post_id' => $new_post_id,
            'url' => $new_url,
            'title' => $new_title,
            'slug' => $new_slug,
            'status' => $status,
            'message' => "Page created successfully from template #{$template_id}"
        ]);
    }

    /**
     * Create a redirect (301/302) using available plugins or custom table
     * Supports: Rank Math, Yoast, Redirection plugin, or custom meta
     *
     * Accepts `match_type`: one of exact|contains|start|end|regex (default exact).
     * Regex quirk: Rank Math evaluates patterns against the URL path WITHOUT the
     * leading slash, so we strip one `/` right after `^` before storing. Users can
     * keep writing `^/product/(.*)$` in Patrick — we rewrite to `^product/(.*)$`.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_redirect($request)
    {
        $from_url = $request['from_url'];
        $to_url = $request['to_url'];
        $type = isset($request['type']) ? intval($request['type']) : 301;
        $match_type = isset($request['match_type']) ? sanitize_key($request['match_type']) : 'exact';
        $valid_match_types = ['exact', 'contains', 'start', 'end', 'regex'];
        if (!in_array($match_type, $valid_match_types, true)) {
            $match_type = 'exact';
        }

        // Destination: strip domain if a full site URL
        $site_url = home_url();
        $to_path = str_replace($site_url, '', $to_url);

        // Source pattern: regex patterns must NOT be treated as URL paths.
        // Non-regex types: strip domain, enforce leading slash.
        if ($match_type === 'regex') {
            $from_path = trim($from_url);
            // Rank Math matches the path without the leading slash — normalize
            // `^/foo/...` to `^foo/...` so the user's natural regex works.
            $from_path = preg_replace('#^\^/+#', '^', $from_path);
            // Also handle patterns that lead with `/` but have no `^` anchor.
            if (strpos($from_path, '^') !== 0) {
                $from_path = ltrim($from_path, '/');
            }
        } else {
            $from_path = str_replace($site_url, '', $from_url);
            if (strpos($from_path, '/') !== 0) {
                $from_path = '/' . $from_path;
            }
        }

        $this->log("[CREATE_REDIRECT] Creating {$type} {$match_type} redirect: {$from_path} → {$to_url}");

        $method_used = null;

        // Method 1: Try Rank Math Redirection (most common for SEO-focused sites)
        if (class_exists('RankMath\\Redirections\\Redirection')) {
            try {
                global $wpdb;
                $table = $wpdb->prefix . 'rank_math_redirections';

                // Check if table exists
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                    // Check if redirect already exists with SAME pattern + comparison.
                    // Previous versions used a loose LIKE check which produced false
                    // "already exists" errors for regex patterns that happened to share
                    // a substring with another rule.
                    $needle = serialize([[ 'pattern' => $from_path, 'comparison' => $match_type ]]);
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM $table WHERE sources = %s AND status != 'trashed'",
                        $needle
                    ));

                    if (!$existing) {
                        $wpdb->insert($table, [
                            'sources' => serialize([
                                ['pattern' => $from_path, 'comparison' => $match_type]
                            ]),
                            'url_to' => $to_url,
                            'header_code' => $type,
                            'status' => 'active',
                            'created' => current_time('mysql'),
                            'updated' => current_time('mysql')
                        ]);
                        $new_id = $wpdb->insert_id;
                        $method_used = 'rank_math';
                        $this->flush_rank_math_redirect_cache();
                        $this->log("[CREATE_REDIRECT] Created via Rank Math (id=rm_{$new_id})");

                        return rest_ensure_response([
                            'success' => true,
                            'id' => 'rm_' . $new_id,
                            'fromUrl' => $from_path,
                            'toUrl' => $to_url,
                            'type' => $type,
                            'matchType' => $match_type,
                            'isActive' => true,
                            'source' => 'rank_math',
                            'method' => $method_used,
                            'message' => "Redirect created via rank_math"
                        ]);
                    } else {
                        return new WP_Error('redirect_exists', 'Redirect already exists', ['status' => 409]);
                    }
                }
            } catch (\Exception $e) {
                $this->log("[CREATE_REDIRECT] Rank Math error: " . $e->getMessage());
            }
        }

        // Method 2: Try Redirection plugin
        if (!$method_used && class_exists('Red_Item')) {
            try {
                // Redirection plugin's match_type is different from Rank Math's:
                // it uses 'url' / 'regex' for the source-matching strategy.
                $rp_match = $match_type === 'regex' ? 'regex' : 'url';
                Red_Item::create([
                    'url' => $from_path,
                    'action_data' => ['url' => $to_url],
                    'action_type' => 'url',
                    'match_type' => $rp_match,
                    'regex' => $match_type === 'regex' ? 1 : 0,
                    'action_code' => $type,
                    'group_id' => 1 // Default group
                ]);
                $method_used = 'redirection_plugin';
                $this->log("[CREATE_REDIRECT] Created via Redirection plugin ({$rp_match})");
            } catch (\Exception $e) {
                $this->log("[CREATE_REDIRECT] Redirection plugin error: " . $e->getMessage());
            }
        }

        // Method 3: Custom option-based redirect (fallback)
        if (!$method_used) {
            $redirects = get_option('mehrana_redirects', []);
            // Migrate old format (path-keyed) to new format (numeric-keyed with from_url)
            $redirects = $this->normalize_custom_redirects($redirects);
            $redirects[] = [
                'from_url' => $from_path,
                'to_url' => sanitize_text_field($to_url),
                'type' => $type,
                'match_type' => $match_type,
                'created' => current_time('mysql')
            ];
            update_option('mehrana_redirects', array_values($redirects));
            $method_used = 'mehrana_custom';
            $this->log("[CREATE_REDIRECT] Created via Mehrana custom option");

            // Add template_redirect hook if not already added
            if (!has_action('template_redirect', [$this, 'handle_custom_redirects'])) {
                add_action('template_redirect', [$this, 'handle_custom_redirects']);
            }
        }

        return rest_ensure_response([
            'success' => true,
            'from' => $from_path,
            'to' => $to_url,
            'type' => $type,
            'matchType' => $match_type,
            'method' => $method_used,
            'message' => "Redirect created via {$method_used}"
        ]);
    }

    /**
     * Flush Rank Math redirect cache after direct DB changes.
     * Without this, Rank Math serves stale cached redirects.
     */
    private function flush_rank_math_redirect_cache() {
        global $wpdb;
        try {
            // Clear Rank Math's internal redirect cache (transients + options)
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%rank_math%redirection%cache%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%rank_math%redirect%'");
            // Clear object cache
            wp_cache_delete('redirections', 'rank_math');
            // Trigger Rank Math's own cache clear if available. Catch Throwable because
            // Rank Math's internals can fatal (missing deps, signature changes) and we do
            // NOT want that to fail the outer delete/update — the DB row is already changed.
            if (class_exists('RankMath\\Redirections\\Cache')) {
                try { \RankMath\Redirections\Cache::clear(); } catch (\Throwable $e) {
                    $this->log("[RANK_MATH] Cache::clear threw: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->log("[RANK_MATH] flush cache threw: " . $e->getMessage());
        }
        $this->log("[RANK_MATH] Flushed redirect cache");
    }

    /**
     * Normalize custom redirects from old format (path-keyed assoc array)
     * to new format (numeric-indexed with from_url field).
     */
    private function normalize_custom_redirects($custom) {
        if (empty($custom)) return [];
        $normalized = [];
        foreach ($custom as $key => $val) {
            if (is_string($key) && !is_numeric($key)) {
                // Old format: path is the key, value has 'to'
                $normalized[] = [
                    'from_url' => $key,
                    'to_url' => is_array($val) ? ($val['to'] ?? ($val['to_url'] ?? '')) : $val,
                    'type' => is_array($val) ? (int)($val['type'] ?? 301) : 301,
                    'created' => is_array($val) ? ($val['created'] ?? '') : '',
                ];
            } else {
                // New format: numeric key, from_url in value
                $normalized[] = $val;
            }
        }
        return array_values($normalized);
    }

    /**
     * Handle custom redirects (fallback when no plugin available)
     */
    public function handle_custom_redirects()
    {
        $redirects = get_option('mehrana_redirects', []);
        if (empty($redirects))
            return;

        $current_path = $_SERVER['REQUEST_URI'];

        // Check with and without trailing slash
        if (isset($redirects[$current_path])) {
            $redirect = $redirects[$current_path];
            wp_redirect($redirect['to'], $redirect['type']);
            exit;
        }

        $alt_path = rtrim($current_path, '/');
        if (isset($redirects[$alt_path])) {
            $redirect = $redirects[$alt_path];
            wp_redirect($redirect['to'], $redirect['type']);
            exit;
        }
    }

    /**
     * Get raw page content for editing (used by image deploy)
     * GET /wp-json/mehrana/v1/pages/content?page_id=123
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_page_content($request)
    {
        $page_id = intval($request['page_id']);
        $post = get_post($page_id);

        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }

        $elementor_data = get_post_meta($page_id, '_elementor_data', true);

        $result = [
            'content' => $post->post_content,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'title' => $post->post_title,
        ];

        if (!empty($elementor_data)) {
            $result['elementor_data'] = $elementor_data;
        }

        $this->log("[GET_PAGE_CONTENT] Returned content for page ID: {$page_id}, length: " . strlen($post->post_content));

        return rest_ensure_response($result);
    }

    /**
     * Update page content, slug, and status (for Content Factory)
     * POST /wp-json/mehrana/v1/pages/update
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    /**
     * Update page content, slug, and status (for Content Factory)
     * POST /wp-json/mehrana/v1/pages/update
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_page_content($request)
    {
        $body = $request->get_json_params();
        $page_id = intval($body['page_id'] ?? 0);

        // If no page_id, create a new page
        if (!$page_id) {
            $this->log("[UPDATE_PAGE] Creating NEW page since ID is 0");

            // DEBUG: Log received post_type
            $received_post_type = isset($body['post_type']) ? $body['post_type'] : 'NOT_SET';
            $this->log("[UPDATE_PAGE] Received post_type: {$received_post_type}");

            $new_page_data = [
                'post_title' => isset($body['title']) ? sanitize_text_field($body['title']) : 'New Page',
                'post_type' => isset($body['post_type']) && in_array($body['post_type'], ['page', 'post', 'news']) ? $body['post_type'] : 'page',
                'post_status' => isset($body['status']) ? $body['status'] : 'draft',
            ];

            $this->log("[UPDATE_PAGE] Using post_type: " . $new_page_data['post_type']);

            $page_id = wp_insert_post($new_page_data);

            if (is_wp_error($page_id)) {
                return new WP_Error('create_failed', 'Failed to create page: ' . $page_id->get_error_message(), ['status' => 500]);
            }

            $this->log("[UPDATE_PAGE] Created new page with ID: {$page_id}");
        }

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }

        $this->log("[UPDATE_PAGE] Updating page ID: {$page_id}");

        $update_data = [
            'ID' => $page_id
        ];

        // Update content if provided
        if (isset($body['content'])) {
            $update_data['post_content'] = $body['content'];
            $this->log("[UPDATE_PAGE] Content length: " . strlen($body['content']));
        }

        // Update Elementor data if provided (for Elementor pages)
        if (isset($body['elementor_data']) && !empty($body['elementor_data'])) {
            $this->log("[UPDATE_PAGE] Updating Elementor data, length: " . strlen($body['elementor_data']));

            // Validate JSON
            $decoded = json_decode($body['elementor_data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_elementor_json', 'Invalid Elementor JSON data', ['status' => 400]);
            }

            // Update the _elementor_data meta
            $update_result = update_post_meta($page_id, '_elementor_data', wp_slash($body['elementor_data']));
            $this->log("[UPDATE_PAGE] Elementor meta update result: " . ($update_result ? 'SUCCESS' : 'FAILED/NO_CHANGE'));

            // Clear Elementor caches
            if (class_exists('\Elementor\Plugin')) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                delete_post_meta($page_id, '_elementor_css');
                delete_post_meta($page_id, '_elementor_page_assets');
                $this->log("[UPDATE_PAGE] Cleared Elementor caches for page {$page_id}");
            }

            // Touch the post to trigger Elementor regeneration
            update_post_meta($page_id, '_edit_last', get_current_user_id());
            update_post_meta($page_id, '_edit_lock', time() . ':' . get_current_user_id());
        }

        // Update slug if provided
        if (isset($body['slug']) && !empty($body['slug'])) {
            $update_data['post_name'] = sanitize_title($body['slug']);
            $this->log("[UPDATE_PAGE] New slug: " . $update_data['post_name']);
        }


        // Handle Parent Logic (Recursive)
        // Input: $body['parent_path'] e.g. "/burnaby/commercial"
        $parent_id = 0;
        if (!empty($body['parent_path'])) {
            $clean_path = trim($body['parent_path'], '/');
            $segments = array_filter(explode('/', $clean_path));
            $current_parent_id = 0;

            foreach ($segments as $segment) {
                $segment_slug = sanitize_title($segment);

                // First: Try to find existing page by slug AND correct parent
                $args = [
                    'name' => $segment_slug,
                    'post_type' => 'page',
                    'post_status' => ['publish', 'draft'],
                    'numberposts' => 1,
                    'post_parent' => $current_parent_id
                ];

                $existing_pages = get_posts($args);

                if (!empty($existing_pages)) {
                    // Found with correct parent
                    $current_parent_id = $existing_pages[0]->ID;
                } else {
                    // Second: Try to find page by slug only (any parent)
                    $args_any_parent = [
                        'name' => $segment_slug,
                        'post_type' => 'page',
                        'post_status' => ['publish', 'draft'],
                        'numberposts' => 1
                    ];
                    $pages_any_parent = get_posts($args_any_parent);

                    if (!empty($pages_any_parent)) {
                        // Page exists but has WRONG parent - FIX IT!
                        $existing_page_id = $pages_any_parent[0]->ID;
                        $old_parent = $pages_any_parent[0]->post_parent;

                        if ($old_parent != $current_parent_id) {
                            wp_update_post([
                                'ID' => $existing_page_id,
                                'post_parent' => $current_parent_id
                            ]);
                            $this->log("[UPDATE_PAGE] Fixed parent for '{$segment}' (ID: {$existing_page_id}): {$old_parent} -> {$current_parent_id}");
                        }
                        $current_parent_id = $existing_page_id;
                    } else {
                        // Create new parent page if it doesn't exist
                        $new_parent_data = [
                            'post_title' => ucwords(str_replace('-', ' ', $segment)),
                            'post_name' => $segment_slug,
                            'post_status' => 'publish', // Parents are usually published
                            'post_type' => 'page',
                            'post_parent' => $current_parent_id,
                            'post_author' => get_current_user_id() > 0 ? get_current_user_id() : 1
                        ];

                        $new_parent_id = wp_insert_post($new_parent_data);

                        if (!is_wp_error($new_parent_id)) {
                            $this->log("[UPDATE_PAGE] Created missing parent: {$segment} (ID: {$new_parent_id})");
                            $current_parent_id = $new_parent_id;
                        } else {
                            $this->log("[UPDATE_PAGE] Error creating parent {$segment}: " . $new_parent_id->get_error_message());
                            // Stop recursion on error, but keep last valid parent
                            break;
                        }
                    }
                }
            }
            $parent_id = $current_parent_id;
        }

        // Allow explicit parent_id override
        if (isset($body['parent_id'])) {
            $parent_id = intval($body['parent_id']);
        }

        // Only update if we have a parent_id
        if ($parent_id > 0) {
            $update_data['post_parent'] = $parent_id;
            $this->log("[UPDATE_PAGE] Setting parent ID: {$parent_id}");
        }

        // Update status if provided (draft, publish, pending, etc.)
        if (isset($body['status']) && in_array($body['status'], ['draft', 'publish', 'pending', 'private'])) {
            $update_data['post_status'] = $body['status'];
            $this->log("[UPDATE_PAGE] New status: " . $update_data['post_status']);
        }

        // Update title if provided
        if (isset($body['title'])) {
            $update_data['post_title'] = sanitize_text_field($body['title']);
        }

        // Update post_type if provided (allows converting between 'page' and 'post')
        if (isset($body['post_type']) && in_array($body['post_type'], ['page', 'post', 'news'])) {
            $update_data['post_type'] = $body['post_type'];
            $this->log("[UPDATE_PAGE] Changing post_type to: " . $body['post_type']);
        }

        // Perform the update
        $result = wp_update_post($update_data, true);

        if (is_wp_error($result)) {
            $this->log("[UPDATE_PAGE] Error: " . $result->get_error_message());
            return $result;
        }

        // Update schema markup if provided (store as post meta)
        if (isset($body['schema'])) {
            update_post_meta($page_id, '_mehrana_schema_markup', $body['schema']);
            $this->log("[UPDATE_PAGE] Schema markup saved");
        }

        // Set Featured Image
        if (isset($body['featured_media']) && intval($body['featured_media']) > 0) {
            set_post_thumbnail($page_id, intval($body['featured_media']));
            $this->log("[UPDATE_PAGE] Set featured image ID: " . $body['featured_media']);
        }

        // Handle generic meta_input (e.g. Rank Math focus keyword)
        if (isset($body['meta_input']) && is_array($body['meta_input'])) {
            foreach ($body['meta_input'] as $meta_key => $meta_value) {
                if (!empty($meta_value)) {
                    update_post_meta($page_id, sanitize_key($meta_key), sanitize_text_field($meta_value));
                    $this->log("[UPDATE_PAGE] Updated meta: {$meta_key}");
                }
            }
        }

        // Get updated post
        $updated_post = get_post($page_id);
        $permalink = get_permalink($page_id);

        $this->log("[UPDATE_PAGE] Success. New URL: {$permalink}");

        return rest_ensure_response([
            'success' => true,
            'page_id' => $page_id,
            'url' => $permalink,
            'title' => $updated_post->post_title,
            'status' => $updated_post->post_status,
            'slug' => $updated_post->post_name,
            'parent_id' => $updated_post->post_parent
        ]);
    }

    /**
     * Update page SEO meta (Rank Math / Yoast)
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_page_seo($request)
    {
        $page_id = intval($request['id']);
        $body = $request->get_json_params();

        $post = get_post($page_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }

        $this->log("[UPDATE_SEO] Updating SEO for page ID: {$page_id}");

        $updated = [];

        // Detect SEO plugin
        $has_rank_math = defined('RANK_MATH_VERSION');
        $has_yoast = defined('WPSEO_VERSION');

        // Title
        if (isset($body['title'])) {
            $title = sanitize_text_field($body['title']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_title', $title);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_title', $title);
            }
            $updated['title'] = $title;
        }

        // Description
        if (isset($body['description'])) {
            $desc = sanitize_textarea_field($body['description']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_description', $desc);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_metadesc', $desc);
            }
            $updated['description'] = $desc;
        }

        // Focus keyword
        if (isset($body['focus_keyword'])) {
            $kw = sanitize_text_field($body['focus_keyword']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_focus_keyword', $kw);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_focuskw', $kw);
            }
            $updated['focus_keyword'] = $kw;
        }

        // Canonical URL
        if (isset($body['canonical'])) {
            $canonical = esc_url_raw($body['canonical']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_canonical_url', $canonical);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_canonical', $canonical);
            }
            $updated['canonical'] = $canonical;
        }

        // OG Title
        if (isset($body['og_title'])) {
            $og_title = sanitize_text_field($body['og_title']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_facebook_title', $og_title);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_opengraph-title', $og_title);
            }
            $updated['og_title'] = $og_title;
        }

        // OG Description
        if (isset($body['og_description'])) {
            $og_desc = sanitize_textarea_field($body['og_description']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_facebook_description', $og_desc);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_opengraph-description', $og_desc);
            }
            $updated['og_description'] = $og_desc;
        }

        // OG Image
        if (isset($body['og_image'])) {
            $og_image = esc_url_raw($body['og_image']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_facebook_image', $og_image);
            } elseif ($has_yoast) {
                update_post_meta($page_id, '_yoast_wpseo_opengraph-image', $og_image);
            }
            $updated['og_image'] = $og_image;
        }

        // OG URL
        if (isset($body['og_url'])) {
            $og_url = esc_url_raw($body['og_url']);
            if ($has_rank_math) {
                update_post_meta($page_id, 'rank_math_facebook_url', $og_url);
            }
            $updated['og_url'] = $og_url;
        }

        $this->log("[UPDATE_SEO] Updated fields: " . implode(', ', array_keys($updated)));

        return rest_ensure_response([
            'success' => true,
            'page_id' => $page_id,
            'seo_plugin' => $has_rank_math ? 'rank_math' : ($has_yoast ? 'yoast' : 'none'),
            'updated' => $updated
        ]);
    }

    /**
     * List all public taxonomy terms.
     * Returns categories, tags, WooCommerce product_cat/product_tag, and any other
     * public taxonomies. Used by On-Page Studio to resolve taxonomy-archive URLs
     * (e.g. /product-category/cabinets/) that don't exist in the /pages response.
     *
     * @return WP_REST_Response
     */
    public function get_terms()
    {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        $exclude = ['nav_menu', 'link_category', 'post_format'];
        $taxonomies = array_values(array_diff($taxonomies, $exclude));

        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => 0,
            ]);
            if (is_wp_error($terms)) continue;

            foreach ($terms as $t) {
                $url = get_term_link($t);
                if (is_wp_error($url)) $url = '';
                $out[] = [
                    'id' => (int) $t->term_id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'taxonomy' => $t->taxonomy,
                    'url' => $url,
                    'description' => $t->description,
                    'count' => (int) $t->count,
                ];
            }
        }

        return rest_ensure_response([
            'success' => true,
            'count' => count($out),
            'terms' => $out,
        ]);
    }

    /**
     * Update SEO meta for a taxonomy term (Rank Math / Yoast).
     * Rank Math stores term meta with the same keys as posts (rank_math_title etc.)
     * via update_term_meta(). Yoast stores all term SEO in a single option
     * (wpseo_taxonomy_meta) keyed by taxonomy and term ID.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_term_seo($request)
    {
        $term_id = intval($request['id']);
        $body = $request->get_json_params();
        $taxonomy = isset($body['taxonomy']) ? sanitize_key($body['taxonomy']) : '';

        $term = $taxonomy ? get_term($term_id, $taxonomy) : get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return new WP_Error('term_not_found', 'Term not found', ['status' => 404]);
        }
        $taxonomy = $term->taxonomy;

        $this->log("[UPDATE_TERM_SEO] Updating SEO for term ID: {$term_id} (taxonomy: {$taxonomy})");

        $has_rank_math = defined('RANK_MATH_VERSION');
        $has_yoast = defined('WPSEO_VERSION');

        // Load Yoast taxonomy meta option once if needed
        $yoast_meta = null;
        if ($has_yoast) {
            $yoast_meta = get_option('wpseo_taxonomy_meta', []);
            if (!is_array($yoast_meta)) $yoast_meta = [];
            if (!isset($yoast_meta[$taxonomy])) $yoast_meta[$taxonomy] = [];
            if (!isset($yoast_meta[$taxonomy][$term_id])) $yoast_meta[$taxonomy][$term_id] = [];
        }

        $updated = [];

        if (isset($body['title'])) {
            $title = sanitize_text_field($body['title']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_title', $title);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_title'] = $title;
            $updated['title'] = $title;
        }

        if (isset($body['description'])) {
            $desc = sanitize_textarea_field($body['description']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_description', $desc);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_desc'] = $desc;
            $updated['description'] = $desc;
        }

        if (isset($body['focus_keyword'])) {
            $kw = sanitize_text_field($body['focus_keyword']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_focus_keyword', $kw);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_focuskw'] = $kw;
            $updated['focus_keyword'] = $kw;
        }

        if (isset($body['canonical'])) {
            $canonical = esc_url_raw($body['canonical']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_canonical_url', $canonical);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_canonical'] = $canonical;
            $updated['canonical'] = $canonical;
        }

        if (isset($body['og_title'])) {
            $og_title = sanitize_text_field($body['og_title']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_facebook_title', $og_title);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_opengraph-title'] = $og_title;
            $updated['og_title'] = $og_title;
        }

        if (isset($body['og_description'])) {
            $og_desc = sanitize_textarea_field($body['og_description']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_facebook_description', $og_desc);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_opengraph-description'] = $og_desc;
            $updated['og_description'] = $og_desc;
        }

        if (isset($body['og_image'])) {
            $og_image = esc_url_raw($body['og_image']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_facebook_image', $og_image);
            elseif ($has_yoast) $yoast_meta[$taxonomy][$term_id]['wpseo_opengraph-image'] = $og_image;
            $updated['og_image'] = $og_image;
        }

        if (isset($body['og_url'])) {
            $og_url = esc_url_raw($body['og_url']);
            if ($has_rank_math) update_term_meta($term_id, 'rank_math_facebook_url', $og_url);
            $updated['og_url'] = $og_url;
        }

        if ($has_yoast && $yoast_meta !== null) {
            update_option('wpseo_taxonomy_meta', $yoast_meta);
        }

        $this->log("[UPDATE_TERM_SEO] Updated fields: " . implode(', ', array_keys($updated)));

        return rest_ensure_response([
            'success' => true,
            'term_id' => $term_id,
            'taxonomy' => $taxonomy,
            'seo_plugin' => $has_rank_math ? 'rank_math' : ($has_yoast ? 'yoast' : 'none'),
            'updated' => $updated,
        ]);
    }

    /**
     * Upload media to WordPress
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function upload_media($request)
    {
        // Check if file is present
        if (empty($_FILES['file'])) {
            return new WP_Error('no_file', 'No file uploaded', ['status' => 400]);
        }

        $file = $_FILES['file'];

        // Basic validation
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'File upload error: ' . $file['error'], ['status' => 500]);
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $this->log("[UPLOAD_MEDIA] Uploading file: " . $file['name']);

        // Handle upload
        $attachment_id = media_handle_upload('file', 0); // 0 = no parent post initially

        if (is_wp_error($attachment_id)) {
            $this->log("[UPLOAD_MEDIA] Error: " . $attachment_id->get_error_message());
            return $attachment_id;
        }

        // Add alt text if provided
        if ($request->get_param('alt_text')) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($request->get_param('alt_text')));
        }

        // Get details
        $url = wp_get_attachment_url($attachment_id);

        $this->log("[UPLOAD_MEDIA] Success. ID: {$attachment_id}, URL: {$url}");

        return rest_ensure_response([
            'success' => true,
            'id' => $attachment_id,
            'url' => $url
        ]);
    }

    /**
     * Render [iframe] shortcode
     * Usage: [iframe src="..." width="100%" height="450"]
     */
    public function render_iframe_shortcode($atts)
    {
        $atts = shortcode_atts(
            [
                'src' => '',
                'width' => '100%',
                'height' => '450',
                'frameborder' => '0',
                'scrolling' => 'no',
            ],
            $atts,
            'iframe'
        );

        if (empty($atts['src'])) {
            return '';
        }

        // Basic security: Ensure src is a URL.
        $src = esc_url($atts['src']);

        return sprintf(
            '<iframe src="%s" width="%s" height="%s" frameborder="%s" scrolling="%s" style="border:0;" allowfullscreen="" loading="lazy"></iframe>',
            $src,
            esc_attr($atts['width']),
            esc_attr($atts['height']),
            esc_attr($atts['frameborder']),
            esc_attr($atts['scrolling'])
        );
    }

    // =============================================
    // LinkLab Handler Functions
    // =============================================

    /**
     * GET /menus — Return all navigation menus with full item trees
     */
    public function linklab_get_menus($request) {
        $menus = wp_get_nav_menus();
        $result = [];
        $locations = get_nav_menu_locations();
        $location_map = array_flip($locations);

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            $location = isset($location_map[$menu->term_id]) ? $location_map[$menu->term_id] : null;

            $tree = $this->linklab_build_menu_tree($items ?: []);

            $result[] = [
                'id' => (string) $menu->term_id,
                'name' => $menu->name,
                'location' => $location,
                'items' => $tree,
            ];
        }

        return rest_ensure_response($result);
    }

    private function linklab_build_menu_tree($items, $parent_id = 0) {
        $tree = [];
        foreach ($items as $item) {
            if ((int) $item->menu_item_parent === $parent_id) {
                $children = $this->linklab_build_menu_tree($items, $item->ID);
                $tree[] = [
                    'id' => (string) $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'children' => $children,
                ];
            }
        }
        return $tree;
    }

    /**
     * PUT /menus/items/{id} — Update a menu item's URL
     */
    public function linklab_update_menu_item($request) {
        $item_id = intval($request['id']);
        $body = $request->get_json_params();
        $new_url = isset($body['url']) ? esc_url_raw($body['url']) : null;

        if (!$new_url) {
            return new \WP_Error('missing_url', 'URL is required', ['status' => 400]);
        }

        $menu_item = wp_setup_nav_menu_item(get_post($item_id));
        if (!$menu_item || $menu_item->post_type !== 'nav_menu_item') {
            return new \WP_Error('not_found', 'Menu item not found', ['status' => 404]);
        }

        $old_url = $menu_item->url;
        $item_type = get_post_meta($item_id, '_menu_item_type', true);

        // Page/post/taxonomy menu items derive their URL from the linked object — can't safely change via API
        if ($item_type !== 'custom') {
            return new \WP_Error('not_custom_link',
                "This menu item is a {$item_type} link (URL comes from the linked {$item_type}). Change it in WordPress → Appearance → Menus.",
                ['status' => 400]
            );
        }

        // Custom link — update the URL directly
        update_post_meta($item_id, '_menu_item_url', $new_url);

        // Clear caches so the menu change is visible immediately
        // WordPress nav menu cache
        wp_cache_delete('last_changed', 'nav_menus');
        wp_cache_flush();

        // LiteSpeed Cache
        if (class_exists('LiteSpeed\Purge') || function_exists('litespeed_purge_all')) {
            if (function_exists('litespeed_purge_all')) {
                litespeed_purge_all();
            } elseif (method_exists('LiteSpeed\Purge', 'purge_all')) {
                \LiteSpeed\Purge::purge_all();
            }
            $this->log("[MENU_UPDATE] LiteSpeed cache purged");
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $this->log("[MENU_UPDATE] WP Rocket cache purged");
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        $this->log("[MENU_UPDATE] Updated menu item {$item_id}: {$old_url} → {$new_url}");

        return rest_ensure_response([
            'success' => true,
            'item_id' => $item_id,
            'old_url' => $old_url,
            'new_url' => $new_url,
        ]);
    }

    /**
     * GET /redirects — List all redirects from Rank Math + Redirection plugin + custom
     */
    public function linklab_get_redirects($request) {
        global $wpdb;
        $redirects = [];

        // 1. Rank Math redirections
        $table = $wpdb->prefix . 'rank_math_redirections';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            // Exclude trashed rows — those are soft-deleted and should not appear in the UI.
            // Keep 'active' + 'inactive' so users can toggle enabled state.
            $rows = $wpdb->get_results("SELECT id, sources, url_to, header_code, status FROM $table WHERE status != 'trashed' ORDER BY id DESC LIMIT 500");
            foreach ($rows as $row) {
                $sources = maybe_unserialize($row->sources);
                $from_url = is_array($sources) && isset($sources[0]['pattern']) ? $sources[0]['pattern'] : '';
                $comparison = is_array($sources) && isset($sources[0]['comparison']) ? $sources[0]['comparison'] : 'exact';
                $redirects[] = [
                    'id' => 'rm_' . $row->id,
                    'fromUrl' => $from_url,
                    'toUrl' => $row->url_to,
                    'type' => (int) $row->header_code,
                    'isActive' => $row->status === 'active',
                    'matchType' => $comparison,
                    'source' => 'rank_math',
                ];
            }
        }

        // 2. Redirection plugin
        if (class_exists('Red_Item')) {
            $items = \Red_Item::get_all();
            if (is_array($items)) {
                foreach ($items as $item) {
                    $is_regex = method_exists($item, 'is_regex') ? $item->is_regex() : false;
                    $redirects[] = [
                        'id' => 'rp_' . $item->get_id(),
                        'fromUrl' => $item->get_url(),
                        'toUrl' => $item->get_action_data(),
                        'type' => (int) $item->get_action_code(),
                        'isActive' => $item->is_enabled(),
                        'matchType' => $is_regex ? 'regex' : 'exact',
                        'source' => 'redirection_plugin',
                    ];
                }
            }
        }

        // 3. Custom Mehrana redirects
        $custom = get_option('mehrana_redirects', []);
        $custom = $this->normalize_custom_redirects($custom);
        foreach ($custom as $idx => $r) {
            if (empty($r['from_url'])) continue;
            $redirects[] = [
                'id' => 'custom_' . $idx,
                'fromUrl' => $r['from_url'],
                'toUrl' => $r['to_url'] ?? ($r['to'] ?? ''),
                'type' => (int) ($r['type'] ?? 301),
                'isActive' => true,
                'matchType' => $r['match_type'] ?? 'exact',
                'source' => 'mehrana',
            ];
        }

        return rest_ensure_response($redirects);
    }

    /**
     * PUT /redirects/{id} — Update a redirect rule
     */
    public function linklab_update_redirect($request) {
        global $wpdb;
        $id = $request['id'];
        $body = $request->get_json_params();

        $valid_match_types = ['exact', 'contains', 'start', 'end', 'regex'];

        // Rank Math
        if (strpos($id, 'rm_') === 0) {
            $rm_id = intval(str_replace('rm_', '', $id));
            $table = $wpdb->prefix . 'rank_math_redirections';
            $update = [];
            if (isset($body['toUrl'])) $update['url_to'] = esc_url_raw($body['toUrl']);
            if (isset($body['type'])) $update['header_code'] = intval($body['type']);
            if (isset($body['isActive'])) $update['status'] = $body['isActive'] ? 'active' : 'inactive';

            // Rebuild the `sources` blob when fromUrl or matchType changes.
            $new_pattern = isset($body['fromUrl']) ? trim($body['fromUrl']) : null;
            $new_match = isset($body['matchType']) && in_array($body['matchType'], $valid_match_types, true)
                ? $body['matchType'] : null;

            if ($new_pattern !== null || $new_match !== null) {
                // Fetch the existing sources row so we only overwrite the fields that changed.
                $existing_sources = $wpdb->get_var($wpdb->prepare(
                    "SELECT sources FROM $table WHERE id = %d", $rm_id
                ));
                $sources = maybe_unserialize($existing_sources);
                $cur_pattern = is_array($sources) && isset($sources[0]['pattern']) ? $sources[0]['pattern'] : '';
                $cur_match   = is_array($sources) && isset($sources[0]['comparison']) ? $sources[0]['comparison'] : 'exact';

                $final_pattern = $new_pattern !== null ? $new_pattern : $cur_pattern;
                $final_match   = $new_match !== null ? $new_match : $cur_match;

                // Apply the regex leading-slash normalization on pattern edits too.
                if ($final_match === 'regex') {
                    $final_pattern = preg_replace('#^\^/+#', '^', $final_pattern);
                    if (strpos($final_pattern, '^') !== 0) {
                        $final_pattern = ltrim($final_pattern, '/');
                    }
                } else {
                    if (strpos($final_pattern, '/') !== 0) {
                        $final_pattern = '/' . $final_pattern;
                    }
                }

                $update['sources'] = serialize([
                    ['pattern' => $final_pattern, 'comparison' => $final_match]
                ]);
            }

            if (!empty($update)) {
                $update['updated'] = current_time('mysql');
                $wpdb->update($table, $update, ['id' => $rm_id]);
                $this->flush_rank_math_redirect_cache();
            }
            return rest_ensure_response(['success' => true]);
        }

        // Redirection plugin
        if (strpos($id, 'rp_') === 0) {
            $rp_id = intval(str_replace('rp_', '', $id));
            if (class_exists('Red_Item')) {
                $item = \Red_Item::get_by_id($rp_id);
                if ($item) {
                    $update_data = [];
                    if (isset($body['toUrl'])) $update_data['action_data'] = ['url' => esc_url_raw($body['toUrl'])];
                    if (isset($body['type'])) $update_data['action_code'] = intval($body['type']);
                    if (isset($body['fromUrl'])) $update_data['url'] = trim($body['fromUrl']);
                    if (isset($body['matchType']) && in_array($body['matchType'], $valid_match_types, true)) {
                        $update_data['regex'] = $body['matchType'] === 'regex' ? 1 : 0;
                        $update_data['match_type'] = $body['matchType'] === 'regex' ? 'regex' : 'url';
                    }
                    if (!empty($update_data)) {
                        $item->update($update_data);
                    }
                    if (isset($body['isActive'])) {
                        try {
                            if ($body['isActive']) { $item->enable(); } else { $item->disable(); }
                        } catch (\Throwable $e) {
                            $this->log("[REDIRECTION_PLUGIN] toggle failed: " . $e->getMessage());
                        }
                    }
                    return rest_ensure_response(['success' => true]);
                }
            }
            return new \WP_Error('not_found', 'Redirection plugin redirect not found', ['status' => 404]);
        }

        // Custom Mehrana redirects
        if (strpos($id, 'custom_') === 0) {
            $idx = intval(str_replace('custom_', '', $id));
            $custom = get_option('mehrana_redirects', []);
            $custom = $this->normalize_custom_redirects($custom);
            if (isset($custom[$idx])) {
                if (isset($body['toUrl'])) $custom[$idx]['to_url'] = sanitize_text_field($body['toUrl']);
                if (isset($body['fromUrl'])) $custom[$idx]['from_url'] = sanitize_text_field($body['fromUrl']);
                if (isset($body['type'])) $custom[$idx]['type'] = intval($body['type']);
                if (isset($body['matchType']) && in_array($body['matchType'], $valid_match_types, true)) {
                    $custom[$idx]['match_type'] = $body['matchType'];
                }
                update_option('mehrana_redirects', $custom);
                return rest_ensure_response(['success' => true]);
            }
            return new \WP_Error('not_found', 'Custom redirect not found', ['status' => 404]);
        }

        return new \WP_Error('not_supported', 'Unknown redirect type: ' . $id, ['status' => 400]);
    }

    /**
     * DELETE /redirects/{id} — Delete a redirect rule
     */
    public function linklab_delete_redirect($request) {
        global $wpdb;
        $id = $request['id'];

        // Rank Math
        if (strpos($id, 'rm_') === 0) {
            $rm_id = intval(str_replace('rm_', '', $id));
            $table = $wpdb->prefix . 'rank_math_redirections';
            $wpdb->delete($table, ['id' => $rm_id]);
            $this->flush_rank_math_redirect_cache();
            $this->log("Deleted Rank Math redirect #{$rm_id}");
            return rest_ensure_response(['success' => true]);
        }

        // Redirection plugin
        if (strpos($id, 'rp_') === 0) {
            $rp_id = intval(str_replace('rp_', '', $id));
            if (class_exists('Red_Item')) {
                $item = \Red_Item::get_by_id($rp_id);
                if ($item) {
                    $item->delete();
                    $this->log("Deleted Redirection plugin redirect #{$rp_id}");
                    return rest_ensure_response(['success' => true]);
                }
            }
            return new \WP_Error('not_found', 'Redirection plugin redirect not found', ['status' => 404]);
        }

        // Custom Mehrana redirects
        if (strpos($id, 'custom_') === 0) {
            $idx = intval(str_replace('custom_', '', $id));
            $custom = get_option('mehrana_redirects', []);
            $custom = $this->normalize_custom_redirects($custom);
            if (isset($custom[$idx])) {
                unset($custom[$idx]);
                update_option('mehrana_redirects', array_values($custom));
                $this->log("Deleted custom redirect #{$idx}");
            }
            return rest_ensure_response(['success' => true]);
        }

        return new \WP_Error('not_supported', 'Unknown redirect type: ' . $id, ['status' => 400]);
    }

    /**
     * GET /pages/{id}/robots — Read current robots directives
     */
    public function linklab_get_page_robots($request) {
        $page_id = intval($request['id']);
        $noindex = false;
        $nofollow = false;
        $raw = '';

        // Rank Math
        $rm_robots = get_post_meta($page_id, 'rank_math_robots', true);
        if (is_array($rm_robots)) {
            $noindex = in_array('noindex', $rm_robots);
            $nofollow = in_array('nofollow', $rm_robots);
            $raw = implode(', ', $rm_robots);
        }

        // Yoast fallback
        if (!$rm_robots) {
            $yoast_noindex = get_post_meta($page_id, '_yoast_wpseo_meta-robots-noindex', true);
            $yoast_nofollow = get_post_meta($page_id, '_yoast_wpseo_meta-robots-nofollow', true);
            $noindex = $yoast_noindex === '1';
            $nofollow = $yoast_nofollow === '1';
            $parts = [];
            if ($noindex) $parts[] = 'noindex';
            if ($nofollow) $parts[] = 'nofollow';
            $raw = implode(', ', $parts);
        }

        return rest_ensure_response([
            'noindex' => $noindex,
            'nofollow' => $nofollow,
            'raw' => $raw,
        ]);
    }

    /**
     * POST /pages/{id}/robots — Set noindex/nofollow
     */
    public function linklab_set_page_robots($request) {
        $page_id = intval($request['id']);
        $body = $request->get_json_params();
        $noindex = isset($body['noindex']) ? (bool) $body['noindex'] : null;
        $nofollow = isset($body['nofollow']) ? (bool) $body['nofollow'] : null;

        // Get current state for undo
        $old = $this->linklab_get_page_robots($request)->get_data();

        // Rank Math
        if (class_exists('RankMath')) {
            $robots = get_post_meta($page_id, 'rank_math_robots', true);
            if (!is_array($robots)) $robots = ['index'];

            if ($noindex !== null) {
                $robots = array_filter($robots, fn($v) => $v !== 'index' && $v !== 'noindex');
                $robots[] = $noindex ? 'noindex' : 'index';
            }
            if ($nofollow !== null) {
                $robots = array_filter($robots, fn($v) => $v !== 'follow' && $v !== 'nofollow');
                $robots[] = $nofollow ? 'nofollow' : 'follow';
            }

            update_post_meta($page_id, 'rank_math_robots', array_values($robots));
        }
        // Yoast fallback
        elseif (defined('WPSEO_VERSION')) {
            if ($noindex !== null) {
                update_post_meta($page_id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '0');
            }
            if ($nofollow !== null) {
                update_post_meta($page_id, '_yoast_wpseo_meta-robots-nofollow', $nofollow ? '1' : '0');
            }
        }

        return rest_ensure_response([
            'success' => true,
            'previous' => $old,
        ]);
    }

    /**
     * POST /theme/search — Search theme files for URL patterns
     */
    public function linklab_search_theme($request) {
        $body = $request->get_json_params();
        $urls = isset($body['urls']) ? (array) $body['urls'] : [];

        if (empty($urls)) {
            return new \WP_Error('missing_urls', 'URLs array is required', ['status' => 400]);
        }

        $theme_dir = get_stylesheet_directory();
        $results = [];

        $files = $this->linklab_scan_directory($theme_dir, ['php', 'js', 'css', 'html']);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) continue;

            $lines = explode("\n", $content);
            $rel_path = str_replace($theme_dir . '/', '', $file);

            foreach ($urls as $url) {
                foreach ($lines as $line_num => $line) {
                    if (stripos($line, $url) !== false) {
                        $results[] = [
                            'url' => $url,
                            'file' => $rel_path,
                            'line' => $line_num + 1,
                            'context' => trim($line),
                            'canAutoFix' => true,
                        ];
                    }
                }
            }
        }

        return rest_ensure_response($results);
    }

    private function linklab_scan_directory($dir, $extensions) {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $extensions)) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * POST /breadcrumb/override — Add persistent breadcrumb URL override
     */
    public function linklab_add_breadcrumb_override($request) {
        $body = $request->get_json_params();
        $from_url = isset($body['from_url']) ? $body['from_url'] : null;
        $to_url = isset($body['to_url']) ? $body['to_url'] : null;
        $to_text = isset($body['to_text']) ? $body['to_text'] : null;

        if (!$from_url || !$to_url) {
            return new \WP_Error('missing_params', 'from_url and to_url are required', ['status' => 400]);
        }

        $overrides = get_option('mehrana_breadcrumb_overrides', []);
        $overrides[] = [
            'from_url' => $from_url,
            'to_url' => $to_url,
            'to_text' => $to_text,
        ];
        update_option('mehrana_breadcrumb_overrides', $overrides);

        return rest_ensure_response(['success' => true, 'id' => count($overrides) - 1]);
    }

    /**
     * GET /breadcrumb/overrides — List all active breadcrumb overrides
     */
    public function linklab_get_breadcrumb_overrides($request) {
        $overrides = get_option('mehrana_breadcrumb_overrides', []);
        $result = [];
        foreach ($overrides as $idx => $o) {
            $result[] = [
                'id' => (string) $idx,
                'fromUrl' => $o['from_url'],
                'toUrl' => $o['to_url'],
                'toText' => isset($o['to_text']) ? $o['to_text'] : null,
            ];
        }
        return rest_ensure_response($result);
    }

    /**
     * DELETE /breadcrumb/override/{id} — Remove a breadcrumb override
     */
    public function linklab_delete_breadcrumb_override($request) {
        $idx = intval($request['id']);
        $overrides = get_option('mehrana_breadcrumb_overrides', []);
        if (isset($overrides[$idx])) {
            array_splice($overrides, $idx, 1);
            update_option('mehrana_breadcrumb_overrides', $overrides);
        }
        return rest_ensure_response(['success' => true]);
    }

    /**
     * POST /sitemap/exclude — Remove a URL from the XML sitemap
     * Works with Rank Math and Yoast. For pages: sets noindex. For redirect URLs: adds to exclusion list.
     */
    // ── Sitemap exclusion: helpers ──────────────────────────────────────────

    /**
     * Return the canonical list of post IDs we want excluded from the XML sitemap.
     */
    private function get_sitemap_excluded_ids() {
        $ids = get_option('mehrana_sitemap_excluded_ids', []);
        if (!is_array($ids)) return [];
        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /**
     * Return the canonical list of URLs (non-post URLs like archives) we want excluded.
     */
    private function get_sitemap_excluded_urls() {
        // Merge new + legacy option name so old installs don't lose data.
        $new = get_option('mehrana_sitemap_excluded_urls', []);
        $legacy = get_option('mehrana_sitemap_exclusions', []);
        $all = array_merge(is_array($new) ? $new : [], is_array($legacy) ? $legacy : []);
        $all = array_values(array_unique(array_map(function($u) {
            return is_string($u) ? untrailingslashit($u) : '';
        }, array_filter($all))));
        return array_values(array_filter($all));
    }

    /**
     * Flush every sitemap cache we know about so the next request regenerates the XML.
     */
    private function flush_sitemap_caches() {
        global $wpdb;
        try {
            // Rank Math sitemap cache (transients + options)
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%rank_math%sitemap%cache%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%rank_math%sitemap%'");
            // Yoast sitemap cache
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%yoast_sitemap%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%wpseo_sitemap%'");
            // Object cache
            wp_cache_delete('sitemap', 'rank_math');
            // Core WP sitemap cache (last-modified data)
            delete_transient('wp_sitemaps_lastmod');
            // Rank Math's own cache clear
            if (class_exists('RankMath\\Sitemap\\Cache')) {
                try { \RankMath\Sitemap\Cache::invalidate_storage(); } catch (\Throwable $e) {
                    $this->log("[SITEMAP_CACHE] Cache::invalidate_storage threw: " . $e->getMessage());
                }
            }
            // Yoast cache ping
            if (class_exists('WPSEO_Sitemaps_Cache')) {
                try { \WPSEO_Sitemaps_Cache::clear(); } catch (\Throwable $e) {
                    $this->log("[SITEMAP_CACHE] WPSEO clear threw: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->log("[SITEMAP_CACHE] flush threw: " . $e->getMessage());
        }
        $this->log("[SITEMAP_CACHE] Flushed sitemap caches");
    }

    // ── Sitemap exclusion: filter callbacks ─────────────────────────────────

    /**
     * Rank Math's exclude_posts hook expects a CSV string of IDs in some versions.
     */
    public function filter_sitemap_excluded_ids_csv($csv) {
        $existing = [];
        if (is_string($csv) && $csv !== '') {
            $existing = array_map('intval', array_filter(explode(',', $csv)));
        } elseif (is_array($csv)) {
            $existing = array_map('intval', $csv);
        }
        $merged = array_unique(array_merge($existing, $this->get_sitemap_excluded_ids()));
        return implode(',', $merged);
    }

    /**
     * Array variant — Rank Math / Yoast both use this shape for post-ID exclusions.
     */
    public function filter_sitemap_excluded_ids_array($ids) {
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        return array_values(array_unique(array_merge($ids, $this->get_sitemap_excluded_ids())));
    }

    /**
     * URL-based exclusion — strip URLs that match our list from the output array.
     */
    public function filter_sitemap_urls_array($urls) {
        if (!is_array($urls)) return $urls;
        $excluded = $this->get_sitemap_excluded_urls();
        if (empty($excluded)) return $urls;
        $excluded_set = array_flip($excluded);
        return array_values(array_filter($urls, function($u) use ($excluded_set) {
            if (!is_string($u)) return true;
            return !isset($excluded_set[untrailingslashit($u)]);
        }));
    }

    /**
     * Per-entry filter used by Rank Math — drop the entry if its loc matches an excluded URL
     * or if its post (when present) is in the excluded ID list. Return false to skip.
     */
    public function filter_sitemap_entry_by_url($url, $type = '', $post = null) {
        // Some Rank Math hook variants pass only ($url); be defensive.
        if (!is_array($url)) return $url;
        $loc = isset($url['loc']) ? (string) $url['loc'] : '';
        if ($loc) {
            $excluded_urls = $this->get_sitemap_excluded_urls();
            if (in_array(untrailingslashit($loc), $excluded_urls, true)) return false;
        }
        if (is_object($post) && isset($post->ID)) {
            if (in_array((int) $post->ID, $this->get_sitemap_excluded_ids(), true)) return false;
        }
        return $url;
    }

    /**
     * Core WordPress sitemap provider — merge post__not_in.
     */
    public function filter_core_sitemap_query_args($args) {
        $excluded = $this->get_sitemap_excluded_ids();
        if (empty($excluded)) return $args;
        $existing = isset($args['post__not_in']) && is_array($args['post__not_in']) ? $args['post__not_in'] : [];
        $args['post__not_in'] = array_values(array_unique(array_merge($existing, $excluded)));
        return $args;
    }

    /**
     * Resolve a URL to a post ID, trying url_to_postid first then a slug lookup.
     */
    private function resolve_url_to_post_id($url) {
        $post_id = url_to_postid($url);
        if ($post_id) return (int) $post_id;

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) return 0;
        $slug = trim($path, '/');
        $parts = explode('/', $slug);
        $final_slug = end($parts);
        if (!$final_slug) return 0;

        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_status = 'publish' LIMIT 1",
            $final_slug
        ));
        return $found ? (int) $found : 0;
    }

    public function linklab_sitemap_exclude($request) {
        $body = $request->get_json_params();
        $url = isset($body['url']) ? esc_url_raw($body['url']) : '';
        if (!$url) {
            return new \WP_Error('missing_url', 'URL is required', ['status' => 400]);
        }

        $this->log("[SITEMAP_EXCLUDE] Excluding (sitemap-only, no noindex): {$url}");

        $post_id = $this->resolve_url_to_post_id($url);
        $method_used = null;

        if ($post_id) {
            // Post found — exclude by ID so Rank Math / Yoast / core all drop it.
            $ids = $this->get_sitemap_excluded_ids();
            if (!in_array($post_id, $ids, true)) {
                $ids[] = $post_id;
                update_option('mehrana_sitemap_excluded_ids', array_values($ids));
            }
            $method_used = 'post_id';
            $this->log("[SITEMAP_EXCLUDE] Added post ID {$post_id} to excluded list");
        } else {
            // Non-post URL (archive, tag page, custom route) — exclude by URL string.
            $urls = $this->get_sitemap_excluded_urls();
            $normalized = untrailingslashit($url);
            if (!in_array($normalized, $urls, true)) {
                $urls[] = $normalized;
                update_option('mehrana_sitemap_excluded_urls', array_values($urls));
            }
            $method_used = 'url';
            $this->log("[SITEMAP_EXCLUDE] Added URL to excluded list");
        }

        $this->flush_sitemap_caches();

        return rest_ensure_response([
            'success' => true,
            'url' => $url,
            'postId' => $post_id ?: null,
            'method' => $method_used,
        ]);
    }

    /**
     * POST /sitemap/include — Re-include a URL in the XML sitemap (undo exclude)
     */
    public function linklab_sitemap_include($request) {
        $body = $request->get_json_params();
        $url = isset($body['url']) ? esc_url_raw($body['url']) : '';
        if (!$url) {
            return new \WP_Error('missing_url', 'URL is required', ['status' => 400]);
        }

        $this->log("[SITEMAP_INCLUDE] Re-including: {$url}");

        $post_id = $this->resolve_url_to_post_id($url);
        $normalized = untrailingslashit($url);

        // Remove from excluded-IDs list
        if ($post_id) {
            $ids = $this->get_sitemap_excluded_ids();
            $ids = array_values(array_filter($ids, fn($i) => (int) $i !== (int) $post_id));
            update_option('mehrana_sitemap_excluded_ids', $ids);
        }

        // Remove from URL list (both new + legacy option names)
        $urls = get_option('mehrana_sitemap_excluded_urls', []);
        if (is_array($urls)) {
            $urls = array_values(array_filter($urls, fn($u) => is_string($u) && untrailingslashit($u) !== $normalized));
            update_option('mehrana_sitemap_excluded_urls', $urls);
        }
        $legacy = get_option('mehrana_sitemap_exclusions', []);
        if (is_array($legacy)) {
            $legacy = array_values(array_filter($legacy, fn($u) => is_string($u) && $u !== $url && untrailingslashit($u) !== $normalized));
            update_option('mehrana_sitemap_exclusions', $legacy);
        }

        // Best-effort legacy cleanup: if a previous plugin version added noindex via
        // this exact endpoint, remove it now so including really restores the page.
        if ($post_id) {
            if (class_exists('RankMath')) {
                $robots = get_post_meta($post_id, 'rank_math_robots', true);
                if (is_array($robots) && in_array('noindex', $robots, true)) {
                    $robots = array_values(array_filter($robots, fn($v) => $v !== 'noindex'));
                    update_post_meta($post_id, 'rank_math_robots', $robots);
                    $this->log("[SITEMAP_INCLUDE] Removed legacy noindex for post {$post_id}");
                }
            } elseif (defined('WPSEO_VERSION')) {
                $noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
                if ($noindex === '1') {
                    update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '0');
                    $this->log("[SITEMAP_INCLUDE] Cleared legacy Yoast noindex for post {$post_id}");
                }
            }
        }

        $this->flush_sitemap_caches();

        return rest_ensure_response([
            'success' => true,
            'url' => $url,
            'postId' => $post_id ?: null,
        ]);
    }

    /**
     * GET /sitemap/exclusions — list current sitemap exclusions so Patrick can show them.
     */
    public function linklab_sitemap_exclusions($request) {
        $ids = $this->get_sitemap_excluded_ids();
        $urls_from_ids = [];
        foreach ($ids as $id) {
            $permalink = get_permalink($id);
            if ($permalink) {
                $urls_from_ids[] = ['id' => (int) $id, 'url' => $permalink];
            }
        }
        return rest_ensure_response([
            'postIds' => $urls_from_ids,
            'urls' => $this->get_sitemap_excluded_urls(),
        ]);
    }

    /**
     * GET /verify-capabilities — Health check for all LinkLab features
     */
    public function linklab_verify_capabilities($request) {
        $has_rank_math = class_exists('RankMath');
        $has_yoast = defined('WPSEO_VERSION');
        $has_redirection = class_exists('Red_Item');

        global $wpdb;
        $rm_table = $wpdb->prefix . 'rank_math_redirections';
        $has_rm_redirects = $wpdb->get_var("SHOW TABLES LIKE '$rm_table'") === $rm_table;

        return rest_ensure_response([
            'menus' => true,
            'redirects' => true,
            'noindex' => $has_rank_math || $has_yoast,
            'breadcrumbs' => $has_rank_math || $has_yoast,
            'themeEdit' => true,
            'sitemapExclude' => $has_rank_math,
            'seoPlugin' => $has_rank_math ? 'rank_math' : ($has_yoast ? 'yoast' : 'none'),
            'redirectPlugin' => $has_rm_redirects ? 'rank_math' : ($has_redirection ? 'redirection' : 'custom'),
            'pluginVersion' => $this->version,
        ]);
    }
}

// Apply breadcrumb overrides at render time
add_filter('rank_math/frontend/breadcrumb/items', function($items) {
    $overrides = get_option('mehrana_breadcrumb_overrides', []);
    if (empty($overrides)) return $items;
    foreach ($items as &$item) {
        if (!isset($item[1])) continue;
        foreach ($overrides as $o) {
            if (rtrim($item[1], '/') === rtrim($o['from_url'], '/')) {
                $item[1] = $o['to_url'];
                if (!empty($o['to_text'])) $item[0] = $o['to_text'];
            }
        }
    }
    return $items;
});

add_filter('wpseo_breadcrumb_links', function($links) {
    $overrides = get_option('mehrana_breadcrumb_overrides', []);
    if (empty($overrides)) return $links;
    foreach ($links as &$link) {
        if (!isset($link['url'])) continue;
        foreach ($overrides as $o) {
            if (rtrim($link['url'], '/') === rtrim($o['from_url'], '/')) {
                $link['url'] = $o['to_url'];
                if (!empty($o['to_text'])) $link['text'] = $o['to_text'];
            }
        }
    }
    return $links;
});

// Initialize plugin
new Mehrana_App_Plugin();
