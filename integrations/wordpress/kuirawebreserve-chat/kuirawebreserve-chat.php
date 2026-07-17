<?php
/**
 * Plugin Name: KuiraWebReserve Chat
 * Description: Burbuja de chat con el asistente IA de tu hotel en KuiraWebReserve: cotiza, revisa disponibilidad y aparta habitaciones en vivo. Tambien disponible como shortcode [kuirawebreserve_chat].
 * Version: 1.0.0
 * Author: KuiraWebReserve
 * License: GPL-2.0-or-later
 * Text Domain: kuirawebreserve-chat
 */

if (! defined('ABSPATH')) {
    exit;
}

final class KuiraWebReserveChat
{
    const OPTION = 'kuira_chat_settings';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'adminMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('wp_footer', [self::class, 'renderBubble']);
        add_shortcode('kuirawebreserve_chat', [self::class, 'shortcode']);
    }

    /** @return array{url: string, color: string, position: string, bubble: string} */
    public static function settings(): array
    {
        return wp_parse_args(get_option(self::OPTION, []), [
            'url' => '',
            'color' => '#1e40af',
            'position' => 'right',
            'bubble' => '1',
        ]);
    }

    public static function adminMenu(): void
    {
        add_options_page(
            'KuiraWebReserve Chat',
            'KuiraWebReserve Chat',
            'manage_options',
            'kuira-chat',
            [self::class, 'settingsPage'],
        );
    }

    public static function registerSettings(): void
    {
        register_setting('kuira_chat', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => function ($input) {
                return [
                    'url' => esc_url_raw($input['url'] ?? ''),
                    'color' => sanitize_hex_color($input['color'] ?? '#1e40af') ?: '#1e40af',
                    'position' => in_array($input['position'] ?? '', ['left', 'right'], true) ? $input['position'] : 'right',
                    'bubble' => empty($input['bubble']) ? '0' : '1',
                ];
            },
        ]);
    }

    public static function settingsPage(): void
    {
        $s = self::settings(); ?>
        <div class="wrap">
            <h1>KuiraWebReserve Chat</h1>
            <p>Conecta el asistente IA de tu hotel: pega la direccion de tu webchat
               (en tu panel de KuiraWebReserve: Bandeja &rarr; "Ver webchat").</p>
            <form method="post" action="options.php">
                <?php settings_fields('kuira_chat'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="kuira-url">URL del webchat</label></th>
                        <td>
                            <input id="kuira-url" type="url" class="regular-text" required
                                   name="<?php echo esc_attr(self::OPTION); ?>[url]"
                                   value="<?php echo esc_attr($s['url']); ?>"
                                   placeholder="https://mihotel.kuirawebreserve.com/chat" />
                            <p class="description">La pagina publica /chat de tu hotel en KuiraWebReserve.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kuira-color">Color del boton</label></th>
                        <td><input id="kuira-color" type="color"
                                   name="<?php echo esc_attr(self::OPTION); ?>[color]"
                                   value="<?php echo esc_attr($s['color']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Posicion</th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[position]"
                                          value="right" <?php checked($s['position'], 'right'); ?> /> Derecha</label>
                            &nbsp;&nbsp;
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[position]"
                                          value="left" <?php checked($s['position'], 'left'); ?> /> Izquierda</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Burbuja flotante</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[bubble]"
                                          value="1" <?php checked($s['bubble'], '1'); ?> />
                                Mostrar en todo el sitio (tambien puedes usar el shortcode
                                <code>[kuirawebreserve_chat]</code> en una pagina)</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar'); ?>
            </form>
        </div>
    <?php }

    /** Shortcode: chat embebido en linea dentro de una pagina/entrada. */
    public static function shortcode($atts = []): string
    {
        $s = self::settings();
        $atts = shortcode_atts(['height' => '620'], $atts);

        if (! $s['url']) {
            return current_user_can('manage_options')
                ? '<p><em>KuiraWebReserve Chat: configura la URL del webchat en Ajustes.</em></p>'
                : '';
        }

        return sprintf(
            '<iframe src="%s" title="Chat del hotel" style="width:100%%;max-width:520px;height:%dpx;border:1px solid #e2e8f0;border-radius:16px;" loading="lazy"></iframe>',
            esc_url($s['url']),
            max(360, (int) $atts['height']),
        );
    }

    /** Burbuja flotante + panel con el webchat, sin dependencias. */
    public static function renderBubble(): void
    {
        $s = self::settings();

        if (! $s['url'] || $s['bubble'] !== '1' || is_admin()) {
            return;
        }

        $side = $s['position'] === 'left' ? 'left' : 'right';
        $color = esc_attr($s['color']);
        $url = esc_url($s['url']); ?>
        <div id="kuira-chat-root" style="position:fixed;bottom:24px;<?php echo esc_attr($side); ?>:24px;z-index:99999;font-family:inherit;">
            <div id="kuira-chat-panel" style="display:none;width:380px;max-width:calc(100vw - 48px);height:600px;max-height:calc(100vh - 120px);margin-bottom:12px;border-radius:16px;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,.28);background:#fff;">
                <iframe src="<?php echo $url; ?>" title="Chat del hotel" style="width:100%;height:100%;border:0;" loading="lazy"></iframe>
            </div>
            <button id="kuira-chat-toggle" type="button" aria-label="Abrir chat del hotel"
                    style="display:flex;align-items:center;justify-content:center;width:60px;height:60px;margin-<?php echo $side === 'right' ? 'left' : 'right'; ?>:auto;border:0;border-radius:50%;cursor:pointer;background:<?php echo $color; ?>;box-shadow:0 10px 30px rgba(15,23,42,.3);">
                <svg id="kuira-icon-open" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <svg id="kuira-icon-close" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <script>
        (function () {
            var toggle = document.getElementById('kuira-chat-toggle');
            var panel = document.getElementById('kuira-chat-panel');
            var open = document.getElementById('kuira-icon-open');
            var close = document.getElementById('kuira-icon-close');
            toggle.addEventListener('click', function () {
                var visible = panel.style.display !== 'none';
                panel.style.display = visible ? 'none' : 'block';
                open.style.display = visible ? '' : 'none';
                close.style.display = visible ? 'none' : '';
            });
        })();
        </script>
    <?php }
}

KuiraWebReserveChat::boot();
