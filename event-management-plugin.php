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

// Plugin activation hook
register_activation_hook(__FILE__, 'event_management_plugin_activate');

function event_management_plugin_activate() {
    // Check if required files exist
    $plugin_dir = plugin_dir_path(__FILE__);
    $required_files = [
        'chain_&_enrich_events_endpoint.php',
        'customise_nested_form_text.php',
        'form_edits.php'
    ];
    
    $missing_files = [];
    foreach ($required_files as $file) {
        if (!file_exists($plugin_dir . $file)) {
            $missing_files[] = $file;
        }
    }
    
    if (!empty($missing_files)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            'Event Management Plugin cannot be activated. Missing required files: ' . implode(', ', $missing_files),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }
    
    // Set default options if they don't exist
    if (!get_option('event_management_event_field_id')) {
        add_option('event_management_event_field_id', '1');
    }
}

// Load plugin textdomain for localization
add_action('plugins_loaded', function() {
    load_plugin_textdomain('event-management-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Include modules (OOP classes) - with error handling
$plugin_dir = plugin_dir_path(__FILE__);

// Check and include chain_&_enrich_events_endpoint.php
if (file_exists($plugin_dir . 'chain_&_enrich_events_endpoint.php')) {
    require_once $plugin_dir . 'chain_&_enrich_events_endpoint.php';
} else {
    error_log('Event Management Plugin: chain_&_enrich_events_endpoint.php not found');
}

// Check and include customise_nested_form_text.php
if (file_exists($plugin_dir . 'customise_nested_form_text.php')) {
    require_once $plugin_dir . 'customise_nested_form_text.php';
} else {
    error_log('Event Management Plugin: customise_nested_form_text.php not found');
}

// Check and include form_edits.php
if (file_exists($plugin_dir . 'form_edits.php')) {
    require_once $plugin_dir . 'form_edits.php';
} else {
    error_log('Event Management Plugin: form_edits.php not found');
}

// ---
// For future extension, it is recommended to move classes to an includes/ folder and implement autoloading via spl_autoload_register or composer
// ---

add_action('init', function() {
    // Speakers
    register_taxonomy('speakers', ['events'], [
        'label'        => __('Speakers', 'event-management-plugin'),
        'public'       => true,
        'show_ui'      => true,
        'show_admin_column' => true,
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'speakers'],
    ]);
    // Sponsors
    register_taxonomy('sponsors', ['events'], [
        'label'        => __('Sponsors', 'event-management-plugin'),
        'public'       => true,
        'show_ui'      => true,
        'show_admin_column' => true,
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'sponsors'],
    ]);
    // Buildings
    register_taxonomy('buildings', ['events'], [
        'label'        => __('Buildings', 'event-management-plugin'),
        'public'       => true,
        'show_ui'      => true,
        'show_admin_column' => true,
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'buildings'],
    ]);
});

class Event_Management_Plugin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Initialize modules with dependency checks
        $this->initialize_modules();
    }
    
    private function initialize_modules() {
        // Check if required WordPress plugins/functions exist
        if (!function_exists('wpgetapi_endpoint')) {
            error_log('Event Management Plugin: WPGetAPI plugin not found. Some features may not work.');
        }
        
        if (!class_exists('GFAPI')) {
            error_log('Event Management Plugin: Gravity Forms not found. Some features may not work.');
        }
        
        // Initialize Event_Chain_Enricher
        if (class_exists('Event_Chain_Enricher')) {
            try {
                new Event_Chain_Enricher();
            } catch (Exception $e) {
                error_log('Event Management Plugin: Error initializing Event_Chain_Enricher: ' . $e->getMessage());
            }
        }
        
        // Initialize Event_Customise_Nested_Form_Text
        if (class_exists('Event_Customise_Nested_Form_Text')) {
            try {
                new Event_Customise_Nested_Form_Text();
            } catch (Exception $e) {
                error_log('Event Management Plugin: Error initializing Event_Customise_Nested_Form_Text: ' . $e->getMessage());
            }
        }
        
        // Initialize Event_Form_Edits
        if (class_exists('Event_Form_Edits')) {
            try {
                new Event_Form_Edits();
            } catch (Exception $e) {
                error_log('Event Management Plugin: Error initializing Event_Form_Edits: ' . $e->getMessage());
            }
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
        register_setting('event_management_options', 'event_management_event_field_id');
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
                        <tr valign="top">
                            <th scope="row"><?php _e('Event Field ID', 'event-management-plugin'); ?></th>
                            <td><input style="min-width: 400px;" type="text" name="event_management_event_field_id" value="<?php echo esc_attr(get_option('event_management_event_field_id', '1')); ?>" placeholder="1" /></td>
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

// Enqueue custom JS and CSS for event radio buttons
add_action('wp_enqueue_scripts', function() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $plugin_url = plugin_dir_url(__FILE__);
    
    // Check if JS file exists before enqueuing
    if (file_exists($plugin_dir . 'event-coming-radio.js')) {
        wp_enqueue_script(
            'event-coming-radio',
            $plugin_url . 'event-coming-radio.js',
            [],
            '1.0',
            true // Load in footer
        );
    }
    
    // Check if CSS file exists before enqueuing
    if (file_exists($plugin_dir . 'event-coming-radio.css')) {
        wp_enqueue_style(
            'event-coming-radio-style',
            $plugin_url . 'event-coming-radio.css',
            [],
            '1.0'
        );
    }
}); 
