<?php
/**
 * Plugin Name: WP Webklient Announcements and Timer
 * Description: Custom post type „Oznámení“ s deadlinem a horním pruhem s odpočtem. Načítání přes AJAX, přesné podle času serveru.
 * Version: 1.1.0
 * Author: by webklient.cz
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class WPWebklient_Announcements {
    const CPT = 'announcement';
    const META_DEADLINE = '_announcement_deadline_ts'; // UNIX timestamp UTC
    const OPTION_AUTO_INJECT = 'wpa_auto_inject'; // 1 = zapnuto

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);

        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_columns_content'], 10, 2);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_body_open', [$this, 'inject_mountpoint']); // prázdný mountpoint
        add_shortcode('announcement_banner', [$this, 'shortcode_banner']);

        add_action('wp_ajax_wpa_get_announcement', [$this, 'ajax_get_announcement']);
        add_action('wp_ajax_nopriv_wpa_get_announcement', [$this, 'ajax_get_announcement']);

        add_action('admin_init', function () {
            if (get_option(self::OPTION_AUTO_INJECT) === false) update_option(self::OPTION_AUTO_INJECT, 1);
        });
    }

    public function register_cpt() {
        $labels = [
            'name'               => 'Oznámení',
            'singular_name'      => 'Oznámení',
            'menu_name'          => 'Oznámení',
            'add_new'            => 'Přidat nové',
            'add_new_item'       => 'Přidat nové oznámení',
            'new_item'           => 'Nové oznámení',
            'edit_item'          => 'Upravit oznámení',
            'view_item'          => 'Zobrazit oznámení',
            'all_items'          => 'Všechna oznámení',
            'search_items'       => 'Hledat oznámení',
            'not_found'          => 'Nenalezeno',
            'not_found_in_trash' => 'V koši nenalezeno',
        ];

        register_post_type(self::CPT, [
            'labels' => $labels,
            'public' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor'],
            'menu_icon' => 'dashicons-megaphone',
            'has_archive' => false,
            'rewrite' => ['slug' => 'oznameni'],
        ]);
    }

    public function register_metabox() {
        add_meta_box(
            'wpa_deadline_box',
            'Deadline oznámení',
            [$this, 'metabox_html'],
            self::CPT,
            'side',
            'high'
        );
    }

    public function metabox_html($post) {
        wp_nonce_field('wpa_deadline_save', 'wpa_deadline_nonce');
        $ts = (int) get_post_meta($post->ID, self::META_DEADLINE, true);

        $local = '';
        if ($ts > 0) {
            $dt = new DateTime('@' . $ts);
            $dt->setTimezone(wp_timezone());
            $local = $dt->format('Y-m-d\TH:i');
        }
        ?>
        <div class="wpa-meta wpa-meta--deadline">
            <p class="wpa-meta__row">
                <label class="wpa-meta__label" for="wpa_deadline"><strong>Datum a čas ukončení</strong></label><br>
                <input class="wpa-meta__input wpa-meta__input--datetime" type="datetime-local" id="wpa_deadline" name="wpa_deadline" value="<?php echo esc_attr($local); ?>" />
            </p>
            <p class="wpa-meta__hint">Po uplynutí se horní pruh skryje. Ukládá se v UTC podle časové zóny webu.</p>
        </div>
        <?php
    }

    public function save_metabox($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (!isset($_POST['wpa_deadline_nonce']) || !wp_verify_nonce($_POST['wpa_deadline_nonce'], 'wpa_deadline_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['wpa_deadline']) && $_POST['wpa_deadline'] !== '') {
            try {
                $tz = wp_timezone();
                $dt = new DateTime(sanitize_text_field($_POST['wpa_deadline']), $tz);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $ts = $dt->getTimestamp();
                update_post_meta($post_id, self::META_DEADLINE, $ts);
            } catch (Exception $e) {
                delete_post_meta($post_id, self::META_DEADLINE);
            }
        } else {
            delete_post_meta($post_id, self::META_DEADLINE);
        }
    }

    public function admin_columns($cols) {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') $new['wpa_deadline_col'] = 'Deadline';
        }
        return $new;
    }

    public function admin_columns_content($col, $post_id) {
        if ($col === 'wpa_deadline_col') {
            $ts = (int) get_post_meta($post_id, self::META_DEADLINE, true);
            if ($ts > 0) {
                $dt = new DateTime('@' . $ts);
                $dt->setTimezone(wp_timezone());
                echo '<span class="wpa-deadline-admin">' . esc_html($dt->format('j.n.Y H:i')) . '</span>';
            } else {
                echo '<span class="wpa-deadline-admin wpa-deadline-admin--empty">nenastaveno</span>';
            }
        }
    }

    private function get_active_announcement() {
        $now_utc = time();
        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => self::META_DEADLINE,
                    'value'   => $now_utc,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
            ],
            'no_found_rows'  => true,
        ]);
        if ($q->have_posts()) {
            $p = $q->posts[0];
            return [
                'id'       => (int) $p->ID,
                'title'    => get_the_title($p),
                'content'  => apply_filters('the_content', $p->post_content),
                'deadline' => (int) get_post_meta($p->ID, self::META_DEADLINE, true),
            ];
        }
        return null;
    }

    public function enqueue_assets() {
        if (is_admin()) return;

        wp_register_style('wpa-banner', plugins_url('assets/banner.css', __FILE__), [], '1.1.0');
        wp_enqueue_style('wpa-banner');

        wp_register_script('wpa-countdown', plugins_url('assets/countdown.js', __FILE__), ['jquery'], '1.1.0', true);
        wp_localize_script('wpa-countdown', 'WPA_DATA', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'action'    => 'wpa_get_announcement',
            'mount_sel' => '#wpa-announcement-mount',
        ]);
        wp_enqueue_script('wpa-countdown');
    }

    public function inject_mountpoint() {
        if (is_admin()) return;
        if (!get_option(self::OPTION_AUTO_INJECT)) return;
        echo '<div class="wpa-mount wpa-mount--top" id="wpa-announcement-mount" aria-live="polite" aria-atomic="true"></div>';
    }

    public function shortcode_banner($atts = []) {
        return '<div class="wpa-mount wpa-mount--shortcode" id="wpa-announcement-mount" aria-live="polite" aria-atomic="true"></div>';
    }

    public function ajax_get_announcement() {
        // veřejné čtení bez noncu, jen read-only
        $ann = $this->get_active_announcement();
        $server_now = time();

        if (!$ann) {
            wp_send_json_success([
                'active' => false,
                'now'    => $server_now,
            ]);
        }

        // vrátíme i předrenderovaný bezpečný HTML obsah
        $deadline_iso = gmdate('c', $ann['deadline']);
        $html = sprintf(
            '<div class="wpa-banner" data-deadline="%s" role="region" aria-label="%s">' .
                '<div class="wpa-banner__inner">' .
                    '<div class="wpa-banner__title">%s</div>' .
                    '<div class="wpa-banner__content">%s</div>' .
                    '<div class="wpa-banner__timer">' .
                        '<span class="wpa-banner__timer-label">Zbývá:</span>' .
                        '<span class="wpa-banner__timer-value" id="wpa-countdown">--:--:--</span>' .
                    '</div>' .
                '</div>' .
            '</div>',
            esc_attr($deadline_iso),
            esc_attr__('Oznámení', 'wp-webklient-announcements-and-timer'),
            esc_html($ann['title']),
            $ann['content'] // již přes the_content; předpokládá vlastní escaping WP
        );

        wp_send_json_success([
            'active'   => true,
            'now'      => $server_now,
            'deadline' => (int) $ann['deadline'],
            'html'     => $html,
        ]);
    }
}

// Inicializace pluginu
function wp_webklient_announcements_init() {
    new WPWebklient_Announcements();
}
add_action('plugins_loaded', 'wp_webklient_announcements_init');

// Instalační hook
register_activation_hook(__FILE__, 'wp_webklient_announcements_activate');
function wp_webklient_announcements_activate() {
    // Registruj custom post type při aktivaci
    $plugin = new WPWebklient_Announcements();
    $plugin->register_cpt();
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Nastav výchozí hodnoty
    if (get_option(WPWebklient_Announcements::OPTION_AUTO_INJECT) === false) {
        update_option(WPWebklient_Announcements::OPTION_AUTO_INJECT, 1);
    }
}

// Deaktivace hook
register_deactivation_hook(__FILE__, 'wp_webklient_announcements_deactivate');
function wp_webklient_announcements_deactivate() {
    // Flush rewrite rules při deaktivaci
    flush_rewrite_rules();
}
