<?php

class Event_Form_Edits {
	public function __construct() {
		$form_ids = array_filter(array_map('trim', explode(',', get_option('event_management_form_id') ?: '')));
		foreach ($form_ids as $form_id) {
			add_filter("gform_pre_render_{$form_id}", [$this, 'scia_inject_child_rows']);
			add_filter("gform_pre_validation_{$form_id}", [$this, 'scia_inject_child_rows']);
			add_filter("gform_pre_submission_filter_{$form_id}", [$this, 'scia_inject_child_rows']);
			add_filter("gform_admin_pre_render_{$form_id}", [$this, 'scia_inject_child_rows']);
			add_action("gform_entry_post_save_{$form_id}", [$this, 'save_runtime_map'], 10, 2);
			add_action("gform_entry_post_save_{$form_id}", [$this, 'save_custom_registration_fields'], 20, 2);
		}
		$webhook_form_ids = array_filter(array_map('trim', explode(',', get_option('event_management_webhook_form_ids') ?: '')));
		foreach ($webhook_form_ids as $webhook_id) {
			add_filter("gform_webhooks_request_data_{$webhook_id}", [$this, 'scia_webhook_rekey'], 10, 4);
		}
	}

	public function scia_inject_child_rows($form) {
		$event_id = get_the_ID() ?: url_to_postid(wp_get_referer());
		if (is_admin() && isset($_GET['lid'])) {
			$e = GFAPI::get_entry($_GET['lid']);
			if (!is_wp_error($e)) {
				$event_id = rgar($e, '1');
			}
		}
		if (!$event_id || !is_numeric($event_id)) {
			return $form;
		}
		$rows = get_post_meta($event_id, 'custom_registration_fields', true);
		if (!$rows || !is_array($rows)) {
			return $form;
		}
		$rows = isset($rows[0]) ? $rows[0] : $rows;
		$next = max(array_map(fn($f) => (int)$f->id, $form['fields'])) + 1;
		foreach ($rows as $row) {
			$label = sanitize_text_field($row['custom_registration_field_label'] ?? '');
			$uuid  = sanitize_text_field($row['custom_registration_field_id']   ?? '');
			$type  = strtolower(sanitize_text_field($row['custom_registration_field_type'] ?? 'text'));
			if (!$label || !$uuid) {
				continue;
			}
			$args = [
				'id'         => $next++,
				'formId'     => $form['id'],
				'label'      => $label,
				'adminLabel' => $label,
				'isRequired' => false,
				'type'       => 'text',
				'inputName'  => $uuid,
			];
			if ($type === 'boolean') {
				$choices         = explode(',', $row['custom_registration_field_boolean_choices'] ?? 'Yes,No');
				$args['type']    = 'radio';
				$args['choices'] = array_map(fn($c) => ['text' => trim($c), 'value' => trim($c)], $choices);
			} elseif ($type === 'single choice') {
				$choices         = explode(',', $row['custom_registration_field_choices'] ?? '');
				$args['type']    = 'select';
				$args['choices'] = array_map(fn($c) => ['text' => trim($c), 'value' => trim($c)], $choices);
			}
			$form['fields'][] = GF_Fields::create($args);
		}
		return $form;
	}

	public function map_custom_fields_to_gf_inputs($form) {
		foreach ($form['fields'] as $field) {
			if (!empty($field->inputName) && isset($_POST[$field->inputName])) {
				$_POST['input_' . $field->id] = $_POST[$field->inputName];
			}
		}
		return $form;
	}

	public function save_runtime_map($entry, $form) {
		$map = [];
		foreach ($form['fields'] as $f) {
			if ($f->adminLabel !== '') {
				$map[(string)$f->id] = $f->adminLabel;
			}
		}
		if ($map) {
			gform_update_meta($entry['id'], '_scia_admin_map', wp_json_encode($map));
		}
		return $entry;
	}

