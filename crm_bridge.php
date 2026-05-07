Copy

<?php
/**
 * Plugin Name: E2S2 CRM Bridge
 * Description: Dynamically captures all Elementor form submissions and sends them to Zoho CRM and/or Salesforce CRM. Includes submission logs, admin settings, test connection, and retry.
 * Version: 3.1
 * Author: Sudharsan
 */

if (!defined('ABSPATH'))
    exit;

// ============================================================
// 1. ACTIVATION — Create logs table in DB
// ============================================================
register_activation_hook(__FILE__, 'e2s2_create_logs_table');
function e2s2_create_logs_table()
{
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        submitted_at    DATETIME            NOT NULL,
        form_name       VARCHAR(255)        DEFAULT '',
        course_name     VARCHAR(255)        DEFAULT '',
        page_url        TEXT                DEFAULT '',
        payload         LONGTEXT            DEFAULT '',
        zoho_status     VARCHAR(20)         DEFAULT 'pending',
        zoho_response   LONGTEXT            DEFAULT '',
        sf_status       VARCHAR(20)         DEFAULT 'pending',
        sf_response     LONGTEXT            DEFAULT '',
        retry_count     TINYINT(3) UNSIGNED DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ============================================================
// 2. UPGRADE — Add new columns to existing table safely
// ============================================================
add_action('plugins_loaded', 'e2s2_maybe_upgrade_table');
function e2s2_maybe_upgrade_table()
{
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';

    // Table might not exist yet (pre-activation state) — bail safely
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if (!$exists)
        return;

    $cols = $wpdb->get_col("SHOW COLUMNS FROM $table");

    if (!in_array('sf_status', $cols)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN sf_status VARCHAR(20) DEFAULT 'pending' AFTER zoho_response");
    }
    if (!in_array('sf_response', $cols)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN sf_response LONGTEXT DEFAULT '' AFTER sf_status");
    }
    if (!in_array('retry_count', $cols)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN retry_count TINYINT(3) UNSIGNED DEFAULT 0 AFTER sf_response");
    }
}

// ============================================================
// 3. ADMIN MENU
// ============================================================
add_action('admin_menu', 'e2s2_admin_menu');
function e2s2_admin_menu()
{
    add_menu_page(
        'E2S2 CRM Bridge',
        'CRM Bridge',
        'manage_options',
        'e2s2-crm-bridge',
        'e2s2_settings_page',
        'dashicons-arrow-right-alt',
        30
    );
    add_submenu_page('e2s2-crm-bridge', 'Settings', 'Settings', 'manage_options', 'e2s2-crm-bridge', 'e2s2_settings_page');
    add_submenu_page('e2s2-crm-bridge', 'Submission Logs', 'Submission Logs', 'manage_options', 'e2s2-crm-logs', 'e2s2_logs_page');
}

// ============================================================
// 4. REGISTER SETTINGS
// ============================================================
add_action('admin_init', 'e2s2_register_settings');
function e2s2_register_settings()
{
    $fields = [
        'e2s2_zoho_url',
        'e2s2_zoho_enabled',
        'e2s2_sf_enabled',
        'e2s2_sf_client_id',
        'e2s2_sf_client_secret',
        'e2s2_sf_username',
        'e2s2_sf_password',
        'e2s2_sf_security_token',
        'e2s2_sf_instance_url',
    ];
    foreach ($fields as $f) {
        register_setting('e2s2-settings-group', $f);
    }
}

// ============================================================
// 5. AJAX — Test Salesforce connection
// ============================================================
add_action('wp_ajax_e2s2_test_sf', 'e2s2_ajax_test_sf');
function e2s2_ajax_test_sf()
{
    check_ajax_referer('e2s2_test_sf_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_die('Unauthorized');

    // Clear cached token so we always do a live test
    delete_option('e2s2_sf_access_token');
    delete_option('e2s2_sf_token_expiry');

    $token = e2s2_get_sf_token();
    if (is_wp_error($token)) {
        wp_send_json_error(['message' => $token->get_error_message()]);
    }

    // Try a lightweight describe call to verify token + permissions
    $instance_url = rtrim(get_option('e2s2_sf_instance_url', ''), '/');
    $resp = wp_remote_get($instance_url . '/services/data/v59.0/sobjects/Lead/describe/', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['message' => 'Token OK but describe failed: ' . $resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    if ($code === 200) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $field_count = isset($body['fields']) ? count($body['fields']) : '?';
        wp_send_json_success([
            'message' => '✅ Connected successfully! Lead object has ' . $field_count . ' fields.',
            'instance' => $instance_url,
        ]);
    } else {
        wp_send_json_error(['message' => 'HTTP ' . $code . ' — ' . wp_remote_retrieve_body($resp)]);
    }
}

// ============================================================
// 6. AJAX — Retry a failed submission
// ============================================================
add_action('wp_ajax_e2s2_retry', 'e2s2_ajax_retry');
function e2s2_ajax_retry()
{
    check_ajax_referer('e2s2_retry_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_die('Unauthorized');

    $id = (int) $_POST['log_id'];
    $crm = sanitize_text_field($_POST['crm']); // 'zoho' or 'sf'

    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';
    $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

    if (!$log) {
        wp_send_json_error(['message' => 'Log entry not found.']);
    }

    $form_data = json_decode($log->payload, true);
    if (!$form_data) {
        wp_send_json_error(['message' => 'Payload could not be decoded.']);
    }

    // Increment retry counter
    $wpdb->update($table, ['retry_count' => (int) $log->retry_count + 1], ['id' => $id]);

    if ($crm === 'zoho') {
        $zoho_url = get_option('e2s2_zoho_url', '');
        if (empty($zoho_url)) {
            wp_send_json_error(['message' => 'Zoho URL not configured.']);
        }
        e2s2_send_to_zoho($zoho_url, $form_data, $id);
        $updated = $wpdb->get_row($wpdb->prepare("SELECT zoho_status, zoho_response FROM $table WHERE id = %d", $id));
        wp_send_json_success(['status' => $updated->zoho_status, 'response' => $updated->zoho_response]);
    } elseif ($crm === 'sf') {
        e2s2_send_to_salesforce($form_data, $id);
        $updated = $wpdb->get_row($wpdb->prepare("SELECT sf_status, sf_response FROM $table WHERE id = %d", $id));
        wp_send_json_success(['status' => $updated->sf_status, 'response' => $updated->sf_response]);
    } else {
        wp_send_json_error(['message' => 'Unknown CRM target.']);
    }
}

// ============================================================
// 7. SETTINGS PAGE
// ============================================================
function e2s2_settings_page()
{
    $zoho_url = get_option('e2s2_zoho_url', '');
    $zoho_enabled = get_option('e2s2_zoho_enabled', '1');
    $sf_enabled = get_option('e2s2_sf_enabled', '0');
    $sf_client_id = get_option('e2s2_sf_client_id', '');
    $sf_client_secret = get_option('e2s2_sf_client_secret', '');
    $sf_username = get_option('e2s2_sf_username', '');
    $sf_password = get_option('e2s2_sf_password', '');
    $sf_security_token = get_option('e2s2_sf_security_token', '');
    $sf_instance_url = get_option('e2s2_sf_instance_url', 'https://login.salesforce.com');

    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $z_ok = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE zoho_status='success'");
    $z_fail = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE zoho_status='failed'");
    $sf_ok = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE sf_status='success'");
    $sf_fail = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE sf_status='failed'");
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap');

        #e2s2-wrap * {
            box-sizing: border-box;
            font-family: 'DM Sans', sans-serif;
        }

        #e2s2-wrap {
            max-width: 840px;
            margin: 30px 20px;
        }

        #e2s2-wrap h1 {
            font-size: 22px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #e2s2-wrap h1 span.badge {
            font-size: 11px;
            background: #22c55e;
            color: white;
            padding: 2px 9px;
            border-radius: 20px;
            font-weight: 500;
        }

        .e2s2-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 32px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .04);
        }

        .e2s2-card h2 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .e2s2-field {
            margin-bottom: 18px;
        }

        .e2s2-field label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }

        .e2s2-field input[type="text"],
        .e2s2-field input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            font-family: 'DM Mono', monospace;
            color: #0f172a;
            transition: border .2s;
            outline: none;
        }

        .e2s2-field input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .1);
        }

        .e2s2-field .hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .e2s2-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .e2s2-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #6366f1;
        }

        .e2s2-btn {
            background: #6366f1;
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }

        .e2s2-btn:hover {
            background: #4f46e5;
        }

        .e2s2-btn-sf {
            background: #00A1E0;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }

        .e2s2-btn-sf:hover {
            background: #0077B6;
        }

        .e2s2-grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .e2s2-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }

        .e2s2-stat {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            text-align: center;
        }

        .e2s2-stat .num {
            font-size: 24px;
            font-weight: 700;
            color: #6366f1;
        }

        .e2s2-stat .lbl {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }

        .notice-e2s2 {
            background: #f0fdf4;
            border-left: 4px solid #22c55e;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #166534;
        }

        .sf-card {
            border-color: #00A1E0;
        }

        .sf-card h2 {
            color: #00A1E0;
        }

        .crm-pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            vertical-align: middle;
            margin-left: 6px;
        }

        .pill-zoho {
            background: #ff6a00;
            color: white;
        }

        .pill-sf {
            background: #00A1E0;
            color: white;
        }

        #sf-test-result {
            display: none;
            margin-top: 12px;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
        }

        #sf-test-result.ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        #sf-test-result.fail {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 14px 18px;
            font-size: 12px;
            color: #1e40af;
            margin-top: 4px;
            line-height: 1.7;
        }

        .warn-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 14px 18px;
            font-size: 12px;
            color: #78350f;
            margin-top: 12px;
            line-height: 1.7;
        }

        .custom-fields-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 8px;
        }

        .custom-fields-table th {
            background: #f8fafc;
            padding: 7px 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border: 1px solid #e2e8f0;
        }

        .custom-fields-table td {
            padding: 7px 12px;
            border: 1px solid #f1f5f9;
            color: #374151;
            font-family: 'DM Mono', monospace;
            font-size: 11px;
        }

        .custom-fields-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .api-badge {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            font-family: 'DM Mono', monospace;
        }
    </style>

    <div id="e2s2-wrap">
        <h1>⚡ E2S2 CRM Bridge <span class="badge">v3.1</span></h1>
        <p style="color:#64748b;margin-bottom:24px;font-size:13px;">
            Captures all Elementor form submissions → routes to <strong>Zoho CRM</strong> and/or <strong>Salesforce
                CRM</strong>.
        </p>

        <!-- Stats -->
        <div class="e2s2-card">
            <h2>📊 Submission Overview</h2>
            <div class="e2s2-stats">
                <div class="e2s2-stat">
                    <div class="num">
                        <?php echo $total; ?>
                    </div>
                    <div class="lbl">Total</div>
                </div>
                <div class="e2s2-stat">
                    <div class="num" style="color:#ff6a00">
                        <?php echo $z_ok; ?>
                    </div>
                    <div class="lbl">Zoho ✓</div>
                </div>
                <div class="e2s2-stat">
                    <div class="num" style="color:#ef4444">
                        <?php echo $z_fail; ?>
                    </div>
                    <div class="lbl">Zoho ✗</div>
                </div>
                <div class="e2s2-stat">
                    <div class="num" style="color:#00A1E0">
                        <?php echo $sf_ok; ?>
                    </div>
                    <div class="lbl">Salesforce ✓</div>
                </div>
                <div class="e2s2-stat">
                    <div class="num" style="color:#ef4444">
                        <?php echo $sf_fail; ?>
                    </div>
                    <div class="lbl">Salesforce ✗</div>
                </div>
            </div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('e2s2-settings-group'); ?>

            <!-- ── ZOHO ── -->
            <div class="e2s2-card">
                <h2>🟠 Zoho CRM Connection <span class="crm-pill pill-zoho">ZOHO</span></h2>

                <?php if (isset($_GET['settings-updated'])): ?>
                    <div class="notice-e2s2">✅ Settings saved successfully!</div>
                <?php endif; ?>

                <div class="e2s2-field">
                    <label>Zoho Deluge Function REST URL <span style="color:#ef4444">*</span></label>
                    <input type="text" name="e2s2_zoho_url" value="<?php echo esc_attr($zoho_url); ?>"
                        placeholder="https://www.zohoapis.in/crm/v7/functions/your_function/actions/execute?auth_type=apikey&zapikey=..." />
                    <div class="hint">Full REST URL from Zoho CRM → Functions → your function → REST API → includes zapikey.
                    </div>
                </div>

                <div class="e2s2-field">
                    <div class="e2s2-toggle">
                        <input type="checkbox" name="e2s2_zoho_enabled" id="e2s2_zoho_enabled" value="1" <?php checked($zoho_enabled, '1'); ?> />
                        <label for="e2s2_zoho_enabled" style="margin:0;font-size:13px;">Enable Zoho CRM sync</label>
                    </div>
                </div>
            </div>

            <!-- ── SALESFORCE ── -->
            <div class="e2s2-card sf-card">
                <h2>🔵 Salesforce CRM Connection <span class="crm-pill pill-sf">SALESFORCE</span></h2>
                <p style="font-size:12px;color:#64748b;margin-bottom:18px;">
                    Uses a <strong>Connected App</strong> with OAuth2 Username–Password flow to POST Leads directly via the
                    Salesforce REST API.
                    <a href="https://help.salesforce.com/s/articleView?id=sf.connected_app_create.htm" target="_blank"
                        style="color:#00A1E0;">How to create a Connected App →</a>
                </p>

                <div class="e2s2-field">
                    <div class="e2s2-toggle">
                        <input type="checkbox" name="e2s2_sf_enabled" id="e2s2_sf_enabled" value="1" <?php checked($sf_enabled, '1'); ?> />
                        <label for="e2s2_sf_enabled" style="margin:0;font-size:13px;">Enable Salesforce CRM sync</label>
                    </div>
                </div>

                <div class="e2s2-grid2">
                    <div class="e2s2-field">
                        <label>Consumer Key (Client ID) <span style="color:#ef4444">*</span></label>
                        <input type="text" name="e2s2_sf_client_id" value="<?php echo esc_attr($sf_client_id); ?>"
                            placeholder="3MVG9..." />
                        <div class="hint">Connected App → Manage Consumer Details</div>
                    </div>
                    <div class="e2s2-field">
                        <label>Consumer Secret (Client Secret) <span style="color:#ef4444">*</span></label>
                        <input type="password" name="e2s2_sf_client_secret"
                            value="<?php echo esc_attr($sf_client_secret); ?>" placeholder="••••••••" />
                        <div class="hint">Shown once in Connected App — save it immediately</div>
                    </div>
                    <div class="e2s2-field">
                        <label>Salesforce Username <span style="color:#ef4444">*</span></label>
                        <input type="text" name="e2s2_sf_username" value="<?php echo esc_attr($sf_username); ?>"
                            placeholder="you@yourdomain.com" />
                        <div class="hint">Your Salesforce login email</div>
                    </div>
                    <div class="e2s2-field">
                        <label>Salesforce Password <span style="color:#ef4444">*</span></label>
                        <input type="password" name="e2s2_sf_password" value="<?php echo esc_attr($sf_password); ?>"
                            placeholder="••••••••" />
                        <div class="hint">Your Salesforce login password</div>
                    </div>
                    <div class="e2s2-field">
                        <label>Security Token</label>
                        <input type="password" name="e2s2_sf_security_token"
                            value="<?php echo esc_attr($sf_security_token); ?>"
                            placeholder="Token from Salesforce profile email" />
                        <div class="hint">Profile icon → Settings → Reset My Security Token (emailed to you)</div>
                    </div>
                    <div class="e2s2-field">
                        <label>Login / Instance URL</label>
                        <input type="text" name="e2s2_sf_instance_url" value="<?php echo esc_attr($sf_instance_url); ?>"
                            placeholder="https://login.salesforce.com" />
                        <div class="hint">Use <code>https://test.salesforce.com</code> for sandbox orgs</div>
                    </div>
                </div>

                <!-- Test Connection -->
                <div style="margin-top:4px;">
                    <button type="button" class="e2s2-btn-sf" id="sf-test-btn" onclick="e2s2TestSF()">
                        🔌 Test Salesforce Connection
                    </button>
                    <span id="sf-test-spinner"
                        style="display:none;margin-left:10px;font-size:12px;color:#64748b;">Testing…</span>
                    <div id="sf-test-result"></div>
                </div>

                <div class="info-box" style="margin-top:16px;">
                    <strong>🔐 How authentication works:</strong><br>
                    The plugin exchanges your credentials for a short-lived OAuth Bearer token via Salesforce's token
                    endpoint.
                    The token is cached in <code>wp_options</code> for 50 minutes and auto-refreshed.
                    Your password is stored in the WordPress database (same as all plugin settings) — use a dedicated
                    integration user account for best security.
                </div>
            </div>

            <?php submit_button('Save All Settings', 'primary', 'submit', false, ['class' => 'e2s2-btn', 'style' => 'margin-top:0']); ?>
        </form>

        <!-- Custom Fields Guide -->
        <div class="e2s2-card">
            <h2>🧩 Salesforce Custom Fields Guide</h2>
            <p style="font-size:13px;color:#374151;margin-bottom:16px;">
                The following form fields are captured from your Elementor forms and are ready to map to custom Lead fields
                in Salesforce.
                Go to <strong>Setup → Object Manager → Lead → Fields &amp; Relationships → New</strong> to create them.
                Once created, uncomment the matching lines in <code>e2s2_send_to_salesforce()</code>.
            </p>
            <table class="custom-fields-table">
                <thead>
                    <tr>
                        <th>Form field (snake_case key)</th>
                        <th>Suggested SF Field Label</th>
                        <th>Suggested API Name</th>
                        <th>SF Field Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>name</td>
                        <td>—</td>
                        <td>FirstName / LastName</td>
                        <td>Text</td>
                        <td style="color:#22c55e;font-weight:600;">✓ Standard — auto-mapped</td>
                    </tr>
                    <tr>
                        <td>email</td>
                        <td>—</td>
                        <td>Email</td>
                        <td>Email</td>
                        <td style="color:#22c55e;font-weight:600;">✓ Standard — auto-mapped</td>
                    </tr>
                    <tr>
                        <td>phone</td>
                        <td>—</td>
                        <td>Phone</td>
                        <td>Phone</td>
                        <td style="color:#22c55e;font-weight:600;">✓ Standard — auto-mapped</td>
                    </tr>
                    <tr>
                        <td>course_name</td>
                        <td>—</td>
                        <td>Company (fallback)</td>
                        <td>Text</td>
                        <td style="color:#22c55e;font-weight:600;">✓ Standard — auto-mapped</td>
                    </tr>
                    <tr>
                        <td>_page_url</td>
                        <td>—</td>
                        <td>Website</td>
                        <td>URL</td>
                        <td style="color:#22c55e;font-weight:600;">✓ Standard — auto-mapped</td>
                    </tr>
                    <tr>
                        <td>course_name</td>
                        <td>Course Interested</td>
                        <td><span class="api-badge">Course_Interested__c</span></td>
                        <td>Text (255)</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                    <tr>
                        <td>dob</td>
                        <td>Date of Birth</td>
                        <td><span class="api-badge">Date_of_Birth__c</span></td>
                        <td>Date</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                    <tr>
                        <td>role</td>
                        <td>Role Applied</td>
                        <td><span class="api-badge">Role_Applied__c</span></td>
                        <td>Text (255)</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                    <tr>
                        <td>qualification_expected</td>
                        <td>Qualification Expected</td>
                        <td><span class="api-badge">Qualification_Expected__c</span></td>
                        <td>Text (255)</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                    <tr>
                        <td>placement_required</td>
                        <td>Placement Required</td>
                        <td><span class="api-badge">Placement_Required__c</span></td>
                        <td>Picklist (Yes/No)</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                    <tr>
                        <td>_form_name</td>
                        <td>Form Name</td>
                        <td><span class="api-badge">Form_Name__c</span></td>
                        <td>Text (255)</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                    <tr>
                        <td>_page_url</td>
                        <td>Source Page URL</td>
                        <td><span class="api-badge">Source_Page_URL__c</span></td>
                        <td>URL</td>
                        <td style="color:#f59e0b;font-weight:600;">⚠ Create custom field</td>
                    </tr>
                </tbody>
            </table>
            <div class="warn-box">
                ⚠ <strong>Important:</strong> Do NOT uncomment custom field lines in the plugin until the corresponding
                field exists in Salesforce.
                Sending an unknown field name causes a <code>INVALID_FIELD</code> error and the entire Lead creation will
                fail.
            </div>
        </div>

        <!-- How it works -->
        <div class="e2s2-card" style="background:#fffbeb;border-color:#fde68a;">
            <h2 style="border-color:#fde68a;">📋 How This Works (v3.1)</h2>
            <ol style="font-size:13px;color:#374151;line-height:1.9;margin:0;padding-left:18px;">
                <li>Hooks into <strong>all Elementor forms</strong> automatically — zero per-form configuration needed.</li>
                <li>Each submission is <strong>logged immediately</strong> in the database with a <code>pending</code>
                    status for both CRMs.</li>
                <li>If <strong>Zoho</strong> is enabled → calls your Deluge function via REST URL (same as v2.0 —
                    unchanged).</li>
                <li>If <strong>Salesforce</strong> is enabled → fetches OAuth2 token once, caches it 50 min, then POSTs a
                    Lead via REST API. Duplicate emails → updates existing Lead + adds a note.</li>
                <li>Both CRMs run <strong>simultaneously</strong> — one submission → two CRMs, each logged independently.
                </li>
                <li>Use the <strong>Test Connection</strong> button to verify Salesforce credentials before going live.</li>
                <li>Failed submissions can be <strong>retried individually</strong> from the Logs page — no need to resubmit
                    the form.</li>
                <li>Check <a href="<?php echo admin_url('admin.php?page=e2s2-crm-logs'); ?>">Submission Logs</a> for
                    real-time per-CRM status and error details.</li>
            </ol>
        </div>
    </div>

    <script>
        function e2s2TestSF() {
            var btn = document.getElementById('sf-test-btn');
            var spinner = document.getElementById('sf-test-spinner');
            var result = document.getElementById('sf-test-result');
            btn.disabled = true;
            spinner.style.display = 'inline';
            result.style.display = 'none';

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'e2s2_test_sf',
                    nonce: '<?php echo wp_create_nonce("e2s2_test_sf_nonce"); ?>'
                })
            })
                .then(r => r.json())
                .then(data => {
                    result.style.display = 'block';
                    if (data.success) {
                        result.className = 'ok';
                        result.textContent = data.data.message;
                    } else {
                        result.className = 'fail';
                        result.textContent = '❌ ' + (data.data ? data.data.message : 'Unknown error');
                    }
                })
                .catch(() => {
                    result.style.display = 'block';
                    result.className = 'fail';
                    result.textContent = '❌ Request failed — check your network.';
                })
                .finally(() => {
                    btn.disabled = false;
                    spinner.style.display = 'none';
                });
        }
    </script>
    <?php
}

