<?php
/**
 * Gestión de Sesiones de Caja, Arqueo de Dinero y Control de Movimientos
 * Boliches, Restaurantes y Gastronomía POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Batllie_Caja_Register {

    private static $table_name = null;

    /**
     * Obtener nombre de la tabla de sesiones
     */
    public static function get_table_name() {
        global $wpdb;
        if (self::$table_name === null) {
            self::$table_name = $wpdb->prefix . 'caja_sessions';
        }
        return self::$table_name;
    }

    /**
     * Inicialización y chequeo de tabla
     */
    public static function init() {
        self::check_db_table();
    }

    /**
     * Crear o actualizar la tabla de sesiones en la BD
     */
    public static function check_db_table() {
        global $wpdb;
        $table = self::get_table_name();

        $cache_key = 'caja_sessions_table_checked_v2';
        if (get_transient($cache_key)) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_code varchar(50) NOT NULL DEFAULT '',
            user_id bigint(20) NOT NULL DEFAULT 0,
            user_name varchar(100) NOT NULL DEFAULT '',
            sector varchar(100) NOT NULL DEFAULT '',
            cash_box_id varchar(50) NOT NULL DEFAULT '',
            cash_box_name varchar(100) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'open',
            opened_at datetime NOT NULL,
            closed_at datetime NULL DEFAULT NULL,
            opening_cash decimal(12,2) NOT NULL DEFAULT 0.00,
            cash_sales decimal(12,2) NOT NULL DEFAULT 0.00,
            card_sales decimal(12,2) NOT NULL DEFAULT 0.00,
            transfer_sales decimal(12,2) NOT NULL DEFAULT 0.00,
            tips_cash decimal(12,2) NOT NULL DEFAULT 0.00,
            tips_digital decimal(12,2) NOT NULL DEFAULT 0.00,
            total_sales decimal(12,2) NOT NULL DEFAULT 0.00,
            expected_cash decimal(12,2) NOT NULL DEFAULT 0.00,
            counted_cash decimal(12,2) NOT NULL DEFAULT 0.00,
            difference decimal(12,2) NOT NULL DEFAULT 0.00,
            cash_breakdown longtext NULL,
            orders_count int(11) NOT NULL DEFAULT 0,
            opening_notes text NULL,
            closing_notes text NULL,
            closed_by_user_id bigint(20) NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_status (user_id, status),
            KEY sector_status (sector, status),
            KEY cash_box_id (cash_box_id),
            KEY opened_at (opened_at)
        ) $charset_collate;";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql);

        // Verificación de columnas directas para tablas preexistentes
        $col_box = $wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'cash_box_id'");
        if (empty($col_box)) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `cash_box_id` varchar(50) NOT NULL DEFAULT '' AFTER `sector`, ADD COLUMN `cash_box_name` varchar(100) NOT NULL DEFAULT '' AFTER `cash_box_id`");
        }

        set_transient($cache_key, 1, HOUR_IN_SECONDS * 12);
    }

    /**
     * Obtener sesión activa abierta para un usuario
     */
    public static function get_active_session($user_id = null, $sector = null) {
        global $wpdb;
        $table = self::get_table_name();

        if (empty($user_id)) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) {
            return null;
        }

        $query = "SELECT * FROM $table WHERE user_id = %d AND status = 'open'";
        $params = array($user_id);

        if (!empty($sector)) {
            $query .= " AND sector = %s";
            $params[] = $sector;
        }

        $query .= " ORDER BY id DESC LIMIT 1";

        $row = $wpdb->get_row($wpdb->prepare($query, $params), ARRAY_A);
        if (!$row) {
            return null;
        }

        return self::format_session_data($row);
    }

    /**
     * Obtener una sesión por su ID
     */
    public static function get_session_by_id($session_id) {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d LIMIT 1", intval($session_id)), ARRAY_A);
        if (!$row) {
            return null;
        }

        return self::format_session_data($row);
    }

    /**
     * Formatear fila de sesión con datos computados y moneda
     */
    private static function format_session_data($row) {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Argentina/Buenos_Aires');

        $opening_cash   = floatval($row['opening_cash']);
        $cash_sales     = floatval($row['cash_sales']);
        $card_sales     = floatval($row['card_sales']);
        $transfer_sales = floatval($row['transfer_sales']);
        $tips_cash      = floatval($row['tips_cash']);
        $tips_digital   = floatval($row['tips_digital']);
        $total_sales    = floatval($row['total_sales']);
        $counted_cash   = floatval($row['counted_cash']);

        // Efectivo esperado = Fondo de apertura + Ventas en efectivo + Propinas en efectivo
        $expected_cash  = $opening_cash + $cash_sales + $tips_cash;
        $difference     = $counted_cash - $expected_cash;

        // Formatos de fecha en hora local
        $opened_dt = !empty($row['opened_at']) ? new DateTime($row['opened_at']) : null;
        $closed_dt = !empty($row['closed_at']) ? new DateTime($row['closed_at']) : null;

        $opened_time_str = $opened_dt ? $opened_dt->format('H:i') : '';
        $opened_full_str = $opened_dt ? $opened_dt->format('d/m/Y H:i:s') : '';
        $closed_full_str = $closed_dt ? $closed_dt->format('d/m/Y H:i:s') : '';

        // Desglose de billetes decodificado
        $breakdown = array();
        if (!empty($row['cash_breakdown'])) {
            $decoded = json_decode($row['cash_breakdown'], true);
            if (is_array($decoded)) {
                $breakdown = $decoded;
            }
        }

        return array(
            'id'              => intval($row['id']),
            'session_code'    => $row['session_code'],
            'user_id'         => intval($row['user_id']),
            'user_name'       => $row['user_name'],
            'sector'          => $row['sector'],
            'cash_box_id'     => $row['cash_box_id'] ?? '',
            'cash_box_name'   => $row['cash_box_name'] ?? '',
            'status'          => $row['status'],
            'opened_at'       => $row['opened_at'],
            'closed_at'       => $row['closed_at'],
            'opened_time_str' => $opened_time_str,
            'opened_full_str' => $opened_full_str,
            'closed_full_str' => $closed_full_str,
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'card_sales'      => $card_sales,
            'transfer_sales'  => $transfer_sales,
            'digital_sales'   => $card_sales + $transfer_sales,
            'tips_cash'       => $tips_cash,
            'tips_digital'    => $tips_digital,
            'total_tips'      => $tips_cash + $tips_digital,
            'total_sales'     => $total_sales,
            'expected_cash'   => $expected_cash,
            'counted_cash'    => $counted_cash,
            'difference'      => $difference,
            'cash_breakdown'  => $breakdown,
            'orders_count'    => intval($row['orders_count']),
            'opening_notes'   => $row['opening_notes'],
            'closing_notes'   => $row['closing_notes'],
            'closed_by_user_id' => intval($row['closed_by_user_id'] ?? 0),
        );
    }

    /**
     * Abrir una nueva sesión de caja
     */
    public static function open_session($user_id, $sector, $opening_cash, $notes = '') {
        global $wpdb;
        $table = self::get_table_name();

        self::check_db_table();

        // Si ya tiene una sesión abierta, devolverla
        $existing = self::get_active_session($user_id);
        if ($existing) {
            return $existing;
        }

        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : __('Cajero', 'emp-caja');

        $opening_cash = max(0, floatval($opening_cash));
        $now = current_time('mysql');

        // Código de sesión legible
        $today_code = wp_date('Ymd');
        $count_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM $table WHERE DATE(opened_at) = %s",
            wp_date('Y-m-d')
        ));
        $seq = str_pad($count_today + 1, 3, '0', STR_PAD_LEFT);
        $session_code = 'CJ-' . $today_code . '-' . $seq;

        $money_box = self::get_money_box_by_puesto($sector);
        $cash_box_id = $money_box ? $money_box['id'] : '';
        $cash_box_name = $money_box ? $money_box['name'] : '';

        $insert_data = array(
            'session_code'  => $session_code,
            'user_id'       => $user_id,
            'user_name'     => $user_name,
            'sector'        => sanitize_text_field($sector),
            'cash_box_id'   => $cash_box_id,
            'cash_box_name' => $cash_box_name,
            'status'        => 'open',
            'opened_at'     => $now,
            'opening_cash'  => $opening_cash,
            'expected_cash' => $opening_cash,
            'opening_notes' => sanitize_textarea_field($notes),
        );

        $wpdb->insert($table, $insert_data);
        $new_id = $wpdb->insert_id;

        if (!$new_id) {
            return false;
        }

        return self::get_session_by_id($new_id);
    }

    /**
     * Registrar una venta completada en la sesión activa
     */
    public static function record_sale($session_id, $order_id, $payment_method, $order_total, $tip_amount = 0) {
        global $wpdb;
        $table = self::get_table_name();

        $session_id = intval($session_id);
        if (!$session_id) {
            return false;
        }

        $order_total = floatval($order_total);
        $tip_amount  = max(0, floatval($tip_amount));
        $net_sale    = max(0, $order_total - $tip_amount);

        $cash_add     = 0.00;
        $card_add     = 0.00;
        $transfer_add = 0.00;
        $tip_cash_add = 0.00;
        $tip_dig_add  = 0.00;

        $pm = strtolower((string)$payment_method);

        if ($pm === 'efectivo' || $pm === 'cod' || $pm === 'cash') {
            $cash_add     = $net_sale;
            $tip_cash_add = $tip_amount;
        } elseif (stripos($pm, 'tarjeta') !== false || stripos($pm, 'card') !== false || stripos($pm, 'pos') !== false) {
            $card_add    = $net_sale;
            $tip_dig_add = $tip_amount;
        } else {
            $transfer_add = $net_sale;
            $tip_dig_add  = $tip_amount;
        }

        // Actualización atómica en base de datos
        $sql = "UPDATE $table SET 
            cash_sales = cash_sales + %f,
            card_sales = card_sales + %f,
            transfer_sales = transfer_sales + %f,
            tips_cash = tips_cash + %f,
            tips_digital = tips_digital + %f,
            total_sales = total_sales + %f,
            expected_cash = opening_cash + (cash_sales + %f) + (tips_cash + %f),
            orders_count = orders_count + 1
            WHERE id = %d";

        $res = $wpdb->query($wpdb->prepare(
            $sql,
            $cash_add,
            $card_add,
            $transfer_add,
            $tip_cash_add,
            $tip_dig_add,
            $order_total,
            $cash_add,
            $tip_cash_add,
            $session_id
        ));

        return ($res !== false);
    }

    /**
     * Cerrar sesión de caja y asentar arqueo de dinero
     */
    public static function close_session($session_id, $counted_cash, $cash_breakdown = array(), $closing_notes = '', $closed_by_user_id = null) {
        global $wpdb;
        $table = self::get_table_name();

        $session = self::get_session_by_id($session_id);
        if (!$session) {
            return false;
        }

        $counted_cash = max(0, floatval($counted_cash));
        $expected_cash = $session['expected_cash'];
        $difference = $counted_cash - $expected_cash;

        if (empty($closed_by_user_id)) {
            $closed_by_user_id = get_current_user_id();
        }

        $breakdown_json = !empty($cash_breakdown) ? wp_json_encode($cash_breakdown) : null;
        $now = current_time('mysql');

        $update_data = array(
            'status'            => 'closed',
            'closed_at'         => $now,
            'counted_cash'      => $counted_cash,
            'expected_cash'     => $expected_cash,
            'difference'        => $difference,
            'cash_breakdown'    => $breakdown_json,
            'closing_notes'     => sanitize_textarea_field($closing_notes),
            'closed_by_user_id' => intval($closed_by_user_id),
        );

        $wpdb->update($table, $update_data, array('id' => $session_id));

        return self::get_session_by_id($session_id);
    }

    /**
     * Obtener listado de sesiones con filtros y métricas globales
     */
    public static function get_sessions($args = array()) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'sector'    => '',
            'user_id'   => '',
            'status'    => '',
            'date_from' => '',
            'date_to'   => '',
            'date_preset' => 'today',
            'limit'     => 50,
            'offset'    => 0,
        );
        $params = wp_parse_args($args, $defaults);

        $where = array("1=1");
        $values = array();

        if (!empty($params['sector']) && $params['sector'] !== 'all') {
            $where[] = "sector = %s";
            $values[] = $params['sector'];
        }

        if (!empty($params['user_id']) && $params['user_id'] !== 'all') {
            $where[] = "user_id = %d";
            $values[] = intval($params['user_id']);
        }

        if (!empty($params['status']) && $params['status'] !== 'all') {
            $where[] = "status = %s";
            $values[] = $params['status'];
        }

        // Filtro de fechas
        $today = wp_date('Y-m-d');
        if ($params['date_preset'] === 'today') {
            $where[] = "DATE(opened_at) = %s";
            $values[] = $today;
        } elseif ($params['date_preset'] === 'yesterday') {
            $yesterday = wp_date('Y-m-d', strtotime('-1 day'));
            $where[] = "DATE(opened_at) = %s";
            $values[] = $yesterday;
        } elseif ($params['date_preset'] === 'week') {
            $week_start = wp_date('Y-m-d', strtotime('-7 days'));
            $where[] = "DATE(opened_at) >= %s";
            $values[] = $week_start;
        } elseif ($params['date_preset'] === 'custom' && !empty($params['date_from'])) {
            $where[] = "DATE(opened_at) >= %s";
            $values[] = $params['date_from'];
            if (!empty($params['date_to'])) {
                $where[] = "DATE(opened_at) <= %s";
                $values[] = $params['date_to'];
            }
        }

        $where_sql = implode(' AND ', $where);

        // Métricas globales de la selección
        $metrics_sql = "SELECT 
            COUNT(id) as total_sessions,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as active_open_count,
            SUM(opening_cash) as sum_opening_cash,
            SUM(cash_sales) as sum_cash_sales,
            SUM(card_sales + transfer_sales) as sum_digital_sales,
            SUM(tips_cash + tips_digital) as sum_tips,
            SUM(total_sales) as sum_total_sales,
            SUM(CASE WHEN status = 'open' THEN expected_cash ELSE counted_cash END) as sum_cash_in_registers
            FROM $table WHERE $where_sql";

        $prepared_metrics = !empty($values) ? $wpdb->prepare($metrics_sql, $values) : $metrics_sql;
        $metrics_row = $wpdb->get_row($prepared_metrics, ARRAY_A);

        // Listado de sesiones
        $query_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_values = array_merge($values, array(intval($params['limit']), intval($params['offset'])));
        $rows = $wpdb->get_results($wpdb->prepare($query_sql, $list_values), ARRAY_A);

        $sessions = array();
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $sessions[] = self::format_session_data($r);
            }
        }

        // Cajas activas en vivo (open)
        $active_sql = "SELECT * FROM $table WHERE status = 'open' ORDER BY opened_at DESC";
        $active_rows = $wpdb->get_results($active_sql, ARRAY_A);
        $active_sessions = array();
        if (!empty($active_rows)) {
            foreach ($active_rows as $ar) {
                $active_sessions[] = self::format_session_data($ar);
            }
        }

        return array(
            'metrics' => array(
                'total_sessions'       => intval($metrics_row['total_sessions'] ?? 0),
                'active_open_count'    => intval($metrics_row['active_open_count'] ?? 0),
                'sum_opening_cash'     => floatval($metrics_row['sum_opening_cash'] ?? 0),
                'sum_cash_sales'       => floatval($metrics_row['sum_cash_sales'] ?? 0),
                'sum_digital_sales'    => floatval($metrics_row['sum_digital_sales'] ?? 0),
                'sum_tips'             => floatval($metrics_row['sum_tips'] ?? 0),
                'sum_total_sales'      => floatval($metrics_row['sum_total_sales'] ?? 0),
                'sum_cash_in_registers'=> floatval($metrics_row['sum_cash_in_registers'] ?? 0),
            ),
            'active_sessions' => $active_sessions,
            'sessions'        => $sessions,
        );
    }

    /**
     * Obtener el historial detallado de movimientos de una caja con horarios exactos (HH:MM:SS)
     */
    public static function get_session_movements($session_id) {
        $session = self::get_session_by_id($session_id);
        if (!$session) {
            return false;
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Argentina/Buenos_Aires');

        // Consultar órdenes de WooCommerce asociadas a este session_id
        $orders = wc_get_orders(array(
            'limit'      => -1,
            'orderby'    => 'date',
            'order'      => 'ASC', // Cronológico segundo a segundo
            'meta_key'   => '_caja_session_id',
            'meta_value' => $session_id,
            'status'     => array('any'),
        ));

        $movements = array();
        $running_cash  = $session['opening_cash'];
        $running_total = 0.00;

        foreach ($orders as $order) {
            if (!$order || !is_a($order, 'WC_Order')) {
                continue;
            }

            $dt = $order->get_date_created();
            $time_exact = $dt ? $dt->setTimezone($tz)->format('H:i:s') : '';
            $date_str   = $dt ? $dt->setTimezone($tz)->format('d/m/Y') : '';

            $mode = $order->get_meta('_caja_pos_mode') ?: 'compra_realizada';
            $mode_label = ($mode === 'pedido_despachar') ? __('Take Away / Despacho', 'emp-caja') : __('Venta en Barra', 'emp-caja');

            $pm_title = $order->get_payment_method_title() ?: __('Efectivo', 'emp-caja');
            $pm = $order->get_payment_method();

            $total = floatval($order->get_total());
            $tip   = floatval($order->get_meta('_caja_tip_amount') ?: 0);
            $tendered = floatval($order->get_meta('_caja_cash_tendered') ?: 0);
            $change   = floatval($order->get_meta('_caja_cash_change') ?: 0);

            $is_cash = ($pm === 'cod' || stripos($pm, 'efectivo') !== false || stripos($pm_title, 'efectivo') !== false);

            if ($is_cash) {
                $running_cash += $total;
            }
            $running_total += $total;

            // Desglose de ítems vendidos
            $items = array();
            foreach ($order->get_items() as $item_id => $item) {
                if (is_a($item, 'WC_Order_Item_Product')) {
                    $items[] = array(
                        'name'     => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total'    => floatval($item->get_total()),
                    );
                }
            }

            $movements[] = array(
                'order_id'       => $order->get_id(),
                'order_number'   => $order->get_order_number(),
                'time_exact'     => $time_exact,
                'date'           => $date_str,
                'mode'           => $mode,
                'mode_label'     => $mode_label,
                'sector'         => $order->get_meta('_caja_gastro_sector') ?: $session['sector'],
                'table'          => $order->get_meta('_caja_gastro_mesa') ?: '',
                'payment_method' => $pm,
                'payment_title'  => $pm_title,
                'is_cash'        => $is_cash,
                'items'          => $items,
                'total'          => $total,
                'tip'            => $tip,
                'tendered'       => $tendered,
                'change'         => $change,
                'running_cash'   => $running_cash,
                'running_total'  => $running_total,
            );
        }

        return array(
            'session'   => $session,
            'movements' => $movements,
        );
    }

    // =========================================================================
    // GESTIÓN DE CAJAS DE DINERO (ADMINISTRADOR Y ENCARGADO)
    // REGLA: Cada mostrador/puesto puede pertenecer como máximo a 1 caja de dinero
    // =========================================================================

    /**
     * Obtener todas las Cajas de Dinero físicas configuradas
     */
    public static function get_money_boxes() {
        $boxes = get_option('batllie_caja_money_boxes', null);
        if ($boxes === null || !is_array($boxes)) {
            $all_sectors = class_exists('Batllie_Caja_Employees') ? Batllie_Caja_Employees::get_available_sectors() : array('Barra Principal', 'Take Away');
            $default_puestos = array();
            if (!empty($all_sectors[0])) {
                $default_puestos[] = $all_sectors[0];
            }
            $boxes = array(
                array(
                    'id'               => 'box_1',
                    'name'             => __('Caja Principal', 'emp-caja'),
                    'code'             => 'CJ-01',
                    'notes'            => __('Caja física de mostrador / barra', 'emp-caja'),
                    'assigned_puestos' => $default_puestos,
                    'status'           => 'active',
                    'created_at'       => current_time('mysql'),
                    'updated_at'       => current_time('mysql'),
                )
            );
            update_option('batllie_caja_money_boxes', $boxes);
        }
        return $boxes;
    }

    /**
     * Obtener una Caja de Dinero por su ID
     */
    public static function get_money_box_by_id($id) {
        $boxes = self::get_money_boxes();
        foreach ($boxes as $box) {
            if ($box['id'] === $id) {
                return $box;
            }
        }
        return null;
    }

    /**
     * Obtener la Caja de Dinero vinculada a un puesto/mostrador específico
     */
    public static function get_money_box_by_puesto($puesto) {
        if (empty($puesto)) return null;
        $boxes = self::get_money_boxes();
        $puesto_clean = trim($puesto);
        foreach ($boxes as $box) {
            if (!empty($box['status']) && $box['status'] === 'inactive') {
                continue;
            }
            if (!empty($box['assigned_puestos']) && is_array($box['assigned_puestos'])) {
                foreach ($box['assigned_puestos'] as $p) {
                    if (strcasecmp(trim($p), $puesto_clean) === 0) {
                        return $box;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Obtener mapa de todos los puestos/mostradores ya asignados a alguna caja de dinero.
     * Útil para validar que no haya más de una caja vinculada al mismo puesto.
     *
     * @param string|null $exclude_box_id ID de caja a excluir (ej. al editar)
     * @return array [ puesto => [ 'box_id' => ..., 'box_name' => ... ] ]
     */
    public static function get_all_assigned_puestos_map($exclude_box_id = null) {
        $boxes = self::get_money_boxes();
        $map = array();
        foreach ($boxes as $box) {
            if ($exclude_box_id && $box['id'] === $exclude_box_id) {
                continue;
            }
            if (!empty($box['assigned_puestos']) && is_array($box['assigned_puestos'])) {
                foreach ($box['assigned_puestos'] as $p) {
                    $p_clean = trim($p);
                    if ($p_clean !== '') {
                        $map[$p_clean] = array(
                            'box_id'   => $box['id'],
                            'box_name' => $box['name'],
                            'status'   => $box['status'] ?? 'active',
                        );
                    }
                }
            }
        }
        return $map;
    }

    /**
     * Crear o actualizar una Caja de Dinero.
     * REGLA ESTRICTA: No puede haber más de una caja de dinero vinculada a un puesto.
     */
    public static function save_money_box($data) {
        $name = isset($data['name']) ? sanitize_text_field(wp_unslash($data['name'])) : '';
        if (empty($name)) {
            return new WP_Error('empty_name', __('El nombre de la caja de dinero es obligatorio.', 'emp-caja'));
        }

        $id = !empty($data['id']) ? sanitize_text_field(wp_unslash($data['id'])) : ('box_' . time() . '_' . wp_rand(100, 999));
        $code = !empty($data['code']) ? sanitize_text_field(wp_unslash($data['code'])) : ('CJ-' . strtoupper(substr(md5($id), 0, 4)));
        $notes = isset($data['notes']) ? sanitize_textarea_field(wp_unslash($data['notes'])) : '';
        $status = (!empty($data['status']) && $data['status'] === 'inactive') ? 'inactive' : 'active';

        // Puestos / mostradores solicitados
        $raw_puestos = isset($data['assigned_puestos']) ? (array) $data['assigned_puestos'] : array();
        $assigned_puestos = array();
        foreach ($raw_puestos as $p) {
            $cleaned = sanitize_text_field(trim(wp_unslash($p)));
            if ($cleaned !== '' && !in_array($cleaned, $assigned_puestos, true)) {
                $assigned_puestos[] = $cleaned;
            }
        }

        // VALIDACIÓN ESTRICTA: Comprobar que ningún puesto esté asignado ya a otra caja de dinero
        $assigned_map = self::get_all_assigned_puestos_map($id);
        $conflicts = array();
        foreach ($assigned_puestos as $puesto) {
            if (isset($assigned_map[$puesto])) {
                $conflicts[] = sprintf('"%s" (asignado a %s)', $puesto, $assigned_map[$puesto]['box_name']);
            }
        }

        if (!empty($conflicts)) {
            return new WP_Error(
                'duplicate_puesto_assignment',
                sprintf(__('No se puede guardar: No puede haber más de una caja de dinero vinculada a un puesto. Puestos ya en uso: %s.', 'emp-caja'), implode(', ', $conflicts))
            );
        }

        $boxes = self::get_money_boxes();
        $found = false;
        $now = current_time('mysql');

        $saved_box = array(
            'id'               => $id,
            'name'             => $name,
            'code'             => $code,
            'notes'            => $notes,
            'assigned_puestos' => $assigned_puestos,
            'status'           => $status,
            'created_at'       => $now,
            'updated_at'       => $now,
        );

        foreach ($boxes as $idx => $box) {
            if ($box['id'] === $id) {
                $saved_box['created_at'] = $box['created_at'] ?? $now;
                $saved_box['updated_at'] = $now;
                $boxes[$idx] = $saved_box;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $boxes[] = $saved_box;
        }

        update_option('batllie_caja_money_boxes', $boxes);

        // Asegurar que los puestos asignados existan en la lista de sectores disponibles
        foreach ($assigned_puestos as $puesto) {
            self::add_available_puesto($puesto);
        }

        return $saved_box;
    }

    /**
     * Eliminar una Caja de Dinero
     */
    public static function delete_money_box($id) {
        $id = sanitize_text_field(wp_unslash($id));
        $boxes = self::get_money_boxes();
        $filtered = array();
        $found = false;

        foreach ($boxes as $box) {
            if ($box['id'] === $id) {
                $found = true;
                continue;
            }
            $filtered[] = $box;
        }

        if ($found) {
            update_option('batllie_caja_money_boxes', array_values($filtered));
            return true;
        }

        return new WP_Error('not_found', __('La caja de dinero no fue encontrada.', 'emp-caja'));
    }

    /**
     * Registrar un nuevo puesto o mostrador en los sectores globales si no existe
     */
    public static function add_available_puesto($puesto_name) {
        $puesto_name = sanitize_text_field(trim($puesto_name));
        if (empty($puesto_name)) return false;

        $options = Batllie_Caja_Plugin::get_color_settings();
        $current_raw = !empty($options['gastro_sectors']) ? $options['gastro_sectors'] : 'Barra Principal, Barra VIP, Terraza, Boletería / Entrada, Pista, Take Away / Para Llevar';
        $current_list = array_values(array_filter(array_map('trim', explode(',', $current_raw))));

        $exists = false;
        foreach ($current_list as $item) {
            if (strcasecmp($item, $puesto_name) === 0) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $current_list[] = $puesto_name;
            $options['gastro_sectors'] = implode(', ', $current_list);
            update_option('batllie_caja_options', $options);
            return true;
        }

        return false;
    }
}
