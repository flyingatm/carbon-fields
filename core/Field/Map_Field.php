<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Value_Set\Value_Set;

/**
 * Google Maps with Address field class.
 * Allows to manually select a pin, or to position a pin based on a specified address.
 * Coords (lat, lng), address and zoom are saved in the database.
 */
class Map_Field extends Field
{

	/**
	 * {@inheritDoc}
	 */
	protected $default_value = array(
		Value_Set::VALUE_PROPERTY => '40.346544,-101.645507',
		'lat' => 40.346544,
		'lng' => -101.645507,
		'zoom' => 10,
		'name' => '',
		'place_id' => ''
	);

	/**
	 * Create a field from a certain type with the specified label.
	 *
	 * @param string $type  Field type
	 * @param string $name  Field name
	 * @param string $label Field label
	 */
	public function __construct($type, $name, $label)
	{
		$this->set_value_set(new Value_Set(Value_Set::TYPE_MULTIPLE_PROPERTIES, array('lat' => '', 'lng' => '', 'zoom' => '', 'name' => '', 'place_id' => '')));
		parent::__construct($type, $name, $label);
	}

	/**
	 * Enqueue scripts and styles in admin
	 * Called once per field type
	 */
	public static function admin_enqueue_scripts()
	{
		$api_key = apply_filters('carbon_fields_map_field_api_key', false);
		$url = apply_filters('carbon_fields_map_field_api_url', '//maps.googleapis.com/maps/api/js?' . ($api_key ? 'key=' . $api_key : ''), $api_key) . '&libraries=places';

		wp_enqueue_script('carbon-google-maps', $url, array(), null);
	}

	/**
	 * Convert lat and lng to a comma-separated list
	 */
	protected function lat_lng_to_latlng($lat, $lng)
	{
		return (!empty($lat) && !empty($lng)) ? $lat . ',' . $lng : '';
	}

	/**
	 * Load the field value from an input array based on its name
	 *
	 * @param  array $input Array of field names and values.
	 * @return self  $this
	 */
	public function set_value_from_input($input)
	{

		// Check if the field's value is present in the input array
		if (!isset($input[$this->get_name()])) {
			// If not present, set the value to null and return
			$this->set_value(null);
			return $this;
		}

		// Initialize an array to store values
		$value_set = array(
			'lat' => '',
			'lng' => '',
			'zoom' => '',
			'name' => '',
			'place_id' => ''
		);

		// Loop through each value in $value_set array and check if it exists in the input array
		foreach ($value_set as $key => $v) {
			if (isset($input[$this->get_name()][$key])) {
				// If it exists, assign the value from the input array to $value_set
				$value_set[$key] = $input[$this->get_name()][$key];
			}
		}

		// Convert lat, lng, and zoom values to appropriate data types
		$value_set['lat'] = (float) $value_set['lat'];
		$value_set['lng'] = (float) $value_set['lng'];
		$value_set['zoom'] = (int) $value_set['zoom'];
		$value_set['place_id'] = $value_set['place_id'];
		$value_set['name'] = $value_set['name'];

		// Calculate latlng value and assign it to the appropriate key in $value_set
		$value_set[Value_Set::VALUE_PROPERTY] = $this->lat_lng_to_latlng($value_set['lat'], $value_set['lng']);

		// Set the final value in the field and return $this
		$this->set_value($value_set);
		return $this;
	}

	/**
	 * Returns an array that holds the field data, suitable for JSON representation.
	 *
	 * @param bool $load  Should the value be loaded from the database or use the value from the current instance.
	 * @return array
	 */
	public function to_json($load)
	{
		$field_data = parent::to_json($load);

		$value_set = $this->get_value();

		$field_data = array_merge($field_data, array(
			'value' => array(
				'lat' => floatval($value_set['lat']),
				'lng' => floatval($value_set['lng']),
				'zoom' => intval($value_set['zoom']),
				'name' => $value_set['name'],
				'place_id' => $value_set['place_id'],
				'value' => $value_set[Value_Set::VALUE_PROPERTY],
			),
		));

		return $field_data;
	}

	/**
	 * Set the coords and zoom of this field.
	 *
	 * @param  string $lat  Latitude
	 * @param  string $lng  Longitude
	 * @param  int    $zoom Zoom level
	 * @return $this
	 */
	public function set_position($lat, $lng, $zoom)
	{
		return $this->set_default_value(array_merge(
			$this->get_default_value(),
			array(
				Value_Set::VALUE_PROPERTY => $this->lat_lng_to_latlng($lat, $lng),
				'lat' => $lat,
				'lng' => $lng,
				'zoom' => $zoom,
			)
		));
	}
}
