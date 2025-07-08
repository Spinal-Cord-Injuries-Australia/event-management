# Event Management Plugin

A universal WordPress plugin for event management with OOP architecture, external API integration, and dynamic form configuration.

## What the plugin does
1. Configures forms and API integration via WordPress admin panel
2. Processes form submissions and enriches data via external APIs
3. Automatically creates/updates taxonomies (speakers, sponsors, buildings)
4. Displays forms and data according to your settings
5. All parameters configurable without code editing

## Installation & Setup
1. Copy plugin folder to `wp-content/plugins/`
2. Activate via WordPress admin panel
3. Install required plugins: Gravity Forms, WPGetAPI, JetEngine
4. **Note:** This plugin does not import or configure external APIs by itself. API import and data mapping must be set up manually using the WPGetAPI plugin. Please ensure you configure the necessary data mapping in the WPGetAPI settings according to your needs.
5. Configure settings at "Settings → Event Management"

## Multi-site Usage
- Copy plugin to each site in your network
- Use unique settings for each site (no shared parameters)
- Configure form IDs, API endpoints, and button texts per site
- Ensure all dependencies are installed on each site

## Security & Risks
- Uses WordPress standard mechanisms (register_setting, get_option)
- All data passes through WordPress filters and validation
- Restrict settings access to administrators only
- Keep third-party plugins updated and secure
- Monitor external API security

## File Structure
```
event-menegment/
├── event-management-plugin.php         # Main plugin file
├── chain_&_enrich_events_endpoint.php  # API processing & taxonomy sync
├── customise_nested_form_text.php      # Form text customization
├── form_edits.php                      # Dynamic form field processing
└── README.md                           # Documentation
```

## Requirements
- WordPress 5.0+
- Gravity Forms
- WPGetAPI
- JetEngine (optional)
- PHP 7.4+

## Configuration Fields
- **Form ID**: Gravity Forms form identifier
- **API ID**: External API identifier
- **No entries label**: Text for empty states
- **Add button label**: Primary action button text
- **Add another button label**: Secondary action button text

## Troubleshooting
- Verify all required plugins are installed and activated
- Check settings in plugin options page
- Review server error logs for PHP errors
- Ensure API endpoints are accessible

## Support
For questions or issues, contact the plugin author or your developer. 