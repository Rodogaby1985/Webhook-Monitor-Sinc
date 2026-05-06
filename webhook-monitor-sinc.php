<?php
/*
Plugin Name: Webhook Monitor & Sinc
Description: Monitor visual de webhooks clave (por ID). Widget en Dashboard, indicador por pedido (nxsync). No envía emails. Cache en transient, AJAX ligero y polling configurable. Incluye botón de "Re-sincronizar" por pedido que fuerza cambios de estado para disparar webhooks y registra marca temporal para auditoría. Usa modal jQuery UI para confirmación y Dashicons para el botón.
Version: 1.6.4
Author: Rodogaby1985 & Copilot
*/

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------- Config / Defaults -------------------- */
if (!defined('WMS_WEBHOOK_IDS')) {
    define('WMS_WEBHOOK_IDS', [
        'Ninox - Orden actualizada' => 66,
        'Ninox - Orden creada'      => 65,
    ]);
}

/**
 * Default settings
 */
function wms_default_settings() {
    return [
        'cron_enabled' => 0,
        'cron_hour'    => 23,
        'cron_minute'  => 59,
        'poll_interval_minutes' => 60, // default 1 hour
    ];
}
function wms_get_settings() {
    $defaults = wms_default_settings();
    $settings = get_option('wms_settings', []);
    return array_merge($defaults, (array) $settings);
}
function wms_save_settings($settings) {
    update_option('wms_settings', $settings);
}

/* ---------------- No emails (user requested) ---------------- */
if (!function_exists('wms_send_alert')) {
    function wms_send_alert($message) {
        // intentionally disabled to avoid sending emails
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WMS ALERT DISABLED] ' . $message);
        }
    }
}