// ============================================================
// 8. LOGS PAGE
// ============================================================
function e2s2_logs_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';

    // Handle clear all
    if (isset($_POST['e2s2_clear_logs']) && check_admin_referer('e2s2_clear_logs_nonce')) {
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="notice notice-success"><p>All logs cleared.</p></div>';
    }

    // Pagination
    $per_page = 20;
    $current_page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $offset = ($current_page - 1) * $per_page;

    // Filter
    $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $where = '';
    if ($filter_status === 'zoho_success')
        $where = "WHERE zoho_status='success'";
    elseif ($filter_status === 'zoho_failed')
        $where = "WHERE zoho_status='failed'";
    elseif ($filter_status === 'sf_success')
        $where = "WHERE sf_status='success'";
    elseif ($filter_status === 'sf_failed')
        $where = "WHERE sf_status='failed'";

    $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $total_pages = ceil($total_items / $per_page);
    $logs = $wpdb->get_results("SELECT * FROM $table $where ORDER BY submitted_at DESC LIMIT $per_page OFFSET $offset");
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400&display=swap');

        #e2s2-logs * {
            box-sizing: border-box;
            font-family: 'DM Sans', sans-serif;
        }

        #e2s2-logs {
            max-width: 1200px;
            margin: 30px 20px;
        }

        #e2s2-logs h1 {
            font-size: 22px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 20px;
        }

        .e2s2-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .e2s2-filters a {
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 6px;
            text-decoration: none;
            color: #374151;
            border: 1px solid #e2e8f0;
            margin-right: 5px;
            background: white;
            transition: all .15s;
        }

        .e2s2-filters a.active,
        .e2s2-filters a:hover {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
        }

        .e2s2-filters a.sf-filter.active,
        .e2s2-filters a.sf-filter:hover {
            background: #00A1E0;
            border-color: #00A1E0;
        }

        .e2s2-filters a.zoho-filter.active,
        .e2s2-filters a.zoho-filter:hover {
            background: #ff6a00;
            border-color: #ff6a00;
        }

        .e2s2-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            font-size: 12px;
        }

        .e2s2-table th {
            background: #f8fafc;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e2e8f0;
        }

        .e2s2-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #374151;
            vertical-align: top;
        }

        .e2s2-table tr:last-child td {
            border-bottom: none;
        }

        .e2s2-table tr:hover td {
            background: #fafafa;
        }

        .bs {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            display: inline-block;
        }

        .bs-ok {
            background: #dcfce7;
            color: #166534;
        }

        .bs-fail {
            background: #fee2e2;
            color: #991b1b;
        }

        .bs-pend {
            background: #fef9c3;
            color: #713f12;
        }

        .bs-skip {
            background: #f1f5f9;
            color: #94a3b8;
        }

        .payload-toggle {
            color: #6366f1;
            cursor: pointer;
            font-size: 11px;
            text-decoration: underline;
            background: none;
            border: none;
            padding: 0;
            font-family: inherit;
        }

        .payload-box {
            display: none;
            background: #1e293b;
            color: #94a3b8;
            padding: 10px;
            border-radius: 6px;
            font-size: 10px;
            font-family: 'DM Mono', monospace;
            margin-top: 6px;
            white-space: pre-wrap;
            max-height: 180px;
            overflow-y: auto;
        }

        .retry-btn {
            font-size: 10px;
            background: none;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 2px 7px;
            cursor: pointer;
            color: #374151;
            margin-top: 4px;
            transition: all .15s;
        }

        .retry-btn:hover {
            background: #f8fafc;
            border-color: #9ca3af;
        }

        .retry-btn.loading {
            color: #9ca3af;
            cursor: wait;
        }

        .e2s2-clear-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .e2s2-clear-btn:hover {
            background: #dc2626;
        }

        .e2s2-pagination {
            margin-top: 16px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .e2s2-pagination a,
        .e2s2-pagination span {
            padding: 5px 11px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            color: #374151;
            background: white;
        }

        .e2s2-pagination .current {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
        }

        .mono {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            color: #6366f1;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .retry-count {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 2px;
        }
    </style>

    <div id="e2s2-logs">
        <h1>📋 Submission Logs</h1>

        <div class="e2s2-toolbar">
            <div class="e2s2-filters">
                <a href="?page=e2s2-crm-logs" class="<?php echo !$filter_status ? 'active' : ''; ?>">
                    All (
                    <?php echo $total_items; ?>)
                </a>
                <a href="?page=e2s2-crm-logs&status=zoho_success"
                    class="zoho-filter <?php echo $filter_status === 'zoho_success' ? 'active' : ''; ?>">🟠 Zoho ✓</a>
                <a href="?page=e2s2-crm-logs&status=zoho_failed"
                    class="zoho-filter <?php echo $filter_status === 'zoho_failed' ? 'active' : ''; ?>">🟠 Zoho ✗</a>
                <a href="?page=e2s2-crm-logs&status=sf_success"
                    class="sf-filter <?php echo $filter_status === 'sf_success' ? 'active' : ''; ?>">🔵 SF ✓</a>
                <a href="?page=e2s2-crm-logs&status=sf_failed"
                    class="sf-filter <?php echo $filter_status === 'sf_failed' ? 'active' : ''; ?>">🔵 SF ✗</a>
            </div>
            <form method="post" onsubmit="return confirm('Clear all logs? This cannot be undone.')">
                <?php wp_nonce_field('e2s2_clear_logs_nonce'); ?>
                <button type="submit" name="e2s2_clear_logs" class="e2s2-clear-btn">🗑 Clear All Logs</button>
            </form>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div style="font-size:40px">📭</div>
                <p>No submissions yet. Once forms are submitted they'll appear here.</p>
            </div>
        <?php else: ?>
            <table class="e2s2-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>Form</th>
                        <th>Course</th>
                        <th>🟠 Zoho</th>
                        <th>🔵 Salesforce</th>
                        <th>Retries</th>
                        <th>Payload</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $p = json_decode($log->payload, true);
                        $payload_pretty = $p ? json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $log->payload;
                        $z_class = $log->zoho_status === 'success' ? 'bs-ok' : ($log->zoho_status === 'failed' ? 'bs-fail' : ($log->zoho_status === 'skipped' ? 'bs-skip' : 'bs-pend'));
                        $s_class = $log->sf_status === 'success' ? 'bs-ok' : ($log->sf_status === 'failed' ? 'bs-fail' : ($log->sf_status === 'skipped' ? 'bs-skip' : 'bs-pend'));
                        ?>
                        <tr id="log-row-<?php echo $log->id; ?>">
                            <td class="mono">
                                <?php echo esc_html($log->id); ?>
                            </td>
                            <td style="white-space:nowrap;color:#64748b;font-size:11px;">
                                <?php echo esc_html(date('d M Y', strtotime($log->submitted_at))); ?><br>
                                <span style="color:#94a3b8">
                                    <?php echo esc_html(date('h:i A', strtotime($log->submitted_at))); ?>
                                </span>
                            </td>
                            <td><strong>
                                    <?php echo esc_html($log->form_name ?: '—'); ?>
                                </strong></td>
                            <td class="mono" style="font-size:11px;">
                                <?php echo esc_html($log->course_name ?: '—'); ?>
                            </td>

                            <!-- Zoho status cell -->
                            <td>
                                <span class="bs <?php echo $z_class; ?>" id="zoho-badge-<?php echo $log->id; ?>">
                                    <?php echo strtoupper($log->zoho_status ?: 'pending'); ?>
                                </span>
                                <?php if ($log->zoho_status === 'failed' && $log->zoho_response): ?>
                                    <div style="font-size:10px;color:#ef4444;margin-top:3px;">
                                        <?php echo esc_html(substr($log->zoho_response, 0, 80)); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($log->zoho_status === 'failed'): ?>
                                    <button class="retry-btn" id="retry-zoho-<?php echo $log->id; ?>"
                                        onclick="e2s2Retry(<?php echo $log->id; ?>, 'zoho', this)">↺ Retry Zoho</button>
                                <?php endif; ?>
                            </td>

                            <!-- Salesforce status cell -->
                            <td>
                                <span class="bs <?php echo $s_class; ?>" id="sf-badge-<?php echo $log->id; ?>">
                                    <?php echo strtoupper($log->sf_status ?: 'pending'); ?>
                                </span>
                                <?php if ($log->sf_status === 'failed' && $log->sf_response): ?>
                                    <div style="font-size:10px;color:#ef4444;margin-top:3px;">
                                        <?php echo esc_html(substr($log->sf_response, 0, 80)); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($log->sf_status === 'failed'): ?>
                                    <button class="retry-btn" id="retry-sf-<?php echo $log->id; ?>"
                                        onclick="e2s2Retry(<?php echo $log->id; ?>, 'sf', this)">↺ Retry SF</button>
                                <?php endif; ?>
                            </td>

                            <td class="retry-count">
                                <?php echo (int) $log->retry_count > 0 ? (int) $log->retry_count . 'x' : '—'; ?>
                            </td>

                            <td>
                                <button class="payload-toggle" onclick="togglePayload(<?php echo $log->id; ?>)">View data</button>
                                <div class="payload-box" id="payload-<?php echo $log->id; ?>">
                                    <?php echo esc_html($payload_pretty); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="e2s2-pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i === $current_page): ?>
                            <span class="current">
                                <?php echo $i; ?>
                            </span>
                        <?php else: ?>
                            <a
                                href="?page=e2s2-crm-logs&paged=<?php echo $i; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        var e2s2RetryNonce = '<?php echo wp_create_nonce('e2s2_retry_nonce'); ?>';

        function togglePayload(id) {
            var b = document.getElementById('payload-' + id);
            b.style.display = b.style.display === 'block' ? 'none' : 'block';
        }

        function e2s2Retry(logId, crm, btn) {
            btn.classList.add('loading');
            btn.disabled = true;
            btn.textContent = '↻ Retrying…';

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'e2s2_retry',
                    nonce: e2s2RetryNonce,
                    log_id: logId,
                    crm: crm
                })
            })
                .then(r => r.json())
                .then(data => {
                    var badgeId = (crm === 'zoho' ? 'zoho-badge-' : 'sf-badge-') + logId;
                    var badge = document.getElementById(badgeId);
                    if (data.success) {
                        var st = data.data.status;
                        badge.textContent = st.toUpperCase();
                        badge.className = 'bs ' + (st === 'success' ? 'bs-ok' : 'bs-fail');
                        if (st === 'success') {
                            btn.remove();
                        } else {
                            btn.textContent = '↺ Retry ' + (crm === 'zoho' ? 'Zoho' : 'SF');
                            btn.disabled = false;
                            btn.classList.remove('loading');
                        }
                    } else {
                        btn.textContent = '↺ Retry failed — try again';
                        btn.disabled = false;
                        btn.classList.remove('loading');
                    }
                })
                .catch(() => {
                    btn.textContent = '↺ Network error';
                    btn.disabled = false;
                    btn.classList.remove('loading');
                });
        }
    </script>
    <?php
}

