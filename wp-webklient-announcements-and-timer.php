<?php
/**
 * Plugin Name: WP Webklient Announcements and Timer
 * Description: Custom post type „Oznámení“ s deadlinem a horním pruhem s odpočtem. Načítání přes AJAX, přesné podle času serveru.
 * Version: 1.2.0
 * Author: by webklient.cz
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class WPWebklient_Announcements {
    const CPT = 'announcement';
    const META_DEADLINE = '_announcement_deadline_ts'; // UNIX timestamp UTC
    const META_BUTTON_LABEL = '_announcement_button_label'; // text tlačítka
    const META_BUTTON_URL = '_announcement_button_url'; // URL tlačítka
    const OPTION_DISPLAY_MODE = 'wpa_display_mode'; // newest, all_vertical

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);

        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_columns_content'], 10, 2);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_shortcode('wk_announcement_banner', [$this, 'shortcode_universal']);

        add_action('wp_ajax_wpa_get_announcement', [$this, 'ajax_get_announcement']);
        add_action('wp_ajax_nopriv_wpa_get_announcement', [$this, 'ajax_get_announcement']);

        add_action('admin_init', function () {
            if (get_option(self::OPTION_DISPLAY_MODE) === false) update_option(self::OPTION_DISPLAY_MODE, 'newest');
        });

        add_action('admin_menu', [$this, 'add_admin_menu']);
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
        $button_label = get_post_meta($post->ID, self::META_BUTTON_LABEL, true);
        $button_url = get_post_meta($post->ID, self::META_BUTTON_URL, true);

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

            <p class="wpa-meta__row">
                <label class="wpa-meta__label" for="wpa_button_label"><strong>Text tlačítka (volitelné)</strong></label><br>
                <input class="wpa-meta__input" type="text" id="wpa_button_label" name="wpa_button_label" value="<?php echo esc_attr($button_label); ?>" placeholder="např. VYPLNIT DOTAZNÍK" />
            </p>

            <p class="wpa-meta__row">
                <label class="wpa-meta__label" for="wpa_button_url"><strong>Odkaz tlačítka (volitelné)</strong></label><br>
                <input class="wpa-meta__input" type="url" id="wpa_button_url" name="wpa_button_url" value="<?php echo esc_attr($button_url); ?>" placeholder="https://example.com" />
            </p>
            <p class="wpa-meta__hint">Pokud vyplníte oba údaje, zobrazí se tlačítko vpravo od titulku.</p>
        </div>
        <?php
    }

    public function save_metabox($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (!isset($_POST['wpa_deadline_nonce']) || !wp_verify_nonce($_POST['wpa_deadline_nonce'], 'wpa_deadline_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Uložení deadline
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

        // Uložení tlačítka
        if (isset($_POST['wpa_button_label']) && $_POST['wpa_button_label'] !== '') {
            update_post_meta($post_id, self::META_BUTTON_LABEL, sanitize_text_field($_POST['wpa_button_label']));
        } else {
            delete_post_meta($post_id, self::META_BUTTON_LABEL);
        }

        if (isset($_POST['wpa_button_url']) && $_POST['wpa_button_url'] !== '') {
            $url = esc_url_raw($_POST['wpa_button_url']);
            if ($url) {
                update_post_meta($post_id, self::META_BUTTON_URL, $url);
            } else {
                delete_post_meta($post_id, self::META_BUTTON_URL);
            }
        } else {
            delete_post_meta($post_id, self::META_BUTTON_URL);
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

    private function get_active_announcements() {
        $now_utc = time();
        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Všechna oznámení
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => self::META_DEADLINE,
                    'value'   => $now_utc,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => self::META_DEADLINE,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => self::META_DEADLINE,
                    'value'   => '',
                    'compare' => '=', // prázdná hodnota
                ],
            ],
            'no_found_rows'  => true,
        ]);

        $announcements = [];
        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $deadline = (int) get_post_meta($p->ID, self::META_DEADLINE, true);

                // Přeskočíme vypršená oznámení
                if ($deadline > 0 && $deadline <= $now_utc) {
                    continue;
                }

                $announcements[] = [
                    'id'           => (int) $p->ID,
                    'title'        => get_the_title($p),
                    'content'      => apply_filters('the_content', $p->post_content),
                    'deadline'     => $deadline > 0 ? date('Y-m-d H:i:s', $deadline) : '',
                    'button_label' => get_post_meta($p->ID, self::META_BUTTON_LABEL, true),
                    'button_url'   => get_post_meta($p->ID, self::META_BUTTON_URL, true),
                ];
            }
        }
        return $announcements;
    }

    private function get_active_announcement() {
        $announcements = $this->get_active_announcements();
        return !empty($announcements) ? $announcements[0] : null;
    }

    public function enqueue_assets() {
        if (is_admin()) return;

        wp_register_style('wpa-banner', plugins_url('assets/banner.css', __FILE__), [], '1.2.0');
        wp_enqueue_style('wpa-banner');

        // countdown.js můžeš ponechat, ale není vázaný na mountpoint
        wp_register_script('wpa-countdown', plugins_url('assets/countdown.js', __FILE__), [], '1.2.0', true);
        wp_localize_script('wpa-countdown', 'WPA_DATA', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'action'    => 'wpa_get_announcement',
        ]);
        wp_enqueue_script('wpa-countdown');
    }

    public function shortcode_universal($atts = []) {
        $display_mode = get_option(self::OPTION_DISPLAY_MODE, 'newest');

        $announcements = $this->get_active_announcements();
        if (empty($announcements)) {
            return '';
        }

        if ($display_mode === 'newest') {
            $announcements = [reset($announcements)];
        }

        $html = '';
        $server_now = time();

        foreach ($announcements as $index => $ann) {
            $unique_id = 'wpa-announcement-' . $index . '-' . $server_now;
            $html .= $this->render_single_banner($ann, $unique_id, $server_now);

            if ($display_mode === 'all_vertical' && $index < count($announcements) - 1) {
                $html .= '<hr class="wpa-banner-separator" style="margin: 10px 0; border: none; border-top: 1px solid rgba(255,255,255,0.3);">';
            }
        }

        return $html;
    }

    private function render_single_banner($ann, $unique_id, $server_now) {
        $deadline_valid = (!empty($ann['deadline']) && $ann['deadline'] !== '0' && strtotime($ann['deadline']) > time());

        $html = '<div class="wpa-banner" role="region" aria-label="Oznámení" id="' . esc_attr($unique_id) . '">';
        $html .= '<div class="wpa-banner__inner">';
        $html .= '<div class="wpa-banner__content">';

        // Titulek
        if (!empty($ann['content'])) {
            $html .= '<a href="' . esc_url(get_permalink($ann['id'])) . '" class="wpa-banner__title">' . esc_html($ann['title']) . '</a>';
        } else {
            $html .= '<span class="wpa-banner__title">' . esc_html($ann['title']) . '</span>';
        }

        // Tlačítko
        if (!empty($ann['button_label']) && !empty($ann['button_url'])) {
            $html .= '<a href="' . esc_url($ann['button_url']) . '" class="wpa-banner__button" target="_blank" rel="noopener">' . esc_html($ann['button_label']) . '</a>';
        }

        $html .= '</div>'; // content

        // Countdown
        if ($deadline_valid) {
            $html .= '<div class="wpa-banner__timer">';
            $html .= '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-d-' . $unique_id . '">00</div><div class="wpa-banner__timer-label">DNI</div></div>';
            $html .= '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-h-' . $unique_id . '">00</div><div class="wpa-banner__timer-label">HODIN</div></div>';
            $html .= '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-m-' . $unique_id . '">00</div><div class="wpa-banner__timer-label">MINUT</div></div>';
            $html .= '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-s-' . $unique_id . '">00</div><div class="wpa-banner__timer-label">SEKUND</div></div>';
            $html .= '</div>';

            $deadline_ts = strtotime($ann['deadline']);
            $html .= '<script>
            (function(){
                var d = document.getElementById("wpa-d-' . $unique_id . '");
                var h = document.getElementById("wpa-h-' . $unique_id . '");
                var m = document.getElementById("wpa-m-' . $unique_id . '");
                var s = document.getElementById("wpa-s-' . $unique_id . '");
                if(!d||!h||!m||!s) return;

                var serverNow = ' . (int)$server_now . ';
                var deadline  = ' . (int)$deadline_ts . ';
                var skew = Date.now() - (serverNow * 1000);

                function pad(n){ return n < 10 ? "0" + n : "" + n; }

                function tick(){
                    var nowMs = Date.now() - skew;
                    var diff = (deadline * 1000) - nowMs;

                    if (diff <= 0) {
                        var banner = d.closest(".wpa-banner");
                        if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
                        return;
                    }

                    var secs = Math.floor(diff / 1000);
                    var dd = Math.floor(secs / 86400);
                    var hh = Math.floor((secs % 86400) / 3600);
                    var mm = Math.floor((secs % 3600) / 60);
                    var ss = secs % 60;

                    d.textContent = pad(dd);
                    h.textContent = pad(hh);
                    m.textContent = pad(mm);
                    s.textContent = pad(ss);
                }

                tick();
                setInterval(tick, 1000);
            })();
            </script>';
        }

        $html .= '</div>'; // inner
        $html .= '</div>'; // banner
        return $html;
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . self::CPT,
            'Nastavení - Oznámení',
            'Nastavení',
            'manage_options',
            'wpa-settings',
            [$this, 'admin_settings_page']
        );
    }

    public function admin_settings_page() {
        // Zpracování formuláře
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['wpa_settings_nonce'], 'wpa_settings_save')) {
            $display_mode = sanitize_text_field($_POST['wpa_display_mode']);
            update_option(self::OPTION_DISPLAY_MODE, $display_mode);
            echo '<div class="notice notice-success"><p>Nastavení bylo uloženo!</p></div>';
        }

        $current_mode = get_option(self::OPTION_DISPLAY_MODE, 'newest');
        ?>
        <div class="wrap">
            <h1>Nastavení - Oznámení a Timer</h1>

            <div class="wpa-settings-container" style="max-width: 800px;">

                <form method="post" action="">
                    <?php wp_nonce_field('wpa_settings_save', 'wpa_settings_nonce'); ?>

                    <div class="wpa-settings-section" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 8px;">
                        <h2>Režim zobrazování</h2>
                        <p>Vyberte, jak se budou oznámení zobrazovat na webu:</p>

                        <table class="form-table">
                            <tr>
                                <th scope="row">Režim zobrazování</th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="radio" name="wpa_display_mode" value="newest" <?php checked($current_mode, 'newest'); ?>>
                                            <strong>Nejnovější oznámení</strong><br>
                                            <small>Zobrazí pouze nejnovější aktivní oznámení</small>
                                        </label><br><br>

                                        <label>
                                            <input type="radio" name="wpa_display_mode" value="all_vertical" <?php checked($current_mode, 'all_vertical'); ?>>
                                            <strong>Všechna aktivní pod sebou</strong><br>
                                            <small>Zobrazí všechna aktivní oznámení vertikálně pod sebou (rychlé načítání)</small>
                                        </label><br><br>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('Uložit nastavení'); ?>
                    </div>
                </form>

                <div class="wpa-settings-section" style="background: #e7f3ff; padding: 20px; margin: 20px 0; border: 1px solid #0073aa; border-radius: 8px;">
                    <h2>Použití</h2>
                    <p>Po nastavení režimu zobrazování použijte tento univerzální shortcode:</p>
                    <code style="background: #f1f1f1; padding: 15px; display: block; margin: 10px 0; font-size: 16px; font-weight: bold;">[wk_announcement_banner]</code>
                    <p><strong>Shortcode se automaticky přizpůsobí</strong> vybranému režimu zobrazování.</p>
                </div>

                
            </div>
        </div>

        <style>
        .wpa-settings-container h2 {
            color: #23282d;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 10px;
        }
        .wpa-settings-container code {
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .wpa-settings-container ul {
            margin-left: 20px;
        }
        .wpa-settings-container li {
            margin-bottom: 8px;
        }
        .wpa-settings-container fieldset label {
            display: block;
            margin-bottom: 10px;
        }
        .wpa-settings-container fieldset input[type="radio"] {
            margin-right: 8px;
        }
        </style>
        <?php
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

        // vrátíme i předrenderovaný HTML obsah
        $deadline_iso = !empty($ann['deadline']) ? gmdate('c', strtotime($ann['deadline'])) : '';

        $has_content = !empty(trim(strip_tags($ann['content'])));

        $title_html = $ann['title'];
        if ($has_content) {
            $post_url = get_permalink($ann['id']);
            $title_html = sprintf('<a href="%s" class="wpa-banner__title wpa-banner__title--clickable">%s</a>',
                esc_url($post_url),
                esc_html($ann['title'])
            );
        } else {
            $title_html = sprintf('<span class="wpa-banner__title">%s</span>', esc_html($ann['title']));
        }

        $button_html = '';
        if (!empty($ann['button_label']) && !empty($ann['button_url'])) {
            $button_html = sprintf(
                '<a href="%s" class="wpa-banner__button" target="_blank" rel="noopener">%s</a>',
                esc_url($ann['button_url']),
                esc_html($ann['button_label'])
            );
        }

        $html = sprintf(
            '<div class="wpa-banner" %s role="region" aria-label="%s">' .
                '<div class="wpa-banner__inner">' .
                    '<div class="wpa-banner__content">%s%s</div>' .
                    '<div class="wpa-banner__timer">' .
                        '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-countdown-days">--</div><div class="wpa-banner__timer-label">DNI</div></div>' .
                        '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-countdown-hours">--</div><div class="wpa-banner__timer-label">HODIN</div></div>' .
                        '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-countdown-minutes">--</div><div class="wpa-banner__timer-label">MINUT</div></div>' .
                        '<div class="wpa-banner__timer-item"><div class="wpa-banner__timer-value" id="wpa-countdown-seconds">--</div><div class="wpa-banner__timer-label">SEKUND</div></div>' .
                    '</div>' .
                '</div>' .
            '</div>',
            $deadline_iso ? 'data-deadline="' . esc_attr($deadline_iso) . '"' : '',
            esc_attr__('Oznámení', 'wp-webklient-announcements-and-timer'),
            $title_html,
            $button_html
        );

        wp_send_json_success([
            'active'   => true,
            'now'      => $server_now,
            'deadline' => !empty($ann['deadline']) ? (int) strtotime($ann['deadline']) : 0,
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
    $plugin = new WPWebklient_Announcements();
    $plugin->register_cpt();
    flush_rewrite_rules();
}

// Deaktivace hook
register_deactivation_hook(__FILE__, 'wp_webklient_announcements_deactivate');
function wp_webklient_announcements_deactivate() {
    flush_rewrite_rules();
}
