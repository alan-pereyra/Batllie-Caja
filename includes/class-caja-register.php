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
    private static $movements_table_name = null;

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
     * Obtener nombre de la tabla de movimientos permanentes
     */
    public static function get_movements_table_name() {
        global $wpdb;
        if (self::$movements_table_name === null) {
            self::$movements_table_name = $wpdb->prefix . 'caja_movements';
        }
        return self::$movements_table_name;
    }

    /**
     * Inicialización y chequeo de tabla
     */
    public static function init() {
        self::check_db_table();
    }

    /**
     * Crear o actualizar las tablas en la BD
     */
    public static function check_db_table() {
        global $wpdb;
        $table = self::get_table_name();
        $movements_table = self::get_movements_table_name();

        $cache_key = 'caja_sessions_table_checked_v4';
        if (get_transient($cache_key)) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql_sessions = "CREATE TABLE $table (
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
            cash_in decimal(12,2) NOT NULL DEFAULT 0.00,
            cash_out decimal(12,2) NOT NULL DEFAULT 0.00,
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

        $sql_movements = "CREATE TABLE $movements_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cash_box_id varchar(50) NOT NULL DEFAULT '',
            cash_box_name varchar(100) NOT NULL DEFAULT '',
            session_id bigint(20) NOT NULL DEFAULT 0,
            session_code varchar(50) NOT NULL DEFAULT '',
            user_id bigint(20) NOT NULL DEFAULT 0,
            user_name varchar(100) NOT NULL DEFAULT '',
            type varchar(30) NOT NULL DEFAULT 'movement',
            concept varchar(255) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            cash_flow decimal(12,2) NOT NULL DEFAULT 0.00,
            payment_method varchar(50) NOT NULL DEFAULT 'efectivo',
            balance_after decimal(12,2) NOT NULL DEFAULT 0.00,
            order_id bigint(20) NULL DEFAULT NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY cash_box_id (cash_box_id),
            KEY session_id (session_id),
            KEY type (type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($sql_sessions);
        dbDelta($sql_movements);

        // Verificación de columnas directas para tablas preexistentes
        $col_box = $wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'cash_box_id'");
        if (empty($col_box)) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `cash_box_id` varchar(50) NOT NULL DEFAULT '' AFTER `sector`, ADD COLUMN `cash_box_name` varchar(100) NOT NULL DEFAULT '' AFTER `cash_box_id`");
        }
        $col_in = $wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE 'cash_in'");
        if (empty($col_in)) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `cash_in` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `tips_digital`, ADD COLUMN `cash_out` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `cash_in`");
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
        $cash_in        = floatval($row['cash_in'] ?? 0);
        $cash_out       = floatval($row['cash_out'] ?? 0);
        $total_sales    = floatval($row['total_sales']);
        $counted_cash   = floatval($row['counted_cash']);

        // Efectivo esperado = Fondo apertura + Ventas efectivo + Propinas efectivo + Ingresos - Egresos
        $expected_cash  = $opening_cash + $cash_sales + $tips_cash + $cash_in - $cash_out;
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
            'cash_in'         => $cash_in,
            'cash_out'        => $cash_out,
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

        $new_session = self::get_session_by_id($new_id);
        if ($new_session) {
            self::record_movement(array(
                'cash_box_id'    => $new_session['cash_box_id'],
                'cash_box_name'  => $new_session['cash_box_name'],
                'session_id'     => $new_session['id'],
                'session_code'   => $new_session['session_code'],
                'user_id'        => $new_session['user_id'],
                'user_name'      => $new_session['user_name'],
                'type'           => 'opening',
                'concept'        => __('Apertura de Turno - Fondo Inicial', 'emp-caja') . (!empty($notes) ? " ($notes)" : ''),
                'amount'         => $opening_cash,
                'cash_flow'      => $opening_cash,
                'payment_method' => 'efectivo',
                'balance_after'  => $opening_cash,
                'metadata'       => array('notes' => $notes),
                'created_at'     => $now,
            ));
        }

        return $new_session;
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
            expected_cash = opening_cash + (cash_sales + %f) + (tips_cash + %f) + cash_in - cash_out,
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

        if ($res !== false) {
            $updated_session = self::get_session_by_id($session_id);
            if ($updated_session) {
                $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
                $order_num = ($order && is_a($order, 'WC_Order')) ? $order->get_order_number() : $order_id;
                $pm_title = ($order && is_a($order, 'WC_Order')) ? ($order->get_payment_method_title() ?: $payment_method) : $payment_method;
                $items_summary = array();
                if ($order && is_a($order, 'WC_Order')) {
                    foreach ($order->get_items() as $item) {
                        if (is_a($item, 'WC_Order_Item_Product')) {
                            $items_summary[] = $item->get_name() . ' x' . $item->get_quantity();
                        }
                    }
                }

                $is_cash = ($cash_add > 0 || $tip_cash_add > 0);

                self::record_movement(array(
                    'cash_box_id'    => $updated_session['cash_box_id'],
                    'cash_box_name'  => $updated_session['cash_box_name'],
                    'session_id'     => $updated_session['id'],
                    'session_code'   => $updated_session['session_code'],
                    'user_id'        => $updated_session['user_id'],
                    'user_name'      => $updated_session['user_name'],
                    'type'           => 'sale',
                    'concept'        => sprintf(__('Venta #%s (%s)', 'emp-caja'), $order_num, $pm_title),
                    'amount'         => $order_total,
                    'cash_flow'      => $is_cash ? ($cash_add + $tip_cash_add) : 0,
                    'payment_method' => $pm,
                    'balance_after'  => $updated_session['expected_cash'],
                    'order_id'       => $order_id,
                    'metadata'       => array(
                        'items_summary' => implode(', ', $items_summary),
                        'tip'           => $tip_amount,
                    ),
                    'created_at'     => current_time('mysql'),
                ));
            }
        }

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

        $updated_session = self::get_session_by_id($session_id);
        if ($updated_session) {
            $diff_str = '';
            if ($difference > 0) {
                $diff_str = sprintf(__(' (Sobrante: +$%s)', 'emp-caja'), number_format($difference, 2, ',', '.'));
            } elseif ($difference < 0) {
                $diff_str = sprintf(__(' (Faltante: -$%s)', 'emp-caja'), number_format(abs($difference), 2, ',', '.'));
            } else {
                $diff_str = __(' (Cuadrada exacta)', 'emp-caja');
            }

            self::record_movement(array(
                'cash_box_id'    => $updated_session['cash_box_id'],
                'cash_box_name'  => $updated_session['cash_box_name'],
                'session_id'     => $updated_session['id'],
                'session_code'   => $updated_session['session_code'],
                'user_id'        => $closed_by_user_id ?: $updated_session['user_id'],
                'user_name'      => ($closed_u = get_userdata($closed_by_user_id)) ? $closed_u->display_name : $updated_session['user_name'],
                'type'           => 'closing',
                'concept'        => __('Cierre de Turno y Arqueo', 'emp-caja') . $diff_str,
                'amount'         => $counted_cash,
                'cash_flow'      => 0,
                'payment_method' => 'efectivo',
                'balance_after'  => $counted_cash,
                'metadata'       => array(
                    'expected_cash' => $expected_cash,
                    'counted_cash'  => $counted_cash,
                    'difference'    => $difference,
                    'breakdown'     => $cash_breakdown,
                    'notes'         => $closing_notes,
                ),
                'created_at'     => $now,
            ));
        }

        return $updated_session;
    }

    /**
     * Obtener listado de sesiones con filtros y métricas globales
     */
    public static function get_sessions($args = array()) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'cash_box_id' => '',
            'sector'      => '',
            'user_id'     => '',
            'status'      => '',
            'date_from'   => '',
            'date_to'     => '',
            'date_preset' => 'today',
            'limit'       => 50,
            'offset'      => 0,
        );
        $params = wp_parse_args($args, $defaults);

        $where = array("1=1");
        $values = array();

        if (!empty($params['cash_box_id']) && $params['cash_box_id'] !== 'all') {
            $where[] = "cash_box_id = %s";
            $values[] = $params['cash_box_id'];
        }

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
            SUM(cash_in) as sum_cash_in,
            SUM(cash_out) as sum_cash_out,
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
                'sum_cash_in'          => floatval($metrics_row['sum_cash_in'] ?? 0),
                'sum_cash_out'         => floatval($metrics_row['sum_cash_out'] ?? 0),
                'sum_cash_in_registers'=> floatval($metrics_row['sum_cash_in_registers'] ?? 0),
            ),
            'active_sessions' => $active_sessions,
            'sessions'        => $sessions,
        );
    }

    /**
     * Registrar un movimiento inmutable en la tabla permanente caja_movements
     */
    public static function record_movement($data) {
        global $wpdb;
        $table = self::get_movements_table_name();
        self::check_db_table();

        $meta = isset($data['metadata']) ? (is_string($data['metadata']) ? $data['metadata'] : wp_json_encode($data['metadata'])) : null;
        $now = !empty($data['created_at']) ? $data['created_at'] : current_time('mysql');

        $insert_data = array(
            'cash_box_id'    => sanitize_text_field($data['cash_box_id'] ?? ''),
            'cash_box_name'  => sanitize_text_field($data['cash_box_name'] ?? ''),
            'session_id'     => intval($data['session_id'] ?? 0),
            'session_code'   => sanitize_text_field($data['session_code'] ?? ''),
            'user_id'        => intval($data['user_id'] ?? get_current_user_id()),
            'user_name'      => sanitize_text_field($data['user_name'] ?? ''),
            'type'           => sanitize_text_field($data['type'] ?? 'movement'),
            'concept'        => sanitize_text_field($data['concept'] ?? ''),
            'amount'         => floatval($data['amount'] ?? 0.00),
            'cash_flow'      => floatval($data['cash_flow'] ?? 0.00),
            'payment_method' => sanitize_text_field($data['payment_method'] ?? 'efectivo'),
            'balance_after'  => floatval($data['balance_after'] ?? 0.00),
            'order_id'       => !empty($data['order_id']) ? intval($data['order_id']) : null,
            'metadata'       => $meta,
            'created_at'     => $now,
        );

        $res = $wpdb->insert($table, $insert_data);
        return $res ? $wpdb->insert_id : false;
    }

    /**
     * Registrar movimiento manual de dinero en una caja (Ingreso, Retiro, Ajuste)
     */
    public static function add_cash_movement($cash_box_id, $type, $amount, $concept, $notes = '', $user_id = null) {
        global $wpdb;
        self::check_db_table();

        $box = self::get_money_box_by_id($cash_box_id);
        if (!$box) {
            return new WP_Error('box_not_found', __('Caja de dinero no encontrada.', 'emp-caja'));
        }

        if (empty($user_id)) {
            $user_id = get_current_user_id();
        }
        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : __('Administrador', 'emp-caja');

        $amount = floatval($amount);
        if ($amount <= 0 && $type !== 'adjustment') {
            return new WP_Error('invalid_amount', __('El monto debe ser superior a cero.', 'emp-caja'));
        }

        $concept = trim(sanitize_text_field($concept));
        if (empty($concept)) {
            return new WP_Error('missing_concept', __('Debes ingresar un motivo o concepto para este movimiento.', 'emp-caja'));
        }

        // Buscar si hay sesión activa para esta caja de dinero
        $sessions_table = self::get_table_name();
        $active_session_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $sessions_table WHERE cash_box_id = %s AND status = 'open' ORDER BY id DESC LIMIT 1",
            $cash_box_id
        ), ARRAY_A);

        $session_id = 0;
        $session_code = '';
        $current_cash = 0.00;

        if ($active_session_row) {
            $session_id = intval($active_session_row['id']);
            $session_code = $active_session_row['session_code'];
            $active_session = self::format_session_data($active_session_row);
            $current_cash = $active_session['expected_cash'];
        } else {
            // Si la caja está cerrada, tomamos el saldo de la última sesión cerrada o saldo registrado
            $last_session = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $sessions_table WHERE cash_box_id = %s ORDER BY id DESC LIMIT 1",
                $cash_box_id
            ), ARRAY_A);
            $current_cash = $last_session ? floatval($last_session['counted_cash'] ?: $last_session['expected_cash']) : 0.00;
        }

        $cash_flow = 0.00;
        $new_balance = $current_cash;

        if ($type === 'cash_in' || $type === 'ingreso') {
            $type = 'cash_in';
            $cash_flow = $amount;
            $new_balance = $current_cash + $amount;

            if ($session_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $sessions_table SET 
                        cash_in = cash_in + %f,
                        expected_cash = opening_cash + cash_sales + tips_cash + (cash_in + %f) - cash_out
                    WHERE id = %d",
                    $amount, $amount, $session_id
                ));
            }
        } elseif ($type === 'cash_out' || $type === 'retiro') {
            $type = 'cash_out';
            $cash_flow = -$amount;
            $new_balance = max(0, $current_cash - $amount);

            if ($session_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $sessions_table SET 
                        cash_out = cash_out + %f,
                        expected_cash = opening_cash + cash_sales + tips_cash + cash_in - (cash_out + %f)
                    WHERE id = %d",
                    $amount, $amount, $session_id
                ));
            }
        } elseif ($type === 'adjustment' || $type === 'ajuste') {
            $type = 'adjustment';
            $cash_flow = $amount - $current_cash;
            $new_balance = $amount;

            if ($session_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $sessions_table SET expected_cash = %f WHERE id = %d",
                    $amount, $session_id
                ));
            }
        }

        // Registrar en caja_movements
        $movement_id = self::record_movement(array(
            'cash_box_id'    => $box['id'],
            'cash_box_name'  => $box['name'],
            'session_id'     => $session_id,
            'session_code'   => $session_code,
            'user_id'        => $user_id,
            'user_name'      => $user_name,
            'type'           => $type,
            'concept'        => $concept,
            'amount'         => $amount,
            'cash_flow'      => $cash_flow,
            'payment_method' => 'efectivo',
            'balance_after'  => $new_balance,
            'metadata'       => array('notes' => sanitize_textarea_field($notes)),
            'created_at'     => current_time('mysql'),
        ));

        return array(
            'movement_id' => $movement_id,
            'new_balance' => $new_balance,
            'cash_flow'   => $cash_flow,
            'type'        => $type,
            'concept'     => $concept,
            'user_name'   => $user_name,
            'created_at'  => current_time('mysql'),
        );
    }

    /**
     * Obtener movimientos de una caja con filtros y desglose
     */
    public static function get_box_movements($cash_box_id, $args = array()) {
        global $wpdb;
        self::check_db_table();

        $table = self::get_movements_table_name();
        $where = array("1=1");
        $values = array();

        if (!empty($cash_box_id) && $cash_box_id !== 'all') {
            $where[] = "cash_box_id = %s";
            $values[] = $cash_box_id;
        }

        if (!empty($args['type']) && $args['type'] !== 'all') {
            $where[] = "type = %s";
            $values[] = $args['type'];
        }

        // Fechas
        $preset = $args['date_preset'] ?? 'today';
        $today = wp_date('Y-m-d');
        if ($preset === 'today') {
            $where[] = "DATE(created_at) = %s";
            $values[] = $today;
        } elseif ($preset === 'yesterday') {
            $where[] = "DATE(created_at) = %s";
            $values[] = wp_date('Y-m-d', strtotime('-1 day'));
        } elseif ($preset === 'week') {
            $where[] = "DATE(created_at) >= %s";
            $values[] = wp_date('Y-m-d', strtotime('-7 days'));
        } elseif ($preset === 'custom' && !empty($args['date_from'])) {
            $where[] = "DATE(created_at) >= %s";
            $values[] = $args['date_from'];
            if (!empty($args['date_to'])) {
                $where[] = "DATE(created_at) <= %s";
                $values[] = $args['date_to'];
            }
        }

        $where_sql = implode(' AND ', $where);
        $limit = isset($args['limit']) ? intval($args['limit']) : 100;
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;

        $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        $values_merged = array_merge($values, array($limit, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values_merged), ARRAY_A);

        // Totales de movimientos para el período seleccionado
        $tot_sql = "SELECT 
            COUNT(id) as total_count,
            SUM(CASE WHEN type = 'cash_in' THEN amount ELSE 0 END) as total_cash_in,
            SUM(CASE WHEN type = 'cash_out' THEN amount ELSE 0 END) as total_cash_out,
            SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END) as total_sales,
            SUM(cash_flow) as net_cash_flow
            FROM $table WHERE $where_sql";
        $tot_row = $wpdb->get_row(!empty($values) ? $wpdb->prepare($tot_sql, $values) : $tot_sql, ARRAY_A);

        $movements = array();

        foreach ($rows as $r) {
            $dt = !empty($r['created_at']) ? new DateTime($r['created_at']) : null;
            $r['time_exact'] = $dt ? $dt->format('H:i:s') : '';
            $r['date_str']   = $dt ? $dt->format('d/m/Y') : '';
            $r['amount']     = floatval($r['amount']);
            $r['cash_flow']  = floatval($r['cash_flow']);
            $r['balance_after'] = floatval($r['balance_after']);
            if (!empty($r['metadata'])) {
                $r['metadata'] = json_decode($r['metadata'], true);
            }
            $movements[] = $r;
        }

        return array(
            'movements' => $movements,
            'totals'    => array(
                'total_count'    => intval($tot_row['total_count'] ?? 0),
                'total_cash_in'  => floatval($tot_row['total_cash_in'] ?? 0),
                'total_cash_out' => floatval($tot_row['total_cash_out'] ?? 0),
                'total_sales'    => floatval($tot_row['total_sales'] ?? 0),
                'net_cash_flow'  => floatval($tot_row['net_cash_flow'] ?? 0),
            )
        );
    }

    /**
     * Obtener el historial detallado de movimientos de una caja con horarios exactos (HH:MM:SS)
     */
    public static function get_session_movements($session_id) {
        global $wpdb;
        $session = self::get_session_by_id($session_id);
        if (!$session) {
            return false;
        }

        $movements_table = self::get_movements_table_name();
        self::check_db_table();

        // 1. Consultar si existen movimientos registrados en la tabla dedicada
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $movements_table WHERE session_id = %d ORDER BY id ASC",
            $session_id
        ), ARRAY_A);

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('America/Argentina/Buenos_Aires');
        $movements = array();

        if (!empty($rows)) {
            foreach ($rows as $r) {
                $dt = !empty($r['created_at']) ? new DateTime($r['created_at']) : null;
                $time_exact = $dt ? $dt->format('H:i:s') : '';
                $date_str   = $dt ? $dt->format('d/m/Y') : '';

                // Desglose de ítems si proviene de una orden
                $items = array();
                $order_id = !empty($r['order_id']) ? intval($r['order_id']) : 0;
                $mode_label = '';
                $sector_name = $session['sector'];

                if ($order_id) {
                    $order = wc_get_order($order_id);
                    if ($order && is_a($order, 'WC_Order')) {
                        $mode = $order->get_meta('_caja_pos_mode') ?: 'compra_realizada';
                        $mode_label = ($mode === 'pedido_despachar') ? __('Take Away / Despacho', 'emp-caja') : __('Venta en Barra', 'emp-caja');
                        $sector_name = $order->get_meta('_caja_gastro_sector') ?: $session['sector'];
                        foreach ($order->get_items() as $item) {
                            if (is_a($item, 'WC_Order_Item_Product')) {
                                $items[] = array(
                                    'name'     => $item->get_name(),
                                    'quantity' => $item->get_quantity(),
                                    'total'    => floatval($item->get_total()),
                                );
                            }
                        }
                    }
                }

                $meta = !empty($r['metadata']) ? json_decode($r['metadata'], true) : array();

                $movements[] = array(
                    'id'             => intval($r['id']),
                    'type'           => $r['type'],
                    'concept'        => $r['concept'],
                    'order_id'       => $order_id,
                    'order_number'   => $order_id ? (wc_get_order($order_id) ? wc_get_order($order_id)->get_order_number() : $order_id) : '',
                    'time_exact'     => $time_exact,
                    'date'           => $date_str,
                    'mode_label'     => $mode_label,
                    'sector'         => $sector_name,
                    'user_name'      => $r['user_name'],
                    'payment_method' => $r['payment_method'],
                    'payment_title'  => ucfirst($r['payment_method']),
                    'is_cash'        => (floatval($r['cash_flow']) != 0 || $r['type'] === 'cash_in' || $r['type'] === 'cash_out' || $r['type'] === 'opening' || $r['type'] === 'closing' || stripos($r['payment_method'], 'efectivo') !== false),
                    'items'          => $items,
                    'total'          => floatval($r['amount']),
                    'cash_flow'      => floatval($r['cash_flow']),
                    'tip'            => floatval($meta['tip'] ?? 0),
                    'balance_after'  => floatval($r['balance_after']),
                    'metadata'       => $meta,
                );
            }
        } else {
            // Fallback para sesiones anteriores al registro en caja_movements (solo órdenes de WooCommerce)
            $orders = wc_get_orders(array(
                'limit'      => -1,
                'orderby'    => 'date',
                'order'      => 'ASC',
                'meta_key'   => '_caja_session_id',
                'meta_value' => $session_id,
                'status'     => array('any'),
            ));

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
     * Obtener todas las Cajas de Dinero con balances de efectivo en vivo, turnos activos y estadísticas
     */
    public static function get_money_boxes_with_balances() {
        global $wpdb;
        self::check_db_table();
        $boxes = self::get_money_boxes();
        $sessions_table = self::get_table_name();
        $movements_table = self::get_movements_table_name();

        $enriched = array();
        $today = wp_date('Y-m-d');

        foreach ($boxes as $box) {
            $box_id = $box['id'];

            // 1. Sesión activa en este momento
            $active_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $sessions_table WHERE cash_box_id = %s AND status = 'open' ORDER BY id DESC LIMIT 1",
                $box_id
            ), ARRAY_A);

            $is_open = !empty($active_row);
            $active_session = null;
            $current_cash = 0.00;

            if ($is_open) {
                $active_session = self::format_session_data($active_row);
                $current_cash = $active_session['expected_cash'];
            } else {
                // Última sesión cerrada para obtener último arqueo
                $last_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $sessions_table WHERE cash_box_id = %s ORDER BY id DESC LIMIT 1",
                    $box_id
                ), ARRAY_A);
                if ($last_row) {
                    $last_formatted = self::format_session_data($last_row);
                    $current_cash = ($last_row['status'] === 'closed') 
                        ? floatval($last_row['counted_cash']) 
                        : floatval($last_formatted['expected_cash']);
                }
            }

            // Si hay un saldo registrado o ajuste en caja_movements
            $last_movement = $wpdb->get_row($wpdb->prepare(
                "SELECT balance_after, created_at FROM $movements_table WHERE cash_box_id = %s ORDER BY id DESC LIMIT 1",
                $box_id
            ), ARRAY_A);
            if ($last_movement && !$is_open) {
                $current_cash = floatval($last_movement['balance_after']);
            }

            // 2. Métricas del día de hoy para esta caja
            $today_stats_row = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    COALESCE(SUM(cash_sales), 0) as cash_sales,
                    COALESCE(SUM(card_sales + transfer_sales), 0) as digital_sales,
                    COALESCE(SUM(total_sales), 0) as total_sales,
                    COALESCE(SUM(cash_in), 0) as cash_in,
                    COALESCE(SUM(cash_out), 0) as cash_out,
                    COALESCE(SUM(tips_cash), 0) as tips_cash
                FROM $sessions_table 
                WHERE cash_box_id = %s AND DATE(opened_at) = %s",
                $box_id, $today
            ), ARRAY_A);

            // 3. Cantidad de movimientos registrados en la tabla permanente
            $mov_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(id) FROM $movements_table WHERE cash_box_id = %s",
                $box_id
            ));

            $box_data = $box;
            $box_data['is_open']         = $is_open;
            $box_data['current_cash']    = floatval($current_cash);
            $box_data['active_session']  = $active_session;
            $box_data['today_stats']     = array(
                'cash_sales'    => floatval($today_stats_row['cash_sales'] ?? 0),
                'digital_sales' => floatval($today_stats_row['digital_sales'] ?? 0),
                'total_sales'   => floatval($today_stats_row['total_sales'] ?? 0),
                'cash_in'       => floatval($today_stats_row['cash_in'] ?? 0),
                'cash_out'      => floatval($today_stats_row['cash_out'] ?? 0),
                'tips_cash'     => floatval($today_stats_row['tips_cash'] ?? 0),
            );
            $box_data['movements_count'] = $mov_count;

            $enriched[] = $box_data;
        }

        return $enriched;
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