// ============================================================
// 9. CORE HOOK — Intercept Elementor Form Submission
// ============================================================
add_action('elementor_pro/forms/new_record', 'e2s2_handle_elementor_form', 10, 2);
function e2s2_handle_elementor_form($record, $handler)
{

    $zoho_enabled = get_option('e2s2_zoho_enabled', '1');
    $zoho_url = get_option('e2s2_zoho_url', '');
    $sf_enabled = get_option('e2s2_sf_enabled', '0');

    // Build form data map from all field labels → snake_case keys
    $form_data = [];
    $course_name = '';
    $raw_fields = $record->get('fields');

    foreach ($raw_fields as $field) {
        $key = strtolower(trim($field['title']));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        $value = sanitize_text_field($field['value']);

        $form_data[$key] = $value;

        if (in_array($key, ['course_name', 'course', 'coursename'])) {
            $course_name = $value;
        }
    }

    // Metadata
    $form_name = $record->get_form_settings('form_name') ?: 'Unknown Form';
    $page_url = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';

    $form_data['_form_name'] = $form_name;
    $form_data['_page_url'] = $page_url;
    $form_data['_submitted_at'] = current_time('Y-m-d H:i:s');

    // Insert log row immediately
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';

    $wpdb->insert($table, [
        'submitted_at' => current_time('mysql'),
        'form_name' => $form_name,
        'course_name' => $course_name,
        'page_url' => $page_url,
        'payload' => json_encode($form_data),
        'zoho_status' => ($zoho_enabled && !empty($zoho_url)) ? 'pending' : 'skipped',
        'sf_status' => $sf_enabled ? 'pending' : 'skipped',
        'retry_count' => 0,
    ]);
    $log_id = $wpdb->insert_id;

    // Dispatch to CRMs
    if ($zoho_enabled && !empty($zoho_url)) {
        e2s2_send_to_zoho($zoho_url, $form_data, $log_id);
    }

    if ($sf_enabled) {
        e2s2_send_to_salesforce($form_data, $log_id);
    }
}