/* ---------------- Admin enqueue for jQuery UI dialog & Dashicons ---------------- */
add_action('admin_enqueue_scripts', 'wms_admin_enqueue');
function wms_admin_enqueue($hook) {
    // Enqueue jQuery UI dialog and WP style for it
    wp_enqueue_script('jquery-ui-dialog');
    wp_enqueue_style('wp-jquery-ui-dialog');
    // Ensure Dashicons available in admin (usually loaded, but ensure)
    wp_enqueue_style('dashicons');
    // Add small inline CSS
    wp_add_inline_style('wp-jquery-ui-dialog', '
        .wms-resync-dialog p { margin: 0 0 1em; }
        .wms-resync-dialog strong { font-weight:600; }
    ');
}

/* ---------------- Activation: defer heavy WC calls ---------------- */
register_activation_hook(__FILE__, function() {
    update_option('wms_rebuild_on_init', 1);
});
add_action('init', 'wms_maybe_rebuild_on_init', 20);
function wms_maybe_rebuild_on_init() {
    if (! get_option('wms_rebuild_on_init') ) return;
    if (function_exists('wc_get_webhook') || function_exists('wc_get_webhooks')) {
        $st = wms_build_wc_webhooks_status();
        $settings = wms_get_settings();
        $exp = max(60, intval($settings['poll_interval_minutes'])) * 60;
        wms_set_webhooks_cache($st, $exp);
    } else {
        wms_set_webhooks_cache([], 3600);
    }
    delete_option('wms_rebuild_on_init');
}
add_action('woocommerce_loaded', function() {
    if (! get_option('wms_rebuild_on_init') ) return;
    if (function_exists('wc_get_webhook') || function_exists('wc_get_webhooks')) {
        $st = wms_build_wc_webhooks_status();
        $settings = wms_get_settings();
        $exp = max(60, intval($settings['poll_interval_minutes'])) * 60;
        wms_set_webhooks_cache($st, $exp);
    } else {
        wms_set_webhooks_cache([], 3600);
    }
    delete_option('wms_rebuild_on_init');
}, 20);

/* ---------------- Cache helpers (transient with time+data) ---------------- */
function wms_get_webhooks_cache() {
    $cache = get_transient('wms_webhooks_status_cache_v3');
    if ($cache && is_array($cache) && isset($cache['time']) && array_key_exists('data', $cache)) {
        return $cache;
    }
    return false;
}
function wms_set_webhooks_cache($data, $expiration = 3600) {
    $payload = [
        'time' => time(),
        'data' => $data,
    ];
    set_transient('wms_webhooks_status_cache_v3', $payload, intval($expiration));
}

/* ---------------- Build status using WC API (with fallbacks) ---------------- */
function wms_build_wc_webhooks_status() {
    $result = [];
    foreach (WMS_WEBHOOK_IDS as $name => $id) {
        $result[$name] = ['exists' => false, 'status' => 'missing', 'id' => $id];
    }

    // 1) Primary: wc_get_webhook by ID
    if (function_exists('wc_get_webhook')) {
        foreach (WMS_WEBHOOK_IDS as $name => $id) {
            if (!$id) continue;
            try {
                $wb = wc_get_webhook($id);
            } catch (Exception $e) {
                $wb = false;
            }
            if ($wb && is_object($wb)) {
                $status = method_exists($wb, 'get_status') ? $wb->get_status() : 'unknown';
                $result[$name] = ['exists' => true, 'status' => $status, 'id' => $id];
            }
        }
    }

    // 2) Fallback: search all webhooks by name
    if (function_exists('wc_get_webhooks')) {
        $need = [];
        foreach ($result as $n => $info) if (!$info['exists']) $need[] = $n;
        if (!empty($need)) {
            try {
                $all = wc_get_webhooks();
            } catch (Exception $e) {
                $all = [];
            }
            if (!empty($all) && is_array($all)) {
                foreach ($all as $wb) {
                    $wb_name = is_object($wb) && method_exists($wb, 'get_name') ? $wb->get_name() : '';
                    if ($wb_name && in_array($wb_name, $need, true)) {
                        $wb_status = is_object($wb) && method_exists($wb, 'get_status') ? $wb->get_status() : 'unknown';
                        $wb_id = is_object($wb) && method_exists($wb, 'get_id') ? $wb->get_id() : null;
                        $result[$wb_name] = ['exists' => true, 'status' => $wb_status, 'id' => $wb_id];
                    }
                }
            }
        }
    }

    // 3) Final fallback: inspect posts of type 'shop_webhook'
    foreach ($result as $name => $info) {
        if ($info['exists']) continue;
        $id = $info['id'];
        if ($id) {
            $post = get_post(intval($id));
            if ($post && $post->post_type === 'shop_webhook') {
                $detected_status = ($post->post_status === 'publish') ? 'active' : $post->post_status;
                $meta = get_post_meta($post->ID);
                if (!empty($meta) && is_array($meta)) {
                    foreach ($meta as $mval) {
                        $str = is_array($mval) ? implode(' ', array_map(function($v){ return is_scalar($v) ? (string)$v : ''; }, $mval)) : (is_scalar($mval) ? (string)$mval : '');
                        $low = strtolower($str);
                        if (strpos($low, 'active') !== false || strpos($low, 'enabled') !== false || preg_match('/\b(1|true|enabled)\b/i', $str)) {
                            $detected_status = 'active';
                            break;
                        }
                    }
                }
                $result[$name] = ['exists' => true, 'status' => $detected_status, 'id' => $post->ID];
                continue;
            }
        }

        // search by post title
        $found = get_posts([
            'post_type' => 'shop_webhook',
            's' => $name,
            'posts_per_page' => 5,
            'suppress_filters' => true,
        ]);
        if (!empty($found)) {
            foreach ($found as $p) {
                if (trim($p->post_title) === trim($name)) {
                    $detected_status = ($p->post_status === 'publish') ? 'active' : $p->post_status;
                    $result[$name] = ['exists' => true, 'status' => $detected_status, 'id' => $p->ID];
                    break;
                }
            }
        }
    }

    return $result;
}

/* ---------- Public getter with cache wrapper ---------- */
function wms_get_wc_webhooks_status($max_age = null) {
    $cache = wms_get_webhooks_cache();
    if ($cache !== false && !empty($cache['data'])) {
        return $cache['data'];
    }
    $status = wms_build_wc_webhooks_status();
    $settings = wms_get_settings();
    $exp = max(60, intval($settings['poll_interval_minutes'])) * 60;
    wms_set_webhooks_cache($status, $exp);
    return $status;
}

/* ---------------- Dashboard widget ---------------- */
add_action('wp_dashboard_setup', 'wms_add_dashboard_widget');
function wms_add_dashboard_widget() {
    wp_add_dashboard_widget('wms_dashboard_widget', 'Webhook Monitor & Sinc', 'wms_dashboard_widget_render');
}

function wms_dashboard_widget_render() {
    if (!current_user_can('manage_options')) {
        echo '<p>Sin permisos para ver este panel.</p>';
        return;
    }

    // Styles scoped for widget
    echo '<style>
    #wms_dashboard_widget { box-sizing: border-box; }
    #wms_dashboard_widget .wms-widget-wrap { max-width:100%; overflow:auto; padding-right:4px; }
    #wms_dashboard_widget .wms-table { width:100%; border-collapse:collapse; table-layout:auto; }
    #wms_dashboard_widget .wms-table td, #wms_dashboard_widget .wms-table th { word-break:break-word; white-space:normal; vertical-align:middle; padding:6px 8px; }
    #wms_dashboard_widget .wms-id-col { width:60px; text-align:center; white-space:nowrap; }

    /* small indicator */
    #wms_dashboard_widget .wms-dot {
        display:inline-block;
        width:8px !important;
        height:8px !important;
        border-radius:50%;
        margin-right:6px;
        vertical-align:middle;
    }

    /* Button styling */
    .wms-resync-btn {
        display:inline-block;
        padding:4px !important;
        margin-left:6px !important;
        line-height:1 !important;
        vertical-align:middle !important;
        border-radius:4px !important;
    }

    /* Dashicon size (smaller to match WP admin buttons) */
    .wms-resync-dashicon {
        font-size:12px !important;
        line-height:1 !important;
        vertical-align:middle !important;
    }

    .wms-small{ font-size:12px; color:#555; }
    </style>';

    $status = wms_get_wc_webhooks_status();
    $cache = wms_get_webhooks_cache();
    $checked = !empty($cache['time']) ? date_i18n('Y-m-d H:i:s', $cache['time']) : 'Nunca';
    $settings = wms_get_settings();
    $poll_minutes = max(1, intval($settings['poll_interval_minutes']));

    echo '<div class="wms-widget-wrap">';
    echo '<p class="wms-small"><strong>Estado de webhooks:</strong> Última comprobación: <span id="wms-checked">' . esc_html($checked) . '</span> &nbsp; <button id="wms-recheck" class="button">Revisar ahora</button></p>';

    echo '<table class="wms-table widefat"><thead><tr><th>Webhook</th><th>Estado</th><th class="wms-id-col">ID</th></tr></thead><tbody>';
    foreach ($status as $name => $info) {
        $exists = !empty($info['exists']);
        $st = $info['status'];
        $id = !empty($info['id']) ? intval($info['id']) : '-';
        $dot_class = (!$exists || !($st === 'active' || $st === 'enabled')) ? 'wms-red' : 'wms-green';
        $label = !$exists ? '<span class="wms-small" style="color:#dc3545">no encontrado</span>' : (($st === 'active' || $st === 'enabled') ? '<span class="wms-small" style="color:#28a745">' . esc_html($st) . '</span>' : '<span class="wms-small" style="color:#dc3545">' . esc_html($st) . '</span>');

        echo '<tr data-wms-name="' . esc_attr($name) . '">';
        echo '<td><span class="wms-dot ' . esc_attr($dot_class) . '" aria-hidden="true"></span> <span class="wms-name-text">' . esc_html($name) . '</span></td>';
        echo '<td class="wms-status-cell">' . $label . '</td>';
        echo '<td class="wms-id-col wms-id-cell">' . esc_html($id) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';

    // JS: lightweight polling using last_check and configurable interval (poll_minutes)
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('wms_recheck');
    $server_cache = wms_get_webhooks_cache();
    $server_time = $server_cache ? intval($server_cache['time']) : 0;
    $check_nonce = wp_create_nonce('wms_recheck'); // used by checkOrderSync AJAX
    ?>
    <script>
    (function(){
        const ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
        const nonce = '<?php echo esc_js($nonce); ?>';
        const CHECK_NONCE = '<?php echo esc_js($check_nonce); ?>';
        const POLL_INTERVAL_MS = <?php echo intval($poll_minutes) * 60 * 1000; ?>;
        let last_check = <?php echo intval($server_time); ?>;
        let pollTimer = null;
        let backoffFactor = 1;

        function escapeHtml(str){ return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

        async function doLightCheck(force=false, button=null) {
            if (button) { button.disabled = true; button.textContent = 'Comprobando...'; }
            try {
                const body = new URLSearchParams();
                body.append('action', 'wms_dashboard_recheck');
                body.append('_wpnonce', CHECK_NONCE);
                if (force) body.append('force', '1');
                if (last_check) body.append('last_check', String(last_check));
                const resp = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                });
                const json = await resp.json();
                if (!json) return;
                if (json.success) {
                    const payload = json.data;
                    if (payload.changed) {
                        updateWidget(payload.data);
                        last_check = payload.time || Math.floor(Date.now()/1000);
                        const el = document.getElementById('wms-checked');
                        if (el) {
                            const ts = new Date(last_check * 1000);
                            el.textContent = ts.getFullYear() + '-' + String(ts.getMonth()+1).padStart(2,'0') + '-' + String(ts.getDate()).padStart(2,'0') + ' ' + String(ts.getHours()).padStart(2,'0') + ':' + String(ts.getMinutes()).padStart(2,'0') + ':' + String(ts.getSeconds()).padStart(2,'0');
                        }
                    } else {
                        if (payload.time) last_check = payload.time;
                    }
                    backoffFactor = 1;
                } else {
                    console.warn('WMS AJAX error:', json);
                    backoffFactor = Math.min(4, backoffFactor * 2);
                }
            } catch (err) {
                console.error('WMS check failed', err);
                backoffFactor = Math.min(4, backoffFactor * 2);
            } finally {
                if (button) { button.disabled = false; button.textContent = 'Revisar ahora'; }
            }
        }

        function updateWidget(statusObj) {
            if (!statusObj || typeof statusObj !== 'object') return;
            Object.keys(statusObj).forEach(function(name){
                try {
                    const info = statusObj[name];
                    const row = document.querySelector('#wms_dashboard_widget tr[data-wms-name="' + CSS.escape(name) + '"]');
                    if (!row) return;
                    const dot = row.querySelector('.wms-dot');
                    const statusCell = row.querySelector('.wms-status-cell');
                    const idCell = row.querySelector('.wms-id-cell');
                    const exists = !!info.exists;
                    const st = info.status || '';
                    const id = info.id ? info.id : '-';
                    if (dot) {
                        dot.classList.remove('wms-green','wms-red');
                        if (exists && (st === 'active' || st === 'enabled')) dot.classList.add('wms-green');
                        else dot.classList.add('wms-red');
                    }
                    if (statusCell) {
                        if (!exists) statusCell.innerHTML = '<span class="wms-small" style="color:#dc3545">no encontrado</span>';
                        else if (st === 'active' || st === 'enabled') statusCell.innerHTML = '<span class="wms-small" style="color:#28a745">' + escapeHtml(st) + '</span>';
                        else statusCell.innerHTML = '<span class="wms-small" style="color:#dc3545">' + escapeHtml(st) + '</span>';
                    }
                    if (idCell) idCell.textContent = id;
                } catch (e) {
                    console.error('WMS update error', e);
                }
            });
        }

        function startPolling() {
            if (pollTimer) return;
            pollTimer = setInterval(function(){
                if (document.hidden) return;
                doLightCheck(false);
            }, Math.max(60000, POLL_INTERVAL_MS * backoffFactor));
        }
        function stopPolling() {
            if (!pollTimer) return;
            clearInterval(pollTimer);
            pollTimer = null;
        }

        function bindButton() {
            const btn = document.getElementById('wms-recheck');
            if (!btn) return;
            btn.removeEventListener('click', manualHandler);
            btn.addEventListener('click', manualHandler);
        }
        function manualHandler(e) {
            e.preventDefault();
            doLightCheck(true, this);
        }

        /* ---------------- Resync button behavior (AJAX, cooldown, modal) ---------------- */
        function bindResyncLinksAjax() {
            if (typeof jQuery === "undefined") return;
            jQuery('button.wms-resync-btn').each(function(){
                var $btn = jQuery(this);
                if ($btn.data('wmsBound')) return;
                $btn.data('wmsBound', true);

                $btn.on('click', function(e){
                    e.preventDefault();
                    if ($btn.is(':disabled') || $btn.hasClass('disabled')) return;

                    var orderId = $btn.data('order-id');
                    var nonce = $btn.data('nonce');

                    var row = $btn.closest('tr');
                    var titleEl = row.find('.order_title, .row-title, .post-title, .wms-name-text');
                    var orderLabel = titleEl.length ? jQuery.trim(titleEl.first().text()) : ('#' + orderId);

                    var message = '<p>¿Forzar resincronización de este pedido <strong>' + escapeHtml(orderLabel) + '</strong>?</p>';
                    message += '<p>Esto disparará los webhooks y registrará una marca temporal en el pedido.</p>';

                    var $dialog = jQuery('<div class="wms-resync-dialog" title="Confirmar resincronización"></div>').html(message).appendTo('body');

                    $dialog.dialog({
                        modal: true,
                        resizable: false,
                        width: Math.min(560, jQuery(window).width() - 80),
                        buttons: [
                            {
                                text: 'Confirmar',
                                class: 'button button-primary',
                                click: function() {
                                    jQuery(this).dialog('close');
                                    doResyncAjax(orderId, nonce, $btn);
                                }
                            },
                            {
                                text: 'Cancelar',
                                class: 'button',
                                click: function() { jQuery(this).dialog('close'); }
                            }
                        ],
                        close: function() { jQuery(this).dialog('destroy').remove(); }
                    });
                });
            });
        }

        function doResyncAjax(orderId, nonce, $btn) {
            disableButtonWithCountdown($btn, 120); // 2 minutes

            var body = new URLSearchParams();
            body.append('action', 'wms_reload_order');
            body.append('order_id', String(orderId));
            body.append('_wpnonce', String(nonce));

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function(r){ return r.json(); }).then(function(json){
                if (json && json.success) {
                    // check after a small delay whether nxsync fields appeared
                    setTimeout(function(){ checkOrderSync(orderId, $btn); }, 5000);
                } else {
                    alert('Error al iniciar resincronización: ' + (json && json.data ? json.data : 'Error desconocido'));
                    enableButton($btn);
                }
            }).catch(function(err){
                console.error('AJAX error', err);
                alert('Error al contactar con el servidor.');
                enableButton($btn);
            });
        }

        function checkOrderSync(orderId, $btn) {
            var body = new URLSearchParams();
            body.append('action', 'wms_check_order_sync');
            body.append('order_id', String(orderId));
            body.append('_wpnonce', CHECK_NONCE);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function(r){ return r.json(); }).then(function(json){
                if (json && json.success && json.data) {
                    if (json.data.synced) {
                        // leave disabled (synced)
                    } else {
                        var tries = $btn.data('wmsCheckTries') || 0;
                        if (tries < 6) {
                            $btn.data('wmsCheckTries', tries + 1);
                            setTimeout(function(){ checkOrderSync(orderId, $btn); }, 10000);
                        }
                    }
                }
            }).catch(function(err){
                console.error('checkOrderSync error', err);
            });
        }

        function disableButtonWithCountdown($btn, seconds) {
            $btn.prop('disabled', true).addClass('disabled');
            var originalTitle = $btn.attr('title') || '';
            $btn.data('wms-original-title', originalTitle);

            var $countSpan = $btn.find('.wms-countdown');
            if (!$countSpan.length) {
                $countSpan = jQuery('<span class="wms-countdown" style="margin-left:4px;font-size:11px;color:#666"></span>');
                $btn.append($countSpan);
            }
            var remaining = seconds;
            $countSpan.text('(' + remaining + 's)');

            var timer = setInterval(function(){
                remaining--;
                if (remaining <= 0) {
                    clearInterval(timer);
                    $countSpan.remove();
                    enableButton($btn);
                } else {
                    $countSpan.text('(' + remaining + 's)');
                }
            }, 1000);
            $btn.data('wms-timer', timer);
        }

        function enableButton($btn) {
            $btn.prop('disabled', false).removeClass('disabled');
            var timer = $btn.data('wms-timer');
            if (timer) clearInterval(timer);
            $btn.removeData('wms-timer');
            $btn.removeData('wmsCheckTries');
            $btn.find('.wms-countdown').remove();
        }

        bindButton();
        bindResyncLinksAjax();
        setTimeout(function(){
            doLightCheck(false);
            startPolling();
        }, 800);
        document.addEventListener('visibilitychange', function(){
            if (document.hidden) stopPolling(); else startPolling();
        });
        document.addEventListener('click', function(){ setTimeout(bindResyncLinksAjax, 50); });

    })();
    </script>
    <?php
}

