<?php
/**
 * Plugin Name: KuiraWebReserve Habitaciones
 * Description: Muestra en tu sitio los tipos de habitacion de tu hotel con foto, precio y amenidades EN VIVO desde KuiraWebReserve (nunca copia el precio: lo consulta cada vez, con cache de minutos). Shortcodes [kuirawebreserve_rooms], [kuira_reservas], [kuira_experiencias] y [kuira_grupos].
 * Version: 1.2.0
 * Author: KuiraWebReserve
 * License: GPL-2.0-or-later
 * Text Domain: kuirawebreserve-rooms
 */
if (! defined('ABSPATH')) {
    exit;
}

final class KuiraWebReserveRooms
{
    const VERSION = '1.2.0';

    const OPTION = 'kuira_rooms_settings';

    const LAST_OK = 'kuira_rooms_last_ok';

    const LAST_ERROR = 'kuira_rooms_last_error';

    const CACHE_KEY = 'kuira_rooms_catalog';

    // Respaldo largo: si la API falla, el publico sigue viendo las ultimas
    // tarjetas buenas en vez de una pagina vacia (spec-plugin-wp par. 3).
    const BACKUP_KEY = 'kuira_rooms_catalog_backup';

    const CACHE_MINUTES = 5;

    const BACKUP_HOURS = 12;

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'adminMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_kuira_rooms_clear_cache', [self::class, 'clearCacheAction']);
        add_action('admin_post_kuira_rooms_test', [self::class, 'testConnectionAction']);
        // Cambiar dominio o token invalida el cache al instante: sin esto el
        // admin "probaba" contra datos viejos y creia que no habia servido.
        add_action('update_option_'.self::OPTION, [self::class, 'clearCache'], 10, 0);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
        add_action('admin_enqueue_scripts', [self::class, 'adminAssets']);
        add_shortcode('kuirawebreserve_rooms', [self::class, 'shortcode']);
        // Shortcodes de widgets: el wizard completo como iframe (los mismos
        // que anuncia la pagina Integracion del panel).
        add_shortcode('kuira_reservas', fn () => self::widgetShortcode('reservas'));
        add_shortcode('kuira_experiencias', fn () => self::widgetShortcode('experiencias'));
        add_shortcode('kuira_grupos', fn () => self::widgetShortcode('grupos'));
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

    /**
     * Acepta lo que el hotelero pegue (con https://, con /reservar, con
     * espacios) y se queda SOLO con el host. Un campo que guardaba rutas en
     * silencio fue la causa del outage del 2026-07-20 (spec-plugin-wp par. 1).
     */
    public static function sanitizeDomain(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $withScheme = preg_match('#^https?://#i', $raw) ? $raw : 'https://'.$raw;
        $host = wp_parse_url($withScheme, PHP_URL_HOST);

        return strtolower((string) $host);
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
                    'domain' => self::sanitizeDomain((string) ($input['domain'] ?? '')),
                    'token' => $token !== '' ? $token : $current['token'],
                    'reserve_url' => esc_url_raw($input['reserve_url'] ?? ''),
                    'columns' => in_array($input['columns'] ?? '', ['2', '3', '4'], true) ? $input['columns'] : '3',
                ];
            },
        ]);
    }

    public static function registerAssets(): void
    {
        wp_register_style(
            'kuira-rooms',
            plugins_url('assets/rooms.css', __FILE__),
            [],
            self::VERSION,
        );
    }

    /** Estilos SOLO en la pantalla de ajustes del plugin. */
    public static function adminAssets(string $hook): void
    {
        if ($hook === 'settings_page_kuira-rooms') {
            wp_enqueue_style(
                'kuira-rooms-admin',
                plugins_url('assets/admin.css', __FILE__),
                [],
                self::VERSION,
            );
        }
    }

    protected static function catalogUrl(): string
    {
        $domain = self::settings()['domain'];

        return $domain ? "https://{$domain}/api/site/catalog" : '';
    }

    public static function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
        delete_transient(self::BACKUP_KEY);
    }

    // ---- Ajustes ----

    public static function settingsPage(): void
    {
        $s = self::settings();
        $hasToken = $s['token'] !== '';
        $lastOk = get_option(self::LAST_OK, null);
        $lastError = get_option(self::LAST_ERROR, null);
        $tested = sanitize_text_field($_GET['kuira_test'] ?? '');

        // Estado visual: manda el evento mas reciente (un exito de hace una
        // semana no debe pintar verde si acaba de fallar, ni al reves).
        $okAt = is_array($lastOk) ? (int) ($lastOk['at'] ?? 0) : 0;
        $errAt = is_array($lastError) ? (int) ($lastError['at'] ?? 0) : 0;
        $state = $errAt > $okAt ? 'error' : ($okAt > 0 ? 'ok' : 'none'); ?>
        <div class="wrap kuira-admin">
            <h1>KuiraWebReserve Habitaciones</h1>

            <?php if (isset($_GET['kuira_cache_cleared'])) { ?>
                <div class="notice notice-success"><p>Cache del catalogo vaciada.</p></div>
            <?php } ?>
            <?php if ($tested === 'ok' && is_array($lastOk)) { ?>
                <div class="notice notice-success"><p>
                    <strong>Conexion correcta.</strong>
                    Hotel: <?php echo esc_html($lastOk['hotel'] ?? ''); ?> &mdash;
                    <?php echo (int) ($lastOk['types'] ?? 0); ?> tipo(s) de habitacion,
                    <?php echo (int) ($lastOk['reservable'] ?? 0); ?> con tarifa activa.
                </p></div>
            <?php } elseif ($tested === 'fail' && is_array($lastError)) { ?>
                <div class="notice notice-error"><p>
                    <strong>La conexion fallo:</strong> <?php echo esc_html(self::errorHint($lastError)); ?>
                </p></div>
            <?php } ?>

            <p class="kuira-intro">Conecta el catalogo de tu hotel: fotos, precios y amenidades se leen
               EN VIVO de tu sistema (cache de <?php echo (int) self::CACHE_MINUTES; ?> minutos). El token
               se genera en tu panel: <strong>Integracion &rarr; Conectar sitio</strong>.</p>

            <div class="kuira-grid">
                <form method="post" action="options.php" class="kuira-col">
                    <?php settings_fields('kuira_rooms'); ?>

                    <div class="kuira-card">
                        <h2><span class="dashicons dashicons-admin-links"></span> Conexion con tu panel</h2>
                        <div class="kuira-field">
                            <label for="kuira-domain">Dominio de tu hotel</label>
                            <input id="kuira-domain" type="text" required
                                   name="<?php echo esc_attr(self::OPTION); ?>[domain]"
                                   value="<?php echo esc_attr($s['domain']); ?>"
                                   placeholder="mihotel.kuirawebreserve.com" />
                            <p class="description">El subdominio de tu panel, sin "https://" y sin rutas
                               (nada de /reservar al final; si lo pegas, se limpia solo).</p>
                        </div>
                        <div class="kuira-field">
                            <label for="kuira-token">Token de integracion
                                <?php echo $hasToken
                                    ? '<span class="kuira-tag kuira-tag--ok">Configurado</span>'
                                    : '<span class="kuira-tag kuira-tag--bad">Falta</span>'; ?>
                            </label>
                            <input id="kuira-token" type="password" autocomplete="off"
                                   name="<?php echo esc_attr(self::OPTION); ?>[token]"
                                   placeholder="<?php echo $hasToken ? 'Ya guardado - deja en blanco para no cambiarlo' : 'ksk_...'; ?>" />
                            <p class="description">Se muestra UNA sola vez al generarlo en el panel y aqui no se
                               vuelve a ensenar por seguridad. Si lo perdiste, genera uno nuevo en
                               <strong>Integracion</strong>.</p>
                        </div>
                    </div>

                    <div class="kuira-card">
                        <h2><span class="dashicons dashicons-grid-view"></span> Tarjetas de habitaciones</h2>
                        <div class="kuira-field">
                            <label for="kuira-reserve">URL para "Reservar"</label>
                            <input id="kuira-reserve" type="url"
                                   name="<?php echo esc_attr(self::OPTION); ?>[reserve_url]"
                                   value="<?php echo esc_attr($s['reserve_url']); ?>"
                                   placeholder="https://<?php echo esc_attr($s['domain'] ?: 'mihotel.kuirawebreserve.com'); ?>/reservar" />
                            <p class="description">Vacio = el boton lleva a tu pagina de reservas en linea.
                               Escribe una URL solo si prefieres mandarlo a otro lado (WhatsApp, tel:...).</p>
                        </div>
                        <div class="kuira-field">
                            <label for="kuira-columns">Columnas en escritorio</label>
                            <select id="kuira-columns" name="<?php echo esc_attr(self::OPTION); ?>[columns]">
                                <?php foreach (['2', '3', '4'] as $n) { ?>
                                    <option value="<?php echo esc_attr($n); ?>" <?php selected($s['columns'], $n); ?>><?php echo esc_html($n); ?> columnas</option>
                                <?php } ?>
                            </select>
                            <p class="description">En pantallas chicas se acomoda solo (2 en tablet, 1 en celular).</p>
                        </div>
                    </div>

                    <?php submit_button('Guardar cambios'); ?>
                </form>

                <div class="kuira-col">
                    <div class="kuira-card">
                        <h2><span class="dashicons dashicons-admin-plugins"></span> Estado de la conexion</h2>
                        <?php if ($state === 'ok') { ?>
                            <p><span class="kuira-status kuira-status--ok">Conectado</span></p>
                            <p class="kuira-detail">
                                <?php echo esc_html(wp_date('d/m/Y H:i', $okAt)); ?> &mdash;
                                <strong><?php echo esc_html($lastOk['hotel'] ?? ''); ?></strong><br />
                                <?php echo (int) ($lastOk['types'] ?? 0); ?> tipo(s) de habitacion,
                                <?php echo (int) ($lastOk['reservable'] ?? 0); ?> con tarifa activa.
                            </p>
                        <?php } elseif ($state === 'error') { ?>
                            <p><span class="kuira-status kuira-status--bad">Con error</span></p>
                            <p class="kuira-detail">
                                <?php echo esc_html(wp_date('d/m/Y H:i', $errAt)); ?> &mdash;
                                <?php echo esc_html(self::errorHint($lastError)); ?>
                            </p>
                        <?php } else { ?>
                            <p><span class="kuira-status kuira-status--none">Sin probar</span></p>
                            <p class="kuira-detail">Guarda tus datos y pulsa "Probar conexion".</p>
                        <?php } ?>
                        <div class="kuira-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="kuira_rooms_test" />
                                <?php wp_nonce_field('kuira_rooms_test'); ?>
                                <?php submit_button('Probar conexion', 'primary', 'submit', false); ?>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="kuira_rooms_clear_cache" />
                                <?php wp_nonce_field('kuira_rooms_clear_cache'); ?>
                                <?php submit_button('Vaciar cache', 'secondary', 'submit', false); ?>
                            </form>
                        </div>
                    </div>

                    <div class="kuira-card">
                        <h2><span class="dashicons dashicons-shortcode"></span> Como usarlo</h2>
                        <p class="kuira-detail">Tarjetas de habitaciones (foto, precio vivo, amenidades):</p>
                        <p><code>[kuirawebreserve_rooms]</code></p>
                        <p class="kuira-detail">Opcional con columnas propias:</p>
                        <p><code>[kuirawebreserve_rooms columns="2"]</code></p>
                        <p class="kuira-detail">Wizard completo incrustado (fechas, disponibilidad y pago en linea):</p>
                        <p><code>[kuira_reservas]</code> <code>[kuira_experiencias]</code> <code>[kuira_grupos]</code></p>
                        <p class="kuira-version">Version del plugin: <?php echo esc_html(self::VERSION); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php }

    public static function clearCacheAction(): void
    {
        check_admin_referer('kuira_rooms_clear_cache');

        if (! current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }

        self::clearCache();

        wp_safe_redirect(add_query_arg('kuira_cache_cleared', '1', admin_url('options-general.php?page=kuira-rooms')));
        exit;
    }

    /** Consulta SIN cache y regresa a ajustes con el resultado a la vista. */
    public static function testConnectionAction(): void
    {
        check_admin_referer('kuira_rooms_test');

        if (! current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }

        self::clearCache();
        $result = self::fetchCatalog();

        wp_safe_redirect(add_query_arg(
            'kuira_test',
            $result['data'] !== null && ! $result['stale'] ? 'ok' : 'fail',
            admin_url('options-general.php?page=kuira-rooms'),
        ));
        exit;
    }

    // ---- Catalogo ----

    /**
     * Consulta el catalogo DESDE EL SERVIDOR (nunca en el navegador del
     * visitante) con cache corta y respaldo largo.
     *
     * @return array{data: ?array, error: ?array, stale: bool}
     */
    protected static function fetchCatalog(): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return ['data' => $cached, 'error' => null, 'stale' => false];
        }

        $s = self::settings();
        $url = self::catalogUrl();

        if (! $url || ! $s['token']) {
            return self::failWith(['type' => 'unconfigured', 'message' => '']);
        }

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer '.$s['token'],
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return self::failWith(['type' => 'network', 'message' => $response->get_error_message()]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $apiMessage = is_array($body) ? (string) ($body['message'] ?? '') : '';

            return self::failWith(['type' => 'http_'.$code, 'message' => $apiMessage]);
        }

        if (! is_array($body) || ! isset($body['room_types'])) {
            return self::failWith(['type' => 'invalid', 'message' => '']);
        }

        set_transient(self::CACHE_KEY, $body, self::CACHE_MINUTES * MINUTE_IN_SECONDS);
        set_transient(self::BACKUP_KEY, $body, self::BACKUP_HOURS * HOUR_IN_SECONDS);
        update_option(self::LAST_OK, [
            'at' => time(),
            'hotel' => (string) ($body['property']['name'] ?? ''),
            'types' => count($body['room_types']),
            'reservable' => count(array_filter($body['room_types'], fn ($r) => ! empty($r['reservable']))),
        ], false);
        delete_option(self::LAST_ERROR);

        return ['data' => $body, 'error' => null, 'stale' => false];
    }

    /** Registra el error y trata de servir el respaldo largo. */
    protected static function failWith(array $error): array
    {
        $error['at'] = time();

        if ($error['type'] !== 'unconfigured') {
            update_option(self::LAST_ERROR, $error, false);
        }

        $backup = get_transient(self::BACKUP_KEY);

        return [
            'data' => $backup !== false ? $backup : null,
            'error' => $error,
            'stale' => $backup !== false,
        ];
    }

    /** Traduce el error tecnico a una pista que el admin pueda ARREGLAR. */
    protected static function errorHint(array $error): string
    {
        $domain = self::settings()['domain'];

        switch ($error['type'] ?? '') {
            case 'unconfigured':
                return 'Falta el dominio o el token: configuralos aqui y genera el token en tu panel (Integracion).';
            case 'network':
                return "No se pudo contactar {$domain} ({$error['message']}). Revisa que el dominio sea el de tu panel y que tu hosting permita conexiones salientes.";
            case 'http_401':
                return $error['message'] !== '' ? $error['message'].' Genera un token nuevo en Integracion y pegalo aqui.' : 'Token invalido o revocado: genera uno nuevo en Integracion y pegalo aqui.';
            case 'http_403':
                return $error['message'] !== '' ? $error['message'] : 'Tu plan no incluye el modulo Motor de reservas web; contacta a KuiraWebReserve.';
            case 'http_404':
                return "El dominio {$domain} no parece ser tu panel de KuiraWebReserve (revisa que no lleve rutas de mas).";
            case 'invalid':
                return 'El dominio respondio algo que no es el catalogo. Revisa que sea el subdominio de tu panel.';
            default:
                $code = str_replace('http_', '', (string) ($error['type'] ?? ''));

                return $error['message'] !== '' ? $error['message'] : "El panel respondio con un error ({$code}). Intenta de nuevo en unos minutos.";
        }
    }

    // ---- Shortcodes ----

    public static function shortcode($atts = []): string
    {
        $s = self::settings();
        $atts = shortcode_atts(['columns' => $s['columns']], $atts);
        $columns = in_array((string) $atts['columns'], ['2', '3', '4'], true) ? (int) $atts['columns'] : 3;

        $result = self::fetchCatalog();
        $isAdmin = current_user_can('manage_options');

        // Sin datos ni respaldo: el visitante no ve nada; el admin ve la
        // causa REAL con pista de arreglo (nunca mas "configura..." cuando
        // ya esta configurado y el problema es otro).
        if ($result['data'] === null) {
            return $isAdmin && $result['error'] !== null
                ? '<p><em>KuiraWebReserve Habitaciones (solo visible para administradores): '.esc_html(self::errorHint($result['error'])).'</em></p>'
                : '';
        }

        $data = $result['data'];
        $rooms = array_filter($data['room_types'] ?? [], fn ($r) => ! empty($r['reservable']));

        if (empty($rooms)) {
            return $isAdmin
                ? '<p><em>KuiraWebReserve Habitaciones: aun no hay tipos con tarifa activa (revisa Catalogo en tu panel).</em></p>'
                : '';
        }

        wp_enqueue_style('kuira-rooms');

        $currency = (string) ($data['property']['currency'] ?? 'MXN');
        $reserveUrl = $s['reserve_url'] !== '' ? $s['reserve_url'] : "https://{$s['domain']}/reservar";

        ob_start();

        if ($result['stale'] && $isAdmin) { ?>
            <p class="kuira-rooms-admin-note">Aviso solo para administradores: la ultima consulta al catalogo
                fallo (<?php echo esc_html(self::errorHint($result['error'])); ?>). Se muestran los ultimos
                datos guardados.</p>
        <?php } ?>
        <div class="kuira-rooms-grid" style="--kuira-cols:<?php echo (int) $columns; ?>;">
            <?php foreach ($rooms as $room) {
                $photo = $room['photos'][0]['url'] ?? '';
                $cheapest = $room['rate_plans'][0] ?? null; ?>
                <div class="kuira-room-card">
                    <?php if ($photo !== '') { ?>
                        <div class="kuira-room-photo">
                            <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($room['name']); ?>" loading="lazy" />
                        </div>
                    <?php } else { ?>
                        <div class="kuira-room-photo kuira-room-photo--empty"><?php echo esc_html(mb_substr((string) $room['name'], 0, 1)); ?></div>
                    <?php } ?>
                    <div class="kuira-room-body">
                        <h3 class="kuira-room-name"><?php echo esc_html($room['name']); ?></h3>
                        <?php if (! empty($room['description'])) { ?>
                            <p class="kuira-room-desc"><?php echo esc_html(wp_trim_words($room['description'], 22)); ?></p>
                        <?php } ?>
                        <?php if (! empty($room['capacity'])) { ?>
                            <p class="kuira-room-capacity">Hasta <?php echo (int) $room['capacity']; ?> personas</p>
                        <?php } ?>
                        <?php if (! empty($room['amenities'])) { ?>
                            <div class="kuira-room-amenities">
                                <?php foreach (array_slice($room['amenities'], 0, 6) as $amenity) { ?>
                                    <span class="kuira-chip"><?php echo esc_html($amenity); ?></span>
                                <?php } ?>
                                <?php if (count($room['amenities']) > 6) { ?>
                                    <span class="kuira-chip">+<?php echo count($room['amenities']) - 6; ?> mas</span>
                                <?php } ?>
                            </div>
                        <?php } ?>
                        <div class="kuira-room-footer">
                            <?php if (! empty($room['price_from'])) { ?>
                                <div class="kuira-room-price">
                                    <strong>Desde $<?php echo esc_html(number_format((float) $room['price_from'], 0, '.', ',')); ?> <?php echo esc_html($currency); ?></strong>
                                    <?php if ($cheapest !== null && ! empty($cheapest['duration_label'])) { ?>
                                        <small><?php echo esc_html($cheapest['duration_label']); ?></small>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                            <a class="kuira-room-btn" href="<?php echo esc_url($reserveUrl); ?>">Reservar</a>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Wizard completo incrustado: div marcador + widget.js del dominio del
     * hotel (el loader inyecta el iframe con alto autoajustable; precios y
     * cupos SIEMPRE en vivo). El script se imprime una sola vez por pagina
     * aunque haya varios widgets.
     */
    protected static function widgetShortcode(string $key): string
    {
        $domain = self::settings()['domain'];

        if ($domain === '') {
            return current_user_can('manage_options')
                ? '<p><em>KuiraWebReserve: configura el dominio de tu hotel en Ajustes para usar este shortcode.</em></p>'
                : '';
        }

        static $scriptPrinted = false;

        $out = '<div data-kuira-widget="'.esc_attr($key).'"></div>';

        if (! $scriptPrinted) {
            $scriptPrinted = true;
            $out .= "\n".'<script src="'.esc_url("https://{$domain}/widget.js").'" defer></script>';
        }

        return $out;
    }
}

KuiraWebReserveRooms::boot();
