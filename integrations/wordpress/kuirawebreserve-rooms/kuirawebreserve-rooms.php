<?php
/**
 * Plugin Name: KuiraWebReserve Habitaciones
 * Description: Muestra en tu sitio los tipos de habitacion de tu hotel con precio y amenidades EN VIVO desde KuiraWebReserve (nunca copia el precio: lo consulta cada vez, con cache de minutos). Shortcode [kuirawebreserve_rooms].
 * Version: 1.0.0
 * Author: KuiraWebReserve
 * License: GPL-2.0-or-later
 * Text Domain: kuirawebreserve-rooms
 */
if (! defined('ABSPATH')) {
    exit;
}

final class KuiraWebReserveRooms
{
    const OPTION = 'kuira_rooms_settings';

    const CACHE_KEY = 'kuira_rooms_catalog';

    const CACHE_MINUTES = 5;

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'adminMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_kuira_rooms_clear_cache', [self::class, 'clearCacheAction']);
        add_shortcode('kuirawebreserve_rooms', [self::class, 'shortcode']);
    }

    /** @return array{domain: string, token: string, reserve_url: string, columns: string} */
    public static function settings(): array
    {
        return wp_parse_args(get_option(self::OPTION, []), [
            'domain' => '',
            'token' => '',
            'reserve_url' => '',
            'columns' => '3',
        ]);
    }

    public static function adminMenu(): void
    {
        add_options_page(
            'KuiraWebReserve Habitaciones',
            'KuiraWebReserve Habitaciones',
            'manage_options',
            'kuira-rooms',
            [self::class, 'settingsPage'],
        );
    }

    public static function registerSettings(): void
    {
        register_setting('kuira_rooms', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => function ($input) {
                $current = self::settings();
                // El token solo se pisa si se escribio uno nuevo: evita
                // borrarlo por accidente al guardar otros campos.
                $token = trim($input['token'] ?? '');

                return [
                    'domain' => sanitize_text_field(preg_replace('#^https?://#', '', trim($input['domain'] ?? ''))),
                    'token' => $token !== '' ? $token : $current['token'],
                    'reserve_url' => esc_url_raw($input['reserve_url'] ?? ''),
                    'columns' => in_array($input['columns'] ?? '', ['2', '3', '4'], true) ? $input['columns'] : '3',
                ];
            },
        ]);
    }

    protected static function catalogUrl(): string
    {
        $domain = self::settings()['domain'];

        return $domain ? "https://{$domain}/api/site/catalog" : '';
    }

    public static function settingsPage(): void
    {
        $s = self::settings();
        $hasToken = $s['token'] !== ''; ?>
        <div class="wrap">
            <h1>KuiraWebReserve Habitaciones</h1>
            <p>Conecta el catalogo de tu hotel: los precios y amenidades se leen EN VIVO de tu sistema
               (cache de <?php echo (int) self::CACHE_MINUTES; ?> minutos). Genera el token en tu panel:
               <strong>Integracion &rarr; Conectar sitio</strong>.</p>

            <?php if (isset($_GET['kuira_cache_cleared'])) { ?>
                <div class="notice notice-success"><p>Cache del catalogo vaciada.</p></div>
            <?php } ?>

            <form method="post" action="options.php">
                <?php settings_fields('kuira_rooms'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="kuira-domain">Dominio de tu hotel</label></th>
                        <td>
                            <input id="kuira-domain" type="text" class="regular-text" required
                                   name="<?php echo esc_attr(self::OPTION); ?>[domain]"
                                   value="<?php echo esc_attr($s['domain']); ?>"
                                   placeholder="mihotel.kuirawebreserve.com" />
                            <p class="description">Sin "https://". Es el subdominio de tu panel de KuiraWebReserve.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kuira-token">Token de integracion</label></th>
                        <td>
                            <input id="kuira-token" type="password" class="regular-text" autocomplete="off"
                                   name="<?php echo esc_attr(self::OPTION); ?>[token]"
                                   placeholder="<?php echo $hasToken ? 'Ya guardado - deja en blanco para no cambiarlo' : 'ksk_...'; ?>" />
                            <p class="description">
                                Se guarda una sola vez cuando lo generas en el panel. Este campo no lo vuelve a mostrar
                                por seguridad; si lo perdiste, genera uno nuevo en <strong>Integracion</strong>.
                                <?php echo $hasToken ? '<strong style="color:#0a7c2f">Token configurado.</strong>' : '<strong style="color:#a94442">Falta el token.</strong>'; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kuira-reserve">URL para "Reservar"</label></th>
                        <td>
                            <input id="kuira-reserve" type="url" class="regular-text"
                                   name="<?php echo esc_attr(self::OPTION); ?>[reserve_url]"
                                   value="<?php echo esc_attr($s['reserve_url']); ?>"
                                   placeholder="https://wa.me/52.../?text=Quiero reservar" />
                            <p class="description">
                                A donde manda el boton de cada habitacion (WhatsApp, tel:, o la pagina de reservas
                                cuando este lista). Vacio = el boton no se muestra.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Columnas</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION); ?>[columns]">
                                <?php foreach (['2', '3', '4'] as $n) { ?>
                                    <option value="<?php echo esc_attr($n); ?>" <?php selected($s['columns'], $n); ?>><?php echo esc_html($n); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kuira_rooms_clear_cache" />
                <?php wp_nonce_field('kuira_rooms_clear_cache'); ?>
                <?php submit_button('Vaciar cache ahora', 'secondary'); ?>
            </form>

            <h2>Como usarlo</h2>
            <p>Pega <code>[kuirawebreserve_rooms]</code> en cualquier pagina o entrada. Opcional:
               <code>[kuirawebreserve_rooms columns="2"]</code>.</p>
        </div>
    <?php }

    public static function clearCacheAction(): void
    {
        check_admin_referer('kuira_rooms_clear_cache');

        if (! current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }

        delete_transient(self::CACHE_KEY);

        wp_safe_redirect(add_query_arg('kuira_cache_cleared', '1', admin_url('options-general.php?page=kuira-rooms')));
        exit;
    }

    /**
     * Consulta el catalogo DESDE EL SERVIDOR (nunca en el navegador del
     * visitante) con cache corta. Null = error o sin configurar.
     */
    protected static function fetchCatalog(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $s = self::settings();
        $url = self::catalogUrl();

        if (! $url || ! $s['token']) {
            return null;
        }

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer '.$s['token']],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($data) || ! isset($data['room_types'])) {
            return null;
        }

        set_transient(self::CACHE_KEY, $data, self::CACHE_MINUTES * MINUTE_IN_SECONDS);

        return $data;
    }

    public static function shortcode($atts = []): string
    {
        $s = self::settings();
        $atts = shortcode_atts(['columns' => $s['columns']], $atts);
        $columns = in_array((string) $atts['columns'], ['2', '3', '4'], true) ? (int) $atts['columns'] : 3;

        $data = self::fetchCatalog();

        if ($data === null) {
            return current_user_can('manage_options')
                ? '<p><em>KuiraWebReserve Habitaciones: configura el dominio y el token en Ajustes &rarr; KuiraWebReserve Habitaciones.</em></p>'
                : '';
        }

        $rooms = array_filter($data['room_types'] ?? [], fn ($r) => ! empty($r['reservable']));

        if (empty($rooms)) {
            return current_user_can('manage_options')
                ? '<p><em>KuiraWebReserve Habitaciones: aun no hay tipos con tarifa activa (revisa Zonas y tipos en tu panel).</em></p>'
                : '';
        }

        $currency = esc_html($data['property']['currency'] ?? 'MXN');
        $reserveUrl = $s['reserve_url'];

        ob_start(); ?>
        <div class="kuira-rooms-grid" style="display:grid;gap:20px;grid-template-columns:repeat(<?php echo (int) $columns; ?>,minmax(0,1fr));">
            <?php foreach ($rooms as $room) { ?>
                <div class="kuira-room-card" style="border:1px solid #e2e8f0;border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:10px;">
                    <h3 style="margin:0;font-size:18px;"><?php echo esc_html($room['name']); ?></h3>
                    <?php if (! empty($room['description'])) { ?>
                        <p style="margin:0;color:#475569;font-size:14px;line-height:1.5;"><?php echo esc_html($room['description']); ?></p>
                    <?php } ?>
                    <?php if (! empty($room['capacity'])) { ?>
                        <p style="margin:0;color:#64748b;font-size:13px;">Hasta <?php echo (int) $room['capacity']; ?> personas</p>
                    <?php } ?>
                    <?php if (! empty($room['amenities'])) { ?>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                            <?php foreach (array_slice($room['amenities'], 0, 8) as $amenity) { ?>
                                <span style="background:#f1f5f9;color:#475569;border-radius:999px;padding:3px 10px;font-size:12px;"><?php echo esc_html($amenity); ?></span>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <div style="margin-top:auto;padding-top:10px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <?php if (! empty($room['price_from'])) { ?>
                            <strong style="font-size:16px;">Desde $<?php echo esc_html(number_format((float) $room['price_from'], 0)); ?> <?php echo $currency; ?></strong>
                        <?php } ?>
                        <?php if ($reserveUrl) { ?>
                            <a href="<?php echo esc_url($reserveUrl); ?>" style="background:#1e40af;color:#fff;border-radius:8px;padding:8px 16px;text-decoration:none;font-size:14px;white-space:nowrap;">Reservar</a>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

KuiraWebReserveRooms::boot();