/* ---------------- AJAX: lightweight recheck ---------------- */
add_action('wp_ajax_wms_dashboard_recheck', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wms_recheck')) wp_send_json_error('Nonce inválido');

    $force = ( isset($_POST['force']) && $_POST['force'] === '1' );
    $last_check_client = isset($_POST['last_check']) ? intval($_POST['last_check']) : 0;
    $settings = wms_get_settings();
    $cache = wms_get_webhooks_cache();

    if (!$force && $cache !== false) {
        if ($last_check_client && intval($cache['time']) <= $last_check_client) {
            wp_send_json_success(['changed' => false, 'time' => intval($cache['time'])]);
        }
        wp_send_json_success(['changed' => true, 'time' => intval($cache['time']), 'data' => $cache['data']]);
    }

    $failures = intval(get_transient('wms_wc_check_failures_v3') ?: 0);
    if (!$force && $failures >= 3) {
        if ($cache !== false) {
            wp_send_json_success(['changed' => false, 'time' => intval($cache['time']), 'note' => 'rebuild_suspended']);
        } else {
            wp_send_json_error('Rebuild suspendido temporalmente tras fallos. Usa "Revisar ahora".');
        }
    }

    $status = wms_build_wc_webhooks_status();
    if (empty($status) || !is_array($status)) {
        $failures++;
        set_transient('wms_wc_check_failures_v3', $failures, 15 * 60);
        if ($cache !== false) {
            wp_send_json_success(['changed' => false, 'time' => intval($cache['time']), 'note' => 'rebuild_failed_returning_cache']);
        } else {
            wp_send_json_error('No se pudo obtener estado de webhooks.');
        }
    }

    delete_transient('wms_wc_check_failures_v3');
    $expiration = max(60, intval($settings['poll_interval_minutes'])) * 60;
    wms_set_webhooks_cache($status, $expiration);
    $new_cache = wms_get_webhooks_cache();
    wp_send_json_success(['changed' => true, 'time' => intval($new_cache['time']), 'data' => $new_cache['data']]);
});

