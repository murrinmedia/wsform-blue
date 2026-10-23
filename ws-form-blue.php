<?php

/**
 * Plugin Name: WS Form - Blue
 * Plugin URI: https://murrinmedia.com.au
 * Description: Sends WS Form submissions to Blue (blue.app) as records in a chosen workspace/database, via Blue's REST API. Settings: WS Form > Settings > Blue. Per-form: add a "Send to Blue" action.
 * Version: 1.0.0
 * Author: MurrinMedia
 * Author URI: https://murrinmedia.com.au
 * License: GPL-2.0+
 */

	// Exit if accessed directly
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	// Register once WS Form Pro has loaded WS_Form_Action
	add_action('wsf_plugins_loaded', function() {

		if(class_exists('WS_Form_Action_Blue')) { return; }
		if(!class_exists('WS_Form_Action')) { return; }

		class WS_Form_Action_Blue extends WS_Form_Action {

			public $id = 'blue';
			public $pro_required = false;
			public $label;
			public $label_action;
			public $events;
			public $multiple = true;
			public $configured = false;
			public $priority = 50;
			public $can_repost = true;
			public $form_add = false;
			public $record_label = 'Record';
			public $list_sub_modal_label = 'Select Database';

			// Blue API base
			const API_BASE = 'https://rest.blue.app';

			// Writable Blue field types we offer in the field-mapping dropdown
			const WRITABLE_FIELD_TYPES = array(
				'TEXT_SINGLE', 'TEXT_MULTI', 'NUMBER', 'PERCENT', 'CURRENCY',
				'CHECKBOX', 'SELECT_SINGLE', 'SELECT_MULTI', 'DATE',
				'EMAIL', 'URL', 'PHONE'
			);

			// Plugin-level config (credentials)
			private $api_token_id;
			private $api_secret;
			private $api_org_id;

			// Form action config
			public $list_id = false;      // Blue workspace ID
			public $list_sub_id = false;  // Blue database ID
			public $title;
			public $field_mapping;
			public $custom_mapping;

			public function __construct() {

				// Events
				$this->events = array('submit');

				// Register config filters
				add_filter('wsf_config_options', array($this, 'config_options'), 10, 1);
				add_filter('wsf_config_meta_keys', array($this, 'config_meta_keys'), 10, 2);
				add_filter('wsf_settings_static', array($this, 'settings_static'), 10, 2);
				add_action('rest_api_init', array($this, 'rest_api_init'), 10, 0);

				// Nag if not configured
				add_action('wsf_nag', array($this, 'nag'));

				// Load plugin level configuration (API token / org ID)
				self::load_config_plugin();

				// Register init action
				add_action('init', array($this, 'init'));
			}

			public function init() {

				// Set label
				$this->label = __('Blue', 'ws-form-blue');

				// Set label for actions pull down
				$this->label_action = __('Send to Blue', 'ws-form-blue');

				// Register action
				parent::register($this);
			}

			// Nag - show a reminder in wp-admin if not configured
			public function nag() {

				self::load_config_plugin();

				if(!$this->configured) {

					WS_Form_Common::admin_message_push(sprintf(
						__('To finish setting up %s, enter your Blue Token ID, Secret Key and Organization ID <a href="%s">here</a>.', 'ws-form-blue'),
						$this->label,
						WS_Form_Common::get_admin_url('ws-form-settings', -1, 'tab=action_' . $this->id)
					), 'notice-warning', false);
				}
			}

			// Load plugin level configuration (API credentials)
			public function load_config_plugin() {

				$this->api_token_id = WS_Form_Common::option_get('action_' . $this->id . '_token_id', '');
				$this->api_secret   = WS_Form_Common::option_get('action_' . $this->id . '_secret', '');
				$this->api_org_id   = WS_Form_Common::option_get('action_' . $this->id . '_org_id', '');

				$this->configured = (!empty($this->api_token_id) && !empty($this->api_secret) && !empty($this->api_org_id));

				return $this->configured;
			}

			// Load config for this action (per form)
			public function load_config($config = array()) {

				$this->list_id = parent::get_config($config, 'action_' . $this->id . '_list_id', false);
				$this->list_sub_id = parent::get_config($config, 'action_' . $this->id . '_list_sub_id', false);
				$this->title = parent::get_config($config, 'action_' . $this->id . '_title', '');

				$this->field_mapping = parent::get_config($config, 'action_' . $this->id . '_field_mapping', array());
				if(!is_array($this->field_mapping)) { $this->field_mapping = array(); }

				$this->custom_mapping = parent::get_config($config, 'action_' . $this->id . '_custom_mapping', array());
				if(!is_array($this->custom_mapping)) { $this->custom_mapping = array(); }
			}

			// Check action is configured properly
			public function check_configured() {

				if(!$this->configured) { return self::error(__('Action not configured - enter your Blue Token ID, Secret Key and Organization ID in WS Form Settings', 'ws-form-blue') . ' (' . $this->label . ')'); }

				return $this->configured;
			}

			// Check list ID (workspace) is set
			public function check_list_id() {

				if(empty($this->list_id)) { return self::error(__('Blue workspace is not set', 'ws-form-blue')); }

				return !empty($this->list_id);
			}

			// Check list sub ID (database) is set
			public function check_list_sub_id() {

				if(empty($this->list_sub_id)) { return self::error(__('Blue database is not set', 'ws-form-blue')); }

				return !empty($this->list_sub_id);
			}

			// ---------------------------------------------------------------
			// Blue API helper
			// ---------------------------------------------------------------

			// Generic call to the Blue REST API
			private function blue_api($path, $body = array()) {

				$headers = array(

					'blue-token-id'     => $this->api_token_id,
					'blue-token-secret' => $this->api_secret,
					'blue-org-id'       => $this->api_org_id
				);

				if(!is_array($body)) { $body = array(); }
				$body['organizationId'] = $this->api_org_id;

				$api_response = parent::api_call(self::API_BASE, $path, 'POST', wp_json_encode($body), $headers);

				$decoded = json_decode($api_response['response'], true);

				return array(

					'http_code' => $api_response['http_code'],
					'data'      => $decoded,
					'raw'       => $api_response['response']
				);
			}

			// Get API error detail
			public function get_api_error_detail($raw) {

				$decoded = json_decode($raw, true);

				if(is_array($decoded)) {

					if(isset($decoded['error']) && is_string($decoded['error'])) { return $decoded['error']; }
					if(isset($decoded['message']) && is_string($decoded['message'])) { return $decoded['message']; }
				}

				return __('Unknown Blue API error', 'ws-form-blue');
			}

			// ---------------------------------------------------------------
			// Submission handling
			// ---------------------------------------------------------------

			// Post to Blue - Create a database record
			public function post($form, &$submit, $config) {

				// Check action is configured properly
				if(!self::check_configured()) { return false; }

				// Load configuration
				self::load_config($config);

				// Check workspace / database are set
				if(!self::check_list_id()) { return false; }
				if(!self::check_list_sub_id()) { return false; }

				// Get the Blue database's fields (cached)
				$fields = self::get_list_fields();
				if(empty($fields)) { return self::error(__('Could not load Blue database fields - check the action is still pointing at a valid database', 'ws-form-blue')); }

				$field_lookup = array();
				foreach($fields as $field) { $field_lookup[$field['id']] = $field; }

				$field_values = array();

				// Field mapping
				foreach($this->field_mapping as $field_map) {

					$field_id = isset($field_map['ws_form_field']) ? $field_map['ws_form_field'] : '';
					if(empty($field_id)) { continue; }

					$blue_field_id = isset($field_map['action_' . $this->id . '_list_fields']) ? $field_map['action_' . $this->id . '_list_fields'] : '';
					if(empty($blue_field_id) || !isset($field_lookup[$blue_field_id])) { continue; }

					// Get submit value
					$value = parent::get_submit_value($submit, WS_FORM_FIELD_PREFIX . $field_id, '');

					$built = self::build_field_value($field_lookup[$blue_field_id], $value);
					if($built !== false) { $field_values[] = $built; }
				}

				// Custom mapping (static values / merge tags)
				foreach($this->custom_mapping as $custom_map) {

					$blue_field_id = isset($custom_map['action_' . $this->id . '_list_fields']) ? $custom_map['action_' . $this->id . '_list_fields'] : '';
					if(empty($blue_field_id) || !isset($field_lookup[$blue_field_id])) { continue; }

					$value = isset($custom_map['action_' . $this->id . '_custom_mapping_value']) ? $custom_map['action_' . $this->id . '_custom_mapping_value'] : '';
					$value = WS_Form_Common::parse_variables_process($value, $form, $submit, 'text/plain');
					if($value === '') { continue; }

					$built = self::build_field_value($field_lookup[$blue_field_id], $value);
					if($built !== false) { $field_values[] = $built; }
				}

				// Title (supports merge tags, e.g. #field(8) #field(9))
				$title = WS_Form_Common::parse_variables_process($this->title, $form, $submit, 'text/plain');
				$title = trim((string) $title);
				if($title === '') { $title = sprintf(__('New Lead - %s', 'ws-form-blue'), current_time('Y-m-d H:i')); }

				// Build request
				$data = array(

					'workspaceId' => $this->list_id,
					'databaseId'  => $this->list_sub_id,
					'title'       => $title,
					'fieldValues' => $field_values
				);

				// Create the record
				$api_response = self::blue_api('/v1/create-database-record', $data);

				if(in_array($api_response['http_code'], array(200, 201))) {

					self::success(sprintf(__('Successfully created record in Blue: %s', 'ws-form-blue'), $title));
					return true;
				}

				return self::error(self::get_api_error_detail($api_response['raw']));
			}

			// Convert a WS Form submit value into a Blue fieldValues entry, or false to skip
			private function build_field_value($blue_field, $value) {

				$field_id = $blue_field['id'];
				$type = $blue_field['type'];

				// Normalize to a flat array of non-empty strings (handles single values and arrays from multi-value fields)
				$value_array = is_array($value) ? $value : array($value);
				$value_array = array_values(array_filter(array_map(function($v) {

					return is_scalar($v) ? trim((string) $v) : '';

				}, $value_array), function($v) { return $v !== ''; }));

				switch($type) {

					case 'TEXT_SINGLE' :
					case 'TEXT_MULTI' :

						if(count($value_array) === 0) { return false; }
						return array('fieldId' => $field_id, 'kind' => 'text', 'value' => array('text' => implode(', ', $value_array)));

					case 'NUMBER' :
					case 'PERCENT' :
					case 'CURRENCY' :

						if(count($value_array) === 0) { return false; }
						$number = preg_replace('/[^0-9.\-]/', '', $value_array[0]);
						if(($number === '') || !is_numeric($number)) { return false; }
						return array('fieldId' => $field_id, 'kind' => 'number', 'value' => array('number' => (float) $number));

					case 'CHECKBOX' :

						$checked = (count($value_array) > 0) && !in_array(strtolower($value_array[0]), array('0', 'off', 'no', 'false'));
						return array('fieldId' => $field_id, 'kind' => 'checkbox', 'value' => $checked);

					case 'DATE' :

						if(count($value_array) === 0) { return false; }
						$timestamp = strtotime($value_array[0]);
						if($timestamp === false) { return false; }
						$date = gmdate('Y-m-d\TH:i:s.000\Z', $timestamp);
						return array('fieldId' => $field_id, 'kind' => 'date', 'value' => array('start' => $date, 'end' => $date, 'granularity' => 'ALL_DAY'));

					case 'EMAIL' :
					case 'URL' :

						if(count($value_array) === 0) { return false; }
						return array('fieldId' => $field_id, 'kind' => 'contact', 'value' => array('text' => $value_array[0]));

					case 'PHONE' :

						if(count($value_array) === 0) { return false; }

						// Assumes Australian numbers - change regionCode below if this is ever used for a non-AU site
						return array('fieldId' => $field_id, 'kind' => 'contact', 'value' => array('text' => $value_array[0], 'regionCode' => 'AU'));

					case 'SELECT_SINGLE' :
					case 'SELECT_MULTI' :

						if(count($value_array) === 0) { return false; }
						if(empty($blue_field['options'])) { return false; }

						$option_ids = array();

						foreach($value_array as $value_single) {

							foreach($blue_field['options'] as $option) {

								if(strcasecmp(trim($option['title']), $value_single) === 0) {

									$option_ids[] = $option['id'];
									break;
								}
							}
						}

						if(count($option_ids) === 0) { return false; }
						return array('fieldId' => $field_id, 'kind' => 'select', 'value' => $option_ids);

					default :

						return false;
				}
			}

			// ---------------------------------------------------------------
			// Cascading dropdowns: workspaces -> databases -> fields
			// ---------------------------------------------------------------

			// Get workspaces (Blue "list")
			public function get_lists($fetch = false) {

				if(!self::check_configured()) { return array(); }

				$cache_key = 'action_' . $this->id . '_lists';
				$lists = WS_Form_Common::option_get($cache_key, false);

				if($fetch || ($lists === false)) {

					$api_response = self::blue_api('/v1/list-workspaces', array());

					if($api_response['http_code'] != 200) { return self::error(self::get_api_error_detail($api_response['raw'])); }

					$data = $api_response['data'];
					$items = isset($data['items']) ? $data['items'] : (is_array($data) ? $data : array());

					$lists = array();

					foreach($items as $item) {

						if(!isset($item['id'])) { continue; }

						$lists[] = array(

							'id'           => $item['id'],
							'label'        => isset($item['name']) ? $item['name'] : $item['id'],
							'record_count' => false
						);
					}

					WS_Form_Common::option_set($cache_key, $lists);
				}

				return $lists;
			}

			// Get databases within a workspace (Blue "list_sub")
			public function get_list_subs($fetch = false) {

				if(!self::check_configured()) { return array(); }
				if(!self::check_list_id()) { return array(); }

				$cache_key = 'action_' . $this->id . '_list_subs_' . $this->list_id;
				$list_subs = WS_Form_Common::option_get($cache_key, false);

				if($fetch || ($list_subs === false)) {

					$api_response = self::blue_api('/v1/list-databases', array('workspaceId' => $this->list_id));

					if($api_response['http_code'] != 200) { return self::error(self::get_api_error_detail($api_response['raw'])); }

					$data = $api_response['data'];
					$items = isset($data['items']) ? $data['items'] : (is_array($data) ? $data : array());

					$list_subs = array();

					foreach($items as $item) {

						if(!isset($item['id'])) { continue; }

						$list_subs[] = array(

							'id'           => $item['id'],
							'label'        => isset($item['name']) ? $item['name'] : $item['id'],
							'record_count' => false
						);
					}

					WS_Form_Common::option_set($cache_key, $list_subs);
				}

				return $list_subs;
			}

			// Get fields for a database (Blue "list_fields")
			public function get_list_fields($fetch = false) {

				if(!self::check_configured()) { return array(); }
				if(!self::check_list_id()) { return array(); }
				if(!self::check_list_sub_id()) { return array(); }

				$cache_key = 'action_' . $this->id . '_list_fields_' . $this->list_sub_id;
				$list_fields = WS_Form_Common::option_get($cache_key, false);

				if($fetch || ($list_fields === false)) {

					$api_response = self::blue_api('/v1/list-database-fields', array(

						'workspaceId' => $this->list_id,
						'databaseId'  => $this->list_sub_id,
						'forView'     => true
					));

					if($api_response['http_code'] != 200) { return self::error(self::get_api_error_detail($api_response['raw'])); }

					$data = $api_response['data'];
					$items = isset($data['items']) ? $data['items'] : (is_array($data) ? $data : array());

					$list_fields = array();
					$sort_index = 1;

					foreach($items as $item) {

						if(!isset($item['id']) || !isset($item['type'])) { continue; }

						// Only offer field types we know how to write to
						if(!in_array($item['type'], self::WRITABLE_FIELD_TYPES)) { continue; }

						$options = array();

						if(!empty($item['options']) && is_array($item['options'])) {

							foreach($item['options'] as $option) {

								if(!isset($option['id']) || !isset($option['title'])) { continue; }
								$options[] = array('id' => $option['id'], 'title' => $option['title']);
							}
						}

						$list_fields[] = array(

							'id'            => $item['id'],
							'id_sub'        => false,
							'label'         => $item['name'],
							'label_field'   => $item['name'],
							'label_sub'     => false,
							'type'          => $item['type'],
							'options'       => $options,
							'required'      => false,
							'default_value' => '',
							'pattern'       => false,
							'placeholder'   => false,
							'input_mask'    => false,
							'help'          => '',
							'sort_index'    => $sort_index,
							'visible'       => true
						);

						$sort_index++;
					}

					WS_Form_Common::option_set($cache_key, $list_fields);
				}

				return $list_fields;
			}

			// ---------------------------------------------------------------
			// REST routes backing the cascading dropdowns
			// ---------------------------------------------------------------

			public function rest_api_init() {

				// Workspaces
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/lists/', array('methods' => 'GET', 'callback' => array($this, 'api_get_lists'), 'permission_callback' => function () { return WS_Form_Common::can_user('create_form'); }));
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/lists/fetch/', array('methods' => 'GET', 'callback' => array($this, 'api_fetch_lists'), 'permission_callback' => function () { return WS_Form_Common::can_user('create_form'); }));

				// Databases (within a workspace)
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/list/(?P<list_id>[a-zA-Z0-9_-]+)/subs/', array('methods' => 'GET', 'callback' => array($this, 'api_get_list_subs'), 'permission_callback' => function () { return WS_Form_Common::can_user('create_form'); }));
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/list/(?P<list_id>[a-zA-Z0-9_-]+)/subs/fetch/', array('methods' => 'GET', 'callback' => array($this, 'api_fetch_list_subs'), 'permission_callback' => function () { return WS_Form_Common::can_user('create_form'); }));

				// Fields (within a database)
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/list/(?P<list_id>[a-zA-Z0-9_-]+)/subs/(?P<list_sub_id>[a-zA-Z0-9_-]+)/fields/', array('methods' => 'GET', 'callback' => array($this, 'api_get_list_fields'), 'permission_callback' => function () { return WS_Form_Common::can_user('create_form'); }));
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/list/(?P<list_id>[a-zA-Z0-9_-]+)/subs/(?P<list_sub_id>[a-zA-Z0-9_-]+)/fields/fetch/', array('methods' => 'GET', 'callback' => array($this, 'api_fetch_list_fields'), 'permission_callback' => function () { return WS_Form_Common::can_user('create_form'); }));
			}

			public function api_get_lists() {

				self::api_response(self::get_lists());
			}

			public function api_fetch_lists() {

				self::api_response(self::get_lists(true));
			}

			public function api_get_list_subs($parameters) {

				$this->list_id = WS_Form_Common::get_query_var('list_id', false, $parameters);
				self::api_response(self::get_list_subs());
			}

			public function api_fetch_list_subs($parameters) {

				$this->list_id = WS_Form_Common::get_query_var('list_id', false, $parameters);
				self::api_response(self::get_list_subs(true));
			}

			public function api_get_list_fields($parameters) {

				$this->list_id = WS_Form_Common::get_query_var('list_id', false, $parameters);
				$this->list_sub_id = WS_Form_Common::get_query_var('list_sub_id', false, $parameters);
				self::api_response(self::get_list_fields());
			}

			public function api_fetch_list_fields($parameters) {

				$this->list_id = WS_Form_Common::get_query_var('list_id', false, $parameters);
				$this->list_sub_id = WS_Form_Common::get_query_var('list_sub_id', false, $parameters);
				self::api_response(self::get_list_fields(true));
			}

			// ---------------------------------------------------------------
			// Settings page (WS Form > Settings > Blue) - API credentials
			// ---------------------------------------------------------------

			public function config_options($options) {

				$options['action_' . $this->id] = array(

					'label'  => $this->label,
					'fields' => array(

						'action_' . $this->id . '_status' => array(

							'label' => __('Blue Status', 'ws-form-blue'),
							'type'  => 'static',
							'help'  => $this->configured ? '' : __('Enter your Personal Access Token and Organization ID below, then save.', 'ws-form-blue')
						),

						'action_' . $this->id . '_token_id' => array(

							'label' => __('Token ID', 'ws-form-blue'),
							'type'  => 'text',
							'help'  => __('From Blue: click your profile avatar (top-right) > API > Generate a Token. This is the non-secret "Token ID" shown alongside the secret.', 'ws-form-blue')
						),

						'action_' . $this->id . '_secret' => array(

							'label' => __('Secret Key', 'ws-form-blue'),
							'type'  => 'text',
							'help'  => __('The secret shown once when you generate the token (starts with pat_). Treat it like a password - Blue only shows it once.', 'ws-form-blue')
						),

						'action_' . $this->id . '_org_id' => array(

							'label' => __('Organization ID', 'ws-form-blue'),
							'type'  => 'text',
							'help'  => __('The Blue organization this form should create records in (e.g. cm01p6zbu1lemnyan5wtlbgxe for MurrinMedia).', 'ws-form-blue')
						)
					)
				);

				return $options;
			}

			// Status shown on the settings page
			public function settings_static($value, $field) {

				if($field === 'action_' . $this->id . '_status') {

					return $this->configured ? __('Connected', 'ws-form-blue') : __('Not configured', 'ws-form-blue');
				}

				return $value;
			}

			// ---------------------------------------------------------------
			// Per-form action settings (the "Send to Blue" action panel)
			// ---------------------------------------------------------------

			public function config_meta_keys($meta_keys = array(), $form_id = 0) {

				$config_meta_keys = array(

					// Workspace
					'action_' . $this->id . '_list_id' => array(

						'label'                       => __('Workspace', 'ws-form-blue'),
						'type'                        => 'select',
						'help'                        => __('Select the Blue workspace to create records in.', 'ws-form-blue'),
						'options'                     => 'action_api_populate',
						'options_blank'                => __('Select...', 'ws-form-blue'),
						'options_action_id'            => $this->id,
						'options_action_api_populate'  => 'lists',
						'reload'                      => array(

							'action_id' => $this->id,
							'method'    => 'lists_fetch'
						)
					),

					// Database
					'action_' . $this->id . '_list_sub_id' => array(

						'label'                        => __('Database', 'ws-form-blue'),
						'type'                         => 'select',
						'help'                         => __('Select the Blue database (table) to create records in.', 'ws-form-blue'),
						'options'                      => 'action_api_populate',
						'options_blank'                 => __('Select...', 'ws-form-blue'),
						'options_action_id'             => $this->id,
						'options_list_id_meta_key'      => 'action_' . $this->id . '_list_id',
						'options_action_api_populate'   => 'list_subs',
						'reload'                       => array(

							'action_id'         => $this->id,
							'method'            => 'list_subs_fetch',
							'list_id_meta_key'  => 'action_' . $this->id . '_list_id'
						),
						'condition' => array(

							array(

								'logic'      => '!=',
								'meta_key'   => 'action_' . $this->id . '_list_id',
								'meta_value' => ''
							)
						)
					),

					// Title (supports merge tags)
					'action_' . $this->id . '_title' => array(

						'label'     => __('Record Title', 'ws-form-blue'),
						'type'      => 'text',
						'help'      => __('The title for the new Blue record. Supports merge tags, e.g. #field(8) #field(9)', 'ws-form-blue'),
						'variable_helper' => true,
						'condition' => array(

							array(

								'logic'      => '!=',
								'meta_key'   => 'action_' . $this->id . '_list_sub_id',
								'meta_value' => ''
							)
						)
					),

					// Field mapping
					'action_' . $this->id . '_field_mapping' => array(

						'label'            => __('Field Mapping', 'ws-form-blue'),
						'type'             => 'repeater',
						'help'             => __('Map WS Form fields to Blue fields.', 'ws-form-blue'),
						'meta_keys'        => array(

							'ws_form_field',
							'action_' . $this->id . '_list_fields'
						),
						'meta_keys_unique' => array(

							'action_' . $this->id . '_list_fields'
						),
						'reload' => array(

							'action_id'             => $this->id,
							'method'                => 'list_fields_fetch',
							'list_id_meta_key'      => 'action_' . $this->id . '_list_id',
							'list_sub_id_meta_key'  => 'action_' . $this->id . '_list_sub_id'
						),
						'auto_map'  => true,
						'condition' => array(

							array(

								'logic'      => '!=',
								'meta_key'   => 'action_' . $this->id . '_list_sub_id',
								'meta_value' => ''
							)
						)
					),

					// Custom mapping
					'action_' . $this->id . '_custom_mapping' => array(

						'label'            => __('Custom Mapping', 'ws-form-blue'),
						'type'             => 'repeater',
						'help'             => __('Map static values or merge tags to Blue fields.', 'ws-form-blue'),
						'meta_keys'        => array(

							'action_' . $this->id . '_custom_mapping_value',
							'action_' . $this->id . '_list_fields'
						),
						'meta_keys_unique' => array(

							'action_' . $this->id . '_list_fields'
						),
						'reload' => array(

							'action_id'             => $this->id,
							'method'                => 'list_fields_fetch',
							'list_id_meta_key'      => 'action_' . $this->id . '_list_id',
							'list_sub_id_meta_key'  => 'action_' . $this->id . '_list_sub_id'
						),
						'condition' => array(

							array(

								'logic'      => '!=',
								'meta_key'   => 'action_' . $this->id . '_list_sub_id',
								'meta_value' => ''
							)
						)
					),

					// Custom mapping - Value
					'action_' . $this->id . '_custom_mapping_value' => array(

						'label' => __('Value', 'ws-form-blue'),
						'type'  => 'text',
						'variable_helper' => true
					),

					// List fields (used inside the mapping repeaters above)
					'action_' . $this->id . '_list_fields' => array(

						'label'                        => __('Blue Field', 'ws-form-blue'),
						'type'                         => 'select',
						'options'                      => 'action_api_populate',
						'options_blank'                 => __('Select...', 'ws-form-blue'),
						'options_action_id'             => $this->id,
						'options_list_id_meta_key'      => 'action_' . $this->id . '_list_id',
						'options_list_sub_id_meta_key'  => 'action_' . $this->id . '_list_sub_id',
						'options_action_api_populate'   => 'list_fields'
					)
				);

				$meta_keys = array_merge($meta_keys, $config_meta_keys);

				return $meta_keys;
			}

			// Settings used for the "Add Action" meta shortcuts
			public function get_actions() {

				return array(

					$this->id => array(

						'meta' => array(

							'action_' . $this->id . '_list_id'       => $this->list_id,
							'action_' . $this->id . '_list_sub_id'   => $this->list_sub_id,
							'action_' . $this->id . '_field_mapping' => 'field_mapping'
						)
					),

					'message'
				);
			}

			public function get_fields() {

				return array();
			}

			// Settings wrapper (required by WS Form's admin UI)
			public function get_action_settings() {

				$settings = array(

					'meta_keys' => array(

						'action_' . $this->id . '_list_id',
						'action_' . $this->id . '_list_sub_id',
						'action_' . $this->id . '_title',
						'action_' . $this->id . '_field_mapping',
						'action_' . $this->id . '_custom_mapping'
					)
				);

				$settings = parent::get_settings_wrapper($settings);

				$settings->label = $this->label;
				$settings->label_action = $this->label_action;
				$settings->multiple = $this->multiple;
				$settings->events = $this->events;
				$settings->can_repost = $this->can_repost;

				$settings = apply_filters('wsf_action_' . $this->id . '_settings', $settings);

				return $settings;
			}
		}

		new WS_Form_Action_Blue();
	});
