<?php

class Event_Chain_Enricher {
    public function __construct() {
        add_filter('wpgetapi_raw_data', [$this, 'wpgetapi_map_multiple_lookups'], 10, 2);
        add_action('updated_post_meta', [$this, 'scia_after_sessions_meta_update'], 10, 4);
        add_action('updated_post_meta', [$this, 'scia_after_meta_update_for_taxonomies'], 10, 4);
        add_action('added_post_meta', [$this, 'scia_after_meta_update_for_taxonomies'], 10, 4);
        add_shortcode('custom_sessions', [$this, 'custom_sessions_shortcode']);
    }

    public function delete_orphan_terms($taxonomy) {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'all',
        ]);
        foreach ($terms as $term) {
            $post_count = $term->count;
            if ($post_count === 0) {
                wp_delete_term($term->term_id, $taxonomy);
            }
        }
    }

    public function wpgetapi_map_multiple_lookups($data, $wpgetapi) {
        $this->delete_orphan_terms('sponsors');
        $this->delete_orphan_terms('speakers');
        $this->delete_orphan_terms('buildings');
        $api_id = get_option('event_management_api_id') ?: '';
        if ($wpgetapi->api_id !== $api_id || $wpgetapi->endpoint_id !== 'events') {
            return $data;
        }
        if (empty($data['value']) || !is_array($data['value'])) {
            $data['value'] = [];
            return $data;
        }
        // 1) Fetch speakers & index
        $resp_det = wpgetapi_endpoint( $api_id, 'event_speakers', [ 'debug' => false ] );
        
        if (is_string($resp_det)) return;
        
        $details  = is_array( $resp_det['value'] ?? [] ) ? ($resp_det['value'] ?? []) : [];
        $details_index = [];
        foreach ( $details as $d ) {
            if ( ! empty( $d['msevtmgt_speakerid'] ) ) {
                $details_index[ $d['msevtmgt_speakerid'] ] = $d;
            }
        }

        // 2) Fetch speaker engagements
        $resp_eng    = wpgetapi_endpoint( $api_id, 'event_speakers_engagement', [ 'debug' => false ] );
        $engagements = is_array( $resp_eng['value'] ?? [] ) ? $resp_eng['value'] : [];

        // 3) Build speakers[]  
        foreach ( $data['value'] as &$record ) {
            $event_id   = $record['msevtmgt_eventid'] ?? '';
            $spkr_array = [];
            if ( $event_id ) {
                foreach ( $engagements as $row ) {
                    if ( ( $row['_msevtmgt_event_value'] ?? '' ) === $event_id ) {
                        $sid = $row['_msevtmgt_speaker_value'] ?? '';
                        $spkr_array[] = [
                            'speaker_id'    => sanitize_text_field( $sid ),
                            'speaker_name'  => sanitize_text_field( $row['msevtmgt_name'] ?? '' ),
                            'speaker_bio'   => sanitize_text_field( $details_index[ $sid ]['msevtmgt_about'] ?? '' ),
                            'speaker_email' => sanitize_text_field( $details_index[ $sid ]['msevtmgt_email'] ?? '' ),
                        ];
                    }
                }
            }
            $record['speakers'] = json_encode($spkr_array);
        }
        unset( $record );

        // 4) Fetch sessions & emit raw JSON under 'sessions_raw'
        $resp_sess       = wpgetapi_endpoint( $api_id, 'event_sessions', [ 'debug' => false ] );
        $sessions_lookup = is_array( $resp_sess['value'] ?? [] ) ? $resp_sess['value'] : [];
        
        foreach ( $data['value'] as &$record ) {
            $event_id   = $record['msevtmgt_eventid'] ?? '';
            $sess_array = [];
            
            if ( $event_id ) {
                foreach ( $sessions_lookup as $row ) {
                    if ( ( $row['_msevtmgt_event_value'] ?? '' ) === $event_id ) {
                        $sess_array[] = [
                            'session_id'          => sanitize_text_field( $row['msevtmgt_sessionid'] ?? '' ),
                            'speaker_id'          => sanitize_text_field( $row['_msevtmgt_eventspeakerid_value'] ?? '' ),
                            'session_title'       => sanitize_text_field( $row['msevtmgt_name'] ?? '' ),
                            'session_start_date'  => sanitize_text_field( $row['msevtmgt_starttime'] ?? '' ),
                            'session_start_time'  => sanitize_text_field( $row['msevtmgt_endtime'] ?? '' ),
                            'session_description' => sanitize_text_field( $row['msevtmgt_detaileddescription'] ?? '' ),
                        ];
                    }
                }
            }

            $sessions_repeater = [];
            foreach ( $sess_array as $i => $s ) {
                $sessions_repeater[ "item-{$i}" ] = [
                    'session_name' => sanitize_text_field( $s['session_title']      ?? '' ),
                    'start_time'   => sanitize_text_field( $s['session_start_date'] ?? '' ),
                    'end_time'     => sanitize_text_field( $s['session_start_time'] ?? '' ),
                    'session_id'   => sanitize_text_field( $s['session_id']         ?? '' ),
                    'speaker_id'   => sanitize_text_field( $s['speaker_id']         ?? '' ),
                    'session_description' => sanitize_text_field( $s['session_description'] ?? '' ),
                ];
            }
            $record['sessions_raw'] = wp_json_encode( $sess_array );
            $record['sessions']     = $sessions_repeater;
        }
        unset( $record );

        // 5) Fetch sponsors & emit raw JSON under 'sponsors'
        $resp_spons       = wpgetapi_endpoint( $api_id, 'event_sponsorsships', [ 'debug' => false ] );
        $sponsors_lookup = is_array( $resp_spons['value'] ?? [] ) ? $resp_spons['value'] : [];

        foreach ( $data['value'] as &$record ) {
            $event_id   = $record['msevtmgt_eventid'] ?? '';
            $spons_array = [];
            if ( $event_id ) {
                foreach ( $sponsors_lookup as $row ) {
                    if ( ( $row['_msevtmgt_event_value'] ?? '' ) === $event_id ) {
                        $spons_array[] = [
                            'sponsor_id'          => sanitize_text_field( $row['msevtmgt_sponsorshipid'] ?? '' ),
                            'speaker_id'          => sanitize_text_field( $row['_msevtmgt_eventspeakerid_value'] ?? '' ),
                            'sponsor_title'       => sanitize_text_field( $row['_msevtmgt_sponsor_value@OData.Community.Display.V1.FormattedValue'] ?? '' ),
                            'sponsor_description' => sanitize_text_field( $row['msevtmgt_description'] ?? '' ),
                        ];
                    }
                }
            }

            $record['sponsors'] = json_encode($spons_array);
        }
        unset( $record );

        // 6) Fetch buildings & emit raw JSON under 'buildings'
        $resp_build       = wpgetapi_endpoint( $api_id, 'event_buildings', [ 'debug' => false ] );
        $buildings_lookup = is_array( $resp_build['value'] ?? [] ) ? $resp_build['value'] : [];

        foreach ( $data['value'] as &$record ) {
            $building_id   = $record['_msevtmgt_building_value'] ?? '';
            $build_array = [];
            if ( $event_id ) {
                foreach ( $buildings_lookup as $row ) {
                    if ( ( $row['msevtmgt_buildingid'] ?? '' ) === $building_id ) {
                        $build_array[] = [
                            'building_id'          => sanitize_text_field( $row['msevtmgt_buildingid'] ?? '' ),
                            'name'       => sanitize_text_field( $row['msevtmgt_name'] ?? '' ),
                            'description' => sanitize_text_field( $row['msevtmgt_description'] ?? '' ),
                            'email' => sanitize_text_field( $row['msevtmgt_email'] ?? '' ),
                            'phone'       => sanitize_text_field( $row['msevtmgt_telephone1'] ?? '' ),
                            'addresscomposite' => sanitize_text_field( $row['msevtmgt_addresscomposite'] ?? '' ),
                            'city' => sanitize_text_field( $row['msevtmgt_city'] ?? '' ),
                        ];
                    }
                }
            }

            $record['buildings'] = json_encode($build_array);
        }
        unset( $record );

        // 7) Fetch custom registration fields & emit raw JSON under 'custom_registration_fields_raw'
        $resp_custom_links = wpgetapi_endpoint( $api_id, 'event_custom_registration_fields', [ 'debug' => false ] );
        $resp_custom_defs  = wpgetapi_endpoint( $api_id, 'custom_registration_fields', [ 'debug' => false ] );

        $custom_links = is_array( $resp_custom_links['value'] ?? [] ) ? $resp_custom_links['value'] : [];
        $custom_defs  = is_array( $resp_custom_defs['value']  ?? [] ) ? $resp_custom_defs['value']  : []; 

        // Build lookup by field definition ID
        $definition_index = [];
        foreach ( $custom_defs as $def ) {
            if ( ! empty( $def['msevtmgt_customregistrationfieldid'] ) ) {
                $definition_index[ $def['msevtmgt_customregistrationfieldid'] ] = $def;
            }
        }

        foreach ( $data['value'] as &$record ) {
            $event_id     = $record['msevtmgt_eventid'] ?? '';
            $custom_array = [];

            if ( $event_id ) {
                foreach ( $custom_links as $row ) {
                    if ( ( $row['_msevtmgt_event_value'] ?? '' ) === $event_id ) {
                        $def_id = $row['_msevtmgt_customregistrationfield_value'] ?? '';
                        $def    = $definition_index[ $def_id ] ?? [];

                        $type_code  = intval( $def['msevtmgt_type'] ?? -1 );
                        $choices_raw = $def['msevtmgt_choices'] ?? '';
                        $choices_array = array_filter(array_map('trim', explode("\n", str_replace("\r", '', $choices_raw))));

                        $custom_array[] = [
                          'custom_registration_field_id'           => sanitize_text_field( $def_id ),
                          'custom_registration_field_name'         => sanitize_text_field( $def['msevtmgt_text'] ?? '' ),
                          'custom_registration_field_text'         => $type_code === 100000000,
                          'custom_registration_field_boolean'      => $type_code === 100000001,
                          'custom_registration_field_multi_choice' => $type_code === 100000002,
                          'custom_registration_field_single_choice'=> $type_code === 100000003,
                          'custom_registration_field_choices'      => in_array($type_code, [100000002, 100000003]) 
                              ? explode( "\n", $def['msevtmgt_choices'] ?? '' )
                              : [],
                        ];
                    }
                }
            }

            $repeater = [];
            foreach ( $custom_array as $i => $f ) {
                $repeater[ "item-{$i}" ] = [
                    'custom_registration_field_id'              => sanitize_text_field( $f['custom_registration_field_id'] ?? '' ),
                    'custom_registration_field_label'           => sanitize_text_field( $f['custom_registration_field_name'] ?? '' ),
                    'custom_registration_field_type'            => 
                        !empty($f['custom_registration_field_text'])         ? 'Text' :
                        (!empty($f['custom_registration_field_boolean'])     ? 'Boolean' :
                        (!empty($f['custom_registration_field_multi_choice'])? 'Multi Choice' :
                        (!empty($f['custom_registration_field_single_choice'])? 'Single Choice' : 'Unknown'))),
                    'custom_registration_field_choices'         => !empty($f['custom_registration_field_choices']) && is_array($f['custom_registration_field_choices']) 
                        ? implode(', ', array_map('sanitize_text_field', $f['custom_registration_field_choices'])) 
                        : '',
                    'custom_registration_field_boolean_choices' => !empty($f['custom_registration_field_boolean']) ? 'Yes, No' : '',
                    'custom_registration_field_is_text'         => !empty($f['custom_registration_field_text']) ? 'Yes' : '',
                    'custom_registration_field_is_boolean'      => !empty($f['custom_registration_field_boolean']) ? 'Yes' : '',
                    'custom_registration_field_is_multi'        => !empty($f['custom_registration_field_multi_choice']) ? 'Yes' : '',
                    'custom_registration_field_is_single'       => !empty($f['custom_registration_field_single_choice']) ? 'Yes' : '',
                ];
            }
            $record['custom_registration_fields_raw'] = wp_json_encode( $custom_array );
            $record['custom_registration_fields']     = $repeater;
        }

        // --- Create or update posts for each event and save meta fields ---
        foreach ($data['value'] as &$record) {
            $event_unique_id = $record['msevtmgt_eventid'] ?? '';
            // Try to find existing event post by unique eventid
            $existing = new WP_Query([
                'post_type'  => 'events',
                'meta_key'   => 'msevtmgt_eventid',
                'meta_value' => $event_unique_id,
                'posts_per_page' => 1,
                'fields' => 'ids'
            ]);
            if ($existing->have_posts()) {
                $post_id = $existing->posts[0];
            } else {
                $post_id = wp_insert_post([
                    'post_title'   => $record['event_title'] ?? 'Imported Event',
                    'post_type'    => 'events',
                    'post_status'  => 'publish',
                ]);
                update_post_meta($post_id, 'msevtmgt_eventid', $event_unique_id);
            }
            // Save meta fields
            update_post_meta($post_id, 'speakers', $record['speakers'] ?? '');
            update_post_meta($post_id, 'sponsors', $record['sponsors'] ?? '');
            update_post_meta($post_id, 'buildings', $record['buildings'] ?? '');
            update_post_meta($post_id, 'custom_registration_fields', $record['custom_registration_fields'] ?? '');
            update_post_meta($post_id, 'sessions', $record['sessions'] ?? '');
            // ... add other meta fields if needed ...

            $this->scia_after_meta_update_for_taxonomies($post_id, 'speakers', $record['speakers'] ?? '');
            $this->scia_after_meta_update_for_taxonomies($post_id, 'sponsors', $record['sponsors'] ?? '');
            $this->scia_after_meta_update_for_taxonomies($post_id, 'buildings', $record['buildings'] ?? '');
        }

        return $data;
    }

    public function scia_after_sessions_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        // only target the sessions_raw field
        if ( 'sessions' !== $meta_key ) {
            return;
        }

        // decode into PHP array
        $decoded = json_decode( is_array( $meta_value ) ? reset( $meta_value ) : $meta_value, true );
        
        if ( ! is_array( $decoded ) ) {
            return;
        }

        // build the JetEngine repeater array
        $repeater = [];
        foreach ( $decoded as $i => $s ) {
            $repeater[ "item-{$i}" ] = [
                'session_name' => sanitize_text_field( $s['session_title']      ?? '' ),
                'start_time'   => sanitize_text_field( $s['session_start_date'] ?? '' ),
                'end_time'     => sanitize_text_field( $s['session_start_time'] ?? '' ),
                'session_id'   => sanitize_text_field( $s['session_id']         ?? '' ),
                'speaker_id'   => sanitize_text_field( $s['speaker_id']         ?? '' ),
            ];
        }

        // save as array (JetEngine-compatible)
        remove_action( 'updated_post_meta', 'scia_after_sessions_meta_update', 10 );
        update_post_meta( $post_id, 'sessions', $repeater );
        add_action( 'updated_post_meta', 'scia_after_sessions_meta_update', 10, 4 );
    }

    public function scia_after_meta_update_for_taxonomies($post_id, $meta_key, $meta_value) {
        error_log("[TAXO_SYNC] meta_key={$meta_key} post_id={$post_id}");
        // Only process supported taxonomy meta keys
        if ( ! in_array( $meta_key, [ 'speakers', 'sponsors', 'buildings' ] ) ) {
            error_log("[TAXO_SYNC] meta_key {$meta_key} not supported");
            return;
        }

        // Handle 'speakers' taxonomy syncing
        if ( $meta_key === 'speakers' ) {
            error_log("[TAXO_SYNC] Processing speakers for post {$post_id}");
            $speakers = json_decode($meta_value, true);
            error_log("[TAXO_SYNC] speakers raw: " . var_export($meta_value,1));
            if ( ! is_array( $speakers ) ) {
                error_log("[TAXO_SYNC] speakers not array");
                return;
            }
            $term_ids = [];
            foreach ( $speakers as $speaker ) {
                error_log("[TAXO_SYNC] speaker: " . var_export($speaker,1));
                if ( empty( $speaker['speaker_name'] ) ) {
                    error_log("[TAXO_SYNC] speaker_name empty");
                    continue;
                }
                $name        = sanitize_text_field( $speaker['speaker_name'] );
                $slug        = sanitize_title( $name );
                $description = isset( $speaker['speaker_bio'] ) ? wp_kses_post( $speaker['speaker_bio'] ) : '';
                $existing_term = get_term_by( 'slug', $slug, 'speakers' );
                if ( $existing_term ) {
                    $term_id = $existing_term->term_id;
                    wp_update_term( $term_id, 'speakers', [ 'description' => $description ] );
                    error_log("[TAXO_SYNC] Updated speaker term {$term_id}");
                } else {
                    $result = wp_insert_term( $name, 'speakers', [
                        'slug'        => $slug,
                        'description' => $description,
                    ]);
                    if ( is_wp_error( $result ) ) {
                        error_log("[TAXO_SYNC] wp_insert_term error: " . $result->get_error_message());
                        return;
                    }
                    $term_id = $result['term_id'];
                    error_log("[TAXO_SYNC] Inserted speaker term {$term_id}");
                }
                if ( ! empty( $term_id ) ) {
                    if ( ! empty( $speaker['speaker_id'] ) ) {
                        update_term_meta( $term_id, 'speaker_id', sanitize_text_field( $speaker['speaker_id'] ) );
                    }
                    if ( ! empty( $speaker['speaker_email'] ) ) {
                        update_term_meta( $term_id, 'speaker_email', sanitize_email( $speaker['speaker_email'] ) );
                    }
                    if ( ! empty( $speaker['speaker_image'] ) ) {
                        update_term_meta( $term_id, 'speaker_image', 'data:image/gif;base64,' . sanitize_text_field( $speaker['speaker_image'] ) );
                    }
                    $term_ids[] = $term_id;
                }
            }
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $post_id, $term_ids, 'speakers' );
                error_log("[TAXO_SYNC] Set speaker terms: " . implode(',', $term_ids));
            }
        }
        // Handle 'sponsors' taxonomy syncing
        else if ( $meta_key === 'sponsors' ) {
            error_log("[TAXO_SYNC] Processing sponsors for post {$post_id}");
            $sponsors = json_decode( $meta_value, true );
            error_log("[TAXO_SYNC] sponsors raw: " . var_export($meta_value,1));
            if ( ! is_array( $sponsors ) ) {
                error_log("[TAXO_SYNC] sponsors not array");
                return;
            }
            $existing_terms = wp_get_object_terms( $post_id, 'sponsors', [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $existing_terms ) && ! empty( $existing_terms ) ) {
                foreach ( $existing_terms as $term_id ) {
                    wp_remove_object_terms( $post_id, (int)$term_id, 'sponsors' );
                }
            }
            wp_set_object_terms( $post_id, [], 'sponsors' );
            $term_ids = [];
            foreach ( $sponsors as $sponsor ) {
                error_log("[TAXO_SYNC] sponsor: " . var_export($sponsor,1));
                if ( empty( $sponsor['sponsor_title'] ) ) {
                    error_log("[TAXO_SYNC] sponsor_title empty");
                    continue;
                }
                $name        = sanitize_text_field( $sponsor['sponsor_title'] );
                $slug        = sanitize_title( $name );
                $description = isset( $sponsor['sponsor_description'] ) ? wp_kses_post( $sponsor['sponsor_description'] ) : '';
                $existing_term = get_term_by( 'slug', $slug, 'sponsors' );
                if ( $existing_term ) {
                    $term_id = $existing_term->term_id;
                    wp_update_term( $term_id, 'sponsors', [ 'description' => $description ] );
                    error_log("[TAXO_SYNC] Updated sponsor term {$term_id}");
                } else {
                    $result = wp_insert_term( $name, 'sponsors', [
                        'slug'        => $slug,
                        'description' => $description,
                    ] );
                    if ( is_wp_error( $result ) ) {
                        error_log("[TAXO_SYNC] wp_insert_term error: " . $result->get_error_message());
                        return;
                    }
                    $term_id = $result['term_id'];
                    error_log("[TAXO_SYNC] Inserted sponsor term {$term_id}");
                }
                if ( ! empty( $term_id ) ) {
                    if ( ! empty( $sponsor['sponsor_id'] ) ) {
                        update_term_meta( $term_id, 'sponsor_id', sanitize_text_field( $sponsor['sponsor_id'] ) );
                    }
                    if ( ! empty( $sponsor['sponsor_title'] ) ) {
                        update_term_meta( $term_id, 'sponsor_title', sanitize_text_field( $sponsor['sponsor_title'] ) );
                    }
                    if ( ! empty( $sponsor['sponsor_description'] ) ) {
                        update_term_meta( $term_id, 'sponsor_description', sanitize_text_field( $sponsor['sponsor_description'] ) );
                    }
                    $term_ids[] = $term_id;
                }
            }
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $post_id, $term_ids, 'sponsors' );
                error_log("[TAXO_SYNC] Set sponsor terms: " . implode(',', $term_ids));
            }
        }
        // Handle 'buildings' taxonomy syncing
        else if($meta_key === 'buildings'){
            error_log("[TAXO_SYNC] Processing buildings for post {$post_id}");
            $buildings = json_decode($meta_value, 1);
            error_log("[TAXO_SYNC] buildings raw: " . var_export($meta_value,1));
            if ( ! is_array( $buildings ) ) {
                error_log("[TAXO_SYNC] buildings not array");
                return;
            }
            $term_ids = [];
            foreach ( $buildings as $building ) {
                error_log("[TAXO_SYNC] building: " . var_export($building,1));
                if ( empty( $building['name'] ) ) {
                    error_log("[TAXO_SYNC] building name empty");
                    continue;
                }
                $name        = sanitize_text_field( $building['name'] );
                $slug        = sanitize_title( $name );
                $description = isset( $building['description'] ) ? wp_kses_post( $building['description'] ) : '';
                $existing_term = get_term_by( 'slug', $slug, 'buildings' );
                if ( $existing_term ) {
                    $term_id = $existing_term->term_id;
                    wp_update_term( $term_id, 'buildings', [ 'description' => $description ] );
                    error_log("[TAXO_SYNC] Updated building term {$term_id}");
                } else {
                    $result = wp_insert_term( $name, 'buildings', [
                        'slug'        => $slug,
                        'description' => $description,
                    ]);
                    if ( is_wp_error( $result ) ) {
                        error_log("[TAXO_SYNC] wp_insert_term error: " . $result->get_error_message());
                        return;
                    }
                    $term_id = $result['term_id'];
                    error_log("[TAXO_SYNC] Inserted building term {$term_id}");
                }
                if ( ! empty( $term_id ) ) {
                    if ( ! empty( $building['building_id'] ) ) {
                        update_term_meta( $term_id, 'building_id', sanitize_text_field( $building['building_id'] ) );
                    }
                    if ( ! empty( $building['email'] ) ) {
                        update_term_meta( $term_id, 'email', sanitize_text_field( $building['email'] ) );
                    }
                    if ( ! empty( $building['phone'] ) ) {
                        update_term_meta( $term_id, 'phone', sanitize_text_field( $building['phone'] ) );
                    }
                    if ( ! empty( $building['addresscomposite'] ) ) {
                        update_term_meta( $term_id, 'addresscomposite', sanitize_text_field( $building['addresscomposite'] ) );
                    }
                    if ( ! empty( $building['city'] ) ) {
                        update_term_meta( $term_id, 'city', sanitize_text_field( $building['city'] ) );
                    }
                    $term_ids[] = $term_id;
                }
            }
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $post_id, $term_ids, 'buildings' );
                error_log("[TAXO_SYNC] Set building terms: " . implode(',', $term_ids));
            }
        }
    }

    public function custom_sessions_output($sessions) {
        if ( ! is_array( $sessions ) ) {
            return '';
        }

        $output = '<div class="sessions-wrapper">';

        foreach ( $sessions as $session ) {
            $name = esc_html( $session['session_name'] ?? '' );

            $output .= "<div class='session-item'>";
            $output .= "<h5>$name</h5>";
            $output .= "</div>";
        }

        $output .= '</div>';

        return $output;
    }

    public function custom_sessions_shortcode() {
        $sessions = get_post_meta( get_the_ID(), 'sessions', true );

        return $this->custom_sessions_output( $sessions );
    }
}