/* ---------------- AJAX: reload order (AJAX) - sets cooldown, returns JSON ---------------- */
add_action('wp_ajax_wms_reload_order', function() {
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Sin permisos', 403);
    $order_id = intval($_REQUEST['order_id'] ?? 0);
    if (!$order_id) wp_send_json_error('ID inválido', 400);

    if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wms_reload_' . $order_id)) {
        wp_send_json_error('Nonce inválido', 403);
    }

    if (!function_exists('wc_get_order')) wp_send_json_error('Función wc_get_order no disponible', 500);
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error('Pedido no encontrado', 404);

    $cooldown_key = 'wms_resync_cooldown_' . intval($order_id);
    if (get_transient($cooldown_key)) {
        wp_send_json_error('Acción temporalmente deshabilitada. Intenta más tarde.', 429);
    }

    $expires = time() + 2 * 60; // 2 minutes
    $payload = ['expires_at' => $expires];
    set_transient($cooldown_key, $payload, 2 * 60);

    $current_user = wp_get_current_user();
    update_post_meta($order_id, 'wms_last_resync', current_time('mysql'));
    update_post_meta($order_id, 'wms_last_resync_by', intval($current_user->ID));

    $current_status = $order->get_status();
    $temp_status = ($current_status !== 'pending') ? 'pending' : 'on-hold';

    try {
        $order->update_status($temp_status, 'Sincronización forzada WMS - paso temporal');
        $order->update_status($current_status, 'Sincronización revertida WMS - paso final');
    } catch (Exception $e) {
        delete_transient($cooldown_key);
        wp_send_json_error('Error al actualizar el pedido: ' . $e->getMessage(), 500);
    }

    wp_send_json_success([
        'order_id' => $order_id,
        'cooldown_expires_at' => $expires,
        'message' => 'Resincronización iniciada. Espera unos segundos y verifica si aparecieron los campos.'
    ]);
});

