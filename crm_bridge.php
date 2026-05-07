<?php
/**
 * Plugin Name: E2S2 CRM Bridge
 * Description: Dynamically captures all Elementor form submissions and sends them to Zoho CRM. Includes submission logs and admin settings.
 * Version: 2.0
 * Author: Sudharsan
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. ACTIVATION — Create logs table in DB
// ============================================================
register_activation_hook( __FILE__, 'e2s2_create_logs_table' );
function e2s2_create_logs_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        submitted_at DATETIME NOT NULL,
        form_name VARCHAR(255) DEFAULT '',
        course_name VARCHAR(255) DEFAULT '',
        page_url TEXT DEFAULT '',
        payload LONGTEXT DEFAULT '',
        zoho_status VARCHAR(20) DEFAULT 'pending',
        zoho_response LONGTEXT DEFAULT '',
        PRIMARY KEY (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ============================================================
// 2. ADMIN MENU — Settings + Logs
// ============================================================
add_action( 'admin_menu', 'e2s2_admin_menu' );
function e2s2_admin_menu() {
    add_menu_page(
        'E2S2 CRM Bridge',
        'CRM Bridge',
        'manage_options',
        'e2s2-crm-bridge',
        'e2s2_settings_page',
        'dashicons-arrow-right-alt',
        30
    );
    add_submenu_page(
        'e2s2-crm-bridge',
        'Settings',
        'Settings',
        'manage_options',
        'e2s2-crm-bridge',
        'e2s2_settings_page'
    );
    add_submenu_page(
        'e2s2-crm-bridge',
        'Submission Logs',
        'Submission Logs',
        'manage_options',
        'e2s2-crm-logs',
        'e2s2_logs_page'
    );
}

// ============================================================
// 3. REGISTER SETTINGS
// ============================================================
add_action( 'admin_init', 'e2s2_register_settings' );
function e2s2_register_settings() {
    register_setting( 'e2s2-settings-group', 'e2s2_zoho_url' );
    register_setting( 'e2s2-settings-group', 'e2s2_zoho_enabled' );
}

// ============================================================
// 4. SETTINGS PAGE
// ============================================================
function e2s2_settings_page() {
    $zoho_url     = get_option( 'e2s2_zoho_url', '' );
    $zoho_enabled = get_option( 'e2s2_zoho_enabled', '1' );
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap');
        #e2s2-wrap * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
        #e2s2-wrap { max-width: 780px; margin: 30px 20px; }
        #e2s2-wrap h1 { font-size: 22px; font-weight: 600; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        #e2s2-wrap h1 span.badge { font-size: 11px; background: #22c55e; color: white; padding: 2px 9px; border-radius: 20px; font-weight: 500; vertical-align: middle; }
        .e2s2-card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 28px 32px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .e2s2-card h2 { font-size: 15px; font-weight: 600; color: #0f172a; margin: 0 0 18px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9; }
        .e2s2-field { margin-bottom: 20px; }
        .e2s2-field label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        .e2s2-field input[type="text"] { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: 'DM Mono', monospace; color: #0f172a; transition: border .2s; outline: none; }
        .e2s2-field input[type="text"]:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
        .e2s2-field .hint { font-size: 12px; color: #6b7280; margin-top: 5px; }
        .e2s2-toggle { display: flex; align-items: center; gap: 12px; }
        .e2s2-toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: #6366f1; }
        .e2s2-btn { background: #6366f1; color: white; border: none; padding: 10px 22px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .2s; }
        .e2s2-btn:hover { background: #4f46e5; }
        .e2s2-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .e2s2-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; text-align: center; }
        .e2s2-stat .num { font-size: 28px; font-weight: 700; color: #6366f1; }
        .e2s2-stat .lbl { font-size: 12px; color: #64748b; margin-top: 3px; }
        .notice-e2s2 { background: #f0fdf4; border-left: 4px solid #22c55e; padding: 10px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; color: #166534; }
    </style>

    <div id="e2s2-wrap">
        <h1>⚡ E2S2 CRM Bridge <span class="badge">v2.0</span></h1>
        <p style="color:#64748b;margin-bottom:24px;font-size:13px;">Dynamically captures all Elementor form submissions and routes them to Zoho CRM.</p>

        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'e2s2_crm_logs';
        $total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $success = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE zoho_status='success'");
        $failed  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE zoho_status='failed'");
        ?>

        <div class="e2s2-card">
            <h2>📊 Submission Overview</h2>
            <div class="e2s2-stats">
                <div class="e2s2-stat"><div class="num"><?php echo $total; ?></div><div class="lbl">Total Submissions</div></div>
                <div class="e2s2-stat"><div class="num" style="color:#22c55e"><?php echo $success; ?></div><div class="lbl">Sent to Zoho ✓</div></div>
                <div class="e2s2-stat"><div class="num" style="color:#ef4444"><?php echo $failed; ?></div><div class="lbl">Failed</div></div>
            </div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('e2s2-settings-group'); ?>

            <div class="e2s2-card">
                <h2>🔗 Zoho CRM Connection</h2>

                <?php if ( isset($_GET['settings-updated']) ) : ?>
                    <div class="notice-e2s2">✅ Settings saved successfully!</div>
                <?php endif; ?>

                <div class="e2s2-field">
                    <label>Zoho Function REST URL <span style="color:#ef4444">*</span></label>
                    <input type="text" name="e2s2_zoho_url"
                        value="<?php echo esc_attr($zoho_url); ?>"
                        placeholder="https://www.zohoapis.in/crm/v7/functions/your_function/actions/execute?auth_type=apikey&zapikey=..." />
                    <div class="hint">Paste the full REST URL including the zapikey from your Zoho CRM function settings.</div>
                </div>

                <div class="e2s2-field">
                    <div class="e2s2-toggle">
                        <input type="checkbox" name="e2s2_zoho_enabled" id="e2s2_zoho_enabled" value="1" <?php checked($zoho_enabled, '1'); ?> />
                        <label for="e2s2_zoho_enabled" style="margin:0;font-size:13px;">Enable Zoho CRM sync</label>
                    </div>
                </div>

                <?php submit_button('Save Settings', 'primary', 'submit', false, ['class' => 'e2s2-btn', 'style' => 'margin-top:8px']); ?>
            </div>
        </form>

        <div class="e2s2-card" style="background:#fffbeb;border-color:#fde68a;">
            <h2 style="border-color:#fde68a;">📋 How This Works</h2>
            <ol style="font-size:13px;color:#374151;line-height:1.8;margin:0;padding-left:18px;">
                <li>This plugin automatically hooks into <strong>all Elementor forms</strong> on your site — no configuration per form needed.</li>
                <li>When any form is submitted, it captures <strong>all field labels + values</strong> dynamically.</li>
                <li>The payload is sent to your Zoho function which creates a <strong>Lead record</strong>.</li>
                <li>Every submission is <strong>logged</strong> — go to <a href="<?php echo admin_url('admin.php?page=e2s2-crm-logs'); ?>">Submission Logs</a> to view them.</li>
                <li>The <strong>Course Name field</strong> in each form auto-identifies which course the lead came from.</li>
            </ol>
        </div>
    </div>
    <?php
}

// ============================================================
// 5. LOGS PAGE
// ============================================================
function e2s2_logs_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';

    // Handle delete all
    if ( isset($_POST['e2s2_clear_logs']) && check_admin_referer('e2s2_clear_logs_nonce') ) {
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="notice notice-success"><p>All logs cleared.</p></div>';
    }

    // Pagination
    $per_page    = 20;
    $current_page = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
    $offset      = ($current_page - 1) * $per_page;
    $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $total_pages = ceil($total_items / $per_page);

    // Filter by status
    $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $where = $filter_status ? $wpdb->prepare("WHERE zoho_status = %s", $filter_status) : '';

    $logs = $wpdb->get_results("SELECT * FROM $table $where ORDER BY submitted_at DESC LIMIT $per_page OFFSET $offset");
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400&display=swap');
        #e2s2-logs * { box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
        #e2s2-logs { max-width: 1100px; margin: 30px 20px; }
        #e2s2-logs h1 { font-size: 22px; font-weight: 600; color: #0f172a; margin-bottom: 20px; }
        .e2s2-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .e2s2-filters a { font-size: 13px; padding: 6px 14px; border-radius: 6px; text-decoration: none; color: #374151; border: 1px solid #e2e8f0; margin-right: 6px; background: white; }
        .e2s2-filters a.active, .e2s2-filters a:hover { background: #6366f1; color: white; border-color: #6366f1; }
        .e2s2-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-size: 13px; }
        .e2s2-table th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; color: #374151; border-bottom: 1px solid #e2e8f0; }
        .e2s2-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #374151; vertical-align: top; }
        .e2s2-table tr:last-child td { border-bottom: none; }
        .e2s2-table tr:hover td { background: #fafafa; }
        .badge-success { background: #dcfce7; color: #166534; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-failed { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fef9c3; color: #713f12; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .payload-toggle { color: #6366f1; cursor: pointer; font-size: 12px; text-decoration: underline; background: none; border: none; padding: 0; font-family: inherit; }
        .payload-box { display: none; background: #1e293b; color: #94a3b8; padding: 12px; border-radius: 8px; font-size: 11px; font-family: 'DM Mono', monospace; margin-top: 8px; white-space: pre-wrap; max-height: 200px; overflow-y: auto; line-height: 1.6; }
        .e2s2-clear-btn { background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .e2s2-clear-btn:hover { background: #dc2626; }
        .e2s2-pagination { margin-top: 16px; display: flex; gap: 6px; }
        .e2s2-pagination a, .e2s2-pagination span { padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; text-decoration: none; color: #374151; background: white; }
        .e2s2-pagination .current { background: #6366f1; color: white; border-color: #6366f1; }
        .mono { font-family: 'DM Mono', monospace; font-size: 12px; color: #6366f1; }
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state .icon { font-size: 40px; margin-bottom: 12px; }
    </style>

    <div id="e2s2-logs">
        <h1>📋 Submission Logs</h1>

        <div class="e2s2-toolbar">
            <div class="e2s2-filters">
                <a href="?page=e2s2-crm-logs" class="<?php echo !$filter_status ? 'active' : ''; ?>">All (<?php echo $total_items; ?>)</a>
                <a href="?page=e2s2-crm-logs&status=success" class="<?php echo $filter_status === 'success' ? 'active' : ''; ?>">✅ Success</a>
                <a href="?page=e2s2-crm-logs&status=failed" class="<?php echo $filter_status === 'failed' ? 'active' : ''; ?>">❌ Failed</a>
                <a href="?page=e2s2-crm-logs&status=pending" class="<?php echo $filter_status === 'pending' ? 'active' : ''; ?>">⏳ Pending</a>
            </div>
            <form method="post" onsubmit="return confirm('Clear all logs? This cannot be undone.')">
                <?php wp_nonce_field('e2s2_clear_logs_nonce'); ?>
                <button type="submit" name="e2s2_clear_logs" class="e2s2-clear-btn">🗑 Clear All Logs</button>
            </form>
        </div>

        <?php if ( empty($logs) ) : ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No submissions yet. Once forms are submitted, they'll appear here.</p>
            </div>
        <?php else : ?>
        <table class="e2s2-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date & Time</th>
                    <th>Form Name</th>
                    <th>Course</th>
                    <th>Status</th>
                    <th>Payload</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log) :
                $payload_pretty = '';
                $payload_data = json_decode($log->payload, true);
                if ($payload_data) {
                    $payload_pretty = json_encode($payload_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                } else {
                    $payload_pretty = $log->payload;
                }
            ?>
                <tr>
                    <td class="mono"><?php echo esc_html($log->id); ?></td>
                    <td style="white-space:nowrap;color:#64748b;font-size:12px;">
                        <?php echo esc_html(date('d M Y', strtotime($log->submitted_at))); ?><br>
                        <span style="color:#94a3b8"><?php echo esc_html(date('h:i A', strtotime($log->submitted_at))); ?></span>
                    </td>
                    <td><strong><?php echo esc_html($log->form_name ?: '—'); ?></strong></td>
                    <td class="mono" style="font-size:11px;"><?php echo esc_html($log->course_name ?: '—'); ?></td>
                    <td>
                        <?php if ($log->zoho_status === 'success') : ?>
                            <span class="badge-success">✓ Success</span>
                        <?php elseif ($log->zoho_status === 'failed') : ?>
                            <span class="badge-failed">✗ Failed</span>
                            <?php if ($log->zoho_response) : ?>
                                <div style="font-size:11px;color:#ef4444;margin-top:4px;"><?php echo esc_html(substr($log->zoho_response, 0, 80)); ?></div>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="badge-pending">⏳ Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="payload-toggle" onclick="togglePayload(<?php echo $log->id; ?>)">View data</button>
                        <div class="payload-box" id="payload-<?php echo $log->id; ?>"><?php echo esc_html($payload_pretty); ?></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) : ?>
        <div class="e2s2-pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
                <?php if ($i === $current_page) : ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else : ?>
                    <a href="?page=e2s2-crm-logs&paged=<?php echo $i; ?><?php echo $filter_status ? '&status='.$filter_status : ''; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
    function togglePayload(id) {
        var box = document.getElementById('payload-' + id);
        box.style.display = box.style.display === 'block' ? 'none' : 'block';
    }
    </script>
    <?php
}

// ============================================================
// 6. CORE HOOK — Intercept Elementor Form Submission
// ============================================================
add_action( 'elementor_pro/forms/new_record', 'e2s2_handle_elementor_form', 10, 2 );
function e2s2_handle_elementor_form( $record, $handler ) {

    $zoho_enabled = get_option('e2s2_zoho_enabled', '1');
    $zoho_url     = get_option('e2s2_zoho_url', '');

    // Build dynamic payload from form labels
    $form_data   = [];
    $course_name = '';
    $raw_fields  = $record->get('fields');

    foreach ( $raw_fields as $field ) {
        // Normalize label to snake_case key
        $key   = strtolower( trim( $field['title'] ) );
        $key   = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key   = trim($key, '_');
        $value = sanitize_text_field( $field['value'] );

        $form_data[ $key ] = $value;

        // Capture course name
        if ( in_array($key, ['course_name', 'course', 'coursename']) ) {
            $course_name = $value;
        }
    }

    // Get form metadata
    $form_name    = $record->get_form_settings('form_name') ?: 'Unknown Form';
    $page_url     = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';

    // Add metadata to payload
    $form_data['_form_name'] = $form_name;
    $form_data['_page_url']  = $page_url;
    $form_data['_submitted_at'] = current_time('Y-m-d H:i:s');

    // Save to logs immediately
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';
    $log_id = null;

    $wpdb->insert($table, [
        'submitted_at' => current_time('mysql'),
        'form_name'    => $form_name,
        'course_name'  => $course_name,
        'page_url'     => $page_url,
        'payload'      => json_encode($form_data),
        'zoho_status'  => 'pending',
    ]);
    $log_id = $wpdb->insert_id;

    // Send to Zoho if enabled
    if ( $zoho_enabled && ! empty($zoho_url) ) {
        e2s2_send_to_zoho( $zoho_url, $form_data, $log_id );
    }
}

// ============================================================
// 7. SEND TO ZOHO
// ============================================================
function e2s2_send_to_zoho( $zoho_url, $form_data, $log_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';

    // Zoho expects arguments as JSON in query string
    $final_url = $zoho_url . '&arguments=' . urlencode( json_encode(['formData' => $form_data]) );

    $response = wp_remote_post( $final_url, [
        'method'  => 'POST',
        'timeout' => 45,
        'headers' => ['Content-Type' => 'application/json'],
    ]);

    if ( is_wp_error($response) ) {
        $wpdb->update($table, [
            'zoho_status'   => 'failed',
            'zoho_response' => $response->get_error_message(),
        ], ['id' => $log_id]);
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);

    $wpdb->update($table, [
        'zoho_status'   => ($code >= 200 && $code < 300) ? 'success' : 'failed',
        'zoho_response' => $body,
    ], ['id' => $log_id]);
}
