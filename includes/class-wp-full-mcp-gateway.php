<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WP_Full_MCP_Gateway {
    private static ?self $instance = null;
    private array $opts = [];

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args(get_option('wpfmcp_options', []), $this->defaults());
        $this->maybe_upgrade_options();
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('wpfmcp_wpvivid_run_task', [$this, 'wpvivid_run_queued_task'], 10, 1);
        add_action('wpfmcp_elementor_run_import', [$this, 'run_elementor_import_background'], 10, 2);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('init', [$this, 'rewrite_rules']);
        add_action('template_redirect', [$this, 'maybe_legacy_endpoint']);
    }

    private function defaults(): array {
        return [
            'enabled' => 1,
            'secret' => '',
            'service_user_id' => 0,
            'permission_profile' => 'content_editor',
            'allow_plugin_install' => 0,
            'allow_plugin_update' => 0,
            'allow_plugin_activate' => 0,
            'allow_plugin_deactivate' => 0,
            'allow_plugin_delete' => 0,
            'allow_theme_install' => 0,
            'allow_theme_update' => 0,
            'allow_theme_switch' => 0,
            'allow_media_upload' => 1,
            'approval_required_tools' => "wp-install-plugin
wp-activate-plugin
wp-update-plugin
wp-deactivate-plugin
wp-trash-post
wp-option-update
wp-install-theme
wp-update-theme
wp-activate-theme
wc-create-product
wc-update-product
wc-set-product-pricing
wc-set-product-thumbnail
wc-delete-product
wc-update-order-status
wc-add-order-note
wc-enable-native-mcp
wp-elementor-kit-delete
wp-elementor-kit-erase
wp-elementor-convert-columns-to-containers
wp-import-settings",
            'enabled_tool_groups' => "core
content
media
plugins
themes
options
users
elementor
woocommerce
approvals
backup
settings
diagnostics
audit",
            'backup_before_tools' => "wp-create-post
wp-update-post
wp-install-plugin
wp-activate-plugin
wp-update-plugin
wp-deactivate-plugin
wp-trash-post
wp-option-update
wp-install-theme
wp-update-theme
wp-activate-theme
wc-create-product
wc-update-product
wc-set-product-pricing
wc-set-product-thumbnail
wc-delete-product
wc-update-order-status
wc-add-order-note
wc-enable-native-mcp
wp-elementor-kit-erase
wp-elementor-convert-columns-to-containers
wp-import-settings",
            'backup_strategy' => 'wpvivid_fallback_db',
            'backup_guard_require_db_fallback' => 1,
            'backup_prune_keep' => 10,
            'enable_elementor_mcp_bridge' => 1,
            'elementor_mcp_route' => '/mcp/elementor-mcp-server',
            'allowed_plugin_slugs' => '',
            'allowed_theme_slugs' => '',
            'log_limit' => 200,
        ];
    }

    private function maybe_upgrade_options(): void {
        $stored_version = (string) get_option('wpfmcp_version', '');
        $opts = wp_parse_args(get_option('wpfmcp_options', []), $this->defaults());
        if (empty($opts['secret'])) {
            $opts['secret'] = wp_generate_password(48, false, false);
        }
        if ($stored_version === (defined('WPFMCP_VERSION') ? WPFMCP_VERSION : '') && !empty($this->opts['secret'])) return;
        foreach (['approval_required_tools','backup_before_tools'] as $key) {
            $list = $this->parse_list((string)($opts[$key] ?? ''));
            foreach ($key === 'approval_required_tools' ? ['wp-install-plugin','wp-activate-plugin','wp-update-plugin','wp-deactivate-plugin','wp-trash-post','wp-option-update','wp-install-theme','wp-update-theme','wp-activate-theme','wc-create-product','wc-update-product','wc-set-product-pricing','wc-set-product-thumbnail','wc-delete-product','wc-update-order-status','wc-add-order-note','wc-enable-native-mcp','wp-elementor-kit-delete','wp-elementor-kit-erase','wp-elementor-convert-columns-to-containers','wp-import-settings'] : ['wp-import-settings','wp-activate-plugin','wp-deactivate-plugin','wp-elementor-kit-erase','wc-create-product','wc-update-product','wc-set-product-pricing','wc-set-product-thumbnail','wc-delete-product','wc-update-order-status','wc-add-order-note','wc-enable-native-mcp'] as $tool) {
                if (!in_array($tool, $list, true)) $list[] = $tool;
            }
            $opts[$key] = implode("\n", $list);
        }
        $groups = $this->parse_list((string)($opts['enabled_tool_groups'] ?? ''));
        foreach (['woocommerce','backup','settings','diagnostics','audit'] as $group) {
            if (!in_array($group, $groups, true)) $groups[] = $group;
        }
        $opts['enabled_tool_groups'] = implode("\n", $groups);
        $plugin_slugs = $this->parse_list((string)($opts['allowed_plugin_slugs'] ?? ''));
        if ($plugin_slugs && !in_array('woocommerce', $plugin_slugs, true)) {
            $plugin_slugs[] = 'woocommerce';
            $opts['allowed_plugin_slugs'] = implode("\n", $plugin_slugs);
        }
        if (empty($opts['permission_profile'])) $opts['permission_profile'] = 'content_editor';
        update_option('wpfmcp_options', $opts, false);
        update_option('wpfmcp_version', defined('WPFMCP_VERSION') ? WPFMCP_VERSION : '', false);
        $this->opts = wp_parse_args($opts, $this->defaults());
    }

    public function register_routes(): void {
        register_rest_route('wp-full-mcp/v1', '/mcp/(?P<secret>[A-Za-z0-9_-]+)', [
            'methods' => ['GET', 'POST', 'OPTIONS'],
            'callback' => [$this, 'handle_rest'],
            'permission_callback' => '__return_true',
            'args' => ['secret' => ['required' => true]],
        ]);
    }

    public function query_vars(array $vars): array {
        $vars[] = 'wpfmcp_secret';
        return $vars;
    }

    public function rewrite_rules(): void {
        add_rewrite_rule('^mcp/([A-Za-z0-9_-]+)/?$', 'index.php?wpfmcp_secret=$matches[1]', 'top');
    }

    public function maybe_legacy_endpoint(): void {
        $secret = get_query_var('wpfmcp_secret');
        if (!$secret) return;
        $this->handle_http((string) $secret);
        exit;
    }

    public function admin_menu(): void {
        add_options_page('WP Full MCP Gateway', 'WP Full MCP', 'manage_options', 'wp-full-mcp-gateway', [$this, 'settings_page']);
    }

    public function admin_init(): void {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['wpfmcp_save']) && check_admin_referer('wpfmcp_save')) {
            $post = wp_unslash($_POST);
            $opts = $this->opts;
            $opts['enabled'] = empty($post['enabled']) ? 0 : 1;
            $profile = sanitize_key($post['permission_profile'] ?? ($opts['permission_profile'] ?? 'content_editor'));
            $opts['permission_profile'] = in_array($profile, array_keys($this->permission_profiles()), true) ? $profile : 'content_editor';
            $service_user_id = absint($post['service_user_id'] ?? 0);
            $service_user = $service_user_id ? get_userdata($service_user_id) : false;
            $opts['service_user_id'] = $service_user ? $service_user_id : 0;
            $opts['allow_plugin_install'] = empty($post['allow_plugin_install']) ? 0 : 1;
            $opts['allow_plugin_update'] = empty($post['allow_plugin_update']) ? 0 : 1;
            $opts['allow_plugin_activate'] = empty($post['allow_plugin_activate']) ? 0 : 1;
            $opts['allow_plugin_deactivate'] = empty($post['allow_plugin_deactivate']) ? 0 : 1;
            $opts['allow_plugin_delete'] = empty($post['allow_plugin_delete']) ? 0 : 1;
            $opts['allow_theme_install'] = empty($post['allow_theme_install']) ? 0 : 1;
            $opts['allow_theme_update'] = empty($post['allow_theme_update']) ? 0 : 1;
            $opts['allow_theme_switch'] = empty($post['allow_theme_switch']) ? 0 : 1;
            $opts['allow_media_upload'] = empty($post['allow_media_upload']) ? 0 : 1;
            $opts['approval_required_tools'] = sanitize_textarea_field($post['approval_required_tools'] ?? '');
            $opts['enabled_tool_groups'] = sanitize_textarea_field($post['enabled_tool_groups'] ?? '');
            $opts['backup_before_tools'] = sanitize_textarea_field($post['backup_before_tools'] ?? '');
            $opts['backup_strategy'] = in_array(($post['backup_strategy'] ?? 'wpvivid_fallback_db'), ['off','db_export','wpvivid','wpvivid_fallback_db'], true) ? sanitize_key($post['backup_strategy']) : 'wpvivid_fallback_db';
            $opts['backup_guard_require_db_fallback'] = empty($post['backup_guard_require_db_fallback']) ? 0 : 1;
            $opts['backup_prune_keep'] = max(1, min(100, intval($post['backup_prune_keep'] ?? 10)));
            $opts['enable_elementor_mcp_bridge'] = empty($post['enable_elementor_mcp_bridge']) ? 0 : 1;
            $route = sanitize_text_field($post['elementor_mcp_route'] ?? '/mcp/elementor-mcp-server');
            $opts['elementor_mcp_route'] = '/' . ltrim($route, '/');
            $opts['allowed_plugin_slugs'] = sanitize_textarea_field($post['allowed_plugin_slugs'] ?? '');
            $opts['allowed_theme_slugs'] = sanitize_textarea_field($post['allowed_theme_slugs'] ?? '');
            update_option('wpfmcp_options', $opts, false);
            $this->opts = wp_parse_args($opts, $this->defaults());
            add_settings_error('wpfmcp', 'saved', 'WP Full MCP settings saved.', 'updated');
        }
        if (isset($_POST['wpfmcp_rotate']) && check_admin_referer('wpfmcp_rotate')) {
            $opts = $this->opts;
            $opts['secret'] = wp_generate_password(48, false, false);
            update_option('wpfmcp_options', $opts, false);
            $this->opts = wp_parse_args($opts, $this->defaults());
            add_settings_error('wpfmcp', 'rotated', 'MCP secret rotated.', 'updated');
        }
        if (isset($_POST['wpfmcp_apply_profile']) && check_admin_referer('wpfmcp_apply_profile')) {
            $profile = sanitize_key(wp_unslash($_POST['profile'] ?? ''));
            $msg = $this->apply_permission_profile($profile);
            add_settings_error('wpfmcp', 'profile_applied', $msg, 'updated');
        }
        if (isset($_POST['wpfmcp_queue_action']) && check_admin_referer('wpfmcp_queue')) {
            $qid = sanitize_text_field(wp_unslash($_POST['queue_id'] ?? ''));
            $action = sanitize_key(wp_unslash($_POST['wpfmcp_queue_action'] ?? ''));
            $msg = $this->handle_queue_admin_action($qid, $action);
            add_settings_error('wpfmcp', 'queue_action', $msg, 'updated');
        }
        if (isset($_POST['wpfmcp_clear_log']) && check_admin_referer('wpfmcp_clear_log')) {
            update_option('wpfmcp_log', [], false);
            add_settings_error('wpfmcp', 'log_cleared', 'Audit log cleared.', 'updated');
        }
    }

    public function settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $chatgpt_url = rest_url('wp-full-mcp/v1/mcp/' . ($this->opts['secret'] ?? ''));
        $rest_url = rest_url('wp-full-mcp/v1/mcp/bearer');
        $pretty_url = home_url('/mcp/bearer');
        settings_errors('wpfmcp');
        ?>
        <div class="wrap">
            <h1>WP Full MCP Gateway <span style="font-size:13px;color:#646970">v<?php echo esc_html(WPFMCP_VERSION); ?></span></h1>
            <?php $manifest = $this->connector_manifest(); $config = $this->connector_config(); ?>
            <style>.wpfmcp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:16px 0}.wpfmcp-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}.wpfmcp-card h2,.wpfmcp-card h3{margin-top:0}.wpfmcp-copy{width:100%;font-family:monospace;min-height:92px}.wpfmcp-pill{display:inline-block;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:999px;padding:2px 8px;margin:2px;font-size:12px}.wpfmcp-tool-picker{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wpfmcp-tool-picker{position:relative}.wpfmcp-tool-picker input[type=text]{height:36px;min-height:36px;line-height:34px;padding-top:0;padding-bottom:0;font-size:14px;box-sizing:border-box;vertical-align:middle}.wpfmcp-tool-picker .button{height:36px;line-height:34px;display:inline-flex;align-items:center}.wpfmcp-suggest-wrap{position:relative;display:inline-block}.wpfmcp-suggestions{position:absolute;z-index:100000;top:38px;left:0;width:100%;max-height:220px;overflow:auto;background:#fff;border:1px solid #8c8f94;border-radius:4px;box-shadow:0 6px 16px rgba(0,0,0,.12);display:none}.wpfmcp-suggestions button{display:block;width:100%;padding:7px 10px;border:0;background:#fff;text-align:left;cursor:pointer;font-size:13px}.wpfmcp-suggestions button:hover,.wpfmcp-suggestions button.is-active{background:#f0f6fc}</style>
            <div class="wpfmcp-grid">
                <div class="wpfmcp-card"><h2>Connection</h2><p><strong>Status:</strong> <?php echo empty($this->opts['enabled']) ? '<span style="color:#b32d2e">Disabled</span>' : '<span style="color:#008a20">Enabled</span>'; ?></p><p><strong>Tools:</strong> <?php echo esc_html((string)$manifest['capabilities']['tool_count']); ?></p><p><strong>Profile:</strong> <code><?php echo esc_html($this->opts['permission_profile'] ?? 'content_editor'); ?></code></p></div>
                <div class="wpfmcp-card"><h2>Backup guard</h2><p><strong>Strategy:</strong> <code><?php echo esc_html($this->opts['backup_strategy'] ?? ''); ?></code></p><p><strong>Approval tools:</strong> <?php echo esc_html((string)count($manifest['capabilities']['approval_required_tools'])); ?></p></div>
                <div class="wpfmcp-card"><h2>Endpoint</h2><p><strong>ChatGPT No Auth:</strong><br><code><?php echo esc_html($chatgpt_url); ?></code></p><p><strong>Bearer REST:</strong><br><code><?php echo esc_html($rest_url); ?></code></p><p><strong>Pretty bearer:</strong><br><code><?php echo esc_html($pretty_url); ?></code></p></div>
            </div>
            <h2>Connector quick setup</h2>
            <p>For ChatGPT Custom Connector, use the ChatGPT No Auth URL and choose <code>No authentication</code>. Generic MCP clients can use the bearer URL with <code>Authorization: Bearer</code>. Treat this page as sensitive because generated URLs/configs contain the MCP secret.</p>
            <p><strong>OpenClaw / generic MCP URL</strong></p><textarea readonly class="wpfmcp-copy" onclick="this.select()"><?php echo esc_textarea(wp_json_encode($config['client_configs']['generic_mcp_connector'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea>
            <p><strong>Cursor / Claude-style mcpServers</strong></p><textarea readonly class="wpfmcp-copy" onclick="this.select()"><?php echo esc_textarea(wp_json_encode($config['client_configs']['mcpServers'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea>
            <h2>Capability groups</h2><p><?php foreach ($manifest['capability_groups'] as $group => $info) { echo '<span class="wpfmcp-pill">'.esc_html($info['label']).': '.esc_html((string)count($info['tools'])).'</span> '; } ?></p>
            <form method="post">
                <?php wp_nonce_field('wpfmcp_save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($this->opts['enabled']); ?>> Enable MCP endpoint</label></td></tr>
                    <tr><th scope="row">Service user ID</th><td><input type="number" name="service_user_id" value="<?php echo esc_attr((string)($this->opts['service_user_id'] ?? 0)); ?>" min="1" class="small-text"><p class="description">Required. Remote MCP requests that pass the secret run capability checks as this WordPress user. Use a dedicated low-privilege account that matches the tools you expose. Requests fail closed when this is empty or invalid.</p></td></tr>
                    <tr><th scope="row">Permission profile</th><td><select name="permission_profile"><?php foreach ($this->permission_profiles() as $key => $profile) { echo '<option value="'.esc_attr($key).'" '.selected($this->opts['permission_profile'] ?? 'content_editor', $key, false).'>'.esc_html($profile['label']).'</option>'; } ?></select><p class="description">Save only records the selected profile label. Use Apply profile below to rewrite permissions/tool groups safely.</p></td></tr>
                    <tr><th scope="row">Plugin install</th><td><label><input type="checkbox" name="allow_plugin_install" value="1" <?php checked($this->opts['allow_plugin_install']); ?>> Allow installing plugins from WordPress.org slugs</label></td></tr>
                    <tr><th scope="row">Plugin update</th><td><label><input type="checkbox" name="allow_plugin_update" value="1" <?php checked($this->opts['allow_plugin_update']); ?>> Allow plugin updates</label></td></tr>
                    <tr><th scope="row">Plugin activation</th><td><label><input type="checkbox" name="allow_plugin_activate" value="1" <?php checked($this->opts['allow_plugin_activate']); ?>> Allow activating installed plugins</label></td></tr>
                    <tr><th scope="row">Plugin deactivation</th><td><label><input type="checkbox" name="allow_plugin_deactivate" value="1" <?php checked($this->opts['allow_plugin_deactivate']); ?>> Allow deactivating installed plugins</label></td></tr>
                    <tr><th scope="row">Plugin delete</th><td><label><input type="checkbox" name="allow_plugin_delete" value="1" <?php checked($this->opts['allow_plugin_delete']); ?>> Allow plugin deletion</label></td></tr>
                    <tr><th scope="row">Allowed plugin slugs</th><td><textarea name="allowed_plugin_slugs" rows="5" cols="60" placeholder="elementor&#10;classic-editor"><?php echo esc_textarea($this->opts['allowed_plugin_slugs']); ?></textarea><p class="description">Newline/comma separated allowlist. Empty blocks plugin install/update/activate/deactivate/delete even when the action toggle is enabled.</p></td></tr>
                    <tr><th scope="row">Media upload</th><td><label><input type="checkbox" name="allow_media_upload" value="1" <?php checked($this->opts['allow_media_upload']); ?>> Allow media sideload from URL</label></td></tr>
                    <tr><th scope="row">Theme install</th><td><label><input type="checkbox" name="allow_theme_install" value="1" <?php checked($this->opts['allow_theme_install']); ?>> Allow installing WordPress.org themes</label></td></tr>
                    <tr><th scope="row">Theme update</th><td><label><input type="checkbox" name="allow_theme_update" value="1" <?php checked($this->opts['allow_theme_update']); ?>> Allow theme updates</label></td></tr>
                    <tr><th scope="row">Theme switch</th><td><label><input type="checkbox" name="allow_theme_switch" value="1" <?php checked($this->opts['allow_theme_switch']); ?>> Allow active theme switching</label></td></tr>
                    <tr><th scope="row">Allowed theme slugs</th><td><textarea name="allowed_theme_slugs" rows="5" cols="60" placeholder="twentytwentysix&#10;hello-elementor"><?php echo esc_textarea($this->opts['allowed_theme_slugs']); ?></textarea><p class="description">Newline/comma separated allowlist. Empty blocks theme install/update/switch even when the action toggle is enabled.</p></td></tr>
                    <tr><th scope="row">Require approval for tools</th><td>
                        <textarea id="wpfmcp-approval-tools" name="approval_required_tools" rows="8" cols="60"><?php echo esc_textarea($this->opts['approval_required_tools']); ?></textarea>
                        <div class="wpfmcp-tool-picker">
                            <span class="wpfmcp-suggest-wrap">
                                <input id="wpfmcp-approval-tool-input" type="text" class="regular-text" autocomplete="off" placeholder="Type tool name, e.g. wp-install-plugin">
                                <span id="wpfmcp-tool-suggestions" class="wpfmcp-suggestions" role="listbox" aria-label="Tool suggestions"></span>
                            </span>
                            <button type="button" class="button" id="wpfmcp-add-approval-tool">Add tool</button>
                            <button type="button" class="button" id="wpfmcp-reset-approval-tools">Reset default</button>
                        </div>
                        <p class="description">Newline/comma separated tool names. Type a tool name above to get browser autocomplete, then add it to the textarea. Reset default restores the recommended dangerous-tool approval list.</p>
                        <script>
                        (function(){
                            const textarea = document.getElementById('wpfmcp-approval-tools');
                            const input = document.getElementById('wpfmcp-approval-tool-input');
                            const addBtn = document.getElementById('wpfmcp-add-approval-tool');
                            const resetBtn = document.getElementById('wpfmcp-reset-approval-tools');
                            const defaults = <?php echo wp_json_encode($this->defaults()['approval_required_tools'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                            const allTools = <?php echo wp_json_encode(array_values($manifest['tools']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                            const suggestions = document.getElementById('wpfmcp-tool-suggestions');
                            let activeIndex = -1;
                            function splitTools(value){ return value.split(/[\n,]+/).map(v => v.trim()).filter(Boolean); }
                            function writeTools(tools){ textarea.value = Array.from(new Set(tools)).join('\n'); }
                            function hideSuggestions(){ if (suggestions) suggestions.style.display = 'none'; activeIndex = -1; }
                            function renderSuggestions(){
                                if (!suggestions || !input) return;
                                const q = (input.value || '').trim().toLowerCase();
                                const current = new Set(splitTools(textarea.value));
                                const matches = allTools.filter(t => !current.has(t) && (!q || t.toLowerCase().includes(q))).slice(0, 12);
                                suggestions.innerHTML = matches.map((t,i) => '<button type="button" role="option" data-tool="'+t.replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'" class="'+(i===activeIndex?'is-active':'')+'">'+t+'</button>').join('');
                                suggestions.style.display = matches.length ? 'block' : 'none';
                            }
                            function chooseTool(tool){ input.value = tool; hideSuggestions(); input.focus(); }
                            function addTool(){
                                const tool = (input.value || '').trim();
                                if (!tool) return;
                                const tools = splitTools(textarea.value);
                                tools.push(tool);
                                writeTools(tools);
                                input.value = '';
                                hideSuggestions();
                                input.focus();
                            }
                            suggestions && suggestions.addEventListener('mousedown', function(e){
                                const btn = e.target.closest('button[data-tool]');
                                if (!btn) return;
                                e.preventDefault();
                                chooseTool(btn.getAttribute('data-tool'));
                            });
                            addBtn && addBtn.addEventListener('click', addTool);
                            input && input.addEventListener('input', function(){ activeIndex = -1; renderSuggestions(); });
                            input && input.addEventListener('focus', renderSuggestions);
                            input && input.addEventListener('blur', function(){ setTimeout(hideSuggestions, 120); });
                            input && input.addEventListener('keydown', function(e){
                                const visible = suggestions && suggestions.style.display === 'block';
                                const items = suggestions ? Array.from(suggestions.querySelectorAll('button[data-tool]')) : [];
                                if (visible && e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1); renderSuggestions(); return; }
                                if (visible && e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); renderSuggestions(); return; }
                                if (visible && e.key === 'Escape') { e.preventDefault(); hideSuggestions(); return; }
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    if (visible && activeIndex >= 0 && items[activeIndex]) chooseTool(items[activeIndex].getAttribute('data-tool'));
                                    else addTool();
                                }
                            });
                            resetBtn && resetBtn.addEventListener('click', function(){ textarea.value = defaults; textarea.focus(); });
                        })();
                        </script>
                    </td></tr>
                    <tr><th scope="row">Enabled tool groups</th><td>
                        <textarea id="wpfmcp-enabled-groups" name="enabled_tool_groups" rows="6" cols="60"><?php echo esc_textarea($this->opts['enabled_tool_groups']); ?></textarea>
                        <div class="wpfmcp-tool-picker">
                            <span class="wpfmcp-suggest-wrap">
                                <input id="wpfmcp-enabled-group-input" type="text" class="regular-text" autocomplete="off" placeholder="Type group, e.g. plugins">
                                <span id="wpfmcp-group-suggestions" class="wpfmcp-suggestions" role="listbox" aria-label="Group suggestions"></span>
                            </span>
                            <button type="button" class="button" id="wpfmcp-add-enabled-group">Add group</button>
                            <button type="button" class="button" id="wpfmcp-reset-enabled-groups">Reset default</button>
                        </div>
                        <p class="description">Available groups: core, content, media, plugins, themes, options, users, elementor, approvals, backup, settings, diagnostics, audit. Disabled groups are hidden from tools/list and blocked at execution.</p>
                    </td></tr>
                    <tr><th scope="row">Backup strategy</th><td><select name="backup_strategy"><option value="wpvivid_fallback_db" <?php selected($this->opts['backup_strategy'], 'wpvivid_fallback_db'); ?>>WPvivid if active, fallback DB export (default)</option><option value="wpvivid" <?php selected($this->opts['backup_strategy'], 'wpvivid'); ?>>WPvivid only</option><option value="db_export" <?php selected($this->opts['backup_strategy'], 'db_export'); ?>>DB export to uploads</option><option value="off" <?php selected($this->opts['backup_strategy'], 'off'); ?>>Off</option></select><p class="description">V3.5 safety: uses WPvivid when available or DB SQL export fallback before selected dangerous tools execute.</p></td></tr>
                    <tr><th scope="row">Backup before tools</th><td>
                        <textarea id="wpfmcp-backup-tools" name="backup_before_tools" rows="8" cols="60"><?php echo esc_textarea($this->opts['backup_before_tools']); ?></textarea>
                        <div class="wpfmcp-tool-picker">
                            <span class="wpfmcp-suggest-wrap">
                                <input id="wpfmcp-backup-tool-input" type="text" class="regular-text" autocomplete="off" placeholder="Type tool name, e.g. wp-install-plugin">
                                <span id="wpfmcp-backup-tool-suggestions" class="wpfmcp-suggestions" role="listbox" aria-label="Backup tool suggestions"></span>
                            </span>
                            <button type="button" class="button" id="wpfmcp-add-backup-tool">Add tool</button>
                            <button type="button" class="button" id="wpfmcp-reset-backup-tools">Reset default</button>
                        </div>
                        <p class="description">Newline/comma separated tool names. These tools trigger backup-before-action unless backup_strategy is off.</p>
                    </td></tr>
                    <script>
                    (function(){
                        function setupListPicker(config){
                            const textarea = document.getElementById(config.textareaId);
                            const input = document.getElementById(config.inputId);
                            const suggestions = document.getElementById(config.suggestionsId);
                            const addBtn = document.getElementById(config.addBtnId);
                            const resetBtn = document.getElementById(config.resetBtnId);
                            const defaults = config.defaults || '';
                            const items = config.items || [];
                            let activeIndex = -1;
                            if (!textarea || !input || !suggestions) return;
                            function splitList(value){ return value.split(/[\n,]+/).map(v => v.trim()).filter(Boolean); }
                            function writeList(values){ textarea.value = Array.from(new Set(values)).join('\n'); }
                            function escapeHtml(v){ return String(v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
                            function hideSuggestions(){ suggestions.style.display = 'none'; activeIndex = -1; }
                            function renderSuggestions(){
                                const q = (input.value || '').trim().toLowerCase();
                                const current = new Set(splitList(textarea.value));
                                const matches = items.filter(t => !current.has(t) && (!q || t.toLowerCase().includes(q))).slice(0, 12);
                                suggestions.innerHTML = matches.map((t,i) => '<button type="button" role="option" data-value="'+escapeHtml(t)+'" class="'+(i===activeIndex?'is-active':'')+'">'+escapeHtml(t)+'</button>').join('');
                                suggestions.style.display = matches.length ? 'block' : 'none';
                            }
                            function chooseItem(value){ input.value = value; hideSuggestions(); input.focus(); }
                            function addItem(){
                                const value = (input.value || '').trim();
                                if (!value) return;
                                const values = splitList(textarea.value);
                                values.push(value);
                                writeList(values);
                                input.value = '';
                                hideSuggestions();
                                input.focus();
                            }
                            suggestions.addEventListener('mousedown', function(e){
                                const btn = e.target.closest('button[data-value]');
                                if (!btn) return;
                                e.preventDefault();
                                chooseItem(btn.getAttribute('data-value'));
                            });
                            addBtn && addBtn.addEventListener('click', addItem);
                            input.addEventListener('input', function(){ activeIndex = -1; renderSuggestions(); });
                            input.addEventListener('focus', renderSuggestions);
                            input.addEventListener('blur', function(){ setTimeout(hideSuggestions, 120); });
                            input.addEventListener('keydown', function(e){
                                const visible = suggestions.style.display === 'block';
                                const buttons = Array.from(suggestions.querySelectorAll('button[data-value]'));
                                if (visible && e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, buttons.length - 1); renderSuggestions(); return; }
                                if (visible && e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); renderSuggestions(); return; }
                                if (visible && e.key === 'Escape') { e.preventDefault(); hideSuggestions(); return; }
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    if (visible && activeIndex >= 0 && buttons[activeIndex]) chooseItem(buttons[activeIndex].getAttribute('data-value'));
                                    else addItem();
                                }
                            });
                            resetBtn && resetBtn.addEventListener('click', function(){ textarea.value = defaults; textarea.focus(); });
                        }
                        setupListPicker({
                            textareaId: 'wpfmcp-enabled-groups',
                            inputId: 'wpfmcp-enabled-group-input',
                            suggestionsId: 'wpfmcp-group-suggestions',
                            addBtnId: 'wpfmcp-add-enabled-group',
                            resetBtnId: 'wpfmcp-reset-enabled-groups',
                            defaults: <?php echo wp_json_encode($this->defaults()['enabled_tool_groups'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
                            items: <?php echo wp_json_encode(array_keys($this->group_labels()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                        });
                        setupListPicker({
                            textareaId: 'wpfmcp-backup-tools',
                            inputId: 'wpfmcp-backup-tool-input',
                            suggestionsId: 'wpfmcp-backup-tool-suggestions',
                            addBtnId: 'wpfmcp-add-backup-tool',
                            resetBtnId: 'wpfmcp-reset-backup-tools',
                            defaults: <?php echo wp_json_encode($this->defaults()['backup_before_tools'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
                            items: <?php echo wp_json_encode(array_values($manifest['tools']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
                        });
                    })();
                    </script>
                    <tr><th scope="row">Elementor MCP bridge</th><td><label><input type="checkbox" name="enable_elementor_mcp_bridge" value="1" <?php checked($this->opts['enable_elementor_mcp_bridge']); ?>> Enable bridge; requires Elementor MCP plugin active</label></td></tr>
                    <tr><th scope="row">Elementor MCP route</th><td><input type="text" name="elementor_mcp_route" value="<?php echo esc_attr($this->opts['elementor_mcp_route']); ?>" class="regular-text"><p class="description">Default: /mcp/elementor-mcp-server. This is dispatched internally through WordPress REST, not a self-HTTP call.</p></td></tr>
                </table>
                <p><button class="button button-primary" name="wpfmcp_save" value="1">Save settings</button></p>
            </form>
            <form method="post" style="margin-top:1em">
                <?php wp_nonce_field('wpfmcp_rotate'); ?>
                <button class="button" name="wpfmcp_rotate" value="1" onclick="return confirm('Rotate MCP secret? Existing ChatGPT connector URL will stop working.');">Rotate secret</button>
            </form>
            <h2>Apply permission profile</h2>
            <p>Profiles rewrite enabled groups and high-risk permission toggles. They do not rotate the secret.</p>
            <?php foreach ($this->permission_profiles() as $key => $profile): ?>
                <form method="post" style="display:inline-block;margin:0 8px 8px 0">
                    <?php wp_nonce_field('wpfmcp_apply_profile'); ?>
                    <input type="hidden" name="profile" value="<?php echo esc_attr($key); ?>">
                    <button class="button" name="wpfmcp_apply_profile" value="1" onclick="return confirm('Apply <?php echo esc_js($profile['label']); ?> profile? This will update MCP permissions.');"><?php echo esc_html($profile['label']); ?></button>
                </form>
            <?php endforeach; ?>
            <h2>Approval Queue</h2>
            <?php $this->render_queue_table(); ?>
            <h2>Recent MCP log</h2>
            <?php $this->render_log_table(); ?>
            <form method="post" style="margin-top:1em">
                <?php wp_nonce_field('wpfmcp_clear_log'); ?>
                <button class="button" name="wpfmcp_clear_log" value="1" onclick="return confirm('Clear MCP audit log?');">Clear audit log</button>
            </form>
        </div>
        <?php
    }


    private function parse_list(string $value): array { return array_values(array_filter(array_map('sanitize_key', preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY)))); }
    private function assert_safe_remote_url(string $url, string $label = 'url'): string { $url=esc_url_raw($url); if(!$url || !preg_match('#^https://#i',$url)) throw new RuntimeException($label.' must be a valid HTTPS URL.'); $host=parse_url($url, PHP_URL_HOST); if(!$host) throw new RuntimeException($label.' must include a host.'); $ips=[]; if(filter_var($host, FILTER_VALIDATE_IP)) $ips[]=$host; else { $records=@dns_get_record($host, DNS_A + DNS_AAAA); if(is_array($records)) foreach($records as $record){ if(!empty($record['ip'])) $ips[]=$record['ip']; if(!empty($record['ipv6'])) $ips[]=$record['ipv6']; } if(!$ips){ $resolved=@gethostbynamel($host); if(is_array($resolved)) $ips=array_merge($ips,$resolved); } } $ips=array_values(array_unique($ips)); if(!$ips) throw new RuntimeException($label.' host could not be resolved.'); foreach($ips as $ip){ if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException($label.' resolves to a private or reserved address and is blocked.'); } return $url; }
    private function approval_required(string $tool): bool { return in_array(sanitize_key($tool), $this->parse_list((string)($this->opts['approval_required_tools'] ?? '')), true); }
    private function tool_group(string $tool): string { if (str_starts_with($tool, 'wc-')) return 'woocommerce'; if (in_array($tool, ['wp-create-backup','wp-list-backups','wp-backup-health-check','wp-backup-before-action-status','wp-prune-backups','wp-wpvivid-status','wp-wpvivid-create-backup','wp-wpvivid-task-status','wp-wpvivid-list-backups'], true)) return 'backup'; if (in_array($tool, ['wp-connector-manifest','wp-connector-config'], true)) return 'core'; if (in_array($tool, ['wp-export-diagnostics'], true)) return 'diagnostics'; if (in_array($tool, ['wp-audit-log'], true)) return 'audit'; if (in_array($tool, ['wp-export-settings','wp-import-settings'], true)) return 'settings'; if (str_contains($tool, 'approval')) return 'approvals'; if (str_contains($tool, 'media')) return 'media'; if (str_contains($tool, 'plugin')) return 'plugins'; if (str_contains($tool, 'theme')) return 'themes'; if (str_contains($tool, 'option')) return 'options'; if (str_contains($tool, 'user')) return 'users'; if (str_contains($tool, 'elementor')) return 'elementor'; if (in_array($tool, ['wp-site-info','wp-health-check'], true)) return 'core'; return 'content'; }
    private function group_enabled_for_tool(string $tool): bool { return in_array($this->tool_group($tool), $this->parse_list((string)($this->opts['enabled_tool_groups'] ?? '')), true); }
    private function dry_run_preview(string $tool, array $args): array { if($tool==='wp-elementor-kit-erase') return $this->elementor_kit_erase_preview($args); if($tool==='wp-elementor-convert-columns-to-containers') return $this->elementor_convert_columns_preview($args); $safe=$args; unset($safe['approval_id'], $safe['dry_run']); return ['dry_run'=>true,'tool'=>$tool,'group'=>$this->tool_group($tool),'would_execute'=>true,'approval_required'=>$this->approval_required($tool),'backup_before_action'=>$this->backup_required($tool),'arguments'=>$safe,'notes'=>'No WordPress data was changed. Re-run with dry_run=false/omitted to execute.']; }
    private function backup_required(string $tool): bool { return (($this->opts['backup_strategy'] ?? 'db_export') !== 'off') && in_array(sanitize_key($tool), $this->parse_list((string)($this->opts['backup_before_tools'] ?? '')), true); }
    private function backups_dir(): array { $upload = wp_upload_dir(); $dir = trailingslashit($upload['basedir']) . 'wp-full-mcp-backups'; $url = trailingslashit($upload['baseurl']) . 'wp-full-mcp-backups'; if (!wp_mkdir_p($dir)) throw new RuntimeException('Unable to create backup directory'); $ht = $dir . '/.htaccess'; if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n"); $idx = $dir . '/index.php'; if (!file_exists($idx)) @file_put_contents($idx, "<?php\n// Silence is golden.\n"); $web = $dir . '/web.config'; if (!file_exists($web)) @file_put_contents($web, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' . "\n"); return [$dir, $url]; }
    private function create_db_backup(string $tool, array $args): array { global $wpdb; [$dir, $url] = $this->backups_dir(); $stamp = gmdate('Ymd-His'); $name = 'db-' . $stamp . '-' . sanitize_key($tool) . '-' . wp_generate_password(12, false, false) . '.sql'; $file = $dir . '/' . $name; $fh = fopen($file, 'wb'); if (!$fh) throw new RuntimeException('Unable to write backup file'); fwrite($fh, "-- WP Full MCP Gateway DB backup
-- Time: " . gmdate('c') . "
-- Tool: " . $tool . "

"); $tables = $wpdb->get_col('SHOW TABLES'); foreach ($tables as $table) { $safe_table = str_replace('`','``',(string)$table); $create = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($safe_table) . '`', ARRAY_N); fwrite($fh, "
DROP TABLE IF EXISTS `{$safe_table}`;
" . ($create[1] ?? '') . ";

"); $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql($safe_table) . '`', ARRAY_A); foreach ($rows as $row) { $cols = array_map(fn($c) => '`' . str_replace('`','``',$c) . '`', array_keys($row)); $vals = array_map(function($v) use ($wpdb) { return is_null($v) ? 'NULL' : "'" . esc_sql($v) . "'"; }, array_values($row)); fwrite($fh, 'INSERT INTO `' . $safe_table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");
"); } } fclose($fh); return ['strategy'=>'db_export','name'=>$name,'bytes'=>filesize($file),'created_at'=>gmdate('c'),'stored'=>'private_uploads','note'=>'Backup path is intentionally omitted from MCP responses.']; }
    private function maybe_backup_before(string $tool, array $args): ?array { if (!$this->backup_required($tool)) return null; $strategy=$this->opts['backup_strategy'] ?? 'wpvivid_fallback_db'; if($strategy==='db_export') return $this->create_db_backup($tool, $args); if($strategy==='wpvivid' || $strategy==='wpvivid_fallback_db'){ try { $wpvivid=$this->wpvivid_create_backup(['backup_files'=>'all','remote'=>false,'label'=>'before-'.$tool]); if($strategy==='wpvivid') return $wpvivid; $fallback=$this->create_db_backup($tool, $args); if(empty($fallback['name'])) throw new RuntimeException('DB fallback backup file was not created.'); return ['strategy'=>'wpvivid_queued_plus_db_export','guard_passed'=>true,'wpvivid'=>$wpvivid,'db_export'=>$fallback]; } catch (Throwable $e) { if($strategy==='wpvivid' || !empty($this->opts['backup_guard_require_db_fallback'])) throw new RuntimeException('Backup guard failed before '.$tool.': '.$e->getMessage()); $fallback=$this->create_db_backup($tool, $args); $fallback['wpvivid_error']=$e->getMessage(); $fallback['strategy']='wpvivid_failed_db_export'; return $fallback; } } return null; }
    private function approval_queue(): array { $q = get_option('wpfmcp_approval_queue', []); return is_array($q) ? $q : []; }
    private function save_approval_queue(array $q): void { update_option('wpfmcp_approval_queue', array_slice($q, 0, 200), false); }
    private function create_approval_request(string $tool, array $args): array { $q=$this->approval_queue(); $id='appr_'.wp_generate_password(16,false,false); $q[$id]=['id'=>$id,'tool'=>$tool,'args'=>$this->redact_recursive($args),'approval_hash'=>$this->approval_args_hash($args),'status'=>'pending','created_at'=>gmdate('c'),'created_by_ip'=>$_SERVER['REMOTE_ADDR']??'','approved_at'=>null,'rejected_at'=>null,'executed_at'=>null,'result_summary'=>null]; $this->save_approval_queue($q); return ['approval_required'=>true,'approval_id'=>$id,'status'=>'pending','message'=>'Approval required before executing '.$tool.'. Approve it in WordPress Admin → Settings → WP Full MCP, then call the same tool with approval_id.']; }
    private function approval_args_hash(array $args): string { $copy=$args; unset($copy['approval_id']); ksort($copy); return hash('sha256', wp_json_encode($copy, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
    private function consume_approval(string $tool, array $args): ?array { $approval_id=sanitize_text_field($args['approval_id']??''); if(!$approval_id) return null; $q=$this->approval_queue(); if(empty($q[$approval_id])) throw new RuntimeException('Approval request not found'); $item=$q[$approval_id]; if(($item['tool']??'')!==$tool) throw new RuntimeException('Approval tool mismatch'); if(($item['status']??'')!=='approved') throw new RuntimeException('Approval is not approved'); if(($item['approval_hash'] ?? '') !== $this->approval_args_hash($args)) throw new RuntimeException('Approval arguments changed; create a new approval request'); $q[$approval_id]['status']='executed'; $q[$approval_id]['executed_at']=gmdate('c'); $this->save_approval_queue($q); return $q[$approval_id]; }
    private function finish_approval_result(?array $approval, $result, bool $is_error): void { if(!$approval || empty($approval['id'])) return; $q=$this->approval_queue(); if(empty($q[$approval['id']])) return; $q[$approval['id']]['result_summary']=['is_error'=>$is_error,'summary'=>is_string($result)?mb_substr($result,0,300):mb_substr(wp_json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),0,300)]; $this->save_approval_queue($q); }
    private function handle_queue_admin_action(string $qid, string $action): string { if(!current_user_can('manage_options')) return 'Insufficient capability.'; $q=$this->approval_queue(); if(empty($q[$qid])) return 'Approval request not found.'; if($action==='approve'){ $q[$qid]['status']='approved'; $q[$qid]['approved_at']=gmdate('c'); } elseif($action==='reject'){ $q[$qid]['status']='rejected'; $q[$qid]['rejected_at']=gmdate('c'); } elseif($action==='delete'){ unset($q[$qid]); $this->save_approval_queue($q); return 'Approval request deleted.'; } else { return 'Unknown queue action.'; } $this->save_approval_queue($q); return 'Approval request '.$action.'d.'; }
    private function render_queue_table(): void { $q=array_reverse($this->approval_queue()); if(!$q){ echo '<p>No approval requests.</p>'; return; } echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Status</th><th>Tool</th><th>Approval ID</th><th>Arguments</th><th>Action</th></tr></thead><tbody>'; foreach($q as $item){ echo '<tr>'; echo '<td>'.esc_html($item['created_at']??'').'</td><td>'.esc_html($item['status']??'').'</td><td><code>'.esc_html($item['tool']??'').'</code></td><td><code>'.esc_html($item['id']??'').'</code></td><td><pre style="white-space:pre-wrap;max-width:420px">'.esc_html(wp_json_encode($this->redact_recursive($item['args']??[]), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></td><td>'; if(($item['status']??'')==='pending'){ echo '<form method="post" style="display:inline">'; wp_nonce_field('wpfmcp_queue'); echo '<input type="hidden" name="queue_id" value="'.esc_attr($item['id']).'"><button class="button button-primary" name="wpfmcp_queue_action" value="approve">Approve</button> <button class="button" name="wpfmcp_queue_action" value="reject">Reject</button></form>'; } echo '<form method="post" style="display:inline;margin-left:4px">'; wp_nonce_field('wpfmcp_queue'); echo '<input type="hidden" name="queue_id" value="'.esc_attr($item['id']).'"><button class="button" name="wpfmcp_queue_action" value="delete">Delete</button></form>'; echo '</td></tr>'; } echo '</tbody></table>'; }
    private function render_log_table(): void { $log=get_option('wpfmcp_log', []); if(!$log){ echo '<p>No log entries.</p>'; return; } echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Tool</th><th>IP</th><th>Args</th></tr></thead><tbody>'; foreach(array_slice((array)$log,0,100) as $row){ echo '<tr><td>'.esc_html($row['time']??'').'</td><td><code>'.esc_html($row['tool']??'').'</code></td><td>'.esc_html($row['ip']??'').'</td><td><pre style="white-space:pre-wrap;max-width:640px">'.esc_html(wp_json_encode($row['args']??[], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></td></tr>'; } echo '</tbody></table>'; }

    public function handle_rest(WP_REST_Request $request) {
        $payload = $this->handle_request((string) $request['secret'], $request);
        $data = json_decode($payload['body'] ?: '{}');
        $response = new WP_REST_Response($data, (int) $payload['status']);
        foreach (($payload['headers'] ?? []) as $k => $v) {
            $response->header($k, $v);
        }
        return $response;
    }

    private function handle_http(string $secret): void {
        $payload = $this->handle_request($secret, null);
        status_header($payload['status']);
        foreach ($payload['headers'] as $k => $v) header($k . ': ' . $v);
        echo $payload['body'];
    }

    private function request_authorized(string $secret, ?WP_REST_Request $request = null): bool { $stored=(string)($this->opts['secret'] ?? ''); if(!$stored) return false; if($secret !== 'bearer') return hash_equals($stored, $secret); $auth=$request ? (string)$request->get_header('authorization') : (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')); return (bool)(preg_match('/^Bearer\s+(.+)$/i', $auth, $m) && hash_equals($stored, trim($m[1]))); }
    private function authenticate_service_user(): void { if(get_current_user_id()) return; $user_id=absint($this->opts['service_user_id'] ?? 0); if(!$user_id || !get_userdata($user_id)) throw new RuntimeException('MCP service user is not configured. Set a valid Service user ID in WP Full MCP settings.'); wp_set_current_user($user_id); }
    private function handle_request(string $secret, ?WP_REST_Request $request = null) {
        $headers = ['Content-Type' => 'application/json; charset=UTF-8', 'Mcp-Session-Id' => 'wp-full-mcp'];
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') return ['status' => 204, 'headers' => $headers, 'body' => ''];
        if (empty($this->opts['enabled']) || !$this->request_authorized($secret, $request)) {
            return ['status' => 404, 'headers' => $headers, 'body' => wp_json_encode(['error' => 'Not found'])];
        }
        try { $this->authenticate_service_user(); } catch (Throwable $e) { return ['status' => 500, 'headers' => $headers, 'body' => wp_json_encode(['error' => $e->getMessage()])]; }
        $raw = file_get_contents('php://input');
        $req = json_decode($raw ?: '{}', true) ?: [];
        $id = $req['id'] ?? null;
        $method = $req['method'] ?? '';
        if ($method === 'initialize') {
            return $this->json_response($id, ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => ['listChanged' => true]], 'serverInfo' => ['name' => 'WP Full MCP Gateway', 'version' => WPFMCP_VERSION], 'instructions' => 'Controlled WordPress MCP tools for content and admin operations. For Elementor page building: call wp-elementor-agent-guide first to get the comprehensive guide covering architecture, workflow, containers, widgets, design system, CSS/JS patterns, and critical rules. For Elementor Template Kit ZIP: call wp-elementor-template-kit-install with kit_url (public HTTPS URL). It returns {status:"queued", import_id}. Poll wp-elementor-kit-import-status with import_id. To remove: wp-elementor-template-kit-remove. For clean re-import testing, use wp-elementor-kit-erase with dry_run=true first. Ask before destructive actions.']);
        }
        if ($method === 'tools/list') {
            return $this->json_response($id, ['tools' => $this->tools()]);
        }
        if ($method === 'tools/call') {
            $name = sanitize_key($req['params']['name'] ?? '');
            $args = is_array($req['params']['arguments'] ?? null) ? $req['params']['arguments'] : [];
            $this->log_action($name, $args);
            if (!$this->group_enabled_for_tool($name)) {
                return $this->tool_response($id, ['error' => 'Tool group disabled', 'tool' => $name, 'group' => $this->tool_group($name)], true);
            }
            if (!empty($args['dry_run'])) {
                $preview=$this->dry_run_preview($name, $args); $this->audit_log($name, $args, 'dry_run', $preview);
                return $this->tool_response($id, $preview, false);
            }
            if ($this->approval_required($name) && empty($args['approval_id'])) {
                $req=$this->create_approval_request($name, $args); $this->audit_log($name, $args, 'approval_required', $req);
                return $this->tool_response($id, $req, false);
            }
            $approval = null;
            try {
                $approval = $this->approval_required($name) ? $this->consume_approval($name, $args) : null;
                $backup = $this->maybe_backup_before($name, $args);
                [$result, $is_error] = $this->call_tool($name, $args);
                if ($backup !== null) { $result = ['backup' => $backup, 'result' => $result]; }
                $this->finish_approval_result($approval, $result, $is_error);
                $this->audit_log($name, $args, $is_error ? 'error' : 'success', $result);
                return $this->tool_response($id, $result, $is_error);
            } catch (Throwable $e) {
                $err = ['error' => $e->getMessage(), 'tool' => $name];
                $this->finish_approval_result($approval, $err, true);
                $this->audit_log($name, $args, 'error', $err);
                return $this->tool_response($id, $err, true);
            }
        }
        if ($id === null) return ['status' => 202, 'headers' => ['Content-Type' => 'application/json; charset=UTF-8'], 'body' => wp_json_encode(['ok' => true])];
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json; charset=UTF-8'], 'body' => wp_json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']])];
    }

    private function json_response($id, array $result): array {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json; charset=UTF-8', 'Mcp-Session-Id' => 'wp-full-mcp'], 'body' => wp_json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    private function tool_response($id, $payload, bool $is_error = false): array {
        return $this->json_response($id, ['content' => [['type' => 'text', 'text' => is_string($payload) ? $payload : wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'isError' => $is_error]);
    }

    private function tools(): array {
        $tools = [
            $this->tool('wp-site-info','Site Info','Get WordPress site info.',[], true),
            $this->tool('wp-list-posts','List Posts/Pages','List posts or pages.',['post_type'=>['type'=>'string','enum'=>['post','page','product']], 'status'=>['type'=>'string'], 'search'=>['type'=>'string'], 'per_page'=>['type'=>'integer']], true),
            $this->tool('wp-get-post','Get Post/Page','Get a post/page by ID.',['id'=>['type'=>'integer'], 'post_type'=>['type'=>'string','enum'=>['post','page','product']]], true, ['id']),
            $this->tool('wp-create-post','Create Post/Page/Product','Create a post/page, or create WooCommerce product when post_type=product. For products, title maps to product name; content to description; excerpt to short_description. Supports _regular_price, _price, _thumbnail_id.',['title'=>['type'=>'string'], 'content'=>['type'=>'string'], 'status'=>['type'=>'string','enum'=>['draft','publish','pending','private']], 'post_type'=>['type'=>'string','enum'=>['post','page','product']], 'excerpt'=>['type'=>'string'], 'slug'=>['type'=>'string'], 'categories'=>['type'=>'array','items'=>['type'=>'integer']], 'tags'=>['type'=>'array','items'=>['type'=>'integer']], 'sku'=>['type'=>'string'], 'regular_price'=>['type'=>'string'], '_regular_price'=>['type'=>'string'], '_price'=>['type'=>'string'], 'sale_price'=>['type'=>'string'], 'stock_status'=>['type'=>'string','enum'=>['instock','outofstock','onbackorder']], 'stock_quantity'=>['type'=>'integer'], 'manage_stock'=>['type'=>'boolean'], 'image_id'=>['type'=>'integer'], '_thumbnail_id'=>['type'=>'integer']], false, ['title']),
            $this->tool('wp-update-post','Update Post/Page/Product','Update a post/page, or update WooCommerce product when post_type=product. Supports _regular_price, _price, _thumbnail_id.',['id'=>['type'=>'integer'], 'post_type'=>['type'=>'string','enum'=>['post','page','product']], 'title'=>['type'=>'string'], 'content'=>['type'=>'string'], 'status'=>['type'=>'string','enum'=>['draft','publish','pending','private','trash']], 'excerpt'=>['type'=>'string'], 'slug'=>['type'=>'string'], 'categories'=>['type'=>'array','items'=>['type'=>'integer']], 'tags'=>['type'=>'array','items'=>['type'=>'integer']], 'sku'=>['type'=>'string'], 'regular_price'=>['type'=>'string'], '_regular_price'=>['type'=>'string'], '_price'=>['type'=>'string'], 'sale_price'=>['type'=>'string'], 'stock_status'=>['type'=>'string','enum'=>['instock','outofstock','onbackorder']], 'stock_quantity'=>['type'=>'integer'], 'manage_stock'=>['type'=>'boolean'], 'image_id'=>['type'=>'integer'], '_thumbnail_id'=>['type'=>'integer']], false, ['id']),
            $this->tool('wp-trash-post','Trash Post/Page','Move post/page to trash; force=true permanently deletes.',['id'=>['type'=>'integer'], 'post_type'=>['type'=>'string','enum'=>['post','page','product']], 'force'=>['type'=>'boolean']], false, ['id'], true),
            $this->tool('wp-clone-post','Clone Post/Page','Clone/Duplicate a post or page with all metadata, taxonomies, and Elementor data intact. Creates a draft copy.',[ 'post_id'=>['type'=>'integer'],'title'=>['type'=>'string'],'slug'=>['type'=>'string'],'status'=>['type'=>'string']], false, ['post_id']),
            $this->tool('wp-list-categories','List Categories','List categories.',['search'=>['type'=>'string'], 'per_page'=>['type'=>'integer']], true),
            $this->tool('wp-create-category','Create Category','Create a category.',['name'=>['type'=>'string'], 'slug'=>['type'=>'string'], 'description'=>['type'=>'string']], false, ['name']),
            $this->tool('wp-list-media','List Media','List media library items.',['search'=>['type'=>'string'], 'per_page'=>['type'=>'integer']], true),
            $this->tool('wp-list-plugins','List Plugins','List installed plugins.',[], true),
            $this->tool('wp-install-plugin','Install Plugin','Install a WordPress.org plugin by slug. Disabled unless allowed in settings.',['slug'=>['type'=>'string'], 'activate'=>['type'=>'boolean']], false, ['slug']),
            $this->tool('wp-activate-plugin','Activate Plugin','Activate an installed plugin by plugin file or slug. Disabled unless allowed in settings and requires confirm_text=ACTIVATE PLUGIN.',['plugin'=>['type'=>'string'], 'confirm_text'=>['type'=>'string']], false, ['plugin','confirm_text']),
            $this->tool('wp-deactivate-plugin','Deactivate Plugin','Deactivate an installed plugin by plugin file or slug. Disabled unless allowed in settings and requires confirm_text=DEACTIVATE PLUGIN.',['plugin'=>['type'=>'string'], 'confirm_text'=>['type'=>'string']], false, ['plugin','confirm_text'], true),
            $this->tool('wp-update-plugin','Update Plugin','Update an installed plugin. Disabled unless allowed in settings.',['plugin'=>['type'=>'string']], false, ['plugin']),
            $this->tool('wp-option-get','Get Option','Get a WordPress option.',['name'=>['type'=>'string']], true, ['name']),
            $this->tool('wp-option-update','Update Option','Update a WordPress option. Avoid siteurl/home unless explicitly approved.',['name'=>['type'=>'string'], 'value'=>['type'=>'string']], false, ['name'], true),
            $this->tool('wp-flush-rewrite','Flush Rewrite Rules','Flush WordPress rewrite rules.',[], false),
            $this->tool('wp-elementor-flush-css','Elementor Flush CSS','Flush Elementor CSS cache if Elementor is active.',[], false),
            $this->tool('wp-elementor-mcp-status','Elementor MCP Status','Check Elementor, MCP Adapter, and Elementor MCP plugin availability.',[], true),
            $this->tool('wp-elementor-mcp-info','Elementor MCP Info','Get Elementor MCP bridge route, requirements, and connector guidance.',[], true),
            $this->tool('wp-elementor-mcp-forward','Elementor MCP Forward','Forward a JSON-RPC payload to the active Elementor MCP plugin route. Requires Elementor MCP plugin active. Use for Elementor layout tools instead of reimplementing them here.',['payload'=>['type'=>'object','description'=>'JSON-RPC MCP payload to forward, e.g. {"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'], 'mcp_session_id'=>['type'=>'string','description'=>'Optional Mcp-Session-Id returned by a prior initialize call.']], false, ['payload']),
            $this->tool('wp-elementor-kit-import','Elementor Kit Import','Import an Elementor website/template kit zip from a public HTTPS URL. ChatGPT uploaded-file sandbox paths are not supported; provide kit_url instead. Returns an import_id that can be used for deletion.',['kit_url'=>['type'=>'string','description'=>'Public HTTPS URL to an Elementor kit zip. Required. ChatGPT can provide a temporary download URL for user-uploaded files.'], 'include'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Optional import sections, e.g. content, templates, site-settings. Empty imports Elementor defaults.'], 'label'=>['type'=>'string','description'=>'Human label for tracking this import.']], false, ['kit_url']),
            $this->tool('wp-elementor-template-kit-install','Install Elementor Template Kit ZIP','Install/import an Elementor Template Kit ZIP directly into WordPress from a public HTTPS kit_url. Use this when asked to install an Elementor template kit. ChatGPT uploaded files/local sandbox paths cannot be read by WordPress. Returns import_id for removal.',['kit_url'=>['type'=>'string','description'=>'Public HTTPS URL to the Elementor Template Kit ZIP. Required for ChatGPT. ChatGPT: when user uploads a ZIP, you can generate a temporary upload URL for it. Provide the URL here.'], 'include'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Optional sections: content, templates, site-settings. Empty imports Elementor defaults.'], 'label'=>['type'=>'string','description'=>'Human label for tracking this kit install.']], false, ['kit_url']),
            $this->tool('wp-elementor-kit-list-imports','Elementor Kit List Imports','List Elementor kit imports tracked by WP Full MCP Gateway.',[], true),
            $this->tool('wp-elementor-kit-delete','Elementor Kit Delete','Delete posts/templates/media tracked for a prior Elementor kit import. Requires confirm_text=DELETE ELEMENTOR KIT.',['import_id'=>['type'=>'string'], 'confirm_text'=>['type'=>'string'], 'force'=>['type'=>'boolean','description'=>'If true, permanently delete instead of trash where possible.']], false, ['import_id','confirm_text'], true),
             $this->tool('wp-elementor-kit-import-status','Elementor Kit Import Status','Check the status of a queued/running/completed Elementor kit import.',['import_id'=>['type'=>'string']], true, ['import_id']),
            $this->tool('wp-elementor-kit-erase','Elementor Kit Eraser','Clean slate for Elementor kit testing. Deletes tracked imported posts/pages/templates/media, clears pending kit jobs, regenerates Theme Builder conditions, and flushes Elementor CSS. Requires confirm_text=ERASE ELEMENTOR KIT DATA to execute. Use dry_run=true first.',['import_id'=>['type'=>'string','description'=>'Optional import_id to erase one tracked import. Omit to erase all tracked imports.'], 'force'=>['type'=>'boolean','description'=>'If true, permanently delete instead of moving posts/templates/pages to trash where possible.'], 'clear_pending'=>['type'=>'boolean','description'=>'If true, clear queued/running pending Elementor kit import jobs. Default true.'], 'reset_front_page'=>['type'=>'boolean','description'=>'If true, reset static front page options when the selected front page is erased. Default true.'], 'confirm_text'=>['type'=>'string','description'=>'Must be ERASE ELEMENTOR KIT DATA to execute. Not needed for dry_run preview.']], false, [], true),
            $this->tool('wp-elementor-template-kit-remove','Remove Elementor Template Kit','Remove/delete a previously installed Elementor Template Kit by import_id. Requires confirm_text=DELETE ELEMENTOR KIT.',['import_id'=>['type'=>'string'], 'confirm_text'=>['type'=>'string'], 'force'=>['type'=>'boolean','description'=>'If true, permanently delete instead of trash where possible.']], false, ['import_id','confirm_text'], true),
            $this->tool('wp-elementor-convert-columns-to-containers','Convert Columns to Containers','Convert Elementor page/post/template layouts from section/column to container/flexbox. Preserves visual output. Supports single page, batch by IDs, or batch by kit import_id. Use wp-elementor-kit-list-imports to import_ids.',['page_id'=>['type'=>'integer', 'description'=>'Single page/post ID to convert.'], 'page_ids'=>['type'=>'array', 'items'=>['type'=>'integer'], 'description'=>'Array of page IDs to convert in batch.'], 'import_id'=>['type'=>'string', 'description'=>'Kit import_id to convert all pages from that import. Use wp-elementor-kit-list-imports.']], false, [], true),
            $this->tool('wc-status','WooCommerce Status','Check WooCommerce plugin, native MCP feature flag, REST route, and store currency.',[], true),
            $this->tool('wc-enable-native-mcp','Enable WooCommerce Native MCP','Enable WooCommerce mcp_integration feature flag option. Requires confirm_text=ENABLE WC MCP.',['confirm_text'=>['type'=>'string']], false, ['confirm_text']),
            $this->tool('wc-list-products','Woo List Products','List WooCommerce products with filtering and pagination.',['search'=>['type'=>'string'], 'status'=>['type'=>'string'], 'type'=>['type'=>'string'], 'sku'=>['type'=>'string'], 'category'=>['type'=>'integer'], 'page'=>['type'=>'integer'], 'per_page'=>['type'=>'integer']], true),
            $this->tool('wc-get-product','Woo Get Product','Get WooCommerce product details by product ID.',['id'=>['type'=>'integer']], true, ['id']),
            $this->tool('wc-create-product','WooCommerce API Create Product','Create a WooCommerce product using WooCommerce CRUD/API. Supports regular_price and explicit meta-style aliases _regular_price, _price, _thumbnail_id. Defaults to draft.',['name'=>['type'=>'string'], 'status'=>['type'=>'string','enum'=>['draft','publish','private']], 'sku'=>['type'=>'string'], 'regular_price'=>['type'=>'string','description'=>'WooCommerce regular price. Also writes _regular_price/_price via Woo CRUD.'], '_regular_price'=>['type'=>'string','description'=>'Alias for regular_price meta.'], '_price'=>['type'=>'string','description'=>'Alias for active product price; use with _regular_price when needed.'], 'sale_price'=>['type'=>'string'], 'description'=>['type'=>'string'], 'short_description'=>['type'=>'string'], 'manage_stock'=>['type'=>'boolean'], 'stock_quantity'=>['type'=>'integer'], 'stock_status'=>['type'=>'string','enum'=>['instock','outofstock','onbackorder']], 'category_ids'=>['type'=>'array','items'=>['type'=>'integer']], 'image_id'=>['type'=>'integer','description'=>'Product featured image attachment ID.'], '_thumbnail_id'=>['type'=>'integer','description'=>'Alias for product featured image attachment ID.']], false, ['name']),
            $this->tool('wc-update-product','WooCommerce API Update Product','Update a WooCommerce product by ID using WooCommerce CRUD/API. Supports regular_price and explicit meta-style aliases _regular_price, _price, _thumbnail_id.',['id'=>['type'=>'integer'], 'name'=>['type'=>'string'], 'status'=>['type'=>'string','enum'=>['draft','publish','private']], 'sku'=>['type'=>'string'], 'regular_price'=>['type'=>'string','description'=>'WooCommerce regular price. Also writes _regular_price/_price via Woo CRUD.'], '_regular_price'=>['type'=>'string','description'=>'Alias for regular_price meta.'], '_price'=>['type'=>'string','description'=>'Alias for active product price.'], 'sale_price'=>['type'=>'string'], 'description'=>['type'=>'string'], 'short_description'=>['type'=>'string'], 'manage_stock'=>['type'=>'boolean'], 'stock_quantity'=>['type'=>'integer'], 'stock_status'=>['type'=>'string','enum'=>['instock','outofstock','onbackorder']], 'category_ids'=>['type'=>'array','items'=>['type'=>'integer']], 'image_id'=>['type'=>'integer','description'=>'Product featured image attachment ID.'], '_thumbnail_id'=>['type'=>'integer','description'=>'Alias for product featured image attachment ID.']], false, ['id']),
            $this->tool('wc-set-product-pricing','WooCommerce Set Product Pricing','Set WooCommerce product price fields explicitly: _regular_price, _price, and optional sale_price. Uses WooCommerce CRUD and updates product meta compatibility fields.',['id'=>['type'=>'integer'], '_regular_price'=>['type'=>'string'], '_price'=>['type'=>'string'], 'regular_price'=>['type'=>'string'], 'sale_price'=>['type'=>'string']], false, ['id']),
            $this->tool('wc-set-product-thumbnail','WooCommerce Set Product Thumbnail','Set product featured image / _thumbnail_id from a WordPress media attachment ID.',['id'=>['type'=>'integer'], '_thumbnail_id'=>['type'=>'integer'], 'image_id'=>['type'=>'integer']], false, ['id']),
            $this->tool('wc-delete-product','Woo Delete Product','Trash or permanently delete a WooCommerce product. Requires confirm_text=DELETE PRODUCT for force=true.',['id'=>['type'=>'integer'], 'force'=>['type'=>'boolean'], 'confirm_text'=>['type'=>'string']], false, ['id'], true),
            $this->tool('wc-list-orders','Woo List Orders','List WooCommerce orders. PII is omitted unless include_pii=true.',['status'=>['type'=>'string'], 'search'=>['type'=>'string'], 'page'=>['type'=>'integer'], 'per_page'=>['type'=>'integer'], 'include_pii'=>['type'=>'boolean']], true),
            $this->tool('wc-get-order','Woo Get Order','Get WooCommerce order details by ID. PII is omitted unless include_pii=true.',['id'=>['type'=>'integer'], 'include_pii'=>['type'=>'boolean']], true, ['id']),
            $this->tool('wc-update-order-status','Woo Update Order Status','Update a WooCommerce order status.',['id'=>['type'=>'integer'], 'status'=>['type'=>'string'], 'note'=>['type'=>'string']], false, ['id','status']),
            $this->tool('wc-add-order-note','Woo Add Order Note','Add an internal or customer-visible note to a WooCommerce order.',['id'=>['type'=>'integer'], 'note'=>['type'=>'string'], 'customer_note'=>['type'=>'boolean']], false, ['id','note']),
            $this->tool('wp-upload-media-from-url','Upload Media From URL','Download an image/file URL into the WordPress media library. Disabled unless media upload is allowed.',['url'=>['type'=>'string'], 'title'=>['type'=>'string'], 'alt'=>['type'=>'string'], 'caption'=>['type'=>'string']], false, ['url']),
            $this->tool('wp-list-themes','List Themes','List installed WordPress themes.',[], true),
            $this->tool('wp-install-theme','Install Theme','Install a WordPress.org theme by slug. Disabled unless allowed in settings and requires confirm_text=INSTALL THEME. If activate=true, also requires switch_confirm_text=SWITCH THEME and theme switch permission.',['slug'=>['type'=>'string'], 'activate'=>['type'=>'boolean'], 'confirm_text'=>['type'=>'string'], 'switch_confirm_text'=>['type'=>'string']], false, ['slug']),
            $this->tool('wp-update-theme','Update Theme','Update an installed theme. Disabled unless allowed in settings and requires confirm_text=UPDATE THEME.',['slug'=>['type'=>'string'], 'confirm_text'=>['type'=>'string']], false, ['slug']),
            $this->tool('wp-activate-theme','Activate Theme','Switch active theme. Disabled unless allowed in settings and requires confirm_text=SWITCH THEME.',['slug'=>['type'=>'string'], 'confirm_text'=>['type'=>'string']], false, ['slug'], true),
            $this->tool('wp-list-users','List Users','Read-only list of WordPress users with roles.',['search'=>['type'=>'string'], 'role'=>['type'=>'string'], 'number'=>['type'=>'integer']], true),
            $this->tool('wp-health-check','Health Check','Basic WordPress/PHP/plugin/theme health snapshot.',[], true),
            $this->tool('wp-create-backup','Create Backup','Create an on-demand backup using configured backup strategy.', ['label'=>['type'=>'string']], false),
            $this->tool('wp-list-backups','List Backups','List WP Full MCP database backup files.', [], true),
            $this->tool('wp-backup-health-check','Backup Health Check','Check WP-Cron, WPvivid availability, backup directories, and free disk space.', [], true),
            $this->tool('wp-backup-before-action-status','Backup Before Action Status','Check whether a tool is configured for backup-before-action and inspect latest backup state.', ['tool'=>['type'=>'string']], true),
            $this->tool('wp-prune-backups','Prune Internal Backups','Delete old internal DB export backups, keeping last N.', ['keep'=>['type'=>'integer']], false),
            $this->tool('wp-connector-manifest','Connector Manifest','Return compact capability manifest for ChatGPT/remote MCP clients.', [], true),
            $this->tool('wp-connector-config','Connector Config','Return copy-paste connector endpoint/config snippets for MCP clients.', ['include_secret'=>['type'=>'boolean']], true),
            $this->tool('wp-export-diagnostics','Export Diagnostics','Return sanitized diagnostic summary without secrets.', [], true),
            $this->tool('wp-audit-log','Audit Log','List recent gateway audit/action log entries.', ['limit'=>['type'=>'integer'], 'tool'=>['type'=>'string']], true),
            $this->tool('wp-export-settings','Export Gateway Settings','Export sanitized WP Full MCP settings for backup/migration. Secret is omitted by default.', ['include_secret'=>['type'=>'boolean']], true),
            $this->tool('wp-import-settings','Import Gateway Settings','Import WP Full MCP settings from a prior export. Requires confirm_text=IMPORT SETTINGS. Secret is preserved unless rotate_secret=true or include_secret import is explicitly supplied.', ['settings'=>['type'=>'object'], 'settings_json'=>['type'=>'string'], 'rotate_secret'=>['type'=>'boolean'], 'confirm_text'=>['type'=>'string']], false, ['confirm_text']),
            $this->tool('wp-wpvivid-status','WPvivid Status','Check whether WPvivid backup plugins/interfaces are available.', [], true),
            $this->tool('wp-wpvivid-create-backup','WPvivid Create Backup','Prepare and queue a WPvivid backup task asynchronously. Returns task_id immediately.', ['backup_files'=>['type'=>'string','enum'=>['all','db','files']], 'remote'=>['type'=>'boolean'], 'run_async'=>['type'=>'boolean']], false),
            $this->tool('wp-wpvivid-task-status','WPvivid Task Status','Get WPvivid task status by task_id.', ['task_id'=>['type'=>'string']], true),
            $this->tool('wp-wpvivid-list-backups','WPvivid List Backups','List WPvivid backup records through WPvivid public interface.', [], true),
            $this->tool('wp-list-approval-requests','List Approval Requests','List MCP approval queue requests.', ['status'=>['type'=>'string']], true),
            $this->tool('wp-cancel-approval-request','Cancel Approval Request','Cancel/delete a pending MCP approval request.', ['approval_id'=>['type'=>'string']], false, ['approval_id']),
            $this->tool('wp-elementor-agent-guide','Elementor Agent Guide','Comprehensive guide for AI agents on building Elementor pages via MCP tools. Covers architecture, workflow, containers, widgets, design system, CSS/JS, and critical rules. Read-only, no side effects.',[], true),
        ];
        return array_values(array_filter($tools, fn($t) => $this->group_enabled_for_tool($t['name'] ?? '')));
    }

    private function tool(string $name, string $title, string $description, array $properties, bool $read_only, array $required = [], bool $destructive = false): array {
        if (!$read_only) {
            $properties['approval_id'] = ['type' => 'string', 'description' => 'Optional approved approval request ID for tools that require approval.'];
            $properties['dry_run'] = ['type' => 'boolean', 'description' => 'If true, preview what would happen without changing WordPress data.'];
        }
        return ['name'=>$name,'title'=>$title,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$properties,'required'=>$required],'annotations'=>['readOnlyHint'=>$read_only,'destructiveHint'=>$destructive,'idempotentHint'=>$read_only]];
    }

    private function call_tool(string $name, array $args): array {
        try {
            return match ($name) {
                'wp-site-info' => [$this->site_info(), false],
                'wp-list-posts' => [$this->list_posts($args), false],
                'wp-get-post' => [$this->get_post_tool($args), false],
                'wp-create-post' => [$this->create_post_tool($args), false],
                'wp-update-post' => [$this->update_post_tool($args), false],
                'wp-trash-post' => [$this->trash_post_tool($args), false],
                'wp-clone-post' => [$this->clone_post($args), false],
                'wp-list-categories' => [$this->list_categories($args), false],
                'wp-create-category' => [$this->create_category($args), false],
                'wp-list-media' => [$this->list_media($args), false],
                'wp-list-plugins' => [$this->list_plugins(), false],
                'wp-install-plugin' => [$this->install_plugin($args), false],
                'wp-activate-plugin' => [$this->activate_plugin($args), false],
                'wp-deactivate-plugin' => [$this->deactivate_plugin($args), false],
                'wp-update-plugin' => [$this->update_plugin($args), false],
                'wp-option-get' => [$this->option_get($args), false],
                'wp-option-update' => [$this->option_update($args), false],
                'wp-flush-rewrite' => [$this->flush_rewrite_tool(), false],
                'wp-elementor-flush-css' => [$this->elementor_flush_css(), false],
                'wp-elementor-mcp-status' => [$this->elementor_mcp_status(), false],
                'wp-elementor-mcp-info' => [$this->elementor_mcp_info(), false],
                'wp-elementor-mcp-forward' => [$this->elementor_mcp_forward($args), false],
                'wp-elementor-kit-import' => [$this->elementor_kit_import($args), false],
                'wp-elementor-template-kit-install' => [$this->elementor_kit_import($args), false],
                'wp-elementor-kit-list-imports' => [$this->elementor_kit_list_imports(), false],
                'wp-elementor-kit-delete' => [$this->elementor_kit_delete($args), false],
                'wp-elementor-template-kit-remove' => [$this->elementor_kit_delete($args), false],
                'wp-elementor-kit-import-status' => [$this->elementor_kit_import_status($args), false],
                'wp-elementor-kit-erase' => [$this->elementor_kit_erase($args), false],
                'wp-elementor-convert-columns-to-containers' => [$this->elementor_convert_columns_to_containers($args), false],
                'wc-status' => [$this->wc_status(), false],
                'wc-enable-native-mcp' => [$this->wc_enable_native_mcp($args), false],
                'wc-list-products' => [$this->wc_list_products($args), false],
                'wc-get-product' => [$this->wc_get_product_tool($args), false],
                'wc-create-product' => [$this->wc_create_product($args), false],
                'wc-update-product' => [$this->wc_update_product($args), false],
                'wc-set-product-pricing' => [$this->wc_set_product_pricing($args), false],
                'wc-set-product-thumbnail' => [$this->wc_set_product_thumbnail($args), false],
                'wc-delete-product' => [$this->wc_delete_product($args), false],
                'wc-list-orders' => [$this->wc_list_orders($args), false],
                'wc-get-order' => [$this->wc_get_order_tool($args), false],
                'wc-update-order-status' => [$this->wc_update_order_status($args), false],
                'wc-add-order-note' => [$this->wc_add_order_note($args), false],
                'wp-upload-media-from-url' => [$this->upload_media_from_url($args), false],
                'wp-list-themes' => [$this->list_themes(), false],
                'wp-install-theme' => [$this->install_theme($args), false],
                'wp-update-theme' => [$this->update_theme($args), false],
                'wp-activate-theme' => [$this->activate_theme($args), false],
                'wp-list-users' => [$this->list_users($args), false],
                'wp-health-check' => [$this->health_check(), false],
                'wp-create-backup' => [$this->create_backup_tool($args), false],
                'wp-list-backups' => [$this->list_backups(), false],
                'wp-backup-health-check' => [$this->backup_health_check(), false],
                'wp-backup-before-action-status' => [$this->backup_before_action_status((string)($args['tool'] ?? '')), false],
                'wp-prune-backups' => [$this->prune_backups_tool($args), false],
                'wp-connector-manifest' => [$this->connector_manifest(), false],
                'wp-connector-config' => [$this->connector_config(!empty($args['include_secret'])), false],
                'wp-export-diagnostics' => [$this->export_diagnostics(), false],
                'wp-audit-log' => [$this->audit_log_tool($args), false],
                'wp-export-settings' => [$this->export_settings_tool($args), false],
                'wp-import-settings' => [$this->import_settings_tool($args), false],
                'wp-wpvivid-status' => [$this->wpvivid_status(), false],
                'wp-wpvivid-create-backup' => [$this->wpvivid_create_backup($args), false],
                'wp-wpvivid-task-status' => [$this->wpvivid_task_status((string)($args['task_id'] ?? '')), false],
                'wp-wpvivid-list-backups' => [$this->wpvivid_list_backups(), false],
                'wp-list-approval-requests' => [$this->list_approval_requests($args), false],
                'wp-cancel-approval-request' => [$this->cancel_approval_request($args), false],
                'wp-elementor-agent-guide' => [$this->elementor_agent_guide(), false],
                default => [['error' => 'Unknown tool', 'name' => $name], true],
            };
        } catch (Throwable $e) {
            return [['error' => $e->getMessage()], true];
        }
    }

    private function assert_can(string $cap = 'manage_options', ...$args): void {
        if (!get_current_user_id()) throw new RuntimeException('Authenticated WordPress user required for capability: ' . $cap);
        if (!current_user_can($cap, ...$args)) throw new RuntimeException('Insufficient WordPress capability: ' . $cap);
    }

    private function pt(array $args): string { $pt=sanitize_key($args['post_type'] ?? 'post'); return in_array($pt, ['post','page','product'], true) ? $pt : 'post'; }
    private function compact_post($id): array { $p = get_post($id); if (!$p) return ['id'=>$id,'status'=>'not_found']; return ['id'=>$p->ID,'date'=>get_post_time('c', false, $p),'modified'=>get_post_modified_time('c', false, $p),'slug'=>$p->post_name,'status'=>$p->post_status,'type'=>$p->post_type,'link'=>get_permalink($p),'title'=>get_the_title($p),'excerpt'=>get_the_excerpt($p)]; }

    private function site_info(): array { return ['name'=>get_bloginfo('name'),'url'=>home_url('/'),'siteurl'=>site_url('/'),'admin_url'=>admin_url('/'),'wp_version'=>get_bloginfo('version'),'php_version'=>PHP_VERSION,'active_theme'=>wp_get_theme()->get('Name')]; }
    private function list_posts(array $args): array { if($this->pt($args)==='product') return ($this->wc_list_products($args)['products'] ?? []); $q=['post_type'=>$this->pt($args),'post_status'=>$args['status']??['publish','draft','pending','private','future'],'posts_per_page'=>min(max((int)($args['per_page']??20),1),100),'orderby'=>'modified','order'=>'DESC']; if(!empty($args['search']))$q['s']=sanitize_text_field($args['search']); return array_map(fn($p)=>$this->compact_post($p->ID), get_posts($q)); }
    private function get_post_tool(array $args): array { if($this->pt($args)==='product') return $this->wc_get_product_tool($args); $p=get_post((int)($args['id']??0)); if(!$p || $p->post_type!==$this->pt($args)) throw new RuntimeException('Post not found'); $out=$this->compact_post($p->ID); $out['content']=$p->post_content; return $out; }
    private function post_data(array $args): array { $d=['post_type'=>$this->pt($args)]; foreach(['title'=>'post_title','content'=>'post_content','excerpt'=>'post_excerpt','slug'=>'post_name','status'=>'post_status'] as $k=>$v){ if(array_key_exists($k,$args))$d[$v]=is_string($args[$k])?wp_kses_post($args[$k]):$args[$k]; } if(!isset($d['post_status']))$d['post_status']='draft'; return $d; }
    private function wc_args_from_post_args(array $args): array { $out=$args; if(isset($args['title']) && !isset($out['name'])) $out['name']=$args['title']; if(isset($args['content']) && !isset($out['description'])) $out['description']=$args['content']; if(isset($args['excerpt']) && !isset($out['short_description'])) $out['short_description']=$args['excerpt']; if(isset($args['categories']) && !isset($out['category_ids'])) $out['category_ids']=$args['categories']; if(isset($args['slug'])) $out['slug']=$args['slug']; return $out; }
    private function create_post_tool(array $args): array { if($this->pt($args)==='product') return $this->wc_create_product($this->wc_args_from_post_args($args)); $this->assert_can($this->pt($args)==='page' ? 'edit_pages' : 'edit_posts'); $id=wp_insert_post($this->post_data($args), true); if(is_wp_error($id)) throw new RuntimeException($id->get_error_message()); if($this->pt($args)==='post'){ if(isset($args['categories']))wp_set_post_categories($id,array_map('intval',(array)$args['categories'])); if(isset($args['tags']))wp_set_post_tags($id,array_map('intval',(array)$args['tags'])); } return $this->compact_post($id); }
    private function update_post_tool(array $args): array { if($this->pt($args)==='product') return $this->wc_update_product($this->wc_args_from_post_args($args)); $id=(int)($args['id']??0); $p=get_post($id); if(!$p || $p->post_type!==$this->pt($args)) throw new RuntimeException('Post not found'); $this->assert_can('edit_post', $id); $d=$this->post_data($args); unset($d['post_type']); if(!array_key_exists('status',$args))unset($d['post_status']); $d['ID']=$id; $r=wp_update_post($d,true); if(is_wp_error($r))throw new RuntimeException($r->get_error_message()); if($this->pt($args)==='post'){ if(isset($args['categories']))wp_set_post_categories($id,array_map('intval',(array)$args['categories'])); if(isset($args['tags']))wp_set_post_tags($id,array_map('intval',(array)$args['tags'])); } return $this->compact_post($id); }
    private function trash_post_tool(array $args): array { if($this->pt($args)==='product') return $this->wc_delete_product($args); $id=(int)($args['id']??0); $p=get_post($id); if(!$p || $p->post_type!==$this->pt($args))throw new RuntimeException('Post not found'); $this->assert_can('delete_post', $id); $res=!empty($args['force'])?wp_delete_post($id,true):wp_trash_post($id); if(!$res)throw new RuntimeException('Trash/delete failed'); return ['id'=>$id,'status'=>get_post_status($id)?:'deleted','force'=>!empty($args['force'])]; }
    private function clone_post(array $args): array {
        $post_id = (int)($args['post_id'] ?? 0);
        if ($post_id <= 0) throw new RuntimeException('post_id is required and must be a positive integer');
        $original = get_post($post_id);
        if (!$original || !is_object($original)) throw new RuntimeException('Source post not found with ID: ' . $post_id);
        $post_type = $original->post_type;
        $this->assert_can($post_type === 'page' ? 'edit_pages' : 'edit_posts');
        $title = isset($args['title']) && is_string($args['title']) ? wp_kses_post($args['title']) : $original->post_title . ' (Copy)';
        $slug = isset($args['slug']) && is_string($args['slug']) ? sanitize_title($args['slug']) : '';
        $status = isset($args['status']) && in_array($args['status'], ['draft', 'publish', 'pending', 'private'], true) ? $args['status'] : 'draft';
        $new_data = [
            'post_title'   => $title,
            'post_content' => $original->post_content,
            'post_excerpt' => $original->post_excerpt,
            'post_type'    => $post_type,
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
            'menu_order'   => 0,
        ];
        if ($slug !== '') $new_data['post_name'] = $slug;
        $new_id = wp_insert_post($new_data, true);
        if (is_wp_error($new_id)) throw new RuntimeException('Failed to create clone: ' . $new_id->get_error_message());
        $skip_meta = ['_edit_lock', '_edit_last'];
        $meta_copied = 0;
        $meta_warnings = [];
        global $wpdb;
        $all_meta = get_post_meta($post_id);
        if (is_array($all_meta)) {
            foreach ($all_meta as $key => $values) {
                if (in_array($key, $skip_meta, true)) continue;
                if (!is_array($values)) $values = [$values];
                foreach ($values as $value) {
                    // Use $wpdb->insert() to avoid update_post_meta truncation on large values
                    // and to skip maybe_serialize() which can corrupt JSON strings.
                    $wpdb->insert(
                        $wpdb->postmeta,
                        [
                            'post_id'    => $new_id,
                            'meta_key'   => $key,
                            'meta_value' => $value,
                        ],
                        ['%d', '%s', '%s']
                    );
                    if ($wpdb->insert_id) {
                        $meta_copied++;
                    } else {
                        $meta_warnings[] = [
                            'key'   => $key,
                            'error' => 'insert failed: ' . $wpdb->last_error,
                        ];
                    }
                }
            }
        }
        // Invalidate WP object cache so get_post_meta() reads fresh data from DB
        clean_post_cache($new_id);
        // Post-loop verification: compare source vs clone meta lengths via direct DB
        foreach ($all_meta as $key => $values) {
            if (in_array($key, $skip_meta, true)) continue;
            if (!is_array($values)) $values = [$values];
            foreach ($values as $value) {
                $source_len = strlen($value);
                $clone_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
                    $new_id, $key
                ));
                $clone_len = $clone_row ? strlen($clone_row->meta_value) : 0;
                if ($source_len !== $clone_len && $source_len > 1000) {
                    $meta_warnings[] = [
                        'key'       => $key,
                        'src_len'   => $source_len,
                        'clone_len' => $clone_len,
                        'diff'      => $source_len - $clone_len,
                    ];
                }
            }
        }
        $taxonomies = get_object_taxonomies($post_type, 'names');
        $tax_copied = 0;
        if (!empty($taxonomies)) {
            foreach ($taxonomies as $tax) {
                $terms = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    wp_set_object_terms($new_id, $terms, $tax);
                    $tax_copied += count($terms);
                }
            }
        }
        // Regenerate Elementor CSS for the clone
        $elementor_css_generated = false;
        $elementor_css_error = null;
        if (did_action('elementor/loaded') && class_exists('\Elementor\Plugin')) {
            try {
                $css_file = new \Elementor\Core\Files\CSS\Post($new_id);
                $css_file->update();
                $elementor_css_generated = true;
                \Elementor\Plugin::instance()->files_manager->clear_cache();
            } catch (\Throwable $e) {
                $elementor_css_error = $e->getMessage();
            }
        }

        return [
            'cloned'         => true,
            'source_post_id' => $post_id,
            'new_post_id'    => $new_id,
            'new_title'      => $title,
            'new_slug'       => get_post_field('post_name', $new_id),
            'new_status'     => $status,
            'post_type'      => $post_type,
            'permalink'      => get_permalink($new_id),
            'preview_url'    => get_preview_post_link($new_id),
            'meta_count'     => $meta_copied,
            'meta_warnings'  => $meta_warnings,
            'taxonomy_count' => $tax_copied,
            'elementor_css_generated' => $elementor_css_generated,
            'elementor_css_error' => $elementor_css_error,
        ];
    }

    private function list_categories(array $args): array { $q=['hide_empty'=>false,'number'=>min(max((int)($args['per_page']??50),1),100)]; if(!empty($args['search']))$q['search']=sanitize_text_field($args['search']); return array_map(fn($c)=>['id'=>$c->term_id,'name'=>$c->name,'slug'=>$c->slug,'count'=>$c->count,'description'=>$c->description], get_categories($q)); }
    private function create_category(array $args): array { $this->assert_can('manage_categories'); $r=wp_insert_category(['cat_name'=>sanitize_text_field($args['name']??''),'category_nicename'=>sanitize_title($args['slug']??''),'category_description'=>sanitize_textarea_field($args['description']??'')], true); if(is_wp_error($r))throw new RuntimeException($r->get_error_message()); $c=get_category($r); return ['id'=>$c->term_id,'name'=>$c->name,'slug'=>$c->slug,'description'=>$c->description]; }
    private function list_media(array $args): array { $q=['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>min(max((int)($args['per_page']??20),1),100),'orderby'=>'modified','order'=>'DESC']; if(!empty($args['search']))$q['s']=sanitize_text_field($args['search']); return array_map(fn($p)=>['id'=>$p->ID,'title'=>get_the_title($p),'url'=>wp_get_attachment_url($p->ID),'mime'=>get_post_mime_type($p->ID),'date'=>get_post_time('c',false,$p)], get_posts($q)); }

    private function list_plugins(): array { $this->assert_can('activate_plugins'); require_once ABSPATH.'wp-admin/includes/plugin.php'; $plugins=get_plugins(); $out=[]; foreach($plugins as $file=>$data){ $out[]=['plugin'=>$file,'name'=>$data['Name']??$file,'version'=>$data['Version']??'','active'=>is_plugin_active($file)]; } return $out; }
    private function allowed_slug(string $slug): bool { $slug=sanitize_key($slug); $list=preg_split('/[\s,]+/', (string)$this->opts['allowed_plugin_slugs'], -1, PREG_SPLIT_NO_EMPTY); return $list && in_array($slug, array_map('sanitize_key',$list), true); }
    private function install_plugin(array $args): array { $this->assert_can('install_plugins'); if(empty($this->opts['allow_plugin_install'])) throw new RuntimeException('Plugin install disabled in WP Full MCP settings'); $slug=sanitize_key($args['slug']??''); if(!$slug || !$this->allowed_slug($slug)) throw new RuntimeException('Plugin slug not allowed'); require_once ABSPATH.'wp-admin/includes/plugin-install.php'; require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php'; require_once ABSPATH.'wp-admin/includes/file.php'; $api=plugins_api('plugin_information',['slug'=>$slug,'fields'=>['sections'=>false]]); if(is_wp_error($api))throw new RuntimeException($api->get_error_message()); $upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin()); $res=$upgrader->install($api->download_link); if(is_wp_error($res))throw new RuntimeException($res->get_error_message()); if(!$res)throw new RuntimeException('Plugin install failed'); $plugin_file=$this->find_plugin_file_by_slug($slug); $activated=false; if(!empty($args['activate']) && $plugin_file){ if(empty($this->opts['allow_plugin_activate'])) throw new RuntimeException('Plugin activation disabled in WP Full MCP settings'); $this->require_confirm($args, 'ACTIVATE PLUGIN'); $this->assert_can('activate_plugins'); $a=activate_plugin($plugin_file); if(is_wp_error($a))throw new RuntimeException($a->get_error_message()); $activated=true; } return ['slug'=>$slug,'installed'=>true,'plugin'=>$plugin_file,'activated'=>$activated]; }
    private function find_plugin_file_by_slug(string $slug): ?string { require_once ABSPATH.'wp-admin/includes/plugin.php'; foreach(get_plugins() as $file=>$data){ if(str_starts_with($file, $slug.'/') || $file===$slug.'.php')return $file; } return null; }
    private function activate_plugin(array $args): array { $this->assert_can('activate_plugins'); if(empty($this->opts['allow_plugin_activate'])) throw new RuntimeException('Plugin activation disabled in WP Full MCP settings'); $this->require_confirm($args, 'ACTIVATE PLUGIN'); require_once ABSPATH.'wp-admin/includes/plugin.php'; $plugin=sanitize_text_field($args['plugin']??''); $file=str_contains($plugin,'/')?$plugin:$this->find_plugin_file_by_slug($plugin); if(!$file)throw new RuntimeException('Plugin not found'); $slug=dirname($file); if($slug==='.')$slug=basename($file,'.php'); if(!$this->allowed_slug($slug))throw new RuntimeException('Plugin slug not allowed'); $r=activate_plugin($file); if(is_wp_error($r))throw new RuntimeException($r->get_error_message()); return ['plugin'=>$file,'active'=>is_plugin_active($file)]; }
    private function deactivate_plugin(array $args): array { $this->assert_can('activate_plugins'); if(empty($this->opts['allow_plugin_deactivate'])) throw new RuntimeException('Plugin deactivation disabled in WP Full MCP settings'); $this->require_confirm($args, 'DEACTIVATE PLUGIN'); require_once ABSPATH.'wp-admin/includes/plugin.php'; $plugin=sanitize_text_field($args['plugin']??''); $file=str_contains($plugin,'/')?$plugin:$this->find_plugin_file_by_slug($plugin); if(!$file)throw new RuntimeException('Plugin not found'); $slug=dirname($file); if($slug==='.')$slug=basename($file,'.php'); if(!$this->allowed_slug($slug))throw new RuntimeException('Plugin slug not allowed'); deactivate_plugins([$file]); return ['plugin'=>$file,'active'=>is_plugin_active($file)]; }
    private function update_plugin(array $args): array { $this->assert_can('update_plugins'); if(empty($this->opts['allow_plugin_update'])) throw new RuntimeException('Plugin update disabled in WP Full MCP settings'); $plugin=sanitize_text_field($args['plugin']??''); $file=str_contains($plugin,'/')?$plugin:$this->find_plugin_file_by_slug($plugin); if(!$file)throw new RuntimeException('Plugin not found'); $slug=dirname($file); if($slug==='.')$slug=basename($file,'.php'); if(!$this->allowed_slug($slug))throw new RuntimeException('Plugin slug not allowed'); require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php'; require_once ABSPATH.'wp-admin/includes/file.php'; wp_update_plugins(); $upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin()); $r=$upgrader->upgrade($file); if(is_wp_error($r))throw new RuntimeException($r->get_error_message()); return ['plugin'=>$file,'updated'=>(bool)$r]; }

    private function option_get(array $args): array { $this->assert_can('manage_options'); $name=sanitize_key($args['name']??''); return ['name'=>$name,'value'=>get_option($name)]; }
    private function option_update(array $args): array { $this->assert_can('manage_options'); $name=sanitize_key($args['name']??''); $allowed=['blogname','blogdescription','timezone_string','date_format','time_format','start_of_week','posts_per_page','default_comment_status','default_ping_status','permalink_structure']; if(!in_array($name,$allowed,true)) throw new RuntimeException('Option update blocked. Only safe allowlisted options may be changed through MCP.'); $value=(string)($args['value']??''); update_option($name, sanitize_text_field($value)); return ['name'=>$name,'value'=>get_option($name)]; }
    private function flush_rewrite_tool(): array { $this->assert_can('manage_options'); flush_rewrite_rules(); return ['flushed'=>true]; }
    private function elementor_flush_css(): array { $this->assert_can('manage_options'); if(!did_action('elementor/loaded') || !class_exists('Elementor\Plugin')) throw new RuntimeException('Elementor is not active/loaded'); \Elementor\Plugin::$instance->files_manager->clear_cache(); return ['elementor_css_flushed'=>true]; }


    private function require_confirm(array $args, string $expected): void { if (($args['confirm_text'] ?? '') !== $expected) throw new RuntimeException('Missing required confirm_text: ' . $expected); }
    private function allowed_theme_slug(string $slug): bool { $slug=sanitize_key($slug); $list=preg_split('/[\s,]+/', (string)$this->opts['allowed_theme_slugs'], -1, PREG_SPLIT_NO_EMPTY); return $list && in_array($slug, array_map('sanitize_key',$list), true); }

    private function elementor_mcp_status(): array { require_once ABSPATH.'wp-admin/includes/plugin.php'; $route=(string)($this->opts['elementor_mcp_route'] ?? '/mcp/elementor-mcp-server'); $routes=rest_get_server()->get_routes(); return ['bridge_enabled'=>!empty($this->opts['enable_elementor_mcp_bridge']),'route'=>$route,'route_registered'=>isset($routes[$route]),'elementor_active'=>is_plugin_active('elementor/elementor.php'),'elementor_pro_active'=>is_plugin_active('elementor-pro/elementor-pro.php'),'mcp_adapter_active'=>is_plugin_active('mcp-adapter/mcp-adapter.php'),'elementor_mcp_active'=>is_plugin_active('elementor-mcp/elementor-mcp.php'),'requirements_met'=>!empty($this->opts['enable_elementor_mcp_bridge']) && is_plugin_active('elementor/elementor.php') && is_plugin_active('elementor-mcp/elementor-mcp.php') && isset($routes[$route])]; }
    private function elementor_mcp_info(): array { $status=$this->elementor_mcp_status(); return ['status'=>$status,'purpose'=>'WP Full MCP Gateway bridges to Elementor MCP plugin for Elementor layout/widget/global-style operations. Elementor MCP plugin is the required engine; this gateway does not reimplement Elementor editing logic.','forward_tool'=>'wp-elementor-mcp-forward','example_payload'=>['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list','params'=>(object)[]],'agent_guide_available'=>true,'agent_guide_tool'=>'wp-elementor-agent-guide','missing_requirements'=>$status['requirements_met']?[]:array_values(array_filter(['bridge_enabled'=>$status['bridge_enabled']?null:'Enable Elementor MCP bridge in settings','elementor_active'=>$status['elementor_active']?null:'Activate Elementor','elementor_mcp_active'=>$status['elementor_mcp_active']?null:'Activate MCP Tools for Elementor / elementor-mcp','route_registered'=>$status['route_registered']?null:'Elementor MCP REST route not registered']))]; }
    private function elementor_agent_guide(): array {
        $guide = '# Elementor Page Building Guide for AI Agents

## Architecture Overview

WP Full MCP Gateway acts as a bridge between your AI client (ChatGPT, Claude, etc.) and Elementor:

```
AI Client → WP Full MCP Gateway (this plugin) → Elementor MCP Plugin → Elementor
```

- **WP Full MCP Gateway**: Provides MCP tools for WordPress operations (CRUD posts, media, options, etc.)
- **Elementor MCP Plugin** (`elementor-mcp`): Provides Elementor-specific tools (build-page, add-custom-css, add-custom-js, search-images, sideload-image, upload-svg-icon, etc.)
- **Bridge**: `wp-elementor-mcp-forward` forwards JSON-RPC payloads from this gateway to the Elementor MCP plugin route

## Getting Started — Prerequisites

1. **Elementor** must be active (free or Pro)
2. **Elementor MCP plugin** (`elementor-mcp`) must be active
3. **Elementor MCP bridge** must be enabled in WP Full MCP Gateway settings
4. Check status with `wp-elementor-mcp-status`
5. Check info with `wp-elementor-mcp-info`

## Workflow — Step by Step

### Step 1: Image Sourcing (DO THIS FIRST)
Before building any page, search and sideload images into the WordPress Media Library:
- Use `search-images` (forwarded via `wp-elementor-mcp-forward`) to find relevant stock photos
- Use `sideload-image` to download them into WP Media Library
- **Always use real sideloaded image IDs and URLs** — never placeholder URLs
- Suggest 5-10 images depending on page complexity (hero, sections, gallery)

### Step 2: SVG Icons
Use `upload-svg-icon` tool or inline SVG in HTML widgets. Do NOT use Elementor default icon library (fa-solid, eicon, etc.) for custom designs. Create simple, clean SVG icons for:
- Service icons, feature icons, navigation icons, social icons, decorative elements

### Step 3: Build the Page
Use `build-page` (forwarded via `wp-elementor-mcp-forward`) to create the page structure. The tool accepts a JSON payload defining the page layout, containers, and widgets.

### Step 4: Custom CSS
After the page is built, use `add-custom-css` to add styling enhancements:
- **Page-level CSS** (omit element_id): Global styles, form focus, button hover, image transitions
- **Element-level CSS** (include element_id): Targeted hover effects, shadows, transforms

### Step 5: Custom JS
Use `add-custom-js` to add interactivity:
- Set `wrap_dom_ready=true` for scripts that need DOM ready
- Common: scroll-triggered animations, counter animations, form validation, smooth scroll

## Container & Layout Rules

### Flex Containers
- Use **flex containers** (not deprecated sections/columns) for layout
- **Row layout**: `flex_direction=row` — children auto-split into columns
- **Column layout**: `flex_direction=column` (default) — children stack vertically
- Set `content_width=boxed` for top-level containers (default)
- Children in a row get `content_width=full` automatically — do NOT set manually

### Critical Container Rules
- **NEVER set `flex_wrap`** — it is stripped automatically and causes issues
- **NEVER set `_flex_size`** on any element — it causes layout issues
- **NEVER set `_flex_size: grow`** — this is not a valid value
- **Do NOT manually set `content_width` or `width` on row children** — the build-page tool calculates these automatically
- Use `gap` for spacing between flex children: `{column: 20, row: 20, unit: "px"}`

### Padding & Margin
- Use consistent padding: `{top: "80", right: "0", bottom: "80", left: "0", unit: "px"}` for section spacing
- Use `{top: "20", right: "0", bottom: "20", left: "0", unit: "px"}` for tight internal padding
- All spacing values use string numbers with unit object format

### Background Colors on Containers
- **Always set BOTH**: `background_background="classic"` AND `background_color="#hex"`
- For overlays: `background_overlay_background="classic"`, `background_overlay_color="#hex"`, `background_overlay_opacity={size: 0.75, unit: "px"}`
- For background images: `background_image={url: "...", id: XX}`, `background_size="cover"`, `background_position="center center"`

## Widget Usage Patterns

### Heading Widget
- Properties: `title` (text), `header_size` (h1-h6), `align` (left/center/right), `style` (minimal/default)
- Use `color` for text color, `typography` for font settings
- Typography: `{fontSize: {size: 48, unit: "px"}, fontWeight: "800", textTransform: "uppercase"}`

### Text Editor Widget
- Properties: `editor` (HTML content), `text_color`, `typography`
- Good for paragraphs: `{fontSize: {size: 16, unit: "px"}, lineHeight: {size: 1.7, unit: "em"}, color: "#4B5563"}`

### Image Widget
- Properties: `image` (object with `id` and `url` from sideloaded media)
- Always use real media IDs from sideloaded images
- Add `border_radius` for rounded corners: `{top: 16, right: 16, bottom: 16, left: 16, unit: "px"}`

### Button Widget
- Properties: `text`, `link` (URL), `button_type` (default/info/success/warning/danger)
- Styling: `background_color`, `text_color`, `border_radius`, `typography`
- Hover states via custom CSS (not inline)

### Icon Box Widget
- Properties: `selected_icon` (SVG or icon object), `title`, `description`, `align`
- Use custom SVG icons, not Elementor default library

### Form Widget (Elementor Pro)
- Properties: `form_fields` (array of field objects), `submit_button` (button settings)
- Each field: `{type: "text", label: "Name", placeholder: "Your Name", required: true}`
- Field types: text, tel, email, textarea, select, date, url, number

## Design System Guidance

### Color Palette (Example — Adapt per Project)
- Primary: Brand color (e.g., `#1B4D7A` navy blue)
- Accent: CTA color (e.g., `#F59E0B` amber)
- Dark: Near-black for headings (e.g., `#111827`)
- Body Text: Gray for paragraphs (e.g., `#4B5563`)
- Light BG: Section backgrounds (e.g., `#F3F4F6`)
- Card BG: Card backgrounds (e.g., `#FFFFFF`)

### Typography
- Headings: Bold (700-800 weight), often uppercase with letter-spacing
- Section subtitles: Uppercase, letter-spacing, smaller size (13-14px), accent color
- Body text: 16-17px, gray color, line-height 1.7

### Spacing Consistency
- Section padding: 60-80px top/bottom
- Card padding: 30-40px
- Gap between elements: 16-24px
- Border radius: 8-16px for cards, 30px+ for pills/buttons

## How to Forward Payloads via wp-elementor-mcp-forward

All Elementor MCP tools are accessed by forwarding JSON-RPC payloads:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "tool-name",
    "arguments": { ... }
  }
}
```

### Common Tool Calls

**List available tools:**
```json
{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}
```

**Build a page:**
```json
{
  "jsonrpc":"2.0","id":2,"method":"tools/call",
  "params":{
    "name":"build-page",
    "arguments":{
      "title":"My Page",
      "content":[ ... containers and widgets ... ]
    }
  }
}
```

**Add custom CSS:**
```json
{
  "jsonrpc":"2.0","id":3,"method":"tools/call",
  "params":{
    "name":"add-custom-css",
    "arguments":{
      "page_id":123,
      "css":"selector { color: red; }",
      "element_id":"abc123"
    }
  }
}
```

**Search and sideload images:**
```json
{
  "jsonrpc":"2.0","id":4,"method":"tools/call",
  "params":{
    "name":"search-images",
    "arguments":{"query":"professional service","count":5}
  }
}
```

## Critical Rules Checklist

- Every container with background color MUST have BOTH `background_background="classic"` AND `background_color` set
- All text colors must be explicitly set with `color="#hex"`
- **NO `flex_wrap` anywhere** — it is stripped and causes issues
- **NO `_flex_size` on any element** — it causes layout issues
- All images must be real sideloaded images, not placeholders
- Use SVG icons, not Elementor icon library (unless specifically requested)
- Top-level containers: `content_width="boxed"` (default)
- Row children: auto `content_width="full"` with calculated widths
- After build-page, always apply custom CSS for polish
- After CSS, inject custom JS for interactivity
- Publish as draft first, then review before publishing

## Sample Prompts

Reference sample blueprints for inspiration:
- Local business landing pages (hero, services, about, pricing, testimonials, contact)
- E-commerce product pages
- Portfolio/case study layouts
- Blog layouts

Each sample follows the same pattern: image sourcing → SVG icons → page structure → custom CSS → custom JS → publish.

## Execution Order Summary

1. Search & sideload all images first
2. Upload SVG icons
3. Build the page using build-page
4. Apply page-level custom CSS (no element_id)
5. Apply element-level custom CSS (with element_id)
6. Inject custom JS (wrap_dom_ready=true)
7. Optionally create site-wide code snippets (Pro only)
8. Publish as draft, then review';

        return ['guide' => $guide, 'version' => '1.0.0', 'sections' => ['architecture', 'prerequisites', 'workflow', 'containers', 'widgets', 'design-system', 'forwarding', 'critical-rules', 'sample-prompts', 'execution-order']];
    }

    private function elementor_mcp_forward(array $args): array { $this->assert_can('manage_options'); if(empty($this->opts['enable_elementor_mcp_bridge'])) throw new RuntimeException('Elementor MCP bridge disabled in WP Full MCP settings'); $status=$this->elementor_mcp_status(); if(!$status['requirements_met']) throw new RuntimeException('Elementor MCP bridge requirements not met: '.wp_json_encode($status)); $payload=$args['payload']??null; if(!is_array($payload)) throw new RuntimeException('payload object is required'); $route=(string)$this->opts['elementor_mcp_route']; $request=new WP_REST_Request('POST', $route); $request->set_header('content-type','application/json'); $request->set_header('accept','application/json, text/event-stream'); if(!empty($args['mcp_session_id'])) $request->set_header('mcp-session-id', sanitize_text_field($args['mcp_session_id'])); $request->set_body(wp_json_encode($payload)); $response=rest_do_request($request); if(is_wp_error($response)) throw new RuntimeException($response->get_error_message()); $data=$response instanceof WP_REST_Response ? $response->get_data() : $response; $status_code=$response instanceof WP_REST_Response ? $response->get_status() : 200; $headers=$response instanceof WP_REST_Response ? $response->get_headers() : []; $session_id=$headers['Mcp-Session-Id'] ?? $headers['mcp-session-id'] ?? null; if(!$session_id && ($payload['method'] ?? '') === 'initialize' && class_exists('WP\MCP\Transport\Infrastructure\SessionManager')) { $session_id=\WP\MCP\Transport\Infrastructure\SessionManager::create_session(get_current_user_id(), (array)($payload['params'] ?? [])); } return ['status'=>$status_code,'route'=>$route,'headers'=>$headers,'mcp_session_id'=>$session_id,'response'=>$data]; }

    private function elementor_kit_option(): array { $items=get_option('wpfmcp_elementor_kit_imports', []); return is_array($items) ? $items : []; }
    private function save_elementor_kit_option(array $items): void { update_option('wpfmcp_elementor_kit_imports', array_slice($items, 0, 50), false); }
    private function elementor_kit_snapshot(): array { $types=['post','page','elementor_library','attachment','nav_menu_item'];
        if(post_type_exists('product')) $types[]='product'; $out=[]; foreach($types as $type){ $out[$type]=array_map('intval', get_posts(['post_type'=>$type,'post_status'=>'any','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true])); } return $out; }
    private function elementor_kit_diff(array $before, array $after): array { $out=[]; foreach($after as $type=>$ids){ $out[$type]=array_values(array_diff(array_map('intval',$ids), array_map('intval',$before[$type] ?? []))); } return $out; }
    private function elementor_nav_menu_snapshot(): array {
        $out = [];
        foreach(wp_get_nav_menus() as $menu) $out[(int)$menu->term_id] = ['term_id'=>(int)$menu->term_id,'slug'=>$menu->slug,'name'=>$menu->name,'count'=>(int)$menu->count];
        return $out;
    }
    private function elementor_best_nav_menu_slug(array $before_menus): ?string {
        $after = $this->elementor_nav_menu_snapshot();
        $candidates = array_diff_key($after, $before_menus);
        if(!$candidates) $candidates = $after;
        $candidates = array_values(array_filter($candidates, fn($menu) => !empty($menu['slug']) && (int)($menu['count'] ?? 0) > 0));
        if(!$candidates) return null;
        usort($candidates, fn($a,$b) => (($b['count'] ?? 0) <=> ($a['count'] ?? 0)) ?: (($b['term_id'] ?? 0) <=> ($a['term_id'] ?? 0)));
        return sanitize_title((string)$candidates[0]['slug']);
    }
    private function elementor_kit_manifest_conditions(string $zip): array {
        if(!class_exists('ZipArchive') || !is_file($zip)) return [];
        $archive = new \ZipArchive();
        if(true !== $archive->open($zip)) return [];
        $raw = $archive->getFromName('manifest.json');
        $archive->close();
        if(!$raw) return [];
        $manifest = json_decode($raw, true);
        if(!is_array($manifest) || empty($manifest['templates']) || !is_array($manifest['templates'])) return [];
        $out = [];
        foreach($manifest['templates'] as $template){
            if(empty($template['conditions']) || empty($template['title']) || empty($template['doc_type'])) continue;
            $conditions = [];
            foreach((array)$template['conditions'] as $condition){
                if(!is_array($condition) || empty($condition['type']) || empty($condition['name'])) continue;
                $parts = [sanitize_key((string)$condition['type']), sanitize_key((string)$condition['name'])];
                if(!empty($condition['sub_name'])) $parts[] = sanitize_key((string)$condition['sub_name']);
                if(!empty($condition['sub_id'])) $parts[] = sanitize_text_field((string)$condition['sub_id']);
                $conditions[] = rtrim(implode('/', $parts), '/');
            }
            if($conditions) $out[sanitize_key((string)$template['doc_type']).'|'.sanitize_text_field((string)$template['title'])] = $conditions;
        }
        return $out;
    }
    private function elementor_kit_manifest_summary(string $zip): array {
        if(!class_exists('ZipArchive') || !is_file($zip)) return [];
        $archive = new \ZipArchive();
        if(true !== $archive->open($zip)) return [];
        $raw = $archive->getFromName('manifest.json');
        $archive->close();
        if(!$raw) return [];
        $manifest = json_decode($raw, true);
        if(!is_array($manifest)) return [];
        $summary = [
            'name' => $manifest['name'] ?? null,
            'title' => $manifest['title'] ?? null,
            'version' => $manifest['version'] ?? null,
            'elementor_version' => $manifest['elementor_version'] ?? null,
            'expected' => [],
            'templates_detail' => [],
        ];
        // Templates
        $templates = $manifest['templates'] ?? [];
        if(is_array($templates)) {
            $template_types = [];
            foreach($templates as $tpl) {
                if(!is_array($tpl)) continue;
                $dt = sanitize_key((string)($tpl['doc_type'] ?? ''));
                $template_types[$dt] = ($template_types[$dt] ?? 0) + 1;
                $summary['templates_detail'][] = [
                    'title' => sanitize_text_field((string)($tpl['title'] ?? '')),
                    'doc_type' => $dt,
                    'location' => sanitize_key((string)($tpl['location'] ?? '')),
                ];
            }
            $summary['expected']['templates'] = $template_types;
        }
        // Content
        $content = $manifest['content'] ?? [];
        if(is_array($content)) {
            $content_counts = [];
            foreach($content as $ck => $cv) {
                $n = is_array($cv) ? count($cv) : 0;
                if($n > 0) $content_counts['content_' . sanitize_key((string)$ck)] = $n;
            }
            $summary['expected'] = array_merge($summary['expected'], $content_counts);
        }
        // WP Content (attachments, nav items, etc)
        $wp_content = $manifest['wp-content'] ?? [];
        if(is_array($wp_content)) {
            foreach($wp_content as $wck => $wcv) {
                $n = is_array($wcv) ? count($wcv) : 0;
                if($n > 0) $summary['expected']['wp_' . sanitize_key((string)$wck)] = $n;
            }
        }
        return $summary;
    }
    private function elementor_kit_site_settings_from_zip(string $zip): array {
        if(!class_exists('ZipArchive') || !is_file($zip)) return [];
        $archive = new \ZipArchive();
        if(true !== $archive->open($zip)) return [];
        $raw = $archive->getFromName('site-settings.json');
        $archive->close();
        if(!$raw) return [];
        $json = json_decode($raw, true);
        if(!is_array($json) || empty($json['settings']) || !is_array($json['settings'])) return [];
        return $json['settings'];
    }
    private function elementor_is_valid_kit_id(int $kit_id): bool {
        if(!$kit_id) return false;
        $post = get_post($kit_id);
        if(!$post || $post->post_type !== 'elementor_library' || $post->post_status === 'trash') return false;
        return get_post_meta($kit_id, '_elementor_template_type', true) === 'kit';
    }
    private function elementor_get_or_create_active_kit(array $settings = []): int {
        $active = (int)get_option('elementor_active_kit');
        if($this->elementor_is_valid_kit_id($active)) return $active;
        $kits = get_posts([
            'post_type' => 'elementor_library',
            'post_status' => ['publish','draft','private'],
            'numberposts' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_key' => '_elementor_template_type',
            'meta_value' => 'kit',
            'suppress_filters' => true,
        ]);
        if(!empty($kits[0]) && $this->elementor_is_valid_kit_id((int)$kits[0])) {
            update_option('elementor_previous_kit', $active, false);
            update_option('elementor_active_kit', (int)$kits[0], false);
            return (int)$kits[0];
        }
        if(class_exists('Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->kits_manager) && method_exists(\Elementor\Plugin::$instance->kits_manager, 'create_new_kit')) {
            return (int)\Elementor\Plugin::$instance->kits_manager->create_new_kit('Imported Kit', $settings, true);
        }
        $kit_id = wp_insert_post([
            'post_title' => 'Imported Kit',
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'meta_input' => [
                '_elementor_edit_mode' => 'builder',
                '_elementor_template_type' => 'kit',
                '_elementor_page_settings' => $settings,
            ],
        ], true);
        if(is_wp_error($kit_id)) throw new \RuntimeException($kit_id->get_error_message());
        update_option('elementor_previous_kit', $active, false);
        update_option('elementor_active_kit', (int)$kit_id, false);
        return (int)$kit_id;
    }
    private function elementor_kit_apply_site_settings(array $settings): array {
        if(empty($settings)) return ['applied' => false, 'reason' => 'site-settings.json not found or empty'];
        $kit_id = $this->elementor_get_or_create_active_kit($settings);
        $before = (int)get_option('elementor_active_kit');
        update_option('elementor_previous_kit', $before, false);
        update_option('elementor_active_kit', $kit_id, false);
        update_post_meta($kit_id, '_elementor_edit_mode', 'builder');
        update_post_meta($kit_id, '_elementor_template_type', 'kit');
        update_post_meta($kit_id, '_elementor_page_settings', $settings);
        if(class_exists('Elementor\\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
        return [
            'applied' => true,
            'kit_id' => $kit_id,
            'setting_count' => count($settings),
            'active_kit' => (int)get_option('elementor_active_kit'),
        ];
    }
    private function elementor_kit_apply_manifest_conditions(array $created, array $manifest_conditions): array {
        if(empty($manifest_conditions) || empty($created['elementor_library'])) return [];
        $applied = [];
        $applied_lookup = [];
        $applied_by_type = [];
        foreach(array_map('intval', (array)$created['elementor_library']) as $id){
            $post = get_post($id);
            if(!$post) continue;
            $type = sanitize_key((string)get_post_meta($id, '_elementor_template_type', true));
            $key = $type.'|'.sanitize_text_field($post->post_title);
            if(empty($manifest_conditions[$key])) continue;
            $conditions = array_values($manifest_conditions[$key]);
            update_post_meta($id, '_elementor_conditions', $conditions);
            $applied_lookup[$id] = true;
            foreach($conditions as $condition) $applied_by_type[$type.'|'.$condition] = true;
            $applied[] = ['id'=>$id, 'title'=>$post->post_title, 'type'=>$type, 'conditions'=>$conditions];
        }
        if($applied_by_type){
            foreach(get_posts(['post_type'=>'elementor_library','post_status'=>'publish','numberposts'=>-1,'meta_key'=>'_elementor_conditions','suppress_filters'=>true]) as $post){
                if(isset($applied_lookup[$post->ID])) continue;
                $type = sanitize_key((string)get_post_meta($post->ID, '_elementor_template_type', true));
                $conditions = (array)get_post_meta($post->ID, '_elementor_conditions', true);
                $remaining = [];
                foreach($conditions as $condition) if(empty($applied_by_type[$type.'|'.$condition])) $remaining[] = $condition;
                if($remaining === $conditions) continue;
                if($remaining) update_post_meta($post->ID, '_elementor_conditions', $remaining);
                else delete_post_meta($post->ID, '_elementor_conditions');
            }
        }
        if($applied && class_exists('ElementorPro\\Plugin')) {
            $theme_builder = \ElementorPro\Plugin::instance()->modules_manager->get_modules('theme-builder');
            if($theme_builder && method_exists($theme_builder, 'get_conditions_manager')) $theme_builder->get_conditions_manager()->get_cache()->regenerate();
        }
        if($applied && class_exists('Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
        return $applied;
    }
    private function elementor_kit_repair_nav_menu_widgets(array $created, ?string $menu_slug): array {
        if(!$menu_slug || empty($created['elementor_library']) || !wp_get_nav_menu_object($menu_slug)) return [];
        $available = [];
        foreach(wp_get_nav_menus() as $menu) $available[$menu->slug] = (int)$menu->count > 0;
        $updated = [];
        foreach(array_map('intval', (array)$created['elementor_library']) as $id){
            $raw = get_post_meta($id, '_elementor_data', true);
            $data = json_decode($raw, true);
            if(!is_array($data)) continue;
            $changed = false;
            $walk = function (&$nodes) use (&$walk, &$changed, $menu_slug, $available): void {
                foreach($nodes as &$node){
                    if(!is_array($node)) continue;
                    if(($node['widgetType'] ?? '') === 'nav-menu'){
                        if(!isset($node['settings']) || !is_array($node['settings'])) $node['settings'] = [];
                        $current = (string)($node['settings']['menu'] ?? '');
                        if($current === '' || empty($available[$current])){
                            $node['settings']['menu'] = $menu_slug;
                            unset($node['settings']['menu_id']);
                            $changed = true;
                        }
                    }
                    if(!empty($node['elements']) && is_array($node['elements'])) $walk($node['elements']);
                }
            };
            $walk($data);
            if($changed){
                update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($data)));
                $updated[] = ['id'=>$id,'title'=>get_the_title($id),'menu'=>$menu_slug];
            }
            }
        // Also assign theme locations for header/footer menus
        $locations = get_nav_menu_locations();
        $assigned = [];
        if($menu_slug && isset($locations['menu-1']) && empty($locations['menu-1'])) {
            $menu_obj = wp_get_nav_menu_object($menu_slug);
            if($menu_obj) {
                $locations['menu-1'] = (int)$menu_obj->term_id;
                $assigned[] = 'menu-1';
            }
        }
        if($menu_slug && isset($locations['menu-2']) && empty($locations['menu-2'])) {
            $menu_obj = wp_get_nav_menu_object($menu_slug);
            if($menu_obj) {
                $locations['menu-2'] = (int)$menu_obj->term_id;
                $assigned[] = 'menu-2';
            }
        }
        if($assigned) set_theme_mod('nav_menu_locations', $locations);

        if(($updated || $assigned) && class_exists('\\Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
        $result = $updated;
        if($assigned) $result['theme_locations'] = $assigned;
        return $result;
    }
    private function elementor_kit_file_from_args(array $args): array { $tmp=''; if(!empty($args['kit_url'])){ $url=$this->assert_safe_remote_url((string)$args['kit_url'], 'kit_url'); require_once ABSPATH.'wp-admin/includes/file.php'; $tmp=download_url($url, 120); if(is_wp_error($tmp)) throw new RuntimeException($tmp->get_error_message()); return [$tmp, $url, true]; } $path=(string)($args['file_path'] ?? ''); if($path) { if(!is_file($path)) throw new RuntimeException('file_path must be a real local path on the WordPress server. ChatGPT uploaded-file sandbox paths are not accessible here; upload the ZIP to a public HTTPS URL and pass kit_url instead.'); return [$path, $path, false]; } throw new RuntimeException('kit_url is required.'); }
    private function elementor_kit_import(array $args): array {
        $this->assert_can('manage_options');
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        if(!is_plugin_active('elementor/elementor.php') || !class_exists('Elementor\\Plugin')) throw new RuntimeException('Elementor is not active.');
        [$zip,$source,$temporary]=$this->elementor_kit_file_from_args($args);
        if(strtolower(pathinfo(parse_url($source, PHP_URL_PATH) ?: $source, PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('Elementor kit import requires a .zip file.');
        $import_id='kit_'.gmdate('Ymd_His').'_'.wp_generate_password(8,false,false);
        $label=sanitize_text_field($args['label'] ?? '');
        $pending = get_option('wpfmcp_elementor_pending_imports', []);
        $pending[$import_id] = [
            'import_id'=>$import_id,
            'label'=>$label,
            'source'=>$source,
            'zip_path'=>$zip,
            'temporary'=>$temporary,
            'include'=>!empty($args['include']) && is_array($args['include']) ? array_values(array_map('sanitize_key',$args['include'])) : [],
            'created_at'=>gmdate('c'),
            'created_by'=>get_current_user_id(),
            'status'=>'queued',
        ];
        update_option('wpfmcp_elementor_pending_imports', $pending, false);
        wp_schedule_single_event(time()+1, 'wpfmcp_elementor_run_import', [$import_id, get_current_user_id()]);
        return [
            'import_id'=>$import_id,
            'label'=>$label,
            'source'=>$source,
            'status'=>'queued',
            'message'=>'Import queued in background. Poll wp-elementor-kit-import-status with this import_id; status will be queued, running, completed, or failed.',
        ];
    }
    private function elementor_kit_list_imports(): array { $this->assert_can('manage_options'); $items=$this->elementor_kit_option(); return ['count'=>count($items),'imports'=>array_map(function($item){ $created=[]; foreach((array)($item['created'] ?? []) as $type=>$ids) $created[$type]=count((array)$ids); $timing=$this->elementor_import_timing((array)$item); return ['import_id'=>$item['import_id'] ?? null,'label'=>$item['label'] ?? '','source'=>$item['source'] ?? '','created_at'=>$item['created_at'] ?? null,'started_at'=>$item['started_at'] ?? null,'completed_at'=>$item['completed_at'] ?? null,'queued_seconds'=>$item['queued_seconds'] ?? $timing['queued_seconds'],'run_seconds'=>$item['run_seconds'] ?? $timing['run_seconds'],'total_time_seconds'=>$item['total_time_seconds'] ?? $timing['total_time_seconds'],'created_counts'=>$created]; }, $items)]; }
    private function elementor_kit_delete(array $args): array { $this->assert_can('manage_options'); $this->require_confirm($args,'DELETE ELEMENTOR KIT'); $import_id=sanitize_text_field($args['import_id'] ?? ''); if(!$import_id) throw new RuntimeException('import_id is required.'); $items=$this->elementor_kit_option(); $idx=null; $record=null; foreach($items as $i=>$item){ if(($item['import_id'] ?? '')===$import_id){ $idx=$i; $record=$item; break; } } if(!$record) throw new RuntimeException('Elementor kit import not found.'); $force=!empty($args['force']); $deleted=[]; $errors=[]; $created=(array)($record['created'] ?? []); $order=['page','post','elementor_library','attachment','product']; foreach($order as $type){ foreach(array_map('intval',(array)($created[$type] ?? [])) as $id){ if(!$id || !get_post($id)) continue; $ok=$type==='attachment' ? wp_delete_attachment($id, $force) : wp_delete_post($id, $force); if($ok) $deleted[]=['id'=>$id,'type'=>$type,'force'=>$force]; else $errors[]=['id'=>$id,'type'=>$type,'error'=>'delete_failed']; } } unset($items[$idx]); $record['deleted_at']=gmdate('c'); $record['deleted']=$deleted; $record['delete_errors']=$errors; $trash=(array)get_option('wpfmcp_elementor_kit_deleted_imports', []); array_unshift($trash,$record); update_option('wpfmcp_elementor_kit_deleted_imports', array_slice($trash,0,50), false); $this->save_elementor_kit_option(array_values($items)); return ['import_id'=>$import_id,'deleted_count'=>count($deleted),'errors'=>$errors,'force'=>$force]; }
    private function elementor_kit_erase_plan(array $args): array {
        $import_id = sanitize_text_field($args['import_id'] ?? '');
        $items = $this->elementor_kit_option();
        $selected = [];
        foreach($items as $idx=>$item){
            if($import_id && ($item['import_id'] ?? '') !== $import_id) continue;
            $selected[$idx] = $item;
        }
        if($import_id && !$selected) throw new \RuntimeException('Elementor kit import not found: '.$import_id);
        $ids_by_type = [];
        foreach($selected as $item){
            foreach((array)($item['created'] ?? []) as $type=>$ids){
                $type = sanitize_key((string)$type);
                foreach(array_map('intval', (array)$ids) as $id) if($id > 0) $ids_by_type[$type][$id] = $id;
            }
        }
        $existing = [];
        $counts = [];
        foreach($ids_by_type as $type=>$ids){
            foreach($ids as $id){
                $post = get_post($id);
                if(!$post) continue;
                $existing[$type][$id] = ['id'=>$id,'type'=>$type,'title'=>$post->post_title,'status'=>$post->post_status];
            }
            $counts[$type] = count($existing[$type] ?? []);
        }
        $pending = get_option('wpfmcp_elementor_pending_imports', []);
        $pending_matches = [];
        if(is_array($pending)){
            foreach($pending as $id=>$job) if(!$import_id || $id === $import_id) $pending_matches[$id] = ['status'=>$job['status'] ?? null, 'label'=>$job['label'] ?? '', 'source'=>$job['source'] ?? ''];
        }
        return ['import_id'=>$import_id ?: null,'tracked_import_count'=>count($selected),'post_counts'=>$counts,'items'=>$existing,'pending_count'=>count($pending_matches),'pending'=>$pending_matches];
    }
    private function elementor_kit_erase_preview(array $args): array {
        $safe = $args; unset($safe['approval_id'], $safe['dry_run'], $safe['confirm_text']);
        return ['dry_run'=>true,'tool'=>'wp-elementor-kit-erase','group'=>'elementor','would_execute'=>true,'approval_required'=>$this->approval_required('wp-elementor-kit-erase'),'backup_before_action'=>$this->backup_required('wp-elementor-kit-erase'),'arguments'=>$safe,'plan'=>$this->elementor_kit_erase_plan($args),'notes'=>'No WordPress data was changed. Re-run without dry_run and with confirm_text=ERASE ELEMENTOR KIT DATA to erase tracked Elementor kit data.'];
    }
    private function elementor_regenerate_theme_builder_conditions(): array {
        $cache = [];
        foreach(get_posts(['post_type'=>'elementor_library','post_status'=>'publish','numberposts'=>-1,'meta_key'=>'_elementor_conditions','suppress_filters'=>true]) as $post){
            $type = sanitize_key((string)get_post_meta($post->ID, '_elementor_template_type', true));
            $conditions = get_post_meta($post->ID, '_elementor_conditions', true);
            if($type && $conditions) $cache[$type][$post->ID] = (array)$conditions;
        }
        update_option('elementor_pro_theme_builder_conditions', $cache, false);
        if(class_exists('ElementorPro\\Plugin')) {
            $theme_builder = \ElementorPro\Plugin::instance()->modules_manager->get_modules('theme-builder');
            if($theme_builder && method_exists($theme_builder, 'get_conditions_manager')) $theme_builder->get_conditions_manager()->get_cache()->regenerate();
        }
        if(class_exists('Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
        return $cache;
    }
    private function elementor_kit_erase(array $args): array {
        $this->assert_can('manage_options');
        $this->require_confirm($args, 'ERASE ELEMENTOR KIT DATA');
        $plan = $this->elementor_kit_erase_plan($args);
        $import_id = $plan['import_id'] ?? null;
        $force = !empty($args['force']);
        $clear_pending = array_key_exists('clear_pending', $args) ? !empty($args['clear_pending']) : true;
        $reset_front_page = array_key_exists('reset_front_page', $args) ? !empty($args['reset_front_page']) : true;
        $front_page_id = (int)get_option('page_on_front');
        $deleted = [];
        $errors = [];
        $order = ['page','post','elementor_library','nav_menu_item','attachment','product'];
        foreach(array_unique(array_merge($order, array_keys((array)$plan['items']))) as $type){
            foreach((array)($plan['items'][$type] ?? []) as $item){
                $id = (int)($item['id'] ?? 0);
                if(!$id || !get_post($id)) continue;
                // Bypass Elementor kit delete guard by temporarily changing template type
                $kit_type = null;
                if($type === 'elementor_library') {
                    $kit_type = get_post_meta($id, '_elementor_template_type', true);
                    if($kit_type === 'kit') update_post_meta($id, '_elementor_template_type', 'section');
                }
                $ok = $type === 'attachment' ? wp_delete_attachment($id, $force) : wp_delete_post($id, $force);
                if($ok) $deleted[] = ['id'=>$id,'type'=>$type,'title'=>$item['title'] ?? '', 'force'=>$force];
                else $errors[] = ['id'=>$id,'type'=>$type,'title'=>$item['title'] ?? '', 'error'=>'delete_failed'];
            }
        }
        if($reset_front_page && $front_page_id){
            foreach($deleted as $item){
                if((int)$item['id'] === $front_page_id){
                    update_option('show_on_front', 'posts', false);
                    update_option('page_on_front', 0, false);
                    break;
                }
            }
        }
        $items = $this->elementor_kit_option();
        $remaining = [];
        $erased_records = [];
        foreach($items as $item){
            if($import_id && ($item['import_id'] ?? '') !== $import_id) { $remaining[] = $item; continue; }
            $item['erased_at'] = gmdate('c');
            $item['erase_deleted_count'] = count($deleted);
            $item['erase_errors'] = $errors;
            $erased_records[] = $item;
        }
        update_option('wpfmcp_elementor_kit_imports', array_slice($remaining, 0, 50), false);
        $trash = (array)get_option('wpfmcp_elementor_kit_erased_imports', []);
        foreach($erased_records as $record) array_unshift($trash, $record);
        update_option('wpfmcp_elementor_kit_erased_imports', array_slice($trash, 0, 50), false);
        if($clear_pending){
            $pending = get_option('wpfmcp_elementor_pending_imports', []);
            if(is_array($pending)){
                foreach(array_keys($pending) as $id) if(!$import_id || $id === $import_id) unset($pending[$id]);
                update_option('wpfmcp_elementor_pending_imports', $pending, false);
            }
        }
        $cache = $this->elementor_regenerate_theme_builder_conditions();

        // Ensure a valid active kit exists after erase
        $active_kit = (int)get_option('elementor_active_kit');
        if(!$active_kit || !$this->elementor_is_valid_kit_id($active_kit)) {
            $this->elementor_get_or_create_active_kit();
        }

        return ['erased'=>true,'import_id'=>$import_id,'force'=>$force,'deleted_count'=>count($deleted),'deleted'=>$deleted,'errors'=>$errors,'remaining_import_count'=>count($remaining),'cleared_pending'=>$clear_pending,'reset_front_page'=>$reset_front_page,'theme_builder_condition_cache'=>$cache];
    }

    private function elementor_kit_created_counts(array $created): array {
        $counts = [];
        foreach($created as $type=>$ids) $counts[$type] = count((array)$ids);
        return $counts;
    }
    private function elementor_import_timing(array $item): array {
        $created_at = $item['created_at'] ?? null;
        $started_at = $item['started_at'] ?? null;
        $completed_at = $item['completed_at'] ?? null;
        $failed_at = $item['failed_at'] ?? null;
        $finished_at = $completed_at ?: $failed_at;
        $to_time = static function($value): ?int {
            if(!$value) return null;
            $time = strtotime((string)$value);
            return $time === false ? null : $time;
        };
        $created_ts = $to_time($created_at);
        $started_ts = $to_time($started_at);
        $finished_ts = $to_time($finished_at);
        return [
            'queued_seconds' => ($created_ts && $started_ts) ? max(0, $started_ts - $created_ts) : null,
            'run_seconds' => ($started_ts && $finished_ts) ? max(0, $finished_ts - $started_ts) : null,
            'total_time_seconds' => ($created_ts && $finished_ts) ? max(0, $finished_ts - $created_ts) : null,
        ];
    }


    private function wc_status(bool $assert = false): array { if($assert) $this->assert_can('manage_woocommerce'); require_once ABSPATH.'wp-admin/includes/plugin.php'; $routes=rest_get_server()->get_routes(); $active=is_plugin_active('woocommerce/woocommerce.php') && class_exists('WooCommerce'); return ['woocommerce_active'=>$active,'version'=>defined('WC_VERSION')?WC_VERSION:null,'currency'=>$active && function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,'native_mcp_feature_enabled'=>get_option('woocommerce_feature_mcp_integration_enabled')==='yes','native_mcp_rest_route_registered'=>isset($routes['/woocommerce/mcp']),'native_mcp_endpoint'=>rest_url('woocommerce/mcp'),'gateway_group_enabled'=>in_array('woocommerce',$this->enabled_groups(),true),'notes'=>['Native WooCommerce MCP is developer preview and may require WooCommerce REST API keys in X-MCP-API-Key. WP Full MCP Woo tools use current WordPress permissions through this gateway.']]; }
    private function wc_require(): void { $this->assert_can('manage_woocommerce'); if(!class_exists('WooCommerce') || !function_exists('wc_get_product')) throw new RuntimeException('WooCommerce is not active/loaded'); }
    private function wc_enable_native_mcp(array $args): array { $this->assert_can('manage_woocommerce'); $this->require_confirm($args, 'ENABLE WC MCP'); update_option('woocommerce_feature_mcp_integration_enabled','yes', false); return $this->wc_status(false); }
    private function wc_compact_product($product): array { if(!$product) return []; return ['id'=>$product->get_id(),'type'=>$product->get_type(),'name'=>$product->get_name(),'slug'=>$product->get_slug(),'status'=>$product->get_status(),'sku'=>$product->get_sku(),'permalink'=>$product->get_permalink(),'price'=>$product->get_price(),'regular_price'=>$product->get_regular_price(),'sale_price'=>$product->get_sale_price(),'stock_status'=>$product->get_stock_status(),'manage_stock'=>$product->get_manage_stock(),'stock_quantity'=>$product->get_stock_quantity(),'categories'=>array_map(fn($id)=>['id'=>$id,'name'=>get_term_field('name',$id,'product_cat')], $product->get_category_ids()),'image_id'=>$product->get_image_id(),'date_modified'=>$product->get_date_modified()? $product->get_date_modified()->date('c') : null]; }
    private function wc_product_full($product): array { $out=$this->wc_compact_product($product); $out['description']=$product->get_description(); $out['short_description']=$product->get_short_description(); $out['gallery_image_ids']=$product->get_gallery_image_ids(); return $out; }
    private function wc_list_products(array $args): array { $this->wc_require(); $limit=min(max((int)($args['per_page']??20),1),100); $query=['limit'=>$limit,'page'=>max(1,(int)($args['page']??1)),'orderby'=>'modified','order'=>'DESC','return'=>'objects']; foreach(['status','type','sku'] as $k){ if(!empty($args[$k])) $query[$k]=sanitize_text_field($args[$k]); } if(!empty($args['search'])) $query['s']=sanitize_text_field($args['search']); if(!empty($args['category'])) $query['category']=[(int)$args['category']]; $products=wc_get_products($query); return ['page'=>$query['page'],'per_page'=>$limit,'count'=>count($products),'products'=>array_map(fn($p)=>$this->wc_compact_product($p), $products)]; }
    private function wc_get_product_tool(array $args): array { $this->wc_require(); $p=wc_get_product((int)($args['id']??0)); if(!$p) throw new RuntimeException('Product not found'); return $this->wc_product_full($p); }
    private function wc_apply_product_args($product, array $args): void { $price = $args['regular_price'] ?? $args['_regular_price'] ?? null; if($price !== null) $product->set_regular_price((string)$price); if(array_key_exists('_price',$args) && !array_key_exists('sale_price',$args)) $product->set_price((string)$args['_price']); foreach(['name','status','sku','sale_price','description','short_description','stock_status'] as $k){ if(array_key_exists($k,$args)){ $m='set_'.$k; $product->$m(is_string($args[$k]) ? wp_kses_post($args[$k]) : $args[$k]); } } if(array_key_exists('manage_stock',$args)) $product->set_manage_stock((bool)$args['manage_stock']); if(array_key_exists('stock_quantity',$args)) $product->set_stock_quantity((int)$args['stock_quantity']); if(array_key_exists('category_ids',$args)) $product->set_category_ids(array_map('intval',(array)$args['category_ids'])); $image_id=$args['image_id'] ?? $args['_thumbnail_id'] ?? null; if($image_id !== null) $product->set_image_id((int)$image_id); }
    private function wc_sync_product_meta_aliases($product, array $args): void { $id=$product->get_id(); if(!$id) return; if(isset($args['regular_price']) || isset($args['_regular_price'])) update_post_meta($id, '_regular_price', $product->get_regular_price()); if(isset($args['_price']) || isset($args['regular_price']) || isset($args['_regular_price']) || isset($args['sale_price'])) update_post_meta($id, '_price', $product->get_price()); if(isset($args['image_id']) || isset($args['_thumbnail_id'])) update_post_meta($id, '_thumbnail_id', $product->get_image_id()); }
    private function wc_create_product(array $args): array { $this->wc_require(); if(!class_exists('WC_Product_Simple')) throw new RuntimeException('WC_Product_Simple is unavailable'); $p=new WC_Product_Simple(); $p->set_status('draft'); $this->wc_apply_product_args($p,$args); $id=$p->save(); $p=wc_get_product($id); $this->wc_sync_product_meta_aliases($p,$args); return $this->wc_product_full($p); }
    private function wc_update_product(array $args): array { $this->wc_require(); $p=wc_get_product((int)($args['id']??0)); if(!$p) throw new RuntimeException('Product not found'); $this->wc_apply_product_args($p,$args); $p->save(); $this->wc_sync_product_meta_aliases($p,$args); return $this->wc_product_full(wc_get_product($p->get_id())); }
    private function wc_set_product_pricing(array $args): array { $this->wc_require(); $p=wc_get_product((int)($args['id']??0)); if(!$p) throw new RuntimeException('Product not found'); $price_args=[]; foreach(['regular_price','_regular_price','_price','sale_price'] as $k){ if(array_key_exists($k,$args)) $price_args[$k]=$args[$k]; } if(!$price_args) throw new RuntimeException('At least one price field is required: _regular_price, _price, regular_price, or sale_price'); $this->wc_apply_product_args($p,$price_args); $p->save(); $this->wc_sync_product_meta_aliases($p,$price_args); return ['id'=>$p->get_id(),'regular_price'=>$p->get_regular_price(),'price'=>$p->get_price(),'_regular_price'=>get_post_meta($p->get_id(),'_regular_price',true),'_price'=>get_post_meta($p->get_id(),'_price',true)]; }
    private function wc_set_product_thumbnail(array $args): array { $this->wc_require(); $p=wc_get_product((int)($args['id']??0)); if(!$p) throw new RuntimeException('Product not found'); $image_id=(int)($args['_thumbnail_id'] ?? $args['image_id'] ?? 0); if(!$image_id) throw new RuntimeException('_thumbnail_id or image_id is required'); $p->set_image_id($image_id); $p->save(); update_post_meta($p->get_id(), '_thumbnail_id', $image_id); return ['id'=>$p->get_id(),'image_id'=>$p->get_image_id(),'_thumbnail_id'=>get_post_meta($p->get_id(),'_thumbnail_id',true),'image_url'=>wp_get_attachment_url($p->get_image_id())]; }
    private function wc_delete_product(array $args): array { $this->wc_require(); $id=(int)($args['id']??0); $p=wc_get_product($id); if(!$p) throw new RuntimeException('Product not found'); $force=!empty($args['force']); if($force) $this->require_confirm($args, 'DELETE PRODUCT'); $deleted=$p->delete($force); if(!$deleted) throw new RuntimeException('Product delete failed'); return ['id'=>$id,'deleted'=>$force,'status'=>$force?'deleted':get_post_status($id),'force'=>$force]; }
    private function wc_compact_order($order, bool $full=false, bool $include_pii=false): array { $data=['id'=>$order->get_id(),'number'=>$order->get_order_number(),'status'=>$order->get_status(),'currency'=>$order->get_currency(),'total'=>$order->get_total(),'payment_method'=>$order->get_payment_method(),'payment_method_title'=>$order->get_payment_method_title(),'date_created'=>$order->get_date_created()? $order->get_date_created()->date('c') : null,'date_modified'=>$order->get_date_modified()? $order->get_date_modified()->date('c') : null,'customer_id'=>$order->get_customer_id()]; if($include_pii){ $data['billing']=['first_name'=>$order->get_billing_first_name(),'last_name'=>$order->get_billing_last_name(),'email'=>$order->get_billing_email()]; } if($full){ $data['line_items']=array_map(fn($item)=>['id'=>$item->get_id(),'name'=>$item->get_name(),'product_id'=>$item->get_product_id(),'variation_id'=>$item->get_variation_id(),'quantity'=>$item->get_quantity(),'total'=>$item->get_total()], array_values($order->get_items())); if($include_pii){ $data['shipping']=['first_name'=>$order->get_shipping_first_name(),'last_name'=>$order->get_shipping_last_name(),'address_1'=>$order->get_shipping_address_1(),'city'=>$order->get_shipping_city(),'country'=>$order->get_shipping_country()]; } } return $data; }
    private function wc_list_orders(array $args): array { $this->wc_require(); $limit=min(max((int)($args['per_page']??20),1),100); $query=['limit'=>$limit,'paged'=>max(1,(int)($args['page']??1)),'orderby'=>'modified','order'=>'DESC','return'=>'objects']; if(!empty($args['status'])) $query['status']=sanitize_text_field($args['status']); if(!empty($args['search'])) $query['search']='*'.sanitize_text_field($args['search']).'*'; $include_pii=!empty($args['include_pii']); $orders=wc_get_orders($query); return ['page'=>$query['paged'],'per_page'=>$limit,'count'=>count($orders),'orders'=>array_map(fn($o)=>$this->wc_compact_order($o,false,$include_pii), $orders),'privacy_note'=>$include_pii?'PII included because include_pii=true.':'PII omitted by default. Pass include_pii=true only when necessary.']; }
    private function wc_get_order_tool(array $args): array { $this->wc_require(); $o=wc_get_order((int)($args['id']??0)); if(!$o) throw new RuntimeException('Order not found'); return $this->wc_compact_order($o,true,!empty($args['include_pii'])); }
    private function wc_update_order_status(array $args): array { $this->wc_require(); $o=wc_get_order((int)($args['id']??0)); if(!$o) throw new RuntimeException('Order not found'); $status=sanitize_key($args['status']??''); if(!$status) throw new RuntimeException('status is required'); $o->update_status($status, sanitize_text_field($args['note']??''), true); return $this->wc_compact_order($o,false); }
    private function wc_add_order_note(array $args): array { $this->wc_require(); $o=wc_get_order((int)($args['id']??0)); if(!$o) throw new RuntimeException('Order not found'); $note=wp_kses_post($args['note']??''); if(!$note) throw new RuntimeException('note is required'); $note_id=$o->add_order_note($note, !empty($args['customer_note']), true); return ['order_id'=>$o->get_id(),'note_id'=>$note_id,'customer_note'=>!empty($args['customer_note'])]; }

    private function upload_media_from_url(array $args): array { $this->assert_can('upload_files'); if(empty($this->opts['allow_media_upload'])) throw new RuntimeException('Media upload disabled in WP Full MCP settings'); $url=$this->assert_safe_remote_url((string)($args['url']??''), 'url'); require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php'; $tmp=download_url($url, 30); if(is_wp_error($tmp)) throw new RuntimeException($tmp->get_error_message()); $name=basename(parse_url($url, PHP_URL_PATH) ?: 'remote-file'); $file=['name'=>sanitize_file_name($name ?: 'remote-file'), 'tmp_name'=>$tmp]; $id=media_handle_sideload($file, 0, sanitize_text_field($args['title']??'')); if(is_wp_error($id)){ @unlink($tmp); throw new RuntimeException($id->get_error_message()); } if(isset($args['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($args['alt'])); if(isset($args['caption'])) wp_update_post(['ID'=>$id,'post_excerpt'=>sanitize_text_field($args['caption'])]); return ['id'=>$id,'url'=>wp_get_attachment_url($id),'title'=>get_the_title($id),'mime'=>get_post_mime_type($id)]; }

    private function list_themes(): array { $this->assert_can('switch_themes'); $active=get_stylesheet(); $out=[]; foreach(wp_get_themes() as $slug=>$theme){ $out[]=['slug'=>$slug,'name'=>$theme->get('Name'),'version'=>$theme->get('Version'),'active'=>$slug===$active,'parent'=>$theme->parent()? $theme->parent()->get_stylesheet() : null]; } return $out; }
    private function install_theme(array $args): array { $this->assert_can('install_themes'); if(empty($this->opts['allow_theme_install'])) throw new RuntimeException('Theme install disabled in WP Full MCP settings'); $this->require_confirm($args, 'INSTALL THEME'); $slug=sanitize_key($args['slug']??''); if(!$slug || !$this->allowed_theme_slug($slug)) throw new RuntimeException('Theme slug not allowed'); require_once ABSPATH.'wp-admin/includes/theme.php'; require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php'; require_once ABSPATH.'wp-admin/includes/file.php'; $api=themes_api('theme_information',['slug'=>$slug]); if(is_wp_error($api)) throw new RuntimeException($api->get_error_message()); $upgrader=new Theme_Upgrader(new Automatic_Upgrader_Skin()); $res=$upgrader->install($api->download_link); if(is_wp_error($res)) throw new RuntimeException($res->get_error_message()); if(!$res) throw new RuntimeException('Theme install failed'); if(!empty($args['activate'])){ if(empty($this->opts['allow_theme_switch'])) throw new RuntimeException('Theme switch disabled in WP Full MCP settings'); if(($args['switch_confirm_text'] ?? '') !== 'SWITCH THEME') throw new RuntimeException('Missing required switch_confirm_text: SWITCH THEME'); $this->assert_can('switch_themes'); switch_theme($slug); } return ['slug'=>$slug,'installed'=>true,'active'=>get_stylesheet()===$slug]; }
    private function update_theme(array $args): array { $this->assert_can('update_themes'); if(empty($this->opts['allow_theme_update'])) throw new RuntimeException('Theme update disabled in WP Full MCP settings'); $this->require_confirm($args, 'UPDATE THEME'); $slug=sanitize_key($args['slug']??''); if(!$slug || !$this->allowed_theme_slug($slug)) throw new RuntimeException('Theme slug not allowed'); require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php'; require_once ABSPATH.'wp-admin/includes/file.php'; wp_update_themes(); $upgrader=new Theme_Upgrader(new Automatic_Upgrader_Skin()); $res=$upgrader->upgrade($slug); if(is_wp_error($res)) throw new RuntimeException($res->get_error_message()); return ['slug'=>$slug,'updated'=>(bool)$res]; }
    private function activate_theme(array $args): array { $this->assert_can('switch_themes'); if(empty($this->opts['allow_theme_switch'])) throw new RuntimeException('Theme switch disabled in WP Full MCP settings'); $this->require_confirm($args, 'SWITCH THEME'); $slug=sanitize_key($args['slug']??''); if(!$slug || !$this->allowed_theme_slug($slug)) throw new RuntimeException('Theme slug not allowed'); if(!wp_get_theme($slug)->exists()) throw new RuntimeException('Theme not installed'); switch_theme($slug); return ['slug'=>$slug,'active'=>get_stylesheet()===$slug]; }

    private function list_users(array $args): array { $this->assert_can('list_users'); $q=['number'=>min(max((int)($args['number']??50),1),100), 'fields'=>['ID','user_login','user_email','display_name']]; if(!empty($args['search'])) $q['search']='*'.sanitize_text_field($args['search']).'*'; if(!empty($args['role'])) $q['role']=sanitize_key($args['role']); return array_map(function($u){ $data=get_userdata($u->ID); return ['id'=>$u->ID,'login'=>$u->user_login,'email'=>$u->user_email,'display_name'=>$u->display_name,'roles'=>$data?array_values($data->roles):[]]; }, get_users($q)); }
    private function health_check(): array { require_once ABSPATH.'wp-admin/includes/update.php'; wp_update_plugins(); wp_update_themes(); $plugins=get_site_transient('update_plugins'); $themes=get_site_transient('update_themes'); return ['site'=>$this->site_info(),'debug'=>defined('WP_DEBUG') && WP_DEBUG,'memory_limit'=>ini_get('memory_limit'),'max_execution_time'=>ini_get('max_execution_time'),'plugin_updates'=>isset($plugins->response)?count((array)$plugins->response):0,'theme_updates'=>isset($themes->response)?count((array)$themes->response):0,'active_plugins'=>count((array)get_option('active_plugins', [])),'theme'=>get_stylesheet()]; }

    private function wpvivid_status(): array { require_once ABSPATH.'wp-admin/includes/plugin.php'; $mainwp_file=WP_PLUGIN_DIR.'/wpvivid-backuprestore/includes/class-wpvivid-interface-mainwp.php'; if(file_exists($mainwp_file)) require_once $mainwp_file; $public_file=WP_PLUGIN_DIR.'/wpvivid-backuprestore/includes/class-wpvivid-public-interface.php'; if(file_exists($public_file)) require_once $public_file; return ['wpvivid_active'=>is_plugin_active('wpvivid-backuprestore/wpvivid-backuprestore.php'),'wpvivid_pro_active'=>is_plugin_active('wpvivid-backup-pro/wpvivid-backup-pro.php'),'wpvivid_staging_active'=>is_plugin_active('wpvivid-staging/wpvivid-staging.php'),'public_interface'=>class_exists('WPvivid_Public_Interface'),'mainwp_interface'=>class_exists('WPvivid_MainWP_Interface') || class_exists('WPvivid_Interface_MainWP'),'global_plugin'=>isset($GLOBALS['wpvivid_plugin']),'usable'=>is_plugin_active('wpvivid-backuprestore/wpvivid-backuprestore.php') && class_exists('WPvivid_Public_Interface')]; }
    private function wpvivid_interface(): WPvivid_Public_Interface { if(!class_exists('WPvivid_Public_Interface')) throw new RuntimeException('WPvivid public interface not available. Is WPvivid Backup plugin active?'); return new WPvivid_Public_Interface(); }
    private function wpvivid_options(array $args): array { $type=$args['backup_files'] ?? 'all'; $backup_files='files+db'; if($type==='db') $backup_files='db'; if($type==='files') $backup_files='files'; return ['backup_files'=>$backup_files,'local'=>'1','remote'=>!empty($args['remote'])?'1':'0','type'=>'Manual','action'=>'backup','backup_prefix'=>'mcp-'.sanitize_key($args['label'] ?? 'backup')]; }
    private function wpvivid_create_backup(array $args): array { $this->assert_can('manage_options'); $status=$this->wpvivid_status(); if(empty($status['usable'])) throw new RuntimeException('WPvivid is not usable: '.wp_json_encode($status)); $this->wpvivid_interface(); $options=$this->wpvivid_options($args); if(class_exists('WPvivid_MainWP_Interface')) { $main=new WPvivid_MainWP_Interface(); $prep=$main->wpvivid_prepare_backup_mainwp(['backup'=>$options]); } elseif(class_exists('WPvivid_Interface_MainWP')) { $main=new WPvivid_Interface_MainWP(); $prep=$main->wpvivid_prepare_backup_mainwp(['backup'=>$options]); } else { $iface=$this->wpvivid_interface(); $prep=$iface->prepare_backup($options); } if(($prep['result'] ?? '') !== 'success') throw new RuntimeException('WPvivid prepare backup failed: '.($prep['error'] ?? wp_json_encode($prep))); $task_id=$prep['task_id'] ?? ''; if(!$task_id) throw new RuntimeException('WPvivid did not return a task_id.'); $queued=false; $run_async=array_key_exists('run_async',$args) ? (bool)$args['run_async'] : true; if($run_async){ $queued=$this->wpvivid_queue_task($task_id); } return ['strategy'=>'wpvivid','mode'=>$queued?'queued_async':'prepared_task','task_id'=>$task_id,'options'=>$options,'prepare'=>$prep,'queued'=>$queued,'status'=>$this->wpvivid_task_status($task_id),'note'=>$queued?'WPvivid task queued through WP-Cron. Call wp-wpvivid-task-status with task_id until completed/error.':'WPvivid task prepared only; call with run_async=true to queue runner.']; }
    private function wpvivid_queue_task(string $task_id): bool { if(!$task_id) return false; if(!wp_next_scheduled('wpfmcp_wpvivid_run_task', [$task_id])) { return (bool) wp_schedule_single_event(time()+1, 'wpfmcp_wpvivid_run_task', [$task_id]); } return true; }
    public function wpvivid_run_queued_task($task_id): void { $task_id=sanitize_key((string)$task_id); if(!$task_id) return; try { $this->wpvivid_status(); if(class_exists('WPvivid_MainWP_Interface')) { $main=new WPvivid_MainWP_Interface(); ob_start(); $main->wpvivid_backup_now_mainwp(['task_id'=>$task_id]); ob_end_clean(); } elseif(class_exists('WPvivid_Interface_MainWP')) { $main=new WPvivid_Interface_MainWP(); ob_start(); $main->wpvivid_backup_now_mainwp(['task_id'=>$task_id]); ob_end_clean(); } } catch(Throwable $e) { error_log('WPFMCP WPvivid queued task failed '.$task_id.': '.$e->getMessage()); } }
    private function wpvivid_task_status(string $task_id): array { $this->assert_can('manage_options'); $task_id=sanitize_key($task_id); if(!$task_id) throw new InvalidArgumentException('task_id is required'); $task=null; if(class_exists('WPvivid_taskmanager')) $task=WPvivid_taskmanager::get_task($task_id); $status=['task_id'=>$task_id,'found'=>!empty($task),'wp_cron_queued'=>(bool)wp_next_scheduled('wpfmcp_wpvivid_run_task', [$task_id])]; if($task){ $status['status']=$task['status']['str'] ?? null; $status['action']=$task['action'] ?? null; $status['type']=$task['type'] ?? null; $status['backup_prefix']=$task['options']['backup_prefix'] ?? null; $status['file_prefix']=$task['options']['file_prefix'] ?? null; $status['log_file_name']=$task['options']['log_file_name'] ?? null; $status['jobs']=array_map(function($j){ return ['backup_type'=>$j['backup_type'] ?? null,'finished'=>$j['finished'] ?? null,'progress'=>$j['progress'] ?? null]; }, array_values((array)($task['jobs'] ?? []))); $status['error']=$task['status']['error'] ?? null; } return $status; }
    private function wpvivid_get_status_compact(): array { try { $iface=$this->wpvivid_interface(); $s=$iface->get_status(); return ['result'=>$s['result'] ?? null,'task_count'=>isset($s['wpvivid']['task'])?count((array)$s['wpvivid']['task']):0,'backup_count'=>isset($s['wpvivid']['backup_list'])?count((array)$s['wpvivid']['backup_list']):0,'last_message'=>$s['wpvivid']['schedule']['last_message'] ?? null]; } catch(Throwable $e){ return ['error'=>$e->getMessage()]; } }
    private function wpvivid_list_backups(): array { $this->assert_can('manage_options'); $iface=$this->wpvivid_interface(); $ret=$iface->get_backup_list(); $list=(array)($ret['wpvivid']['backup_list'] ?? []); $items=[]; foreach($list as $id=>$b){ $items[]=['id'=>$id,'type'=>$b['type'] ?? null,'create_time'=>$b['create_time'] ?? null,'backup_prefix'=>$b['backup_prefix'] ?? null,'local_path'=>$b['local']['path'] ?? null,'files'=>isset($b['backup']['data']['meta']['files'])?count((array)$b['backup']['data']['meta']['files']):null]; } usort($items, fn($a,$b)=>($b['create_time']??0)<=>($a['create_time']??0)); return ['result'=>$ret['result'] ?? null,'count'=>count($items),'backups'=>array_slice($items,0,50)]; }

    private function group_labels(): array { return ['core'=>'Core','content'=>'Content','media'=>'Media','plugins'=>'Plugins','themes'=>'Themes','options'=>'Options','users'=>'Users','elementor'=>'Elementor','woocommerce'=>'WooCommerce','approvals'=>'Approvals','backup'=>'Backup','settings'=>'Settings','diagnostics'=>'Diagnostics','audit'=>'Audit']; }
    private function capability_groups(): array { $groups=[]; foreach($this->group_labels() as $key=>$label){ $groups[$key]=['label'=>$label,'enabled'=>in_array($key,$this->enabled_groups(),true),'tools'=>[]]; } foreach($this->tools() as $tool){ $g=$this->tool_group($tool['name'] ?? ''); if(!isset($groups[$g])) $groups[$g]=['label'=>ucfirst($g),'enabled'=>true,'tools'=>[]]; $groups[$g]['tools'][]=$tool['name']; } return array_filter($groups, fn($g)=>!empty($g['tools']) || !empty($g['enabled'])); }
    private function permission_profiles(): array { return [
        'safe_readonly'=>['label'=>'Safe / read-only','enabled_tool_groups'=>"core\ncontent\nmedia\nplugins\nthemes\nusers\nelementor\nwoocommerce\nbackup\nsettings\ndiagnostics\naudit",'allow_plugin_install'=>0,'allow_plugin_update'=>0,'allow_plugin_activate'=>0,'allow_plugin_deactivate'=>0,'allow_plugin_delete'=>0,'allow_theme_install'=>0,'allow_theme_update'=>0,'allow_theme_switch'=>0,'allow_media_upload'=>0,'approval_required_tools'=>"",'backup_before_tools'=>"wc-create-product\nwc-update-product\nwc-set-product-pricing\nwc-set-product-thumbnail\nwc-delete-product\nwc-update-order-status\nwc-add-order-note\nwc-enable-native-mcp\nwp-import-settings"],
        'content_editor'=>['label'=>'Content editor','enabled_tool_groups'=>"core\ncontent\nmedia\nplugins\nthemes\nusers\nelementor\nwoocommerce\napprovals\nbackup\nsettings\ndiagnostics\naudit",'allow_plugin_install'=>0,'allow_plugin_update'=>0,'allow_plugin_activate'=>0,'allow_plugin_deactivate'=>0,'allow_plugin_delete'=>0,'allow_theme_install'=>0,'allow_theme_update'=>0,'allow_theme_switch'=>0,'allow_media_upload'=>1,'approval_required_tools'=>"",'backup_before_tools'=>"wp-trash-post\nwp-option-update\nwp-install-plugin\nwp-activate-plugin\nwp-update-plugin\nwp-deactivate-plugin\nwp-install-theme\nwp-update-theme\nwp-activate-theme\nwc-create-product\nwc-update-product\nwc-set-product-pricing\nwc-set-product-thumbnail\nwc-delete-product\nwc-update-order-status\nwc-add-order-note\nwc-enable-native-mcp\nwp-import-settings"],
        'admin_devops'=>['label'=>'Admin / devops','enabled_tool_groups'=>"core\ncontent\nmedia\nplugins\nthemes\noptions\nusers\nelementor\nwoocommerce\napprovals\nbackup\nsettings\ndiagnostics\naudit",'allow_plugin_install'=>1,'allow_plugin_update'=>1,'allow_plugin_activate'=>0,'allow_plugin_deactivate'=>0,'allow_plugin_delete'=>0,'allow_theme_install'=>1,'allow_theme_update'=>1,'allow_theme_switch'=>1,'allow_media_upload'=>1,'approval_required_tools'=>"",'backup_before_tools'=>"wp-install-plugin\nwp-activate-plugin\nwp-update-plugin\nwp-deactivate-plugin\nwp-trash-post\nwp-option-update\nwp-install-theme\nwp-update-theme\nwp-activate-theme\nwc-create-product\nwc-update-product\nwc-set-product-pricing\nwc-set-product-thumbnail\nwc-delete-product\nwc-update-order-status\nwc-add-order-note\nwc-enable-native-mcp\nwp-import-settings"],
    ]; }
    private function apply_permission_profile(string $profile): string { if(!current_user_can('manage_options')) return 'Insufficient capability.'; $profiles=$this->permission_profiles(); if(empty($profiles[$profile])) return 'Unknown permission profile.'; $opts=$this->opts; foreach($profiles[$profile] as $key=>$value){ if($key==='label') continue; $opts[$key]=$value; } $opts['permission_profile']=$profile; update_option('wpfmcp_options',$opts,false); $this->opts=wp_parse_args($opts,$this->defaults()); return 'Applied permission profile: '.$profiles[$profile]['label'].'.'; }
    private function connector_config(bool $include_secret=true): array { $secret=$include_secret ? (string)($this->opts['secret'] ?? '') : '[secret]'; $chatgpt=rest_url('wp-full-mcp/v1/mcp/'.$secret); $rest=rest_url('wp-full-mcp/v1/mcp/bearer'); $pretty=home_url('/mcp/bearer'); return ['name'=>'WP Full MCP Gateway','version'=>defined('WPFMCP_VERSION')?WPFMCP_VERSION:null,'generated_at'=>gmdate('c'),'auth'=>'hybrid-url-secret-or-bearer-token','endpoints'=>['chatgpt_no_auth'=>$chatgpt,'bearer_rest'=>$rest,'bearer_pretty'=>$pretty],'recommended_endpoint'=>$chatgpt,'client_configs'=>['chatgpt_custom_connector'=>['type'=>'http','url'=>$chatgpt,'transport'=>'streamable-http','authentication'=>'none'],'generic_mcp_connector'=>['type'=>'http','url'=>$rest,'transport'=>'streamable-http','headers'=>['Accept'=>'application/json, text/event-stream','Authorization'=>'Bearer '.$secret]],'mcpServers'=>['wp-full-mcp-gateway'=>['type'=>'http','url'=>$rest,'headers'=>['Accept'=>'application/json, text/event-stream','Authorization'=>'Bearer '.$secret]]]],'verification'=>['initialize_method'=>'initialize','list_tools_method'=>'tools/list','manifest_tool'=>'wp-connector-manifest','diagnostics_tool'=>'wp-export-diagnostics'],'security_notes'=>['ChatGPT Custom Connector currently supports the No Auth URL by embedding the secret in the path. Generic clients should prefer Authorization: Bearer. Rotate the token if the URL or bearer token leaks. State-changing tools require a real authenticated WordPress user/capability in addition to MCP token access.']]; }
    private function enabled_groups(): array { return $this->parse_list((string)($this->opts['enabled_tool_groups'] ?? '')); }
    private function dangerous_tools(): array { return array_values(array_unique(array_merge($this->parse_list((string)($this->opts['approval_required_tools'] ?? '')), $this->parse_list((string)($this->opts['backup_before_tools'] ?? ''))))); }
    private function audit_log(string $tool, array $args, string $result, $payload=null): void { try { $log=(array)get_option('wpfmcp_audit_log', []); $entry=['time'=>gmdate('c'),'user_id'=>get_current_user_id(),'tool'=>sanitize_key($tool),'result'=>$result,'dry_run'=>!empty($args['dry_run']),'approval_required'=>$this->approval_required($tool),'backup_required'=>$this->backup_required($tool)]; if(isset($payload['backup'])) $entry['backup']=$this->audit_compact($payload['backup']); if(isset($payload['task_id'])) $entry['task_id']=$payload['task_id']; if(isset($payload['error'])) $entry['error']=mb_substr((string)$payload['error'],0,300); array_unshift($log,$entry); $log=array_slice($log,0,(int)($this->opts['log_limit'] ?? 200)); update_option('wpfmcp_audit_log',$log,false); } catch(Throwable $e) { error_log('WPFMCP audit log failed: '.$e->getMessage()); } }
    private function audit_compact($backup): array { if(!is_array($backup)) return []; return ['strategy'=>$backup['strategy'] ?? null,'guard_passed'=>$backup['guard_passed'] ?? null,'task_id'=>$backup['wpvivid']['task_id'] ?? $backup['task_id'] ?? null,'db_file'=>$backup['db_export']['name'] ?? $backup['name'] ?? null]; }
    private function settings_export_keys(): array { return ['enabled','service_user_id','permission_profile','allow_plugin_install','allow_plugin_update','allow_plugin_activate','allow_plugin_deactivate','allow_plugin_delete','allow_theme_install','allow_theme_update','allow_theme_switch','allow_media_upload','approval_required_tools','enabled_tool_groups','backup_before_tools','backup_strategy','backup_guard_require_db_fallback','backup_prune_keep','enable_elementor_mcp_bridge','elementor_mcp_route','allowed_plugin_slugs','allowed_theme_slugs','log_limit']; }
    private function export_settings_tool(array $args): array { $this->assert_can('manage_options'); $settings=[]; foreach($this->settings_export_keys() as $key){ $settings[$key]=$this->opts[$key] ?? $this->defaults()[$key] ?? null; } if(!empty($args['include_secret'])) $settings['secret']=$this->opts['secret'] ?? ''; return ['version'=>defined('WPFMCP_VERSION')?WPFMCP_VERSION:null,'exported_at'=>gmdate('c'),'site'=>['url'=>home_url(),'name'=>get_bloginfo('name')],'settings'=>$settings,'notes'=>empty($args['include_secret'])?'Secret omitted. Import preserves existing secret unless rotate_secret=true.':'Secret included because include_secret=true. Store securely.']; }
    private function import_settings_tool(array $args): array { $this->assert_can('manage_options'); $this->require_confirm($args, 'IMPORT SETTINGS'); $incoming=$args['settings'] ?? null; if(!$incoming && !empty($args['settings_json'])) $incoming=json_decode((string)$args['settings_json'], true); if(isset($incoming['settings']) && is_array($incoming['settings'])) $incoming=$incoming['settings']; if(!is_array($incoming)) throw new RuntimeException('settings object or settings_json is required'); $defaults=$this->defaults(); $opts=$this->opts; $changed=[]; foreach($this->settings_export_keys() as $key){ if(!array_key_exists($key,$incoming)) continue; $value=$incoming[$key]; if(in_array($key, ['enabled','allow_plugin_install','allow_plugin_update','allow_plugin_activate','allow_plugin_deactivate','allow_plugin_delete','allow_theme_install','allow_theme_update','allow_theme_switch','allow_media_upload','backup_guard_require_db_fallback','enable_elementor_mcp_bridge'], true)) $value=empty($value)?0:1; elseif(in_array($key, ['service_user_id','backup_prune_keep','log_limit'], true)) $value=max(1,min(1000,intval($value))); elseif($key==='permission_profile') $value=in_array(sanitize_key((string)$value), array_keys($this->permission_profiles()), true)?sanitize_key((string)$value):$defaults['permission_profile']; elseif($key==='backup_strategy') $value=in_array($value, ['off','db_export','wpvivid','wpvivid_fallback_db'], true)?$value:$defaults['backup_strategy']; elseif($key==='elementor_mcp_route') $value='/' . ltrim(sanitize_text_field((string)$value), '/'); else $value=sanitize_textarea_field((string)$value); if(($opts[$key] ?? null)!==$value) $changed[]=$key; $opts[$key]=$value; } if(!empty($args['rotate_secret'])){ $opts['secret']=wp_generate_password(48,false,false); $changed[]='secret(rotated)'; } elseif(!empty($incoming['secret']) && is_string($incoming['secret']) && strlen($incoming['secret'])>=24){ $opts['secret']=sanitize_text_field($incoming['secret']); $changed[]='secret(imported)'; } update_option('wpfmcp_options', $opts, false); $this->opts=wp_parse_args($opts, $defaults); return ['imported'=>true,'changed_keys'=>array_values(array_unique($changed)),'secret_changed'=>in_array('secret(rotated)', $changed, true)||in_array('secret(imported)', $changed, true),'settings'=>$this->export_settings_tool([])['settings']]; }
    private function audit_log_tool(array $args): array { $this->assert_can('manage_options'); $limit=max(1,min(200,intval($args['limit'] ?? 50))); $tool=sanitize_key($args['tool'] ?? ''); $log=(array)get_option('wpfmcp_audit_log', []); if($tool) $log=array_values(array_filter($log, fn($e)=>($e['tool'] ?? '')===$tool)); return ['count'=>min($limit,count($log)),'entries'=>array_slice($log,0,$limit)]; }
    private function connector_manifest(): array { $tools=array_map(fn($t)=>$t['name'], $this->tools()); return ['name'=>'WP Full MCP Gateway','version'=>defined('WPFMCP_VERSION')?WPFMCP_VERSION:null,'site'=>['url'=>home_url(),'name'=>get_bloginfo('name'),'wp_version'=>get_bloginfo('version')],'permission_profile'=>$this->opts['permission_profile'] ?? 'content_editor','capabilities'=>['tool_count'=>count($tools),'enabled_groups'=>$this->enabled_groups(),'dangerous_tools'=>$this->dangerous_tools(),'approval_required_tools'=>$this->parse_list((string)($this->opts['approval_required_tools'] ?? '')),'backup_before_tools'=>$this->parse_list((string)($this->opts['backup_before_tools'] ?? ''))],'capability_groups'=>$this->capability_groups(),'connector_config'=>$this->connector_config(false),'backup'=>['strategy'=>$this->opts['backup_strategy'] ?? null,'guard_require_db_fallback'=>!empty($this->opts['backup_guard_require_db_fallback']),'prune_keep'=>(int)($this->opts['backup_prune_keep'] ?? 10)],'integrations'=>['woocommerce'=>$this->wc_status(false),'elementor_bridge'=>['enabled'=>!empty($this->opts['enable_elementor_mcp_bridge']),'route'=>$this->opts['elementor_mcp_route'] ?? null,'status'=>$this->elementor_mcp_status()],'wpvivid'=>$this->wpvivid_status()],'tools'=>$tools]; }
    private function export_diagnostics(): array { $health=$this->backup_health_check(); $manifest=$this->connector_manifest(); unset($manifest['tools']); return ['generated_at'=>gmdate('c'),'manifest'=>$manifest,'health'=>$health,'recent_audit'=>array_slice((array)get_option('wpfmcp_audit_log', []),0,10),'notes'=>['Secrets and connector tokens are intentionally omitted.']]; }

    private function backup_dir_info(string $dir): array { $exists=is_dir($dir); return ['path'=>$dir,'exists'=>$exists,'writable'=>$exists && is_writable($dir),'free_bytes'=>@disk_free_space($dir) ?: @disk_free_space(dirname($dir)) ?: null]; }
    private function backup_health_check(): array { $upload=wp_upload_dir(); $internal=trailingslashit($upload['basedir']).'wp-full-mcp-backups'; $wpvivid_dir=WP_CONTENT_DIR.'/wpvividbackups'; return ['wp_cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,'wp_cron_spawn_ok'=>function_exists('spawn_cron'),'backup_strategy'=>$this->opts['backup_strategy'] ?? null,'internal_db_backup_dir'=>$this->backup_dir_info($internal),'wpvivid_backup_dir'=>$this->backup_dir_info($wpvivid_dir),'wpvivid_status'=>$this->wpvivid_status(),'backup_before_tools'=>$this->parse_list((string)($this->opts['backup_before_tools'] ?? ''))]; }
    private function backup_before_action_status(string $tool): array { $tool=sanitize_key($tool); if(!$tool) throw new InvalidArgumentException('tool is required'); $backups=$this->list_backups(); $latest=$backups['backups'][0] ?? null; return ['tool'=>$tool,'backup_required'=>$this->backup_required($tool),'strategy'=>$this->opts['backup_strategy'] ?? null,'guard_require_db_fallback'=>!empty($this->opts['backup_guard_require_db_fallback']),'latest_internal_db_backup'=>$latest,'wpvivid'=>$this->wpvivid_get_status_compact()]; }
    private function prune_backups_tool(array $args): array { $this->assert_can('manage_options'); $keep=max(1,min(100,intval($args['keep'] ?? ($this->opts['backup_prune_keep'] ?? 10)))); return $this->prune_internal_backups($keep); }
    private function internal_backup_files(): array { [$dir, $url] = $this->backups_dir(); $files = glob($dir . '/*.sql') ?: []; rsort($files); return $files; }
    private function prune_internal_backups(int $keep): array { $list=$this->internal_backup_files(); $deleted=[]; $errors=[]; foreach(array_slice($list,$keep) as $file){ if($file && is_file($file)){ if(@unlink($file)) $deleted[]=basename($file); else $errors[]=basename($file); } } return ['keep'=>$keep,'deleted_count'=>count($deleted),'deleted'=>$deleted,'errors'=>$errors,'remaining'=>max(0,count($list)-count($deleted))]; }

    private function create_backup_tool(array $args): array { $this->assert_can('manage_options'); $strategy=$this->opts['backup_strategy'] ?? 'wpvivid_fallback_db'; if($strategy==='wpvivid' || $strategy==='wpvivid_fallback_db'){ try { $wpvivid=$this->wpvivid_create_backup(['backup_files'=>'all','remote'=>false,'label'=>$args['label'] ?? 'manual']); if($strategy==='wpvivid') return $wpvivid; $fallback=$this->create_db_backup('manual-' . sanitize_key($args['label'] ?? 'backup'), $args); return ['strategy'=>'wpvivid_queued_plus_db_export','wpvivid'=>$wpvivid,'db_export'=>$fallback]; } catch(Throwable $e){ if($strategy==='wpvivid') throw $e; $fallback=$this->create_db_backup('manual-' . sanitize_key($args['label'] ?? 'backup'), $args); $fallback['wpvivid_error']=$e->getMessage(); $fallback['strategy']='wpvivid_failed_db_export'; return $fallback; } } return $this->create_db_backup('manual-' . sanitize_key($args['label'] ?? 'backup'), $args); }
    private function list_backups(): array { $this->assert_can('manage_options'); $files = $this->internal_backup_files(); return ['count'=>count($files),'backups'=>array_map(fn($f) => ['name'=>basename($f),'bytes'=>filesize($f),'created_at'=>gmdate('c', filemtime($f)),'stored'=>'private_uploads'], array_slice($files, 0, 50))]; }

    private function list_approval_requests(array $args): array { $this->assert_can('manage_options'); $status=sanitize_key($args['status']??''); $items=array_values($this->approval_queue()); if($status)$items=array_values(array_filter($items, fn($i)=>($i['status']??'')===$status)); return array_map(fn($i)=>$this->redact_recursive($i), $items); }
    private function cancel_approval_request(array $args): array { $this->assert_can('manage_options'); $id=sanitize_text_field($args['approval_id']??''); $q=$this->approval_queue(); if(empty($q[$id])) throw new RuntimeException('Approval request not found'); if(($q[$id]['status']??'')==='executed') throw new RuntimeException('Cannot cancel executed approval request'); $q[$id]['status']='cancelled'; $q[$id]['rejected_at']=gmdate('c'); $this->save_approval_queue($q); return ['approval_id'=>$id,'status'=>'cancelled']; }

    private function redact_recursive($value) { if(is_array($value)){ $out=[]; foreach($value as $k=>$v){ $key=(string)$k; $out[$k]=preg_match('/secret|token|password|key|authorization|cookie/i', $key) ? '[redacted]' : $this->redact_recursive($v); } return $out; } return $value; }
    private function log_action(string $name, array $args): void { $log=get_option('wpfmcp_log', []); $safe=$this->redact_recursive($args); array_unshift($log, ['time'=>gmdate('c'),'tool'=>$name,'args'=>$safe,'ip'=>$_SERVER['REMOTE_ADDR']??'']); $limit=max(20,(int)$this->opts['log_limit']); $log=array_slice($log,0,$limit); update_option('wpfmcp_log',$log,false); }

    public function run_elementor_import_background(string $import_id, int $user_id): void {
        if(!$import_id) return;
        try {
            // Set user context
            if($user_id && get_userdata($user_id)) wp_set_current_user($user_id);

            // Set long timeout for Elementor import (up to 10 minutes)
            if(function_exists('set_time_limit')) set_time_limit(600);

            // Get pending import
            $pending = get_option('wpfmcp_elementor_pending_imports', []);
            if(empty($pending[$import_id])) {
                error_log('WPFMCP elementor import '.$import_id.' not found in pending queue');
                return;
            }
            $job = $pending[$import_id];
            $zip = $job['zip_path'] ?? '';
            $source = $job['source'] ?? '';
            $temporary = !empty($job['temporary']);
            $include = $job['include'] ?? [];
            $job['status'] = 'running';
            $job['started_at'] = $job['started_at'] ?? gmdate('c');
            $job['updated_at'] = gmdate('c');
            $job['current_step'] = 'preparing';
            $pending[$import_id] = $job;
            update_option('wpfmcp_elementor_pending_imports', $pending, false);

            if(!$zip || !is_file($zip)) {
                $job['status'] = 'failed';
                $job['failed_at'] = gmdate('c');
                $job['updated_at'] = gmdate('c');
                $job['current_step'] = 'failed';
                $job['error'] = 'ZIP file not found at '.$zip;
                $pending[$import_id] = $job;
                update_option('wpfmcp_elementor_pending_imports', $pending, false);
                error_log('WPFMCP elementor import '.$import_id.' ZIP not found: '.$zip);
                return;
            }

            // Import
            if(!class_exists('Elementor\\Plugin')) {
                require_once ABSPATH.'wp-admin/includes/plugin.php';
                if(!is_plugin_active('elementor/elementor.php')) {
                    $job['status'] = 'failed';
                    $job['failed_at'] = gmdate('c');
                    $job['updated_at'] = gmdate('c');
                    $job['current_step'] = 'failed';
                    $job['error'] = 'Elementor is not active.';
                    $pending[$import_id] = $job;
                    update_option('wpfmcp_elementor_pending_imports', $pending, false);
                    return;
                }
            }

            $manifest_conditions = $this->elementor_kit_manifest_conditions($zip);
            $manifest_summary = $this->elementor_kit_manifest_summary($zip);
            $site_settings = $this->elementor_kit_site_settings_from_zip($zip);
            $menus_before = $this->elementor_nav_menu_snapshot();
            $before = $this->elementor_kit_snapshot();
            try {
                $job['current_step'] = 'importing';
                $job['updated_at'] = gmdate('c');
                $pending[$import_id] = $job;
                update_option('wpfmcp_elementor_pending_imports', $pending, false);
                $component = null;
                if(isset(\Elementor\Plugin::$instance->app) && method_exists(\Elementor\Plugin::$instance->app, 'get_component'))
                    $component = \Elementor\Plugin::$instance->app->get_component('import-export');
                if((!$component || !method_exists($component, 'import_kit')) && class_exists('Elementor\\App\Modules\ImportExport\\Module'))
                    $component = new \Elementor\App\Modules\ImportExport\Module();
                if(!$component || !method_exists($component, 'import_kit')) {
                    throw new \RuntimeException('Elementor import-export component is unavailable.');
                }
                // Bootstrap full WooCommerce frontend for Elementor kits in WP-Cron context
                if (class_exists('WooCommerce') && function_exists('WC')) {
                    WC()->frontend_includes();
                    if (!WC()->cart instanceof \WC_Cart) {
                        WC()->cart = new \WC_Cart();
                    }
                    if (is_null(WC()->session) || !WC()->session->has_session()) {
                        if (class_exists('WC_Session_Handler')) {
                            WC()->session = new \WC_Session_Handler();
                            WC()->session->init();
                        }
                    }
                    if (is_null(WC()->customer)) {
                        $uid = get_current_user_id() ?: 0;
                        WC()->customer = new \WC_Customer($uid, true);
                    }
                }
                $settings = ['referrer' => 'local'];
                if(!empty($include)) $settings['include'] = $include;
                $result = $component->import_kit($zip, $settings, false);
                $job['result_raw'] = $this->redact_recursive($result ?? []);
            } catch(\Throwable $e) {
                $job['status'] = 'failed';
                $job['failed_at'] = gmdate('c');
                $job['updated_at'] = gmdate('c');
                $job['current_step'] = 'failed';
                $job['error'] = $e->getMessage();
                $pending[$import_id] = $job;
                update_option('wpfmcp_elementor_pending_imports', $pending, false);
                error_log('WPFMCP elementor import '.$import_id.' failed: '.$e->getMessage());
                return;
            } finally {
                if($temporary && $zip && is_file($zip)) @unlink($zip);
            }

            $job['current_step'] = 'finalizing';
            $job['updated_at'] = gmdate('c');
            $pending[$import_id] = $job;
            update_option('wpfmcp_elementor_pending_imports', $pending, false);
            $after = $this->elementor_kit_snapshot();
            $created = $this->elementor_kit_diff($before, $after);
            $applied_site_settings = $this->elementor_kit_apply_site_settings($site_settings);
            $applied_conditions = $this->elementor_kit_apply_manifest_conditions($created, $manifest_conditions);
            $repaired_menus = $this->elementor_kit_repair_nav_menu_widgets($created, $this->elementor_best_nav_menu_slug($menus_before));

            $job['status'] = 'completed';
            $job['completed_at'] = gmdate('c');
            $job['updated_at'] = gmdate('c');
            $job['current_step'] = 'completed';
            $timing = $this->elementor_import_timing($job);
            $job['created'] = $created;
            $job['created_counts'] = $this->elementor_kit_created_counts($created);
            $job['queued_seconds'] = $timing['queued_seconds'];
            $job['run_seconds'] = $timing['run_seconds'];
            $job['total_time_seconds'] = $timing['total_time_seconds'];
            $job['applied_site_settings'] = $applied_site_settings;
            $job['applied_conditions'] = $applied_conditions;
            $job['repaired_nav_menus'] = $repaired_menus;
            $job['manifest_summary'] = $manifest_summary ?? [];
            unset($job['result_raw']);

            // Also save to main import history
            $items = $this->elementor_kit_option();
            $record = [
                'import_id' => $import_id,
                'label' => $job['label'] ?? '',
                'source' => $source,
                'created_at' => $job['created_at'] ?? gmdate('c'),
                'started_at' => $job['started_at'] ?? null,
                'created_by' => $user_id,
                'created' => $created,
                'created_counts' => $this->elementor_kit_created_counts($created),
                'completed_at' => $job['completed_at'],
                'queued_seconds' => $job['queued_seconds'],
                'run_seconds' => $job['run_seconds'],
                'total_time_seconds' => $job['total_time_seconds'],
                'result' => ['message' => 'Background import completed', 'applied_site_settings' => $applied_site_settings, 'applied_conditions' => $applied_conditions, 'repaired_nav_menus' => $repaired_menus],
            ];
            array_unshift($items, $record);
            $this->save_elementor_kit_option($items);

            $pending[$import_id] = $job;
            update_option('wpfmcp_elementor_pending_imports', $pending, false);
            error_log('WPFMCP elementor import '.$import_id.' completed successfully');

        } catch(\Throwable $e) {
            error_log('WPFMCP elementor background import runner failed: '.$e->getMessage());
        }
    }

    private function elementor_kit_import_status(array $args): array {
        $this->assert_can('manage_options');
        $import_id = sanitize_text_field($args['import_id'] ?? '');
        if(!$import_id) throw new \RuntimeException('import_id is required.');

        // Check pending imports first
        $pending = get_option('wpfmcp_elementor_pending_imports', []);
        if(!empty($pending[$import_id])) {
            $job = $pending[$import_id];
            $status = $job['status'] ?? 'unknown';
            $created_at = $job['created_at'] ?? null;
            $started_at = $job['started_at'] ?? null;
            $completed_at = $job['completed_at'] ?? null;
            $failed_at = $job['failed_at'] ?? null;
            $updated_at = $job['updated_at'] ?? $created_at;
            $elapsed_from = $started_at ?: $created_at;
            $timing = $this->elementor_import_timing($job);
            $result = [
                'import_id' => $import_id,
                'label' => $job['label'] ?? '',
                'source' => $job['source'] ?? '',
                'status' => $status,
                'current_step' => $job['current_step'] ?? $status,
                'created_at' => $created_at,
                'started_at' => $started_at,
                'updated_at' => $updated_at,
                'completed_at' => $completed_at,
                'failed_at' => $failed_at,
                'elapsed_seconds' => $elapsed_from ? max(0, time() - strtotime((string)$elapsed_from)) : null,
                'queued_seconds' => $job['queued_seconds'] ?? $timing['queued_seconds'],
                'run_seconds' => $job['run_seconds'] ?? $timing['run_seconds'],
                'total_time_seconds' => $job['total_time_seconds'] ?? $timing['total_time_seconds'],
                'wp_cron_scheduled' => (bool)wp_next_scheduled('wpfmcp_elementor_run_import', [$import_id, (int)($job['created_by'] ?? 0)]),
            ];
            if($status === 'completed') {
                $result['created'] = $job['created'] ?? [];
                $result['created_counts'] = $job['created_counts'] ?? $this->elementor_kit_created_counts((array)($job['created'] ?? []));
                $result['applied_site_settings'] = $job['applied_site_settings'] ?? [];
                $result['applied_conditions'] = $job['applied_conditions'] ?? [];
                $result['repaired_nav_menus'] = $job['repaired_nav_menus'] ?? [];
                $result['manifest_summary'] = $job['manifest_summary'] ?? [];
                // Build manifest comparison with normalized key names
                $expected = $result['manifest_summary']['expected'] ?? [];
                $actual = $result['created_counts'] ?? [];
                // Normalize expected keys to match created_counts keys
                $normalized = [];
                // content_page -> page, content_post -> post
                foreach($expected as $k => $v) {
                    if($k === 'content_page') $normalized['page'] = ($normalized['page'] ?? 0) + (int)$v;
                    elseif($k === 'content_post') $normalized['post'] = ($normalized['post'] ?? 0) + (int)$v;
                    elseif($k === 'wp_post') $normalized['post'] = ($normalized['post'] ?? 0) + (int)$v;
                    elseif($k === 'wp_nav_menu_item') $normalized['nav_menu_item'] = ($normalized['nav_menu_item'] ?? 0) + (int)$v;
                    elseif($k === 'templates') {
                        // Templates in manifest are nested by doc_type; sum them
                        if(is_array($v)) {
                            $tpl_sum = 0;
                            foreach($v as $tpl_n) $tpl_sum += (int)$tpl_n;
                            // +1 for the kit itself (included in elementor_library count)
                            $normalized['elementor_library'] = ($normalized['elementor_library'] ?? 0) + $tpl_sum + 1;
                        }
                    }
                    elseif($k === 'wp_product') {
                        $normalized['product'] = ($normalized['product'] ?? 0) + (int)$v;
                    }
                    elseif($k === 'wp_attachment') {
                        // Not separately tracked
                    }
                    else {
                        $normalized[$k] = ($normalized[$k] ?? 0) + (int)$v;
                    }
                }
                $comparison = [];
                $total_expected = 0;
                $total_matched = 0;
                $all_matched = true;
                foreach($normalized as $k => $expected_n) {
                    $actual_n = (int)($actual[$k] ?? 0);
                    $match = $actual_n === $expected_n;
                    if(!$match) $all_matched = false;
                    if($expected_n > 0) {
                        $total_expected += $expected_n;
                        if($match) $total_matched += $expected_n;
                        else $total_matched += min($actual_n, $expected_n);
                    }
                    $comparison[$k] = ['expected' => $expected_n, 'actual' => $actual_n, 'match' => $match, 'delta' => $actual_n - $expected_n];
                }
                // Check for items in actual not in normalized expected
                foreach($actual as $k => $actual_n) {
                    if(!isset($normalized[$k]) && (int)$actual_n > 0) {
                        $comparison[$k] = ['expected' => 0, 'actual' => (int)$actual_n, 'match' => false, 'delta' => (int)$actual_n];
                        $all_matched = false;
                    }
                }
                $pct = $total_expected > 0 ? round(($total_matched / $total_expected) * 100) : 100;
                $result['manifest_comparison'] = [
                    'all_matched' => $all_matched,
                    'items_matched_pct' => (int)$pct,
                    'total_expected_items' => $total_expected,
                    'total_matched_items' => $total_matched,
                    'details' => $comparison,
                ];
            }
            if($status === 'failed') {
                $result['error'] = $job['error'] ?? 'Unknown error';
            }
            return $result;
        }

        // Check completed history
        $items = $this->elementor_kit_option();
        foreach($items as $item) {
            if(($item['import_id'] ?? '') === $import_id) {
                $timing = $this->elementor_import_timing($item);
                return [
                    'import_id' => $import_id,
                    'label' => $item['label'] ?? '',
                    'source' => $item['source'] ?? '',
                    'status' => 'completed',
                    'created_at' => $item['created_at'] ?? null,
                    'started_at' => $item['started_at'] ?? null,
                    'completed_at' => $item['completed_at'] ?? null,
                    'queued_seconds' => $item['queued_seconds'] ?? $timing['queued_seconds'],
                    'run_seconds' => $item['run_seconds'] ?? $timing['run_seconds'],
                    'total_time_seconds' => $item['total_time_seconds'] ?? $timing['total_time_seconds'],
                    'created' => $item['created'] ?? [],
                    'created_counts' => $item['created_counts'] ?? $this->elementor_kit_created_counts((array)($item['created'] ?? [])),
                    'applied_site_settings' => $item['result']['applied_site_settings'] ?? [],
                    'applied_conditions' => $item['result']['applied_conditions'] ?? [],
                    'repaired_nav_menus' => $item['result']['repaired_nav_menus'] ?? [],
                ];
            }
        }

        throw new \RuntimeException('Import not found for import_id: '.$import_id);
    }

    /**
     * Convert Elementor section/column layout to container/flexbox layout.
     * Supports single page, batch by IDs, or batch by kit import_id.
     */
    private function elementor_convert_columns_to_containers(array $args): array {
        $this->assert_can('manage_options');
        $dry_run = !empty($args['dry_run']);

        // Collect page IDs from args
        $page_ids = [];
        if (!empty($args['page_id'])) {
            $page_ids = [(int) $args['page_id']];
        } elseif (!empty($args['page_ids']) && is_array($args['page_ids'])) {
            $page_ids = array_map('intval', $args['page_ids']);
        } elseif (!empty($args['import_id'])) {
            $import_id = sanitize_text_field($args['import_id']);
            $items = $this->elementor_kit_option();
            $record = null;
            foreach ($items as $item) {
                if (($item['import_id'] ?? '') === $import_id) {
                    $record = $item;
                    break;
                }
            }
            if (!$record) throw new RuntimeException('Import not found for import_id: ' . $import_id);
            $created = (array)($record['created'] ?? []);
            foreach (['page', 'post', 'elementor_library'] as $type) {
                foreach (array_map('intval', (array)($created[$type] ?? [])) as $id) {
                    if ($id) $page_ids[] = $id;
                }
            }
            $page_ids = array_values(array_unique($page_ids));
        }

        if (empty($page_ids)) throw new RuntimeException('No pages to convert. Provide page_id, page_ids, or import_id.');

        $results = [];
        foreach ($page_ids as $pid) {
            $results[] = $this->elementor_convert_single_post($pid, $dry_run);
        }

        return [
            'dry_run' => $dry_run,
            'total_pages' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Preview handler for dry_run of elementor convert columns to containers.
     */
    private function elementor_convert_columns_preview(array $args): array {
        $safe = $args;
        unset($safe['approval_id'], $safe['dry_run']);
        $args['dry_run'] = true;
        try {
            $preview = $this->elementor_convert_columns_to_containers($args);
        } catch (\Throwable $e) {
            return ['dry_run' => true, 'tool' => 'wp-elementor-convert-columns-to-containers', 'group' => 'elementor', 'would_execute' => true, 'error' => $e->getMessage()];
        }
        return $preview + ['dry_run' => true, 'notes' => 'No WordPress data was changed. Re-run with dry_run=false/omitted to execute.'];
    }

    /**
     * Convert a single post from section/column to container/flexbox layout.
     */
    private function elementor_convert_single_post(int $post_id, bool $dry_run): array {
        $post = get_post($post_id);
        if (!$post) throw new RuntimeException('Post not found: ' . $post_id);

        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (!$raw || $raw === '[]') throw new RuntimeException('No Elementor data found for post: ' . $post_id);

        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('Invalid Elementor data for post: ' . $post_id);

        // Detect if already using containers
        $has_sections = false;
        foreach ($data as $el) {
            if (($el['elType'] ?? '') === 'section') { $has_sections = true; break; }
        }
        if (!$has_sections) {
            return [
                'page_id' => $post_id,
                'title' => $post->post_title,
                'converted' => false,
                'reason' => 'Already using container layout (no sections with columns found)',
                'sections_found' => 0,
                'containers_created' => 0,
            ];
        }

        $stats = ['sections' => 0, 'columns' => 0, 'containers' => 0, 'widgets' => 0, 'warnings' => []];
        $converted = [];
        foreach ($data as $element) {
            $converted[] = $this->elementor_convert_element($element, $stats, 0);
        }

        $result_sample = $converted[0] ?? [];

        if (!$dry_run) {
            $new_json = wp_json_encode($converted);
            if ($new_json === false) throw new RuntimeException('Failed to encode converted Elementor data');
            update_post_meta($post_id, '_elementor_data', $new_json);
            // Update elementor version meta to trigger CSS regeneration
            $ev = get_post_meta($post_id, '_elementor_version', true);
            if ($ev) update_post_meta($post_id, '_elementor_version', $ev);
        }

        return [
            'page_id' => $post_id,
            'title' => $post->post_title,
            'converted' => true,
            'sections_found' => $stats['sections'],
            'containers_created' => $stats['containers'],
            'columns_merged' => $stats['columns'],
            'widgets_preserved' => $stats['widgets'],
            'warnings' => $stats['warnings'],
            'result_sample' => $result_sample,
        ];
    }

    /**
     * Recursively convert an Elementor element from section/column to container.
     */
    private function elementor_convert_element(array $el, array &$stats, int $depth): array {
        $el_type = $el['elType'] ?? '';

        if ($el_type === 'section') {
            $stats['sections']++;
            $section_settings = $el['settings'] ?? [];
            $children = $el['elements'] ?? [];
            $columns = array_filter($children, fn($c) => ($c['elType'] ?? '') === 'column');

            if (count($columns) <= 1) {
                // Single column: merge section + column settings, flatten widgets
                $column = $columns[0] ?? null;
                $column_settings = $column ? ($column['settings'] ?? []) : [];
                $merged_settings = $this->elementor_merge_settings($section_settings, $column_settings);

                $inner_elements = [];
                if ($column) {
                    foreach (($column['elements'] ?? []) as $child) {
                        $inner_elements[] = $this->elementor_convert_element($child, $stats, $depth + 1);
                        if (($child['elType'] ?? '') === 'widget') $stats['widgets']++;
                    }
                }

                $stats['containers']++;
                return [
                    'id' => $this->elementor_new_id(),
                    'elType' => 'container',
                    'settings' => $merged_settings,
                    'elements' => $inner_elements,
                ];
            } else {
                // Multi-column: parent container with row direction, each column becomes child container
                $parent_settings = $this->elementor_section_to_container_settings($section_settings);
                $child_containers = [];

                foreach ($children as $child) {
                    if (($child['elType'] ?? '') === 'column') {
                        $stats['columns']++;
                        $col_settings = $child['settings'] ?? [];
                        $child_settings = $this->elementor_column_to_container_settings($col_settings);

                        $grandchildren = [];
                        foreach (($child['elements'] ?? []) as $gc) {
                            $grandchildren[] = $this->elementor_convert_element($gc, $stats, $depth + 2);
                            if (($gc['elType'] ?? '') === 'widget') $stats['widgets']++;
                        }

                        $stats['containers']++;
                        $child_containers[] = [
                            'id' => $this->elementor_new_id(),
                            'elType' => 'container',
                            'settings' => $child_settings,
                            'elements' => $grandchildren,
                        ];
                    } elseif (($child['elType'] ?? '') === 'section') {
                        // Inner section: recurse
                        $child_containers[] = $this->elementor_convert_element($child, $stats, $depth + 1);
                    } else {
                        $child_containers[] = $this->elementor_convert_element($child, $stats, $depth + 1);
                    }
                }

                $stats['containers']++;
                return [
                    'id' => $this->elementor_new_id(),
                    'elType' => 'container',
                    'settings' => $parent_settings,
                    'elements' => $child_containers,
                ];
            }
        }

        // Not a section: pass through (widget, inner section container, etc.)
        return $el;
    }

    /**
     * Generate a new 8-char hex ID for Elementor elements.
     */
    private function elementor_new_id(): string {
        return bin2hex(random_bytes(4));
    }

    /**
     * Merge section settings + column settings into container settings.
     * Section settings take priority; column settings fill in gaps.
     * Handles responsive, background, padding, margin, border, etc.
     */
    private function elementor_merge_settings(array $section, array $column): array {
        $merged = $section;

        // Background: prefer section background, but if section has none, use column
        if (empty($merged['background_background']) && !empty($column['background_background'])) {
            foreach (['background_background', 'background_color', 'background_image', 'background_image_id', 'background_position', 'background_size', 'background_repeat', 'background_video_link', 'background_video_fallback'] as $k) {
                if (!empty($column[$k])) $merged[$k] = $column[$k];
            }
        }

        // Padding: merge column padding if section doesn't have explicit values
        if (!empty($column['_padding']) && empty($merged['_padding'])) {
            $merged['_padding'] = $column['_padding'];
        }

        // Column alignment
        if (!empty($column['align_items'])) $merged['flex_align_items'] = $column['align_items'];
        if (!empty($column['text_align'])) $merged['text_align'] = $column['text_align'];

        // HTML tag from section
        if (!empty($section['html_tag'])) $merged['html_tag'] = $section['html_tag'];

        // CSS classes: merge both
        $section_classes = $section['_css_classes'] ?? '';
        $column_classes = $column['_css_classes'] ?? '';
        if ($section_classes || $column_classes) {
            $merged['_css_classes'] = trim($section_classes . ' ' . $column_classes);
        }

        // Responsive: section settings are primary, column responsive fills gaps
        foreach (['_tablet', '_mobile'] as $bp) {
            foreach (['_padding', '_margin'] as $prop) {
                $key = $bp . $prop;
                if (!empty($column[$key]) && empty($merged[$key])) {
                    $merged[$key] = $column[$key];
                }
            }
        }

        return $merged;
    }

    /**
     * Convert section settings to parent container settings (multi-column row).
     */
    private function elementor_section_to_container_settings(array $section): array {
        $container = $section;
        // Set flex direction to row for multi-column
        $container['flex_direction'] = 'row';
        $container['flex_justify'] = $section['flex_justify_content'] ?? 'center';
        $container['flex_align_items'] = $section['flex_align_items'] ?? 'stretch';
        $container['content_width'] = $section['content_width'] ?? 'boxed';
        // Gap for column spacing
        if (!empty($section['gap'])) {
            $container['gap'] = $section['gap'];
        }
        // HTML tag
        if (!empty($section['html_tag'])) {
            $container['html_tag'] = $section['html_tag'];
        }
        return $container;
    }

    /**
     * Convert column settings to child container settings.
     */
    private function elementor_column_to_container_settings(array $column): array {
        $container = [];
        // Column width
        $col_size = $column['_column_size'] ?? 50;
        if ($col_size && $col_size != 100) {
            $container['width'] = ['size' => (int)$col_size, 'unit' => '%'];
        }
        // Background
        foreach (['background_background', 'background_color', 'background_image', 'background_image_id', 'background_position', 'background_size', 'background_repeat', 'background_video_link', 'background_video_fallback'] as $k) {
            if (!empty($column[$k])) $container[$k] = $column[$k];
        }
        // Padding
        if (!empty($column['_padding'])) $container['_padding'] = $column['_padding'];
        // Margin
        if (!empty($column['_margin'])) $container['_margin'] = $column['_margin'];
        // Border
        foreach (['border_border', 'border_width', 'border_color', 'border_radius'] as $k) {
            if (!empty($column[$k])) $container[$k] = $column[$k];
        }
        // Alignment
        if (!empty($column['align_items'])) $container['flex_align_items'] = $column['align_items'];
        if (!empty($column['text_align'])) $container['text_align'] = $column['text_align'];
        // CSS classes
        if (!empty($column['_css_classes'])) $container['_css_classes'] = $column['_css_classes'];
        // Responsive
        foreach (['_tablet', '_mobile'] as $bp) {
            foreach (['_padding', '_margin'] as $prop) {
                $key = $bp . $prop;
                if (!empty($column[$key])) $container[$key] = $column[$key];
            }
        }
        return $container;
    }
}