/* ---------------- AJAX: check single order sync status ---------------- */
add_action('wp_ajax_wms_check_order_sync', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos', 403);
    if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wms_recheck')) wp_send_json_error('Nonce inválido', 403);

    $order_id = intval($_REQUEST['order_id'] ?? 0);
    if (!$order_id) wp_send_json_error('ID inválido', 400);

    $nxsync = get_post_meta($order_id, 'nxsync', true);
    $nxsync_status = get_post_meta($order_id, 'nxsync_status', true);
    $is_ok = !empty($nxsync) && !empty($nxsync_status);

    wp_send_json_success([
        'order_id' => $order_id,
        'synced' => $is_ok,
        'nxsync' => $nxsync,
        'nxsync_status' => $nxsync_status
    ]);
});

/* ---------------- Admin notice (visual only) ---------------- */
add_action('admin_notices', 'wms_admin_notice_if_inactive_webhooks');
function wms_admin_notice_if_inactive_webhooks() {
    if (!current_user_can('manage_options')) return;
    $status = wms_get_wc_webhooks_status();
    $inactive = [];
    foreach ($status as $name => $info) {
        if (!$info['exists'] || ($info['status'] !== 'active' && $info['status'] !== 'enabled')) {
            $inactive[] = $name;
        }
    }
    if (empty($inactive)) return;
    $list = implode(', ', array_map('esc_html', $inactive));
    echo '<div class="notice notice-warning is-dismissible"><p><strong>Webhook Monitor &amp; Sinc:</strong> Webhook(s) inactivo(s)/no encontrados: ' . $list . '. Revisa el Dashboard o WooCommerce → Ajustes → Webhooks.</p></div>';
}

