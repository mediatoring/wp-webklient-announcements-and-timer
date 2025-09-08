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
    const META_BUTTON_LABEL = '_announcement_button_label'; // text tlačítka
    const META_BUTTON_URL = '_announcement_button_url'; // URL tlačítka
    const OPTION_AUTO_INJECT = 'wpa_auto_inject'; // 1 = zapnuto

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);

        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_columns_content'], 10, 2);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // add_action('wp_body_open', [$this, 'inject_mountpoint']); // prázdný mountpoint - vypnuto
        add_shortcode('announcement_banner', [$this, 'shortcode_banner']);
        add_shortcode('announcement_banner_direct', [$this, 'shortcode_banner_direct']);

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
                'id'           => (int) $p->ID,
                'title'        => get_the_title($p),
                'content'      => apply_filters('the_content', $p->post_content),
                'deadline'     => (int) get_post_meta($p->ID, self::META_DEADLINE, true),
                'button_label' => get_post_meta($p->ID, self::META_BUTTON_LABEL, true),
                'button_url'   => get_post_meta($p->ID, self::META_BUTTON_URL, true),
            ];
        }
        return null;
    }

    public function enqueue_assets() {
        if (is_admin()) return;

        wp_register_style('wpa-banner', plugins_url('assets/banner.css', __FILE__), [], '1.1.0');
        wp_enqueue_style('wpa-banner');

        wp_register_script('wpa-countdown', plugins_url('assets/countdown.js', __FILE__), [], '1.1.0', true); // true = načte na konci
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
        // Ujistíme se, že jsou načteny skripty i při shortcode
        $this->enqueue_assets();
        
        // Vytvoříme unikátní ID pro každý shortcode instance
        static $shortcode_count = 0;
        $shortcode_count++;
        $unique_id = 'wpa-announcement-mount-' . $shortcode_count;
        
        // Přidáme inline script pro okamžitou inicializaci
        $script = "
        <script>
        (function() {
            var mountId = '" . esc_js($unique_id) . "';
            var mount = document.getElementById(mountId);
            if (!mount) return;
            
            // Počkáme na načtení WPA_DATA
            function waitForWPA() {
                if (window.WPA_DATA) {
                    initShortcodeAnnouncement();
                } else {
                    setTimeout(waitForWPA, 50);
                }
            }
            
            // Spustíme hned
            waitForWPA();
            
            function initShortcodeAnnouncement() {
                var ajaxUrl = window.WPA_DATA.ajax_url || '/wp-admin/admin-ajax.php';
                var action = window.WPA_DATA.action || 'wpa_get_announcement';
                var url = ajaxUrl + '?action=' + encodeURIComponent(action);
                
                fetch(url)
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data && data.success && data.data && data.data.active) {
                            mount.innerHTML = '<div class=\"wpa-banner-wrap\">' + data.data.html + '</div>';
                            var banner = mount.querySelector('.wpa-banner');
                            if (banner) {
                                startCountdown(banner, data.data.now, data.data.deadline);
                            }
                        }
                    })
                    .catch(function(error) {
                        console.log('WPA: Chyba při načítání oznámení:', error);
                    });
            }
            
            function startCountdown(root, serverNowSec, deadlineSec) {
                var daysEl = root.querySelector('#wpa-countdown-days');
                var hoursEl = root.querySelector('#wpa-countdown-hours');
                var minutesEl = root.querySelector('#wpa-countdown-minutes');
                var secondsEl = root.querySelector('#wpa-countdown-seconds');
                
                if (!daysEl || !hoursEl || !minutesEl || !secondsEl) return;
                
                var skew = Date.now() - (serverNowSec * 1000);
                function tick() {
                    var nowMs = Date.now() - skew;
                    var diff = (deadlineSec * 1000) - nowMs;
                    
                    if (diff <= 0) {
                        var wrap = root.closest('.wpa-banner-wrap') || root;
                        if (wrap && wrap.parentNode) wrap.parentNode.innerHTML = '';
                        return;
                    }
                    
                    var s = Math.floor(diff / 1000);
                    var d = Math.floor(s / 86400);
                    var h = Math.floor((s % 86400) / 3600);
                    var m = Math.floor((s % 3600) / 60);
                    var sec = s % 60;
                    
                    daysEl.textContent = (d < 10 ? '0' : '') + d;
                    hoursEl.textContent = (h < 10 ? '0' : '') + h;
                    minutesEl.textContent = (m < 10 ? '0' : '') + m;
                    secondsEl.textContent = (sec < 10 ? '0' : '') + sec;
                }
                
                tick();
                setInterval(tick, 1000);
            }
        })();
        </script>";
        
        return '<div class="wpa-mount wpa-mount--shortcode" id="' . esc_attr($unique_id) . '" aria-live="polite" aria-atomic="true"></div>' . $script;
    }

    public function shortcode_banner_direct($atts = []) {
        // Načteme oznámení přímo v PHP - rychlejší
        $ann = $this->get_active_announcement();
        
        if (!$ann) {
            return ''; // Žádné aktivní oznámení
        }

        // Zkontrolujeme, zda má obsah nějaký text (kromě prázdných tagů)
        $has_content = !empty(trim(strip_tags($ann['content'])));
        
        // Pokud má obsah, uděláme titulek proklikávací
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
        
        // Přidáme tlačítko, pokud má label i URL
        $button_html = '';
        if (!empty($ann['button_label']) && !empty($ann['button_url'])) {
            $button_html = sprintf(
                '<a href="%s" class="wpa-banner__button" target="_blank" rel="noopener">%s</a>',
                esc_url($ann['button_url']),
                esc_html($ann['button_label'])
            );
        }

        // Vytvoříme unikátní ID pro každý shortcode instance
        static $shortcode_count = 0;
        $shortcode_count++;
        $unique_id = 'wpa-announcement-direct-' . $shortcode_count;
        
        $deadline_iso = gmdate('c', $ann['deadline']);
        $server_now = time();
        
        $html = sprintf(
            '<div class="wpa-banner" data-deadline="%s" role="region" aria-label="%s">' .
                '<div class="wpa-banner__inner">' .
                    '<div class="wpa-banner__content">' .
                        '%s' .
                        '%s' .
                    '</div>' .
                    '<div class="wpa-banner__timer">' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-days-%s">--</div>' .
                            '<div class="wpa-banner__timer-label">DNI</div>' .
                        '</div>' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-hours-%s">--</div>' .
                            '<div class="wpa-banner__timer-label">HODIN</div>' .
                        '</div>' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-minutes-%s">--</div>' .
                            '<div class="wpa-banner__timer-label">MINUT</div>' .
                        '</div>' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-seconds-%s">--</div>' .
                            '<div class="wpa-banner__timer-label">SEKUND</div>' .
                        '</div>' .
                    '</div>' .
                '</div>' .
            '</div>',
            esc_attr($deadline_iso),
            esc_attr__('Oznámení', 'wp-webklient-announcements-and-timer'),
            $title_html,
            $button_html,
            $unique_id,
            $unique_id,
            $unique_id,
            $unique_id
        );

        // Přidáme JavaScript pro countdown
        $script = "
        <script>
        (function() {
            var daysEl = document.getElementById('wpa-countdown-days-%s');
            var hoursEl = document.getElementById('wpa-countdown-hours-%s');
            var minutesEl = document.getElementById('wpa-countdown-minutes-%s');
            var secondsEl = document.getElementById('wpa-countdown-seconds-%s');
            
            if (!daysEl || !hoursEl || !minutesEl || !secondsEl) return;
            
            var serverNow = %d;
            var deadline = %d;
            var skew = Date.now() - (serverNow * 1000);
            
            function pad(n) { return n < 10 ? '0' + n : '' + n; }
            
            function tick() {
                var nowMs = Date.now() - skew;
                var diff = (deadline * 1000) - nowMs;
                
                if (diff <= 0) {
                    var banner = daysEl.closest('.wpa-banner');
                    if (banner && banner.parentNode) {
                        banner.parentNode.innerHTML = '';
                    }
                    return;
                }
                
                var s = Math.floor(diff / 1000);
                var d = Math.floor(s / 86400);
                var h = Math.floor((s %% 86400) / 3600);
                var m = Math.floor((s %% 3600) / 60);
                var sec = s %% 60;
                
                daysEl.textContent = pad(d);
                hoursEl.textContent = pad(h);
                minutesEl.textContent = pad(m);
                secondsEl.textContent = pad(sec);
            }
            
            tick();
            setInterval(tick, 1000);
        })();
        </script>";

        return $html . sprintf($script, $unique_id, $unique_id, $unique_id, $unique_id, $server_now, $ann['deadline']);
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
        
        // Zkontrolujeme, zda má obsah nějaký text (kromě prázdných tagů)
        $has_content = !empty(trim(strip_tags($ann['content'])));
        
        // Pokud má obsah, uděláme titulek proklikávací
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
        
        // Přidáme tlačítko, pokud má label i URL
        $button_html = '';
        if (!empty($ann['button_label']) && !empty($ann['button_url'])) {
            $button_html = sprintf(
                '<a href="%s" class="wpa-banner__button" target="_blank" rel="noopener">%s</a>',
                esc_url($ann['button_url']),
                esc_html($ann['button_label'])
            );
        }
        
        $html = sprintf(
            '<div class="wpa-banner" data-deadline="%s" role="region" aria-label="%s">' .
                '<div class="wpa-banner__inner">' .
                    '<div class="wpa-banner__content">' .
                        '%s' .
                        '%s' .
                    '</div>' .
                    '<div class="wpa-banner__timer">' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-days">--</div>' .
                            '<div class="wpa-banner__timer-label">DNI</div>' .
                        '</div>' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-hours">--</div>' .
                            '<div class="wpa-banner__timer-label">HODIN</div>' .
                        '</div>' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-minutes">--</div>' .
                            '<div class="wpa-banner__timer-label">MINUT</div>' .
                        '</div>' .
                        '<div class="wpa-banner__timer-item">' .
                            '<div class="wpa-banner__timer-value" id="wpa-countdown-seconds">--</div>' .
                            '<div class="wpa-banner__timer-label">SEKUND</div>' .
                        '</div>' .
                    '</div>' .
                '</div>' .
            '</div>',
            esc_attr($deadline_iso),
            esc_attr__('Oznámení', 'wp-webklient-announcements-and-timer'),
            $title_html,
            $button_html
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