// ============================================================
// 10. SEND TO ZOHO (unchanged from v2.0)
// ============================================================
function e2s2_send_to_zoho($zoho_url, $form_data, $log_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';
    $final_url = $zoho_url . '&arguments=' . urlencode(json_encode(['formData' => $form_data]));

    $response = wp_remote_post($final_url, [
        'method' => 'POST',
        'timeout' => 45,
        'headers' => ['Content-Type' => 'application/json'],
    ]);

    if (is_wp_error($response)) {
        $wpdb->update($table, [
            'zoho_status' => 'failed',
            'zoho_response' => $response->get_error_message(),
        ], ['id' => $log_id]);
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);

    $wpdb->update($table, [
        'zoho_status' => ($code >= 200 && $code < 300) ? 'success' : 'failed',
        'zoho_response' => $body,
    ], ['id' => $log_id]);
}

// ============================================================
// 11. SALESFORCE — Get OAuth2 Token (cached, auto-refresh)
// ============================================================
function e2s2_get_sf_token()
{
    $cached_token = get_option('e2s2_sf_access_token', '');
    $token_expiry = (int) get_option('e2s2_sf_token_expiry', 0);

    // Return cached token if still valid (50-min window)
    if ($cached_token && time() < $token_expiry) {
        return $cached_token;
    }

    $client_id = get_option('e2s2_sf_client_id', '');
    $client_secret = get_option('e2s2_sf_client_secret', '');
    $username = get_option('e2s2_sf_username', '');
    $password = get_option('e2s2_sf_password', '');
    $security_token = get_option('e2s2_sf_security_token', '');
    $instance_url = rtrim(get_option('e2s2_sf_instance_url', 'https://login.salesforce.com'), '/');

    if (!$client_id || !$client_secret || !$username || !$password) {
        return new WP_Error('sf_config', 'Salesforce credentials incomplete. Check plugin settings.');
    }

    $response = wp_remote_post($instance_url . '/services/oauth2/token', [
        'timeout' => 30,
        'body' => [
            'grant_type' => 'password',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'username' => $username,
            // Salesforce requires password and security token concatenated
            'password' => $password . $security_token,
        ],
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('sf_http', 'Token request failed: ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if (empty($body['access_token'])) {
        $err = isset($body['error_description']) ? $body['error_description'] : wp_remote_retrieve_body($response);
        return new WP_Error('sf_token', 'Token error (' . $code . '): ' . $err);
    }

    // Cache for 50 minutes (Salesforce tokens are valid for ~2 hours)
    update_option('e2s2_sf_access_token', $body['access_token']);
    update_option('e2s2_sf_token_expiry', time() + 3000);

    // Salesforce returns the actual instance URL — always use this one for API calls
    if (!empty($body['instance_url'])) {
        update_option('e2s2_sf_instance_url', $body['instance_url']);
    }

    return $body['access_token'];
}

// ============================================================
// 12. SEND TO SALESFORCE — with duplicate check
// ============================================================
function e2s2_send_to_salesforce($form_data, $log_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'e2s2_crm_logs';

    // Get token
    $access_token = e2s2_get_sf_token();
    if (is_wp_error($access_token)) {
        $wpdb->update($table, [
            'sf_status' => 'failed',
            'sf_response' => $access_token->get_error_message(),
        ], ['id' => $log_id]);
        return;
    }

    $instance_url = rtrim(get_option('e2s2_sf_instance_url', ''), '/');

    // ── Extract form fields ────────────────────────────────────────────────────
    $v_name = isset($form_data['name']) ? $form_data['name'] : '';
    $v_email = isset($form_data['email']) ? $form_data['email'] : '';
    $v_phone = isset($form_data['phone']) ? $form_data['phone'] : '';
    $v_dob = isset($form_data['dob']) ? $form_data['dob'] : '';
    $v_role = isset($form_data['role']) ? $form_data['role'] : '';
    $v_qual = isset($form_data['qualification_expected']) ? $form_data['qualification_expected'] : '';
    $v_placement = isset($form_data['placement_required']) ? $form_data['placement_required'] : '';
    $v_feedback = isset($form_data['candidate_feedback']) ? $form_data['candidate_feedback'] : '';
    $v_course = isset($form_data['course_name']) ? $form_data['course_name'] : '';
    $v_form_name = isset($form_data['_form_name']) ? $form_data['_form_name'] : '';
    $v_page_url = isset($form_data['_page_url']) ? $form_data['_page_url'] : '';
    $v_submitted = isset($form_data['_submitted_at']) ? $form_data['_submitted_at'] : '';

    // Split full name → FirstName + LastName (SF requires LastName)
    $name_parts = explode(' ', trim($v_name), 2);
    $first_name = count($name_parts) > 1 ? $name_parts[0] : '';
    $last_name = count($name_parts) > 1 ? $name_parts[1] : ($name_parts[0] ?: 'Website Lead');

    // Build description from available context
    $desc_parts = array_filter([
        $v_course ? 'Course: ' . $v_course : '',
        $v_form_name ? 'Form: ' . $v_form_name : '',
        $v_feedback ? 'Feedback: ' . $v_feedback : '',
        $v_role ? 'Role: ' . $v_role : '',
        $v_qual ? 'Qual: ' . $v_qual : '',
        $v_placement ? 'Placement: ' . $v_placement : '',
        $v_submitted ? 'Submitted: ' . $v_submitted : '',
    ]);
    $description = implode(' | ', $desc_parts);

    // ── Duplicate check by email ───────────────────────────────────────────────
    if (!empty($v_email)) {
        $email_encoded = urlencode("Email='" . addslashes($v_email) . "'");
        $search_url = $instance_url . '/services/data/v59.0/query/?q=' .
            urlencode("SELECT Id, Name FROM Lead WHERE Email = '" . addslashes($v_email) . "' AND IsConverted = false LIMIT 1");

        $search_resp = wp_remote_get($search_url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
        ]);

        if (!is_wp_error($search_resp)) {
            $search_body = json_decode(wp_remote_retrieve_body($search_resp), true);
            if (!empty($search_body['records'])) {
                // Duplicate found — add a chatter note / task instead
                $existing_id = $search_body['records'][0]['Id'];
                $task_data = [
                    'Subject' => 'New web enquiry — ' . ($v_form_name ?: 'Form'),
                    'WhoId' => $existing_id,
                    'Description' => 'Re-enquiry via: ' . $v_page_url . "\n" . $description,
                    'Status' => 'Not Started',
                    'Priority' => 'Normal',
                ];
                $task_url = $instance_url . '/services/data/v59.0/sobjects/Task/';
                $task_resp = wp_remote_post($task_url, [
                    'timeout' => 15,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($task_data),
                ]);

                $t_code = is_wp_error($task_resp) ? 0 : wp_remote_retrieve_response_code($task_resp);
                $wpdb->update($table, [
                    'sf_status' => ($t_code === 201) ? 'success' : 'failed',
                    'sf_response' => 'Duplicate — existing Lead ID: ' . $existing_id . '. Task created: ' . ($t_code === 201 ? 'yes' : 'no'),
                ], ['id' => $log_id]);
                return;
            }
        }
    }

    // ── Build Lead payload ─────────────────────────────────────────────────────
    $lead_data = [
        'FirstName' => $first_name,
        'LastName' => $last_name,
        'Email' => $v_email,
        'Phone' => $v_phone,
        'LeadSource' => 'Web',
        'Description' => $description,
        'Website' => $v_page_url,
        // SF Lead requires Company — use course name or fallback
        'Company' => $v_course ?: 'Website Enquiry',

        // ── Custom fields ──────────────────────────────────────────────────────
        // CREATE these fields first in: Setup → Object Manager → Lead → Fields & Relationships
        // Then uncomment the lines you need. API names MUST end with __c.
        // 'Course_Interested__c'      => $v_course,
        // 'Date_of_Birth__c'          => $v_dob,         // Date field — ensure YYYY-MM-DD format
        // 'Role_Applied__c'           => $v_role,
        // 'Qualification_Expected__c' => $v_qual,
        // 'Placement_Required__c'     => $v_placement,   // Picklist: Yes / No
        // 'Form_Name__c'              => $v_form_name,
        // 'Source_Page_URL__c'        => $v_page_url,
    ];

    // Strip empty values — SF rejects empty strings on some field types
    $lead_data = array_filter($lead_data, fn($v) => $v !== '' && $v !== null);

    // ── POST to Salesforce ─────────────────────────────────────────────────────
    $api_url = $instance_url . '/services/data/v59.0/sobjects/Lead/';
    $response = wp_remote_post($api_url, [
        'method' => 'POST',
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($lead_data),
    ]);

    if (is_wp_error($response)) {
        // Clear token cache — might be a stale token issue
        delete_option('e2s2_sf_access_token');
        $wpdb->update($table, [
            'sf_status' => 'failed',
            'sf_response' => 'WP Error: ' . $response->get_error_message(),
        ], ['id' => $log_id]);
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);
    $body_arr = json_decode($body, true);

    // 401 = token expired mid-session — clear so next submission re-authenticates
    if ($code === 401) {
        delete_option('e2s2_sf_access_token');
    }

    $sf_lead_id = isset($body_arr['id']) ? $body_arr['id'] : '';

    // HTTP 201 = Created successfully
    $wpdb->update($table, [
        'sf_status' => ($code === 201) ? 'success' : 'failed',
        'sf_response' => $sf_lead_id
            ? 'Lead ID: ' . $sf_lead_id
            : 'HTTP ' . $code . ' — ' . $body,
    ], ['id' => $log_id]);
}