/* ---------------- Orders column: nxsync indicator + Re-sincronizar button (button version) ---------------- */
add_filter('manage_edit-shop_order_columns', 'wms_add_order_sync_column');
function wms_add_order_sync_column($columns) {
    $new = [];
    foreach ($columns as $key => $title) {
        $new[$key] = $title;
        if ($key === 'order_status') $new['wms_sync_status'] = __('Sinc.', 'wms');
    }
    if (!isset($new['wms_sync_status'])) $new['wms_sync_status'] = __('Sinc.', 'wms');
    return $new;
}

add_action('manage_shop_order_posts_custom_column', 'wms_render_order_sync_column', 10, 2);
function wms_render_order_sync_column($column, $post_id) {
    if ($column !== 'wms_sync_status') return;

    $nxsync = get_post_meta($post_id, 'nxsync', true);
    $nxsync_status = get_post_meta($post_id, 'nxsync_status', true);
    $is_ok = !empty($nxsync) && !empty($nxsync_status);

    // comprobar transient de cooldown por si alguien pulsó re-sync recientemente
    $cooldown_key = 'wms_resync_cooldown_' . intval($post_id);
    $cooldown = get_transient($cooldown_key);
    $in_cooldown = !empty($cooldown);

    // Dot indicator
    $dot = $is_ok ? '<span title="Sincronizado" style="color:#28a745;font-weight:bold">●</span>' : '<span title="No sincronizado" style="color:#dc3545;font-weight:bold">●</span>';

    // button: if synced or cooldown => disabled
    $disabled_attr = ($is_ok || $in_cooldown) ? 'disabled="disabled" aria-disabled="true"' : '';
    $btn_class = 'button button-small wms-resync-btn';
    if ($in_cooldown) $btn_class .= ' disabled';

    // data attributes: order-id y nonce for AJAX
    $nonce = wp_create_nonce('wms_reload_' . $post_id);
    $btn = '<button type="button" class="' . esc_attr($btn_class) . '" ' . $disabled_attr
         . ' data-order-id="' . intval($post_id) . '" data-nonce="' . esc_attr($nonce) . '" title="Forzar resincronización">'
         . '<span class="dashicons dashicons-update wms-resync-dashicon" aria-hidden="true"></span>'
         . '</button>';

    // If in cooldown, attach remaining seconds as data attribute (for non-JS fallbacks)
    if ($in_cooldown && isset($cooldown['expires_at'])) {
        $remaining = max(0, intval($cooldown['expires_at']) - time());
        $btn = str_replace('<button ', '<button data-cooldown-remaining="' . intval($remaining) . '" ', $btn);
    }

    echo $dot . ' ' . $btn;
}

