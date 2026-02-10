<?php
/**
 * Plugin Name: MPS Social Autoposter (MVP)
 * Description: Мінімальний плагін з адмінкою: токени/поля/селекти для автопостингу (Meta, LinkedIn, Telegram, Viber).
 * Version: 0.1.0
 * Author: MPS
 */

if (!defined('ABSPATH')) exit;

final class MPS_Social_Autoposter_MVP {

    const OPTION_KEY = 'mps_autoposter_options';
    const CAPABILITY = 'manage_options';
    const MENU_SLUG  = 'mps-autoposter';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('wp_ajax_nopriv_mps_tg_webhook', [__CLASS__, 'tg_webhook_ajax']);
        add_action('wp_ajax_mps_tg_webhook', [__CLASS__, 'tg_webhook_ajax']);



    }
    public static function tg_webhook_ajax(): void {
        $raw = file_get_contents('php://input');
        error_log('TG ajax raw len=' . strlen($raw));
        error_log('TG ajax raw: ' . $raw);

        $opt = get_option(self::OPTION_KEY, []);
        $token = $opt['tg_bot_token'] ?? '';

        if (!$token) {
            wp_send_json(['ok'=>false,'err'=>'no_token'], 200);
        }

        $update = json_decode($raw, true);

        $message = $update['message']
                ?? $update['edited_message']
                ?? $update['channel_post']
                ?? $update['edited_channel_post']
                ?? null;

        if ($message) {
            $chat_id = $message['chat']['id'] ?? null;
            $text = trim((string)($message['text'] ?? ''));

            if ($chat_id && ($text === '/start' || $text === '🏠 Головне меню')) {
                self::tg_send_menu($token, $chat_id);
            } elseif ($chat_id && $text === '📰 Новини') {
                self::tg_send_latest_news($token, $chat_id, 5);
            } elseif ($chat_id) {
                self::tg_send_text($token, $chat_id, "Натисніть 📰 Новини");
            }
        }

        wp_send_json(['ok'=>true], 200);
    }

    public static function register_routes(): void {

        register_rest_route('mps/v1', '/news-latest', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'news_latest_endpoint'],
                'permission_callback' => '__return_true',
        ]);

        register_rest_route('mps-tg/v1', '/webhook', [
                'methods'  => ['GET','POST'],
                'callback' => [__CLASS__, 'tg_webhook_handler'],
                'permission_callback' => '__return_true',
        ]);
    }

    public static function admin_menu(): void {
        add_menu_page(
            'Autoposter',
            'Autoposter',
            self::CAPABILITY,
            self::MENU_SLUG,
            [__CLASS__, 'render_settings_page'],
            'dashicons-share',
            80
        );
    }

    public static function register_settings(): void {
        register_setting(self::MENU_SLUG, self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
            'default'           => [],
        ]);
    }

    public static function sanitize_options($options): array {
        $options = is_array($options) ? $options : [];

        // 👇 беремо те, що вже було збережено
        $existing = get_option(self::OPTION_KEY, []);
        $existing = is_array($existing) ? $existing : [];

        // 👇 стартуємо з існуючих, а не з пустих
        $clean = $existing;

        // Тепер оновлюємо ТІЛЬКИ те, що прийшло у POST:
        $clean['enabled'] = isset($options['enabled']) ? 1 : 0;
        if (isset($options['only_post_type'])) $clean['only_post_type'] = sanitize_key($options['only_post_type']);

        $clean['tg_enabled'] = isset($options['tg_enabled']) ? 1 : 0;
        if (isset($options['tg_bot_token'])) $clean['tg_bot_token'] = sanitize_text_field($options['tg_bot_token']);
        if (isset($options['tg_chat_id'])) $clean['tg_chat_id'] = sanitize_text_field($options['tg_chat_id']);
        if (isset($options['tg_mode'])) $clean['tg_mode'] = in_array($options['tg_mode'], ['photo','text'], true) ? $options['tg_mode'] : 'photo';

        $clean['viber_enabled'] = isset($options['viber_enabled']) ? 1 : 0;
        if (isset($options['viber_auth_token'])) $clean['viber_auth_token'] = sanitize_text_field($options['viber_auth_token']);
        if (isset($options['viber_sender_name'])) $clean['viber_sender_name'] = sanitize_text_field($options['viber_sender_name']);
        if (isset($options['viber_mode'])) $clean['viber_mode'] = in_array($options['viber_mode'], ['text','photo'], true) ? $options['viber_mode'] : 'text';

        $clean['meta_enabled'] = isset($options['meta_enabled']) ? 1 : 0;
        if (isset($options['meta_app_id'])) $clean['meta_app_id'] = sanitize_text_field($options['meta_app_id']);
        if (isset($options['meta_app_secret'])) $clean['meta_app_secret'] = sanitize_text_field($options['meta_app_secret']);
        if (isset($options['meta_page_access_token'])) $clean['meta_page_access_token'] = sanitize_text_field($options['meta_page_access_token']);
        if (isset($options['meta_selected_fb_page_id'])) $clean['meta_selected_fb_page_id'] = sanitize_text_field($options['meta_selected_fb_page_id']);
        if (isset($options['meta_selected_ig_user_id'])) $clean['meta_selected_ig_user_id'] = sanitize_text_field($options['meta_selected_ig_user_id']);
        if (isset($options['meta_pages_json'])) $clean['meta_pages_json'] = wp_kses_post($options['meta_pages_json']);
        if (isset($options['meta_ig_users_json'])) $clean['meta_ig_users_json'] = wp_kses_post($options['meta_ig_users_json']);

        $clean['li_enabled'] = isset($options['li_enabled']) ? 1 : 0;
        if (isset($options['li_client_id'])) $clean['li_client_id'] = sanitize_text_field($options['li_client_id']);
        if (isset($options['li_client_secret'])) $clean['li_client_secret'] = sanitize_text_field($options['li_client_secret']);
        if (isset($options['li_selected_org_urn'])) $clean['li_selected_org_urn'] = sanitize_text_field($options['li_selected_org_urn']);
        if (isset($options['li_orgs_json'])) $clean['li_orgs_json'] = wp_kses_post($options['li_orgs_json']);

        return $clean;
    }


    private static function get_options(): array {
        $opt = get_option(self::OPTION_KEY, []);
        return is_array($opt) ? $opt : [];
    }

    private static function parse_json_list(string $json, string $id_key, string $label_key): array {
        // expects JSON array of objects: [{id:"", name:""}]
        $json = trim($json);
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;
            $id = isset($row[$id_key]) ? (string)$row[$id_key] : '';
            $label = isset($row[$label_key]) ? (string)$row[$label_key] : $id;
            if ($id !== '') $out[$id] = $label;
        }
        return $out;
    }

    public static function render_settings_page(): void {
        if (!current_user_can(self::CAPABILITY)) return;

        $opt = self::get_options();
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        $tabs = [
            'general' => 'Загальні',
            'telegram' => 'Telegram',
            'viber' => 'Viber',
            'meta' => 'Meta (FB/IG)',
            'linkedin' => 'LinkedIn',
        ];

        // Lists for selects (will be filled after OAuth; now can be manually pasted as JSON)
        $meta_pages = self::parse_json_list((string)($opt['meta_pages_json'] ?? ''), 'id', 'name');
        $meta_ig_users = self::parse_json_list((string)($opt['meta_ig_users_json'] ?? ''), 'id', 'username');
        $li_orgs = self::parse_json_list((string)($opt['li_orgs_json'] ?? ''), 'urn', 'name');

        ?>
        <div class="wrap">
            <h1>Autoposter — Налаштування</h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label): ?>
                    <?php $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=' . $key); ?>
                    <a class="nav-tab <?php echo ($tab === $key) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::MENU_SLUG);
                $name = self::OPTION_KEY;
                ?>

                <?php if ($tab === 'general'): ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Увімкнути автопостинг</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked(!empty($opt['enabled'])); ?> />
                                    Увімкнено
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Тільки для post_type</th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[only_post_type]"
                                       value="<?php echo esc_attr($opt['only_post_type'] ?? 'news'); ?>" />
                                <p class="description">Напр. <code>news</code>. Плагін буде орієнтуватися тільки на цей CPT.</p>
                            </td>
                        </tr>
                    </table>

                <?php elseif ($tab === 'telegram'): ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Telegram</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[tg_enabled]" value="1" <?php checked(!empty($opt['tg_enabled'])); ?> />
                                    Увімкнути Telegram
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Bot Token</th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[tg_bot_token]" value="<?php echo esc_attr($opt['tg_bot_token'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Channel chat_id або @username</th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[tg_chat_id]" value="<?php echo esc_attr($opt['tg_chat_id'] ?? ''); ?>" />
                                <p class="description">Напр.: <code>@mps_news</code> або <code>-1001234567890</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Режим публікації</th>
                            <td>
                                <select name="<?php echo esc_attr($name); ?>[tg_mode]">
                                    <option value="photo" <?php selected(($opt['tg_mode'] ?? 'photo') === 'photo'); ?>>Фото + текст</option>
                                    <option value="text" <?php selected(($opt['tg_mode'] ?? 'photo') === 'text'); ?>>Тільки текст</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                <?php elseif ($tab === 'viber'): ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Viber</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[viber_enabled]" value="1" <?php checked(!empty($opt['viber_enabled'])); ?> />
                                    Увімкнути Viber
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auth Token (X-Viber-Auth-Token)</th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[viber_auth_token]" value="<?php echo esc_attr($opt['viber_auth_token'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Sender name</th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[viber_sender_name]" value="<?php echo esc_attr($opt['viber_sender_name'] ?? ''); ?>" />
                                <p class="description">Опціонально: назва бота/відправника.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Режим публікації</th>
                            <td>
                                <select name="<?php echo esc_attr($name); ?>[viber_mode]">
                                    <option value="text" <?php selected(($opt['viber_mode'] ?? 'text') === 'text'); ?>>Текст</option>
                                    <option value="photo" <?php selected(($opt['viber_mode'] ?? 'text') === 'photo'); ?>>Фото + текст</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                <?php elseif ($tab === 'meta'): ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Meta (Facebook/Instagram)</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[meta_enabled]" value="1" <?php checked(!empty($opt['meta_enabled'])); ?> />
                                    Увімкнути Meta
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">App ID</th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[meta_app_id]" value="<?php echo esc_attr($opt['meta_app_id'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">App Secret</th>
                            <td><input type="password" class="regular-text" name="<?php echo esc_attr($name); ?>[meta_app_secret]" value="<?php echo esc_attr($opt['meta_app_secret'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Page Access Token</th>
                            <td>
                                <input type="password" class="regular-text" name="<?php echo esc_attr($name); ?>[meta_page_access_token]" value="<?php echo esc_attr($opt['meta_page_access_token'] ?? ''); ?>" />
                                <p class="description">Токен сторінки Facebook (pages_manage_posts + pages_read_engagement).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">OAuth підключення</th>
                            <td>
                                <p class="description">
                                    У MVP це заглушка. У повній версії тут буде кнопка “Connect Meta”, яка запускає OAuth, після чого плагін отримає токени та список сторінок/акаунтів.
                                </p>
                                <button type="button" class="button" disabled>Connect Meta (OAuth)</button>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Список Facebook Pages (JSON)</th>
                            <td>
                                <textarea class="large-text code" rows="6" name="<?php echo esc_attr($name); ?>[meta_pages_json]"><?php echo esc_textarea($opt['meta_pages_json'] ?? ''); ?></textarea>
                                <p class="description">Тимчасово вручну. Формат: <code>[{"id":"123","name":"MPS Page","access_token":"EAAB..."}]</code></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Куди постити (Facebook Page)</th>
                            <td>
                                <select name="<?php echo esc_attr($name); ?>[meta_selected_fb_page_id]">
                                    <option value="">— не вибрано —</option>
                                    <?php foreach ($meta_pages as $id => $label): ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected(($opt['meta_selected_fb_page_id'] ?? '') === $id); ?>>
                                            <?php echo esc_html($label . ' (' . $id . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php
                                    $selected_page_id = (string)($opt['meta_selected_fb_page_id'] ?? '');
                                    if ($selected_page_id !== '' && !isset($meta_pages[$selected_page_id])):
                                    ?>
                                        <option value="<?php echo esc_attr($selected_page_id); ?>" selected>
                                            <?php echo esc_html('Поточне збережене значення (' . $selected_page_id . ')'); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Список Instagram акаунтів (JSON)</th>
                            <td>
                                <textarea class="large-text code" rows="6" name="<?php echo esc_attr($name); ?>[meta_ig_users_json]"><?php echo esc_textarea($opt['meta_ig_users_json'] ?? ''); ?></textarea>
                                <p class="description">Формат: <code>[{"id":"1789...","username":"mps_express"}]</code></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Куди постити (Instagram акаунт)</th>
                            <td>
                                <select name="<?php echo esc_attr($name); ?>[meta_selected_ig_user_id]">
                                    <option value="">— не вибрано —</option>
                                    <?php foreach ($meta_ig_users as $id => $label): ?>
                                        <option value="<?php echo esc_attr($id); ?>" <?php selected(($opt['meta_selected_ig_user_id'] ?? '') === $id); ?>>
                                            <?php echo esc_html($label . ' (' . $id . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                <?php elseif ($tab === 'linkedin'): ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">LinkedIn</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[li_enabled]" value="1" <?php checked(!empty($opt['li_enabled'])); ?> />
                                    Увімкнути LinkedIn
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client ID</th>
                            <td><input type="text" class="regular-text" name="<?php echo esc_attr($name); ?>[li_client_id]" value="<?php echo esc_attr($opt['li_client_id'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td><input type="password" class="regular-text" name="<?php echo esc_attr($name); ?>[li_client_secret]" value="<?php echo esc_attr($opt['li_client_secret'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row">OAuth підключення</th>
                            <td>
                                <p class="description">
                                    У MVP це заглушка. У повній версії буде “Connect LinkedIn”, OAuth і підвантаження Organization list.
                                </p>
                                <button type="button" class="button" disabled>Connect LinkedIn (OAuth)</button>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Список Organizations (JSON)</th>
                            <td>
                                <textarea class="large-text code" rows="6" name="<?php echo esc_attr($name); ?>[li_orgs_json]"><?php echo esc_textarea($opt['li_orgs_json'] ?? ''); ?></textarea>
                                <p class="description">Формат: <code>[{"urn":"urn:li:organization:123","name":"MPS Express"}]</code></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Куди постити (Organization)</th>
                            <td>
                                <select name="<?php echo esc_attr($name); ?>[li_selected_org_urn]">
                                    <option value="">— не вибрано —</option>
                                    <?php foreach ($li_orgs as $urn => $label): ?>
                                        <option value="<?php echo esc_attr($urn); ?>" <?php selected(($opt['li_selected_org_urn'] ?? '') === $urn); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php submit_button('Зберегти'); ?>
            </form>

            <hr />
            <p class="description">
                MVP лише створює адмінку, збереження полів і селекти. Наступний крок — додати OAuth-роути та автопостинг по хукy публікації CPT.
            </p>
        </div>
        <?php
    }
    public static function on_transition_post_status($new_status, $old_status, $post): void {
        if (!($post instanceof WP_Post)) return;

        // тільки коли реально стає Published
        if ($old_status === 'publish' || $new_status !== 'publish') return;

        $opt = self::get_options();

        // глобальний вимикач
        if (empty($opt['enabled'])) return;

        // тільки для потрібного post_type
        $only = $opt['only_post_type'] ?? 'news';
        if ($post->post_type !== $only) return;

        if (!empty($opt['tg_enabled'])) {
            $token  = trim((string)($opt['tg_bot_token'] ?? ''));
            $chatId = trim((string)($opt['tg_chat_id'] ?? ''));
            $mode   = (string)($opt['tg_mode'] ?? 'photo');

            if ($token !== '' && $chatId !== '') {
                self::telegram_send_post($token, $chatId, $post->ID, $mode);
            }
        }

        if (!empty($opt['meta_enabled'])) {
            self::facebook_send_post($opt, $post->ID);
        }
    }

    private static function telegram_send_post(string $token, string $chatId, int $post_id, string $mode): void {
        $title = get_the_title($post_id);
        $url   = get_permalink($post_id);

        // короткий текст
        $raw = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $raw = preg_replace('/\s+/', ' ', trim($raw));
        $excerpt = mb_substr($raw, 0, 350);
        if (mb_strlen($raw) > 350) $excerpt .= '…';

        $text = "📰 {$title}\n\n{$excerpt}\n\n{$url}";

        $thumb = get_the_post_thumbnail_url($post_id, 'large');

        if ($mode === 'photo' && $thumb) {
            $endpoint = "https://api.telegram.org/bot{$token}/sendPhoto";
            $body = [
                    'chat_id' => $chatId,
                    'photo'   => $thumb,
                    'caption' => mb_substr($text, 0, 1024), // ліміт caption
            ];
        } else {
            $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
            $body = [
                    'chat_id' => $chatId,
                    'text'    => $text,
            ];
        }

        $resp = wp_remote_post($endpoint, [
                'timeout' => 15,
                'body'    => $body,
        ]);

        // лог помилок (щоб бачити причину)
        if (is_wp_error($resp)) {
            error_log('MPS Autoposter Telegram error: ' . $resp->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body_txt = (string) wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            error_log("MPS Autoposter Telegram HTTP {$code}: {$body_txt}");
        }
    }


    private static function facebook_send_post(array $opt, int $post_id): void {
        $page_id = trim((string)($opt['meta_selected_fb_page_id'] ?? ''));
        if ($page_id === '') {
            error_log('MPS Autoposter Facebook: page id is empty');
            return;
        }

        $token = trim((string)($opt['meta_page_access_token'] ?? ''));
        if ($token === '') {
            $token = self::find_fb_page_token_from_json((string)($opt['meta_pages_json'] ?? ''), $page_id);
        }

        if ($token === '') {
            error_log('MPS Autoposter Facebook: page access token is empty');
            return;
        }

        $title = get_the_title($post_id);
        $url = get_permalink($post_id);

        $raw = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $raw = preg_replace('/\s+/', ' ', trim($raw));
        $excerpt = mb_substr($raw, 0, 350);
        if (mb_strlen($raw) > 350) {
            $excerpt .= '…';
        }

        $message = "📰 {$title}

{$excerpt}

{$url}";
        $endpoint = "https://graph.facebook.com/v22.0/{$page_id}/feed";

        $resp = wp_remote_post($endpoint, [
            'timeout' => 20,
            'body' => [
                'message' => $message,
                'access_token' => $token,
            ],
        ]);

        if (is_wp_error($resp)) {
            error_log('MPS Autoposter Facebook WP_Error: ' . $resp->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body_txt = (string) wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            error_log("MPS Autoposter Facebook HTTP {$code}: {$body_txt}");
        }
    }

    private static function find_fb_page_token_from_json(string $json, string $page_id): string {
        $json = trim($json);
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) ? (string)$row['id'] : '';
            if ($id !== $page_id) {
                continue;
            }

            return isset($row['access_token']) ? trim((string)$row['access_token']) : '';
        }

        return '';
    }

    public static function news_latest_endpoint(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > 20) $limit = 5;

        $q = new WP_Query([
                'post_type'      => 'news',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
        ]);

        $items = [];

        foreach ($q->posts as $p) {
            $items[] = [
                    'id'    => $p->ID,
                    'title' => get_the_title($p->ID),
                    'url'   => get_permalink($p->ID),
                    'date'  => get_the_date('d.m.Y, H:i', $p->ID),
                    'img'   => get_the_post_thumbnail_url($p->ID, 'medium') ?: null,
            ];
        }

        return new WP_REST_Response([
                'ok'    => true,
                'items' => $items,
        ], 200);
    }


    public static function tg_webhook_handler(WP_REST_Request $req): WP_REST_Response {
        $raw = $req->get_body();
        error_log('TG raw len=' . strlen($raw));
        error_log('TG raw: ' . $raw);

        $opt = get_option(self::OPTION_KEY, []);
        $token = $opt['tg_bot_token'] ?? '';

        if (!$token) {
            error_log('TG: no token in options');
            return new WP_REST_Response(['ok'=>false,'err'=>'no_token'], 200);
        }

        $update = json_decode($raw, true);
        error_log('TG update: ' . print_r($update, true));

        $message = $update['message'] ?? null;
        if (!$message) return new WP_REST_Response(['ok'=>true], 200);

        $chat_id = $message['chat']['id'] ?? null;
        $text    = trim((string)($message['text'] ?? ''));

        if (!$chat_id) return new WP_REST_Response(['ok'=>true], 200);

        if ($text === '/start' || $text === '🏠 Головне меню') {
            self::tg_send_menu($token, $chat_id);
            return new WP_REST_Response(['ok'=>true], 200);
        }

        if ($text === '📰 Новини') {
            self::tg_send_latest_news($token, $chat_id, 5);
            return new WP_REST_Response(['ok'=>true], 200);
        }

        self::tg_send_text($token, $chat_id, "Натисніть 📰 Новини");
        return new WP_REST_Response(['ok'=>true], 200);
    }



    public static function tg_send_menu(string $token, $chat_id): void {
        $keyboard = [
                'keyboard' => [
                        [['📰 Новини']],
                        [['🏠 Головне меню']],
                ],
                'resize_keyboard' => true,
        ];

        self::tg_send_message_raw($token, [
                'chat_id' => $chat_id,
                'text' => 'Меню:',
                'reply_markup' => wp_json_encode($keyboard),
        ]);
    }

    public static function tg_send_latest_news(string $token, $chat_id, int $limit = 5): void {
        $q = new WP_Query([
                'post_type'      => 'news',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
        ]);

        if (!$q->have_posts()) {
            self::tg_send_text($token, $chat_id, "Поки немає новин.");
            return;
        }

        $buttons = [];
        foreach ($q->posts as $p) {
            $title = get_the_title($p->ID);
            if (mb_strlen($title) > 60) $title = mb_substr($title, 0, 57) . '…';

            $buttons[] = [[
                    'text' => $title,
                    'url'  => get_permalink($p->ID),
            ]];
        }

        $markup = ['inline_keyboard' => $buttons];

        wp_remote_post("https://api.telegram.org/bot{$token}/sendMessage", [
                'timeout' => 15,
                'body' => [
                        'chat_id' => $chat_id,
                        'text' => "Останні новини:",
                        'reply_markup' => wp_json_encode($markup),
                ],
        ]);
    }

    public static function tg_send_text(string $token, $chat_id, string $text): void {
        self::tg_send_message_raw($token, [
                'chat_id' => $chat_id,
                'text' => $text,
        ]);
    }

    public static function tg_send_message_raw(string $token, array $body): void {
        $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";

        $resp = wp_remote_post($endpoint, [
                'timeout' => 15,
                'body'    => $body,
        ]);

        if (is_wp_error($resp)) {
            error_log('TG send WP_Error: ' . $resp->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $txt  = (string) wp_remote_retrieve_body($resp);

        error_log("TG send HTTP {$code}: {$txt}");
    }


}

MPS_Social_Autoposter_MVP::init();
