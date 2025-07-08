<?php
/*
Plugin Name: Event Management Plugin
Description: Universal plugin for event management with admin settings. Fully OOP.
Version: 1.0
Author: Your Volodya
Text Domain: event-management-plugin
Domain Path: /languages
*/

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Check minimum PHP version
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Event Management Plugin requires PHP 7.4 or higher.</p></div>';
    });
    return;
}

// Load plugin textdomain for localization
add_action('plugins_loaded', function() {
    load_plugin_textdomain('event-management-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Include modules (OOP classes)
require_once plugin_dir_path(__FILE__) . 'chain_&_enrich_events_endpoint.php';
require_once plugin_dir_path(__FILE__) . 'customise_nested_form_text.php';
require_once plugin_dir_path(__FILE__) . 'form_edits.php';

// ---
// For future extension, it is recommended to move classes to an includes/ folder and implement autoloading via spl_autoload_register or composer
// ---

class Event_Management_Plugin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        // Initialize modules (after converting them to classes)
        if (class_exists('Event_Chain_Enricher')) {
            new Event_Chain_Enricher();
        }
        if (class_exists('Event_Customise_Nested_Form_Text')) {
            new Event_Customise_Nested_Form_Text();
        }
        if (class_exists('Event_Form_Edits')) {
            new Event_Form_Edits();
        }
    }

    public function add_settings_page() {
        add_options_page(
            __('Event Management Settings', 'event-management-plugin'),
            __('Event Management', 'event-management-plugin'),
            'manage_options',
            'event-management-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('event_management_options', 'event_management_form_id');
        register_setting('event_management_options', 'event_management_api_id');
        register_setting('event_management_options', 'event_management_no_entries_label');
        register_setting('event_management_options', 'event_management_add_button_label');
        register_setting('event_management_options', 'event_management_add_another_button_label');
        register_setting('event_management_options', 'event_management_parent_form_ids');
        register_setting('event_management_options', 'event_management_nested_field_ids');
        register_setting('event_management_options', 'event_management_webhook_form_ids');
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Event Management Settings', 'event-management-plugin'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('event_management_options');
                do_settings_sections('event_management_options');
                ?>
                <table class="form-table">
                    <tbody style="display: flex; flex-wrap: wrap;">
                        <tr valign="top">
                            <th scope="row"><?php _e('Nested Form ID', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_form_id" value="<?php echo esc_attr(get_option('event_management_form_id')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('API ID', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_api_id" value="<?php echo esc_attr(get_option('event_management_api_id')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('No entries label', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_no_entries_label" value="<?php echo esc_attr(get_option('event_management_no_entries_label')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Add button label', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_add_button_label" value="<?php echo esc_attr(get_option('event_management_add_button_label')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Add another button label', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_add_another_button_label" value="<?php echo esc_attr(get_option('event_management_add_another_button_label')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Parent Form ID(s) (comma separated)', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_parent_form_ids" value="<?php echo esc_attr(get_option('event_management_parent_form_ids')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Nested Field ID(s) (comma separated)', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_nested_field_ids" value="<?php echo esc_attr(get_option('event_management_nested_field_ids')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Webhook Form ID(s) (comma separated)', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_webhook_form_ids" value="<?php echo esc_attr(get_option('event_management_webhook_form_ids')); ?>" /></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Plugin initialization
add_action('plugins_loaded', function() {
    new Event_Management_Plugin();
}); 