/* ---------------- Orders filter: Sinc. (nxsync) ---------------- */

// Render select filter in WooCommerce > Orders list
add_action('restrict_manage_posts', function($post_type) {
    if ($post_type !== 'shop_order') return;

    if (function_exists('get_current_screen')) {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-shop_order') return;
    }

    $current = isset($_GET['wms_sync_filter']) ? sanitize_text_field($_GET['wms_sync_filter']) : '';

    echo '<select name="wms_sync_filter" style="min-width:160px;">';
    echo '<option value="">' . esc_html__('Sinc.: Todas', 'wms') . '</option>';
    echo '<option value="synced"' . selected($current, 'synced', false) . '>' . esc_html__('Sinc.: Sincronizado', 'wms') . '</option>';
    echo '<option value="unsynced"' . selected($current, 'unsynced', false) . '>' . esc_html__('Sinc.: Sin sincronizar', 'wms') . '</option>';
    echo '</select>';
}, 20);

// Apply filter to orders list query
add_filter('posts_where', function($where, $query) {
    if (!is_admin() || !$query->is_main_query()) return $where;

    if (function_exists('get_current_screen')) {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-shop_order') return $where;
    }

    $filter = isset($_GET['wms_sync_filter']) ? sanitize_text_field($_GET['wms_sync_filter']) : '';
    if ($filter !== 'synced' && $filter !== 'unsynced') return $where;

    global $wpdb;

    $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_type = %s ", 'shop_order');

    if ($filter === 'synced') {
        $where .= "
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm1
                WHERE pm1.post_id = {$wpdb->posts}.ID
                  AND pm1.meta_key = 'nxsync'
                  AND pm1.meta_value <> ''
            )
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm2
                WHERE pm2.post_id = {$wpdb->posts}.ID
                  AND pm2.meta_key = 'nxsync_status'
                  AND pm2.meta_value <> ''
            )
        ";
    } else {
        $where .= "
            AND (
                NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm1
                    WHERE pm1.post_id = {$wpdb->posts}.ID
                      AND pm1.meta_key = 'nxsync'
                      AND pm1.meta_value <> ''
                )
                OR NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2
                    WHERE pm2.post_id = {$wpdb->posts}.ID
                      AND pm2.meta_key = 'nxsync_status'
                      AND pm2.meta_value <> ''
                )
            )
        ";
    }

    return $where;
}, 10, 2);

