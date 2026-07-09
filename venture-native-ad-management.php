<?php
/**
 * Plugin Name:       Venture Native Ad Management
 * Plugin URI:        https://github.com/venture-media/Venture-Native-Ad-Management
 * Description:       Host plugin for managing clients, advertisements, campaigns, analytics, and serving native ads to internal client sites.
 * Version:           0.9.1
 * Author:            Leon de Klerk
 * Author URI:        https://github.com/Leon2332
 * License:           MIT
 * License URI:       https://github.com/venture-media/Venture-Native-Ad-Management/blob/main/LICENSE
 * Text Domain:       venture-native-ad-management
 */

if (!defined('ABSPATH')) exit;

class Venture_Native_Ad_Management {

    private static $instance = null;
    public $version = '0.9.1';
    private $secret_key_option = 'venture_native_secret_key';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);

        // Shortcode for analytics
        add_shortcode('venture-native-ad-management', [$this, 'analytics_shortcode']);

        // REST API for client sites
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // CORS support for client sites (fixes X-Venture-Secret header)
        add_filter('rest_allowed_cors_headers', [$this, 'add_cors_headers']);

        // AJAX handlers for admin
        add_action('wp_ajax_venture_save_client', [$this, 'ajax_save_client']);
        add_action('wp_ajax_venture_delete_client', [$this, 'ajax_delete_client']);
        add_action('wp_ajax_venture_save_ad', [$this, 'ajax_save_ad']);
        add_action('wp_ajax_venture_delete_ad', [$this, 'ajax_delete_ad']);
        add_action('wp_ajax_venture_save_campaign', [$this, 'ajax_save_campaign']);
        add_action('wp_ajax_venture_delete_campaign', [$this, 'ajax_delete_campaign']);
        add_action('wp_ajax_venture_campaign_ads', [$this, 'ajax_campaign_ads']);
        add_action('wp_ajax_venture_update_campaign_ads', [$this, 'ajax_update_campaign_ads']);

        // Housekeeping
        add_action('delete_post', [$this, 'cleanup_analytics_on_ad_delete'], 10, 2); // Not used for ads
    }

    public function activate() {
        $this->create_database_tables();

        // Secret key is generated ONLY on first activation / if missing.
        // It is intentionally preserved across deactivate/activate cycles (troubleshooting).
        if (!get_option($this->secret_key_option, false)) {
            $this->generate_new_secret_key();
        }
    }

    public function add_cors_headers($headers) {
        $headers[] = 'X-Venture-Secret';
        $headers[] = 'Content-Type';
        return $headers;
    }

    public function deactivate() {
        // Data is preserved
    }

    private function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tables = [
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}venture_clients (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}venture_ads (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                client_id BIGINT(20) UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                image_url VARCHAR(2048) NOT NULL,
                target_url VARCHAR(2048) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY client_id (client_id)
            ) $charset_collate;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}venture_campaigns (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                campaign_id VARCHAR(64) NOT NULL UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}venture_campaign_ads (
                campaign_id BIGINT(20) UNSIGNED NOT NULL,
                ad_id BIGINT(20) UNSIGNED NOT NULL,
                sort_order INT DEFAULT 0,
                PRIMARY KEY (campaign_id, ad_id),
                KEY ad_id (ad_id)
            ) $charset_collate;",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}venture_ad_stats (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                ad_id BIGINT(20) UNSIGNED NOT NULL,
                type ENUM('impression','click') NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                site_identifier VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY ad_id (ad_id),
                KEY timestamp (timestamp),
                KEY type (type)
            ) $charset_collate;"
        ];

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        foreach ($tables as $table) {
            dbDelta($table);
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Venture Native Ads',
            'Venture Native Ads',
            'manage_options',
            'venture-native-ad-management',
            [$this, 'clients_page'],
            'dashicons-megaphone',
            30
        );

        add_submenu_page('venture-native-ad-management', 'Clients', 'Clients', 'manage_options', 'venture-native-clients', [$this, 'clients_page']);
        add_submenu_page('venture-native-ad-management', 'Advertisements', 'Advertisements', 'manage_options', 'venture-native-ads', [$this, 'advertisements_page']);
        add_submenu_page('venture-native-ad-management', 'Campaigns', 'Campaigns', 'manage_options', 'venture-native-campaigns', [$this, 'campaigns_page']);
        add_submenu_page('venture-native-ad-management', 'Settings', 'Settings', 'manage_options', 'venture-native-settings', [$this, 'settings_page']);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'venture-native') === false) return;

        // Load WordPress Media Uploader
        wp_enqueue_media();

        wp_enqueue_style('venture-admin-css', plugin_dir_url(__FILE__) . 'css/admin.css', [], $this->version);
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('venture-admin-js', plugin_dir_url(__FILE__) . 'js/admin.js', ['jquery', 'jquery-ui-sortable'], $this->version, true);
        
        wp_localize_script('venture-admin-js', 'ventureAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('venture_nonce'),
            'pluginUrl' => plugin_dir_url(__FILE__)
        ]);

        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    }

    public function enqueue_public_assets() {
        // Always load Chart.js + admin styles on frontend for analytics shortcode
        // (Elementor + normal pages both supported)
        wp_enqueue_script(
            'chart-js', 
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', 
            [], 
            '4.4.1', 
            true
        );
        
        wp_enqueue_style(
            'venture-admin-css', 
            plugin_dir_url(__FILE__) . 'css/admin.css', 
            [], 
            $this->version
        );
    }

    // ====================== CLIENTS PAGE ======================
    public function clients_page() {
        global $wpdb;
        $clients = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}venture_clients ORDER BY name ASC");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Clients</h1>
            <button class="page-title-action" onclick="ventureShowClientModal()">Add New Client</button>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client Name</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="venture-clients-table">
                    <?php foreach ($clients as $client): ?>
                    <tr data-id="<?php echo esc_attr($client->id); ?>">
                        <td><?php echo esc_html($client->id); ?></td>
                        <td><?php echo esc_html($client->name); ?></td>
                        <td><?php echo esc_html($client->created_at); ?></td>
                        <td>
                            <button class="button" onclick="ventureEditClient(<?php echo esc_attr($client->id); ?>, '<?php echo esc_js($client->name); ?>')">Edit</button>
                            <button class="button button-link-delete" onclick="ventureDeleteClient(<?php echo esc_attr($client->id); ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Client Modal -->
            <div id="venture-client-modal" class="venture-modal venture-client-modal">
                <h2 id="modal-title">Add New Client</h2>
                <input type="hidden" id="client-id" value="">
                <p><label>Name: <input type="text" id="client-name" style="width:100%"></label></p>
                <button class="button button-primary" onclick="ventureSaveClient()">Save Client</button>
                <button class="button" onclick="ventureHideClientModal()">Cancel</button>
            </div>
        </div>
        <?php
    }

    public function ajax_save_client() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize_text_field($_POST['name']);

        if ($id) {
            $wpdb->update("{$wpdb->prefix}venture_clients", ['name' => $name], ['id' => $id]);
        } else {
            $wpdb->insert("{$wpdb->prefix}venture_clients", ['name' => $name]);
        }
        wp_send_json_success();
    }

    public function ajax_delete_client() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;
        $id = intval($_POST['id']);
        $wpdb->delete("{$wpdb->prefix}venture_ads", ['client_id' => $id]);
        $wpdb->delete("{$wpdb->prefix}venture_clients", ['id' => $id]);
        wp_send_json_success();
    }

    // ====================== ADVERTISEMENTS PAGE ======================
    public function advertisements_page() {
        global $wpdb;
        $clients = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}venture_clients ORDER BY name");
        $ads = $wpdb->get_results("
            SELECT a.*, c.name as client_name 
            FROM {$wpdb->prefix}venture_ads a 
            LEFT JOIN {$wpdb->prefix}venture_clients c ON a.client_id = c.id 
            ORDER BY a.created_at DESC
        ");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Advertisements</h1>
            <button class="page-title-action" onclick="ventureShowAdModal(0)">Add New Advertisement</button>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Image</th>
                        <th>Target URL</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ads as $ad): ?>
                    <tr>
                        <td><?php echo esc_html($ad->title); ?></td>
                        <td><?php echo esc_html($ad->client_name); ?></td>
                        <td><img src="<?php echo esc_url($ad->image_url); ?>" style="max-height:60px; width:auto;" alt=""></td>
                        <td><a href="<?php echo esc_url($ad->target_url); ?>" target="_blank"><?php echo esc_html($ad->target_url); ?></a></td>
                        <td>
                            <button class="button" onclick="ventureShowAdModal(<?php echo esc_attr($ad->id); ?>)">Edit</button>
                            <button class="button button-link-delete" onclick="ventureDeleteAd(<?php echo esc_attr($ad->id); ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Ad Modal -->
            <div id="venture-ad-modal" class="venture-modal venture-ad-modal">
                <h2 id="ad-modal-title">Add New Advertisement</h2>
                <input type="hidden" id="ad-id" value="">
                <p><label>Title: <input type="text" id="ad-title" style="width:100%"></label></p>
                <p><label>Client: 
                    <select id="ad-client-id" style="width:100%">
                        <?php foreach ($clients as $c): ?>
                        <option value="<?php echo esc_attr($c->id); ?>"><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
                <p>
                    <label>Image: 
                        <button class="button" onclick="ventureSelectImage()">Choose Image</button>
                        <input type="hidden" id="ad-image-url" value="">
                        <img id="ad-image-preview" style="max-height:120px; display:none; margin-top:10px;">
                    </label>
                </p>
                <p><label>Target URL: <input type="url" id="ad-target-url" style="width:100%"></label></p>
                <button class="button button-primary" onclick="ventureSaveAd()">Save Advertisement</button>
                <button class="button" onclick="ventureHideAdModal()">Cancel</button>
            </div>
        </div>
        <?php
    }

    public function ajax_save_ad() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        $data = [
            'client_id' => intval($_POST['client_id']),
            'title' => sanitize_text_field($_POST['title']),
            'image_url' => esc_url_raw($_POST['image_url']),
            'target_url' => esc_url_raw($_POST['target_url'])
        ];

        if ($id) {
            $wpdb->update("{$wpdb->prefix}venture_ads", $data, ['id' => $id]);
        } else {
            $wpdb->insert("{$wpdb->prefix}venture_ads", $data);
        }
        wp_send_json_success();
    }

    public function ajax_delete_ad() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;
        $id = intval($_POST['id']);
        $wpdb->delete("{$wpdb->prefix}venture_campaign_ads", ['ad_id' => $id]);
        $wpdb->delete("{$wpdb->prefix}venture_ad_stats", ['ad_id' => $id]);
        $wpdb->delete("{$wpdb->prefix}venture_ads", ['id' => $id]);
        wp_send_json_success();
    }

    // ====================== CAMPAIGNS PAGE ======================
            public function campaigns_page() {
        global $wpdb;
        $campaigns = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}venture_campaigns ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Campaigns</h1>
            <button class="page-title-action" onclick="ventureCreateCampaign()">Create New Campaign</button>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Campaign Name</th>
                        <th>Campaign ID</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $camp): ?>
                    <tr>
                        <td><?php echo esc_html($camp->id); ?></td>
                        <td><?php echo esc_html($camp->name); ?></td>
                        <td><code><?php echo esc_html($camp->campaign_id); ?></code></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=venture-native-campaigns&campaign_id=' . $camp->campaign_id); ?>" class="button">Manage Ads</a>
                            <button class="button button-link-delete" onclick="ventureDeleteCampaign(<?php echo esc_attr($camp->id); ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Campaign Creation Modal -->
            <div id="venture-campaign-modal" class="venture-modal venture-campaign-modal">
                <h2>Create New Campaign</h2>
                <p><label>Campaign Name:<br>
                    <input type="text" id="campaign-name" style="width:100%" placeholder="e.g. FNB Business Rotation">
                </label></p>
                <button class="button button-primary" onclick="ventureSaveCampaign()">Create Campaign</button>
                <button class="button" onclick="ventureHideCampaignModal()">Cancel</button>
            </div>
        </div>
        <?php

        // Detail view (drag & drop) if campaign_id is in the URL
        if (isset($_GET['campaign_id'])) {
            $this->campaign_detail_page(sanitize_text_field($_GET['campaign_id']));
        }
    }
    
    private function campaign_detail_page($campaign_id) {
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}venture_campaigns WHERE campaign_id = %s", $campaign_id));
        if (!$campaign) {
            echo '<div class="wrap"><p>Campaign not found.</p></div>';
            return;
        }

        $all_ads = $wpdb->get_results("
            SELECT a.*, c.name as client_name 
            FROM {$wpdb->prefix}venture_ads a 
            LEFT JOIN {$wpdb->prefix}venture_clients c ON a.client_id = c.id
        ");

        $assigned = $wpdb->get_results($wpdb->prepare("
            SELECT a.*, c.name as client_name, ca.sort_order 
            FROM {$wpdb->prefix}venture_campaign_ads ca 
            JOIN {$wpdb->prefix}venture_ads a ON ca.ad_id = a.id 
            LEFT JOIN {$wpdb->prefix}venture_clients c ON a.client_id = c.id
            WHERE ca.campaign_id = %d 
            ORDER BY ca.sort_order ASC
        ", $campaign->id));

        $assigned_ids = array_column($assigned, 'id');
        $available_ads = array_filter($all_ads, function($ad) use ($assigned_ids) {
            return !in_array($ad->id, $assigned_ids);
        });

        ?>

        <div class="wrap">
            <h2>Campaign: <?php echo esc_html($campaign->name); ?> 
                <small style="font-size:14px;color:#666;">(ID: <code><?php echo esc_html($campaign->campaign_id); ?></code>)</small>
            </h2>

            <div class="venture-campaign-container">
                <div class="venture-column venture-available">
                    <h3>Available Ads <small>(drag to right column)</small></h3>
                    <div id="available-ads" class="venture-sortable">
                        <?php foreach ($available_ads as $ad): ?>
                        <div class="venture-ad-card" data-ad-id="<?php echo esc_attr($ad->id); ?>">
                            <img src="<?php echo esc_url($ad->image_url); ?>" alt="">
                            <strong><?php echo esc_html($ad->title); ?></strong>
                            <small style="color:#666;"><?php echo esc_html($ad->client_name); ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="venture-column venture-campaign">
                    <h3>Campaign Ads <small>(drag here • order matters)</small></h3>
                    <div id="campaign-ads-container" class="venture-sortable">
                        <?php foreach ($assigned as $ad): ?>
                        <div class="venture-ad-card" data-ad-id="<?php echo esc_attr($ad->id); ?>">
                            <img src="<?php echo esc_url($ad->image_url); ?>" alt="">
                            <strong><?php echo esc_html($ad->title); ?></strong>
                            <small style="color:#666;"><?php echo esc_html($ad->client_name); ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="button button-primary" onclick="ventureSaveCampaignAds(<?php echo esc_attr($campaign->id); ?>)" style="margin-top:20px;">
                        💾 Save Order
                    </button>
                </div>
            </div>
        </div>

        <?php

        // Sortable configuration
        $js = <<<JS
    jQuery(function($) {
        $(".venture-sortable").sortable({
            connectWith: ".venture-sortable",
            items: ".venture-ad-card",
            placeholder: "ui-sortable-placeholder",
            forcePlaceholderSize: true,
            dropOnEmpty: true,
            tolerance: "intersect",
            distance: 5,
            helper: "clone",
            appendTo: "body",
            zIndex: 9999,
            revert: 100,
            start: function(e, ui) {
                ui.helper.addClass('dragging');
                ui.helper.css({ width: ui.item.outerWidth() + "px" });
            },
            stop: function(e, ui) {
                ui.helper.removeClass('dragging');
            }
        }).disableSelection();

        // ✅ Fixed save function
        window.ventureSaveCampaignAds = function(campaignId) {
            const adIds = $('#campaign-ads-container .venture-ad-card')
                .map(function() {
                    return $(this).data('ad-id') || $(this).attr('data-ad-id');
                })
                .get();

            console.log('%c📌 Saving campaign ID:', 'color:#F4A239;font-weight:bold', campaignId, '→ ads:', adIds);

            if (adIds.length === 0) {
                alert('No ads detected in the Campaign column. Try dragging again.');
                return;
            }

            const data = new FormData();
            data.append('action', 'venture_update_campaign_ads');
            data.append('nonce', ventureAdmin.nonce);
            data.append('campaign_id', campaignId);
            data.append('ad_ids', JSON.stringify(adIds));

            fetch(ventureAdmin.ajaxurl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    console.log('✅ Save response:', res);
                    if (res.success) {
                        alert('✅ Campaign ads saved successfully!');
                        location.reload();
                    } else {
                        alert('❌ Save failed: ' + (res.data?.message || res.message || 'Unknown error'));
                        console.error(res);
                    }
                })
                .catch(err => {
                    console.error('Request error:', err);
                    alert('❌ Request failed. Check browser console (F12)');
                });
        };
    });
    JS;

        wp_add_inline_script('venture-admin-js', $js, 'after');
    }

    public function ajax_save_campaign() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;
        $name = sanitize_text_field($_POST['name']);
        $campaign_id = 'camp_' . wp_generate_password(12, false, false);
        $wpdb->insert("{$wpdb->prefix}venture_campaigns", [
            'name' => $name,
            'campaign_id' => $campaign_id
        ]);
        wp_send_json_success(['campaign_id' => $campaign_id]);
    }

    public function ajax_delete_campaign() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;
        $id = intval($_POST['id']);
        $wpdb->delete("{$wpdb->prefix}venture_campaign_ads", ['campaign_id' => $id]);
        $wpdb->delete("{$wpdb->prefix}venture_campaigns", ['id' => $id]);
        wp_send_json_success();
    }

    public function ajax_campaign_ads() {
        // Not used
    }

    public function ajax_update_campaign_ads() {
        check_ajax_referer('venture_nonce', 'nonce');
        global $wpdb;

        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $ad_ids_json = $_POST['ad_ids'] ?? '[]';
        $ad_ids = json_decode($ad_ids_json, true) ?: [];

        if (!$campaign_id) {
            wp_send_json_error(['message' => 'Invalid campaign ID']);
        }

        // Debug log
        error_log('=== VENTURE CAMPAIGN ADS UPDATE ===');
        error_log('Campaign ID: ' . $campaign_id);
        error_log('ad_ids received: ' . $ad_ids_json);
        error_log('Decoded count: ' . count($ad_ids));

        // Clear old assignments
        $wpdb->delete("{$wpdb->prefix}venture_campaign_ads", ['campaign_id' => $campaign_id]);

        $inserted = 0;
        foreach ($ad_ids as $i => $ad_id) {
            $ad_id = intval($ad_id);
            if ($ad_id === 0) continue; // safety

            $result = $wpdb->insert("{$wpdb->prefix}venture_campaign_ads", [
                'campaign_id' => $campaign_id,
                'ad_id'       => $ad_id,
                'sort_order'  => $i
            ]);

            if ($result > 0) {  // stricter check
                $inserted++;
            } else {
                error_log('Insert failed for ad_id ' . $ad_id . ' - last_error: ' . $wpdb->last_error);
            }
        }

        // FINAL VERIFICATION
        $actual_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}venture_campaign_ads WHERE campaign_id = %d",
            $campaign_id
        ));

        if ($actual_count === count($ad_ids)) {
            wp_send_json_success(['message' => 'Campaign ads saved successfully!']);
        } else {
            wp_send_json_error([
                'message'     => 'Database error - not all ads were saved',
                'expected'    => count($ad_ids),
                'actual'      => $actual_count,
                'last_error'  => $wpdb->last_error
            ]);
        }
    }

    public function create_campaign() {
        // Simple JS trigger already in campaigns_page
    }

    // ====================== SETTINGS PAGE ======================
    public function settings_page() {
        if (isset($_POST['generate_secret']) && check_admin_referer('venture_settings')) {
            $this->generate_new_secret_key();
            echo '<div class="notice notice-success"><p>New secret key generated successfully.</p></div>';
        }
        $secret = get_option($this->secret_key_option);
        ?>
        <div class="wrap">
            <h1>Venture Native Ad Management — Settings</h1>
            <table class="form-table">
                <tr>
                    <th scope="row">Site Secret Key</th>
                    <td>
                        <input type="text" readonly value="<?php echo esc_attr($secret); ?>" style="width:100%; max-width:600px; font-family:monospace; font-size:15px;">
                        <form method="post" style="display:inline-block; margin-left:10px;">
                            <?php wp_nonce_field('venture_settings'); ?>
                            <button type="submit" name="generate_secret" class="button button-primary">Generate New Secret Key</button>
                        </form>
                        <p><em>Copy this key into every client site’s Venture Native Ads plugin settings.</em></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    private function generate_new_secret_key() {
        $key = bin2hex(random_bytes(16));
        update_option($this->secret_key_option, $key, true);
        return $key;
    }

    // ====================== ANALYTICS SHORTCODE ======================
    public function analytics_shortcode($atts) {
        $atts = shortcode_atts(['id' => ''], $atts);
        $client_id = sanitize_text_field($atts['id']);

        global $wpdb;
        $prefix = $wpdb->prefix;

        ob_start();

        if ($client_id === 'all') {
            // Grouped by client
            $clients = $wpdb->get_results("SELECT * FROM {$prefix}venture_clients");
            echo '<h2>All Clients Analytics</h2>';
            foreach ($clients as $client) {
                $this->render_client_analytics($client->id, $client->name);
            }
        } elseif ($client_id === 'all_campaigns') {
            // Campaign-level aggregated analytics
            $campaigns = $wpdb->get_results("SELECT * FROM {$prefix}venture_campaigns ORDER BY name ASC");
            echo '<h2>All Campaigns Analytics</h2>';
            foreach ($campaigns as $campaign) {
                $this->render_campaign_analytics($campaign->id, $campaign->name, $campaign->campaign_id);
            }
        } elseif (strpos($client_id, 'camp_') === 0) {
            // Specific campaign by its public campaign_id (e.g. camp_koP3jLryG5UU)
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}venture_campaigns WHERE campaign_id = %s",
                $client_id
            ));
            if ($campaign) {
                $this->render_campaign_analytics($campaign->id, $campaign->name, $campaign->campaign_id);
            } else {
                echo '<p>Campaign not found.</p>';
            }
        } else {
            $client = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}venture_clients WHERE id = %d", $client_id));
            if ($client) {
                $this->render_client_analytics($client->id, $client->name);
            } else {
                echo '<p>Client not found.</p>';
            }
        }

        return ob_get_clean();
    }

    private function render_client_analytics($client_id, $client_name) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $ads = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$prefix}venture_ads 
            WHERE client_id = %d 
            ORDER BY title
        ", $client_id));

        if (empty($ads)) {
            return; // Prevents ghost spacing from deleted ads
        }

        echo '<h3>Client: ' . esc_html($client_name) . '</h3>';

        foreach ($ads as $ad) {
            $stats = $this->get_ad_monthly_stats($ad->id);

            ?>
            <div class="venture-analytics-wrapper">
                <div class="venture-ad-main">
                    <!-- Left column: Ad info -->
                    <div class="venture-ad-info">
                        <h3 class="venture-ad-title-main"><?php echo esc_html($ad->title); ?></h3>
                        <div class="venture-ad-container">
                            <img src="<?php echo esc_url($ad->image_url); ?>" class="venture-ad-img" alt="">
                            <h4 class="venture-ad-title"><?php echo esc_html($ad->title); ?></h4>
                            <a href="<?php echo esc_url($ad->target_url); ?>" class="venture-ad-href" target="_blank" rel="nofollow sponsored"><?php echo esc_html($ad->target_url); ?></a>
                        </div>
                    </div>

                    <!-- Right column: Charts -->
                    <div class="venture-charts">
                        <div>
                            <h5 class="venture-chart-title">Impressions</h5>
                            <div class="venture-chart-wrapper">
                                <canvas id="imp-<?php echo esc_attr($ad->id); ?>" class="venture-chart"></canvas>
                            </div>
                        </div>
                        <div>
                            <h5 class="venture-chart-title">Clicks</h5>
                            <div class="venture-chart-wrapper">
                                <canvas id="clk-<?php echo esc_attr($ad->id); ?>" class="venture-chart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                function initCharts() {
                    if (typeof Chart === 'undefined') {
                        setTimeout(initCharts, 100);
                        return;
                    }

                    const impCanvas = document.getElementById('imp-<?php echo esc_attr($ad->id); ?>');
                    const clkCanvas = document.getElementById('clk-<?php echo esc_attr($ad->id); ?>');

                    if (!impCanvas || !clkCanvas) return;
                    if (impCanvas.chartInstance) return;

                    const months = <?php echo json_encode($stats['labels']); ?>;
                    const impData = <?php echo json_encode(array_values($stats['impressions'])); ?>;
                    const clkData = <?php echo json_encode(array_values($stats['clicks'])); ?>;

                    // Impressions chart
                    impCanvas.chartInstance = new Chart(impCanvas, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: [{
                                label: 'Impressions',
                                data: impData,
                                backgroundColor: '#F4A239'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: { y: { beginAtZero: true } },
                            plugins: { legend: { display: false } }
                        }
                    });

                    // Clicks chart
                    clkCanvas.chartInstance = new Chart(clkCanvas, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: [{
                                label: 'Clicks',
                                data: clkData,
                                backgroundColor: '#D1D741'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: { y: { beginAtZero: true } },
                            plugins: { legend: { display: false } }
                        }
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initCharts);
                } else {
                    initCharts();
                }
            })();
            </script>
            <?php
        }
    }

    private function get_ad_monthly_stats($ad_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $now = current_time('mysql');
        $start = date('Y-m-01', strtotime('-11 months', strtotime($now)));

        $raw = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(timestamp, '%Y-%m') as month,
                type,
                COUNT(*) as cnt
            FROM {$prefix}venture_ad_stats
            WHERE ad_id = %d 
            AND timestamp >= %s
            GROUP BY month, type
            ORDER BY month
        ", $ad_id, $start));

        $months_data = [];
        $labels = [];
        $current = strtotime($start);
        for ($i = 0; $i < 12; $i++) {
            $m = date('Y-m', $current);
            $label = date('M y', $current);
            $months_data[$m] = 0;
            $labels[] = $label;
            $current = strtotime('+1 month', $current);
        }

        $impressions = $months_data;
        $clicks = $months_data;

        foreach ($raw as $row) {
            if ($row->type === 'impression') $impressions[$row->month] = (int)$row->cnt;
            if ($row->type === 'click') $clicks[$row->month] = (int)$row->cnt;
        }

        return [
            'impressions' => $impressions,
            'clicks'      => $clicks,
            'labels'      => $labels
        ];
    }

    /**
     * Aggregate monthly stats across ALL ads in a campaign.
     */
    private function get_campaign_monthly_stats($campaign_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $now = current_time('mysql');
        $start = date('Y-m-01', strtotime('-11 months', strtotime($now)));

        // Get all ad IDs belonging to this campaign
        $ad_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ad_id FROM {$prefix}venture_campaign_ads
            WHERE campaign_id = %d
        ", $campaign_id));

        if (empty($ad_ids)) {
            return [
                'impressions' => [],
                'clicks'      => [],
                'ad_counts'   => [],
                'labels'      => []
            ];
        }

        $placeholders = implode(',', array_fill(0, count($ad_ids), '%d'));

        $raw = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(s.timestamp, '%Y-%m') as month,
                s.type,
                COUNT(DISTINCT s.ad_id) as ad_count,
                COUNT(*) as cnt
            FROM {$prefix}venture_ad_stats s
            WHERE s.ad_id IN ($placeholders)
              AND s.timestamp >= %s
            GROUP BY month, type
            ORDER BY month
        ", array_merge($ad_ids, [$start])));

        // Build 12-month skeleton
        $months_data = [];
        $labels = [];
        $current = strtotime($start);
        for ($i = 0; $i < 12; $i++) {
            $m = date('Y-m', $current);
            $label = date('M y', $current);
            $months_data[$m] = 0;
            $labels[] = $label;
            $current = strtotime('+1 month', $current);
        }

        $impressions = $months_data;
        $clicks = $months_data;
        $ad_counts = $months_data;

        foreach ($raw as $row) {
            if ($row->type === 'impression') {
                $impressions[$row->month] = (int)$row->cnt;
                $ad_counts[$row->month] = max($ad_counts[$row->month], (int)$row->ad_count);
            }
            if ($row->type === 'click') {
                $clicks[$row->month] = (int)$row->cnt;
            }
        }

        return [
            'impressions' => $impressions,
            'clicks'      => $clicks,
            'ad_counts'   => $ad_counts,
            'labels'      => $labels
        ];
    }

    /**
     * Render one campaign block with two-column layout (info + 3 charts)
     */
    private function render_campaign_analytics($campaign_id, $campaign_name, $campaign_code) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $stats = $this->get_campaign_monthly_stats($campaign_id);

        // Skip campaigns with no ads assigned
        $ad_count = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$prefix}venture_campaign_ads WHERE campaign_id = %d
        ", $campaign_id));

        if ($ad_count === 0) {
            return;
        }

        $months     = $stats['labels'];
        $impData    = array_values($stats['impressions']);
        $clkData    = array_values($stats['clicks']);
        $adCountData = array_values($stats['ad_counts']);

        ?>
        <div class="venture-analytics-wrapper">
            <div class="venture-ad-main">
                <!-- Left column: Campaign info -->
                <div class="venture-ad-info">
                    <h3 class="venture-ad-title-main"><?php echo esc_html($campaign_name); ?></h3>
                    <div class="venture-ad-container" style="padding:15px; background:#f9f9f9;">
                        <p style="margin:0 0 8px 0; font-size:13px; color:#666;">Campaign ID</p>
                        <code style="background:#eee; padding:4px 8px; border-radius:4px; font-size:13px;"><?php echo esc_html($campaign_code); ?></code>
                    </div>
                </div>

                <!-- Right column: Three charts -->
                <div class="venture-charts">
                    <div class="venture-charts-impressions">
                        <h5 class="venture-chart-title">Total impressions</h5>
                        <div class="venture-chart-wrapper">
                            <canvas id="camp-imp-<?php echo esc_attr($campaign_id); ?>" class="venture-chart"></canvas>
                        </div>
                    </div>
                    <div class="venture-charts-clicks">
                        <h5 class="venture-chart-title">Total clicks</h5>
                        <div class="venture-chart-wrapper">
                            <canvas id="camp-clk-<?php echo esc_attr($campaign_id); ?>" class="venture-chart"></canvas>
                        </div>
                    </div>
                    <div class="venture-charts-advertisements">
                        <h5 class="venture-chart-title">Total advertisements</h5>
                        <div class="venture-chart-wrapper">
                            <canvas id="camp-ads-<?php echo esc_attr($campaign_id); ?>" class="venture-chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            function initCampaignCharts() {
                if (typeof Chart === 'undefined') {
                    setTimeout(initCampaignCharts, 100);
                    return;
                }

                const impCanvas = document.getElementById('camp-imp-<?php echo esc_attr($campaign_id); ?>');
                const clkCanvas = document.getElementById('camp-clk-<?php echo esc_attr($campaign_id); ?>');
                const adsCanvas = document.getElementById('camp-ads-<?php echo esc_attr($campaign_id); ?>');

                if (!impCanvas || !clkCanvas || !adsCanvas) return;

                const months = <?php echo json_encode($months); ?>;
                const impData = <?php echo json_encode($impData); ?>;
                const clkData = <?php echo json_encode($clkData); ?>;
                const adData  = <?php echo json_encode($adCountData); ?>;

                new Chart(impCanvas, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Impressions',
                            data: impData,
                            backgroundColor: '#F4A239'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } },
                        plugins: { legend: { display: false } }
                    }
                });

                new Chart(clkCanvas, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Clicks',
                            data: clkData,
                            backgroundColor: '#D1D741'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } },
                        plugins: { legend: { display: false } }
                    }
                });

                new Chart(adsCanvas, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Active ads',
                            data: adData,
                            backgroundColor: '#F4A239'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true, precision: 0 } },
                        plugins: { legend: { display: false } }
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initCampaignCharts);
            } else {
                initCampaignCharts();
            }
        })();
        </script>
        <?php
    }

    public function cleanup_analytics_on_ad_delete($post_id, $post) {
        // Not triggered for custom ads
    }

    // ====================== REST API ======================
    public function register_rest_routes() {
        register_rest_route('venture-native-ad-management/v1', '/serve-campaign/(?P<campaign_id>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_serve_campaign'],
            'permission_callback' => [$this, 'verify_secret_key'],
        ]);

        register_rest_route('venture-native-ad-management/v1', '/track', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_track_event'],
            'permission_callback' => [$this, 'verify_secret_key'],
        ]);
    }

    public function verify_secret_key($request) {
        $key = $request->get_header('X-Venture-Secret');
        $stored = get_option($this->secret_key_option);
        return $key && $stored && hash_equals($stored, $key);
    }

        public function rest_serve_campaign($request) {
        $campaign_id = $request['campaign_id'];
        global $wpdb;

        $campaign = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}venture_campaigns WHERE campaign_id = %s", $campaign_id));
        if (!$campaign) {
            return new WP_Error('not_found', 'Campaign not found', ['status' => 404]);
        }

        // Get ads in campaign
        $ads = $wpdb->get_results($wpdb->prepare("
            SELECT a.* FROM {$wpdb->prefix}venture_campaign_ads ca
            JOIN {$wpdb->prefix}venture_ads a ON ca.ad_id = a.id
            WHERE ca.campaign_id = %d
            ORDER BY ca.sort_order
        ", $campaign->id));

        if (empty($ads)) {
            $response = rest_ensure_response(['ads' => []]);
            $response->set_headers(wp_get_nocache_headers());
            return $response;
        }

        // Balancing: choose ad with least impressions THIS WEEK
        $week_start = date('Y-m-d H:i:s', strtotime('this week'));
        $impression_counts = [];
        foreach ($ads as $ad) {
            $count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}venture_ad_stats 
                WHERE ad_id = %d AND type = 'impression' AND timestamp >= %s
            ", $ad->id, $week_start));
            $impression_counts[$ad->id] = (int)$count;
        }

        // Find min impressions
        asort($impression_counts);
        $min_count = reset($impression_counts);
        $candidates = array_keys(array_filter($impression_counts, fn($c) => $c === $min_count));

        // Pick first (stable order)
        $selected_ad_id = $candidates[0] ?? $ads[0]->id;

        // Return full ad data
        $selected = current(array_filter($ads, fn($a) => $a->id == $selected_ad_id));

        $response = rest_ensure_response([
            'ads' => [
                [
                    'id'         => $selected->id,
                    'title'      => $selected->title,
                    'image_url'  => $selected->image_url,
                    'target_url' => $selected->target_url
                ]
            ]
        ]);

        // === Prevent all caching ===
        $response->set_headers(wp_get_nocache_headers());
        $response->header('Vary', 'X-Venture-Secret');

        return $response;
    }

    public function rest_track_event($request) {
        global $wpdb;
        $params = $request->get_json_params();

        $ad_id = intval($params['ad_id'] ?? 0);
        $type = in_array($params['type'] ?? '', ['impression', 'click']) ? $params['type'] : 'impression';
        $site = sanitize_text_field($params['site_identifier'] ?? '');

        if ($ad_id) {
            $wpdb->insert("{$wpdb->prefix}venture_ad_stats", [
                'ad_id' => $ad_id,
                'type' => $type,
                'site_identifier' => $site
            ]);
        }

        return rest_ensure_response(['success' => true]);
    }
}

Venture_Native_Ad_Management::get_instance();

