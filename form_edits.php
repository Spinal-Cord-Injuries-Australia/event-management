<?php

class Event_Form_Edits {
	public function __construct() {
		$form_ids = array_filter(array_map('trim', explode(',', get_option('event_management_form_id') ?: '')));
		foreach ($form_ids as $form_id) {
			add_filter( 'gform_pre_render_' . $form_id, [$this, 'inject_child_rows'] );
			add_filter( 'gform_pre_validation_' . $form_id, [$this, 'inject_child_rows'] );
			add_filter( 'gform_pre_submission_filter_' . $form_id, [$this, 'inject_child_rows'] );
			add_filter( 'gform_admin_pre_render_' . $form_id, [$this, 'inject_child_rows'] );
			
			add_action('gform_entry_post_save_' . $form_id, [$this, 'save'], 10, 2);
			
			
		}
		
		$webhook_form_ids = array_filter(array_map('trim', explode(',', get_option('event_management_webhook_form_ids') ?: '')));
		foreach ($webhook_form_ids as $webhook_id) {
			add_filter("gform_webhooks_request_data_{$webhook_id}", [$this, 'webhook_rekey'], 10, 4);
		}
		
	}
	
	public function inject_child_rows( $form ) {
		$event_id = get_the_ID() ?: url_to_postid( wp_get_referer() );

		if ( is_admin() && isset( $_GET['lid'] ) ) {
			$e = GFAPI::get_entry( $_GET['lid'] );
			if ( ! is_wp_error( $e ) ) {
				$event_field_id = get_option('event_management_event_field_id', '1');

				$event_id = rgar( $e, $event_field_id );
			}
		}

		if ( ! $event_id || ! is_numeric( $event_id ) ) {
			return $form;
		}

		$rows = get_post_meta( $event_id, 'custom_registration_fields', true );
		if ( ! $rows || ! is_array( $rows ) ) {
			return $form;
		}

		$rows = isset( $rows[0] ) ? $rows[0] : $rows;
		$next = max( array_map( fn( $f ) => (int) $f->id, $form['fields'] ) ) + 1;

		foreach ( $rows as $row ) {
			$label = sanitize_text_field( $row['custom_registration_field_label'] ?? '' );
			$uuid  = sanitize_text_field( $row['custom_registration_field_id'] ?? '' );
			$type  = strtolower( sanitize_text_field( $row['custom_registration_field_type'] ?? 'text' ) );

			if ( ! $label || ! $uuid ) {
				continue;
			}

			$args = [
				'id'         => $next++,
				'formId'     => $form['id'],
				'label'      => $label,
				'adminLabel' => $uuid,
				'isRequired' => false,
				'type'       => 'text',
			];

			if ( $type === 'boolean' ) {
				$choices       = explode( ',', $row['custom_registration_field_boolean_choices'] ?? 'Yes,No' );
				$args['type']  = 'radio';
				$args['choices'] = array_map(
					fn( $c ) => [ 'text' => trim( $c ), 'value' => trim( $c ) ],
					$choices
				);
			} elseif ( $type === 'single choice' ) {
				$choices       = explode( ',', $row['custom_registration_field_choices'] ?? '' );
				$args['type']  = 'select';
				$args['choices'] = array_map(
					fn( $c ) => [ 'text' => trim( $c ), 'value' => trim( $c ) ],
					$choices
				);
			}

			$form['fields'][] = GF_Fields::create( $args );
		}

		return $form;
	}

	public function save( $entry, $form ) {
        $map = [];
        foreach ( $form['fields'] as $f ) {
            if ( $f->adminLabel !== '' ) {
                $map[ (string) $f->id ] = $f->adminLabel;
            }
        }
        if ( $map ) {
            gform_update_meta( $entry['id'], '_scia_admin_map', wp_json_encode( $map ) );
        }
        return $entry;
    }
	
	public function webhook_rekey( $data, $feed, $entry, $parent_form ) {
		$log = fn( $m ) => error_log( '[SCIA RK] ' . $m );

		/* helpers ────────────────────────────────────────────────*/
		$get_idx = static function( $fid ) {
			$fm = GFAPI::get_form( $fid );
			if ( is_wp_error( $fm ) ) {
				return [];
			}
			$out = [];
			foreach ( $fm['fields'] as $fld ) {
				$out[ (string) $fld->id ] = $fld;
			}
			return $out;
		};

		$get_rt = static function( $eid ) {
			$j = gform_get_meta( $eid, '_scia_admin_map' );
			return $j ? json_decode( $j, true ) : [];
		};

		$remap = static function( $arr, $idx, $rt ) use ( $log ) {
			$out = [];
			foreach ( $arr as $k => $v ) {
				if ( ! preg_match( '/^\d+(\.\d+)?$/', (string) $k ) ) {
					// meta keys stay
					$out[ $k ] = $v;
					continue;
				}

				$root  = strtok( $k, '.' );
				$field = $idx[ $root ] ?? null;
				$key   = $rt[ $k ] // runtime (adminLabel for sub-input)
						   ?? $rt[ $root ] // runtime (adminLabel for parent)
						   ?? ( $field ? ( $field->adminLabel ?: $field->label ) : '' )
						   ?: $k; // fall-back to numeric if all else fails

				/* composite “Name” field → separate parts */
				if ( $field && $field->type === 'name' && $field->inputs ) {
					$sub_lbl = '';
					foreach ( $field->inputs as $in ) {
						if ( (string) $in['id'] === $k ) {
							$sub_lbl = 'Name ' . trim( $in['label'] );
						}
					}
					if ( $sub_lbl !== '' ) {
						$key = $sub_lbl;
					}
				}

				$out[ $key ] = $v;
				unset( $arr[ $k ] ); // ensure numeric key never leaks
				$log( "re-key {$k} → {$key}" );
			}
			return $out;
		};

		/* A ▸ re-key parent primitive fields */
		$data = $remap( $data, $get_idx( $parent_form['id'] ), [] );

		/* B ▸ process every Nested-Forms container automatically */
		$containers = [];
		foreach ( $parent_form['fields'] as $f ) {
			if (
				( method_exists( $f, 'get_input_type' ) && $f->get_input_type() === 'form' )
				|| $f->type === 'form'
			) {
				$containers[ (string) $f->id ] = $f->adminLabel ?: $f->label ?: (string) $f->id;
			}
		}

		foreach ( $containers as $cid => $nice_key ) {
			$children = $data[ $cid ] ?? null;

			if ( ! is_array( $children ) ) {
				$flt      = [ 'field_filters' => [ [ 'key' => 'gpnf_entry_parent', 'value' => $entry['id'] ] ] ];
				$children = GFAPI::get_entries( 0, $flt );
				if ( is_wp_error( $children ) || ! $children ) {
					continue;
				}
			}

			foreach ( $children as $i => $c ) {
				$idx           = $get_idx( $c['form_id'] ?? 0 );
				$rt            = $get_rt( $c['id'] ?? 0 );
				$children[ $i] = $remap( $c, $idx, $rt );
			}

			unset( $data[ $cid ] );
			$data[ $nice_key ] = $children;
			$log( "container {$cid} renamed → {$nice_key}" );
		}

		$log( 'Finished parent + child re-key' );
		return $data;
	}
}