/* ---------------- Settings page (expose poll interval) ---------------- */
add_action('admin_menu', function() {
    add_options_page('Webhook Monitor & Sinc', 'Webhook Monitor & Sinc', 'manage_options', 'wms-settings', 'wms_settings_page');
});
function wms_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = wms_get_settings();
    if ($_POST && check_admin_referer('wms_save_settings')) {
        $settings['cron_enabled'] = isset($_POST['cron_enabled']) ? 1 : 0;
        $settings['cron_hour'] = intval($_POST['cron_hour']);
        $settings['cron_minute'] = intval($_POST['cron_minute']);
        $settings['poll_interval_minutes'] = max(1, intval($_POST['poll_interval_minutes'] ?? $settings['poll_interval_minutes']));
        wms_save_settings($settings);
        wms_update_cron();
        echo '<div class="updated"><p>Opciones guardadas.</p></div>';
    }
    ?>
    <div class="wrap">
        <h2>Webhook Monitor &amp; Sinc - Ajustes</h2>
        <form method="post">
            <?php wp_nonce_field('wms_save_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Activar revisión diaria (cron)</th>
                    <td><input type="checkbox" name="cron_enabled" <?php checked($settings['cron_enabled'], 1); ?> /></td>
                </tr>
                <tr>
                    <th scope="row">Horario de revisión (cron)</th>
                    <td>
                        <input type="number" name="cron_hour" min="0" max="23" value="<?php echo esc_attr($settings['cron_hour']); ?>" /> :
                        <input type="number" name="cron_minute" min="0" max="59" value="<?php echo esc_attr($settings['cron_minute']); ?>" />
                        <span>(hora:minuto, 24h)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Intervalo de polling (minutos)</th>
                    <td>
                        <input type="number" name="poll_interval_minutes" min="1" max="1440" value="<?php echo esc_attr($settings['poll_interval_minutes']); ?>" />
                        <p class="description">Intervalo en minutos que el widget espera entre comprobaciones automáticas (recomendado 60).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Webhooks monitorizados (fijos)</th>
                    <td>
                        <textarea readonly rows="4" style="width:100%;"><?php
                            foreach (WMS_WEBHOOK_IDS as $n => $i) {
                                echo esc_html("$n => ID $i\n");
                            }
                        ?></textarea>
                        <br><em>Editar IDs directamente en el archivo del plugin (constante WMS_WEBHOOK_IDS)</em>
                    </td>
                </tr>
            </table>
            <p><input type="submit" class="button-primary" value="Guardar opciones"></p>
        </form>
    </div>
    <?php
}

/* ---------------- Cron scheduling (optional) ---------------- */
function wms_update_cron() {
    $settings = wms_get_settings();
    $timestamp = wms_next_cron_time($settings['cron_hour'], $settings['cron_minute']);
    wp_clear_scheduled_hook('wms_daily_check');
    if (!empty($settings['cron_enabled'])) {
        wp_schedule_event($timestamp, 'daily', 'wms_daily_check');
    }
}
function wms_next_cron_time($hour,$minute) {
    $now = current_time('timestamp');
    $next = mktime($hour,$minute,0,date('n',$now),date('j',$now),date('Y',$now));
    if ($next <= $now) $next += DAY_IN_SECONDS;
    return $next;
}
add_action('wms_daily_check', function(){
    $st = wms_build_wc_webhooks_status();
    $settings = wms_get_settings();
    $expiration = max(60, intval($settings['poll_interval_minutes'])) * 60;
    wms_set_webhooks_cache($st, $expiration);
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wms_daily_check');
});

/* ---------- End of plugin ---------- */