	public function save_custom_registration_fields($entry, $form) {
		error_log('[GF DEBUG] save_custom_registration_fields called');
		$event_id = get_the_ID() ?: url_to_postid(wp_get_referer());
		error_log('[GF DEBUG] event_id: ' . print_r($event_id, true));
		if (!$event_id || !is_numeric($event_id)) {
			error_log('[GF DEBUG] invalid event_id');
			return $entry;
		}
		$custom_fields = get_post_meta($event_id, 'custom_registration_fields', true);
		error_log('[GF DEBUG] custom_fields: ' . print_r($custom_fields, true));
		if ($custom_fields && is_array($custom_fields)) {
			$custom_fields = isset($custom_fields[0]) ? $custom_fields[0] : $custom_fields;
			foreach ($custom_fields as $row) {
				$uuid = $row['custom_registration_field_id'] ?? '';
				$label = $row['custom_registration_field_label'] ?? '';
				error_log('[GF DEBUG] row: ' . print_r($row, true));
				// Найти поле с таким label в форме
				foreach ($form['fields'] as $f) {
					error_log('[GF DEBUG] compare: label=' . $label . ' f->label=' . $f->label);
					if ($f->label === $label && isset($entry[$f->id])) {
						error_log('[GF DEBUG] MATCH: entry[' . $uuid . '] = entry[' . $f->id . '] = ' . print_r($entry[$f->id], true));
						$entry[$uuid] = $entry[$f->id];
					}
				}
				// Если uuid есть, но не заполнено — оставить пустым
				if ($uuid && !isset($entry[$uuid])) {
					error_log('[GF DEBUG] EMPTY: entry[' . $uuid . '] set to empty string');
					$entry[$uuid] = '';
				}
			}
			error_log('[GF DEBUG] entry before update: ' . print_r($entry, true));
			GFAPI::update_entry($entry);
		}
		return $entry;
	}

	public function scia_webhook_rekey($data, $feed, $entry, $parent_form) {
		$log = fn($m) => error_log('[SCIA RK] ' . $m);
		$get_idx = static function($fid) {
			$fm = GFAPI::get_form($fid);
			if (is_wp_error($fm)) {
				return [];
			}
			$out = [];
			foreach ($fm['fields'] as $fld) {
				$out[(string)$fld->id] = $fld;
			}
			return $out;
		};
		$get_rt = static function($eid) {
			$j = gform_get_meta($eid, '_scia_admin_map');
			return $j ? json_decode($j, true) : [];
		};
		$remap = static function($arr, $idx, $rt) use ($log) {
			$out = [];
			foreach ($arr as $k => $v) {
				if (!preg_match('/^\\d+(?:\\.\\d+)?$/', (string)$k)) {
					$out[$k] = $v;
					continue;
				}
				$root  = strtok($k, '.');
				$field = $idx[$root] ?? null;
				$key = $rt[$k]
					?? $rt[$root]
					?? ($field ? ($field->adminLabel ?: $field->label) : '')
					?: $k;
				if ($field && $field->type === 'name' && $field->inputs) {
					$sub_lbl = '';
					foreach ($field->inputs as $in) {
						if ((string)$in['id'] === $k) {
							$sub_lbl = 'Name ' . trim($in['label']);
							break;
						}
					}
					if ($sub_lbl !== '') {
						$key = $sub_lbl;
					}
				}
				$out[$key] = $v;
				unset($arr[$k]);
				$log("re-key {$k} → {$key}");
			}
			return $out;
		};
		$data = $remap($data, $get_idx($parent_form['id']), []);
		$containers = [];
		foreach ($parent_form['fields'] as $f) {
			if ((method_exists($f, 'get_input_type') && $f->get_input_type() === 'form') || $f->type === 'form') {
				$containers[(string)$f->id] = $f->adminLabel ?: $f->label ?: (string)$f->id;
			}
		}
		foreach ($containers as $cid => $nice_key) {
			$children = $data[$cid] ?? null;
			if (!is_array($children)) {
				$flt = ['field_filters' => [['key' => 'gpnf_entry_parent', 'value' => $entry['id']]]];
				$children = GFAPI::get_entries(0, $flt);
				if (is_wp_error($children) || !$children) {
					continue;
				}
			}
			$rt = $get_rt($entry['id']);
			$idx = $get_idx($parent_form['id']);
			$data[$nice_key] = array_map(function($row) use ($remap, $idx, $rt) {
				return $remap($row, $idx, $rt);
			}, $children);
			unset($data[$cid]);
		}
		return $data;
	}
}
