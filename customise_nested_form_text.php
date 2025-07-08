<?php

class Event_Customise_Nested_Form_Text {
    public function __construct() {
        $parent_form_ids = array_filter(array_map('trim', explode(',', get_option('event_management_parent_form_ids') ?: '')));
        $nested_field_ids = array_filter(array_map('trim', explode(',', get_option('event_management_nested_field_ids') ?: '')));

        foreach ($parent_form_ids as $parent_id) {
            foreach ($nested_field_ids as $nested_id) {
                add_filter("gpnf_template_args_{$parent_id}_{$nested_id}", [$this, 'customise_no_entries_label'], 10, 2);
            }
            add_filter("gpnf_template_args_{$parent_id}", [$this, 'customise_add_button_label']);
        }
    }

    public function customise_no_entries_label($args) {
        $custom_label = get_option('event_management_no_entries_label') ?: "No tickets added, please add one to make a booking";
        $args['labels']['no_entries'] = $custom_label;
        return $args;
    }

    public function customise_add_button_label($args) {
        if (isset($args['add_button'])) {
            $custom_add_label = get_option('event_management_add_button_label') ?: 'Add a ticket';
            $custom_add_another_label = get_option('event_management_add_another_button_label') ?: 'Add Another ticket';
            $search   = 'data-bind="';
            $replace  = $search . sprintf('text: ! entries().length ? `%s` : `%s`, ', $custom_add_label, $custom_add_another_label);
            $args['add_button'] = str_replace($search, $replace, $args['add_button']);
        }
        return $args;
    }
}