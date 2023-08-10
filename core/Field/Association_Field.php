<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Value_Set\Value_Set;
use stdClass;
use WP_Query;
use WP_Term_Query;
use WP_User_Query;
use WP_Comment_Query;

/**
 * Association field class.
 * Allows selecting and manually sorting entries from various types:
 *  - Posts
 *  - Terms
 *  - Users
 *  - Comments
 */
class Association_Field extends Field
{
	/**
	 * WP_Toolset instance for WP data loading
	 *
	 * @var \Carbon_Fields\Toolset\WP_Toolset
	 */
	protected $wp_toolset;

	/**
	 * Min number of selected items allowed. -1 for no limit
	 *
	 * @var integer
	 */
	protected $min = -1;

	/**
	 * Max number of selected items allowed. -1 for no limit
	 *
	 * @var integer
	 */
	protected $max = -1;

	/**
	 * Max items per page. -1 for no limit
	 *
	 * @var integer
	 */
	protected $items_per_page = 20;

	/**
	 * Allow items to be added multiple times
	 *
	 * @var boolean
	 */
	protected $duplicates_allowed = false;

	/**
	 * Default field value
	 *
	 * @var array
	 */
	protected $default_value = array();

	/**
	 * Types of entries to associate with.
	 * @var array
	 */
	protected $types = array(
		array(
			'type' => 'post',
			'post_type' => 'post',
		),
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
		$this->wp_toolset = \Carbon_Fields\Carbon_Fields::resolve('wp_toolset');
		$this->set_value_set(new Value_Set(Value_Set::TYPE_VALUE_SET, array('type' => '', 'subtype' => '', 'id' => 0)));
		parent::__construct($type, $name, $label);
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_value_from_input($input)
	{
		$value = array();
		if (isset($input[$this->get_name()])) {
			$value = stripslashes_deep($input[$this->get_name()]);
			if (is_array($value)) {
				$value = array_values($value);
			}
		}
		$this->set_value($value);
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_value($value)
	{
		$value = $this->value_string_array_to_value_set($value);
		return parent::set_value($value);
	}

	/**
	 * Get value string for legacy value
	 *
	 * @param string $legacy_value
	 * @return string
	 */
	protected function get_value_string_for_legacy_value($legacy_value)
	{
		$entry_type = 'post';
		$entry_subtype = 'post';

		// attempt to find a suitable type that is registered to this field as post type is not stored for legacy data
		foreach ($this->types as $type) {
			if ($type['type'] === $entry_type) {
				$entry_subtype = $type['post_type'];
				break;
			}
		}

		return $entry_type . ':' . $entry_subtype . ':' . $legacy_value;
	}

	/**
	 * Convert a colon:separated:string into its expected components
	 * Used for backwards compatibility to CF 1.5
	 *
	 * @param string $value_string
	 * @return array
	 */
	protected function value_string_to_property_array($value_string)
	{
		if (is_numeric($value_string)) {
			// we are dealing with legacy data that only contains a post ID
			$value_string = $this->get_value_string_for_legacy_value($value_string);
		}

		$value_pieces = explode(':', $value_string);
		$type = isset($value_pieces[0]) ? $value_pieces[0] : 'post';
		$subtype = isset($value_pieces[1]) ? $value_pieces[1] : 'post';
		$id = isset($value_pieces[2]) ? $value_pieces[2] : 0;

		$property_array = array(
			Value_Set::VALUE_PROPERTY => $value_string,
			'type' => $type,
			'subtype' => $subtype,
			'id' => intval($id),
		);
		return $property_array;
	}

	/**
	 * Convert a colon:separated:string into its expected components
	 * Used for backwards compatibility to CF 1.5
	 *
	 * @param array $value_string_array
	 * @return array<array>
	 */
	protected function value_string_array_to_value_set($value_string_array)
	{
		$value_set = array();
		foreach ($value_string_array as $raw_value_entry) {
			$value_string = $raw_value_entry;

			if (is_array($raw_value_entry)) {
				if (isset($raw_value_entry['type'])) {
					// array is already in suitable format
					$value_set[] = $raw_value_entry;
					continue;
				}
				$value_string = $raw_value_entry[Value_Set::VALUE_PROPERTY];
			}
			$value_string = trim($value_string);
			if (empty($value_string)) {
				continue;
			}

			$property_array = $this->value_string_to_property_array($value_string);
			$value_set[] = $property_array;
		}

		return $value_set;
	}

	/**
	 * Generate the item options.
	 *
	 * @access public
	 *
	 * @param array $args
	 * @return array Returns an array containing total options count and options data.
	 */
	public function get_options($args = array())
	{
		// Merge provided arguments with default values
		$args = wp_parse_args($args, array(
			'page' => 1,
			'term' => '',
		));

		// Prepare arguments for the specific type
		$type_args = array_merge($this->types[0], array(
			'term' => $args['term'],
			'page' => $args['page'],
			'posts_per_page' => $this->get_items_per_page()
		));

		// Create a dynamic callback name based on the type
		$callback = "get_{$this->types[0]['type']}_options_sql";

		// Retrieve an SQL query by calling the appropriate callback
		$query = $this->$callback($type_args);

		// Initialize an empty array for options
		$options = array();

		// Create a dynamic callback for formatting options
		$callback = "format_{$this->types[0]['type']}_option";

		// Populate options by calling the formatting callback
		$options = $this->$callback($query->data, $this->types[0]);

		/**
		 * Filter the final list of options, available to a certain association field.
		 *
		 * @param array  $options Unfiltered options items.
		 * @param string $name Name of the association field.
		 */
		$options = apply_filters('carbon_fields_association_field_options', $options, $this->get_base_name());

		// Prepare the return data
		$return = array(
			'total_options' => $query->count,
			'options' => $options,
		);

		return $return;
	}

	/**
	 * Get the types.
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function get_types()
	{
		return $this->types;
	}

	/**
	 * Modify the types.
	 *
	 * @param  array $types New types
	 * @return self  $this
	 */
	public function set_types($types)
	{
		$this->types = $types;
		return $this;
	}

	/**
	 * Get the minimum allowed number of selected entries.
	 *
	 * @return int
	 */
	public function get_min()
	{
		return $this->min;
	}

	/**
	 * Set the minimum allowed number of selected entries.
	 *
	 * @param  int   $min
	 * @return self  $this
	 */
	public function set_min($min)
	{
		$this->min = intval($min);
		return $this;
	}

	/**
	 * Get the maximum allowed number of selected entries.
	 *
	 * @return int
	 */
	public function get_max()
	{
		return $this->max;
	}

	/**
	 * Set the maximum allowed number of selected entries.
	 *
	 * @param  int   $max
	 * @return self  $this
	 */
	public function set_max($max)
	{
		$this->max = intval($max);
		return $this;
	}

	/**
	 * Set the items per page.
	 *
	 * @param  int   $items_per_page
	 * @return self  $this
	 */
	public function set_items_per_page($items_per_page)
	{
		$this->items_per_page = intval($items_per_page);
		return $this;
	}

	/**
	 * Get the items per page.
	 *
	 * @return int
	 */
	public function get_items_per_page()
	{
		return $this->items_per_page;
	}

	/**
	 * Get whether entry duplicates are allowed.
	 *
	 * @return boolean
	 */
	public function get_duplicates_allowed()
	{
		return $this->duplicates_allowed;
	}

	/**
	 * Set whether entry duplicates are allowed.
	 *
	 * @param  boolean $allowed
	 * @return self    $this
	 */
	public function set_duplicates_allowed($allowed)
	{
		$this->duplicates_allowed = $allowed;
		return $this;
	}

	/**
	 * Specify whether to allow each entry to be selected multiple times.
	 * Backwards-compatibility alias.
	 *
	 * @param  boolean $allow
	 * @return self    $this
	 */
	public function allow_duplicates($allow = true)
	{
		return $this->set_duplicates_allowed($allow);
	}

	/**
	 * Converts the field values into a usable associative array.
	 *
	 * The association data is saved in the database in the following format:
	 * 	array (
	 *		0 => 'post:page:4',
	 *		1 => 'term:category:2',
	 *		2 => 'user:user:1',
	 * 	)
	 * where the value of each array item contains:
	 * 	- Type of data (post, term, user or comment)
	 * 	- Subtype of data (the particular post type or taxonomy)
	 * 	- ID of the item (the database ID of the item)
	 */
	protected function value_to_json()
	{
		$value_set = $this->get_value();
		$value = array();
		foreach ($value_set as $entry) {
			$item = array(
				'type' => $entry['type'],
				'subtype' => $entry['subtype'],
				'id' => intval($entry['id']),
				'title' => $this->get_title_by_type($entry['id'], $entry['type'], $entry['subtype']),
				'label' => $this->get_item_label($entry['id'], $entry['type'], $entry['subtype']),
				'is_trashed' => ($entry['type'] == 'post' && get_post_status($entry['id']) === 'trash'),
			);
			$value[] = $item;
		}
		return $value;
	}

	/**
	 * Convert the field data into JSON representation.
	 * @param  bool $load Whether to load data from the datastore.
	 * @return mixed      The JSON field data.
	 */
	public function to_json($load)
	{
		$field_data = parent::to_json($load);

		$field_data = array_merge($field_data, array(
			'value'              => $this->value_to_json(),
			'options'            => $this->get_options(),
			'min'                => $this->get_min(),
			'max'                => $this->get_max(),
			'duplicates_allowed' => $this->duplicates_allowed,
		));

		return $field_data;
	}


	public function search_posts_by_title($where, $wp_query)
	{
		global $wpdb;
		if ($search_term = $wp_query->get('search_title')) {
			if ($search_term !== '') {
				$where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql($wpdb->esc_like($search_term)) . '%\'';
			}
		}
		return $where;
	}

	/**
	 * Helper method to prepare the SQL needed to search for options of type 'post'.
	 *
	 * Creates a 'fake' WP_Query with only one result in order to catch the SQL
	 * that it will construct in order to support all of the WP_Query arguments.
	 *
	 * @access public
	 *
	 * @param  array  $args
	 * @return string
	 */
	public function get_post_options_sql($args = array())
	{
		$type        = $args['type'];
		$post_type   = $args['post_type'];
		$search_term = $args['term'];
		$page = $args['page'];
		$posts_per_page = $args['posts_per_page'];

		unset($args['type'], $args['post_type'], $args['term']);

		/**
		 * Filter the default query when fetching posts for a particular field.
		 *
		 * @param array $args The parameters, passed to WP_Query::__construct().
		 */
		$filter_name = 'carbon_fields_association_field_options_' . $this->get_base_name() . '_' . $type . '_' . $post_type;

		$args = apply_filters($filter_name, array(
			'post_type'        => $post_type,
			'suppress_filters' => 0,
			'paged' => $page,
			'posts_per_page' => $posts_per_page,
			'post_status' => 'publish',
			'no_found_rows' => true,
			'search_title' => sanitize_text_field($search_term)

		));

		add_filter('posts_where', array($this, 'search_posts_by_title'), 10, 2);
		$posts_query = new WP_Query($args);
		remove_filter('posts_where', array($this, 'search_posts_by_title'), 10, 2);

		$return = new stdClass();

		$return->data = $posts_query;
		$return->count = $posts_query->post_count;

		return $return;
	}

	/**
	 * Helper method to prepare the SQL needed to search for options of type 'term'.
	 *
	 * Creates a 'fake' WP_Term_Query with only one result in order to catch the SQL
	 * that it will construct in order to support all of the WP_Term_Query arguments.
	 *
	 * @access public
	 *
	 * @param  array  $args
	 * @return string
	 */
	public function get_term_options_sql($args = array())
	{
		$type        = $args['type'];
		$taxonomy    = $args['taxonomy'];
		$search_term = $args['term'];

		unset($args['type'], $args['taxonomy'], $args['term']);

		/**
		 * Filter the default parameters when fetching terms for a particular field.
		 *
		 * @param array $args The parameters, passed to WP_Term_Query::__construct().
		 */
		$filter_name = 'carbon_fields_association_field_options_' . $this->get_base_name() . '_' . $type . '_' . $taxonomy;

		$args = apply_filters($filter_name, array(
			'hide_empty'             => 0,
			'taxonomy'               => $taxonomy,
			'fields'                 => 'count',
			'number'                 => 1,
			'search'                 => $search_term,
			'update_term_meta_cache' => false,
		));

		add_filter('get_terms_fields', array($this, 'get_term_options_sql_select_clause'));
		add_filter('terms_clauses', array($this, 'get_term_options_sql_clauses'));

		$terms_query = new WP_Term_Query($args);

		remove_filter('get_terms_fields', array($this, 'get_term_options_sql_select_clause'));
		remove_filter('terms_clauses', array($this, 'get_term_options_sql_clauses'));

		return $terms_query->request;
	}

	/**
	 * Modify the "SELECT" columns for the WP_Term_Query.
	 *
	 * @access public
	 *
	 * @param  array  $fields
	 * @return array
	 */
	public function get_term_options_sql_select_clause($fields)
	{
		return array('`t`.`term_id` AS `ID`', '`t`.`name` AS `title`', '\'term\' as `type`', '`tt`.`taxonomy` AS `subtype`');
	}

	/**
	 * Modify the clauses for the SQL request of the WP_Term_Query.
	 *
	 * @access public
	 *
	 * @param  array  $clauses
	 * @return array
	 */
	public function get_term_options_sql_clauses($clauses)
	{
		unset($clauses['orderby'], $clauses['order'], $clauses['limits']);

		return $clauses;
	}

	/**
	 * Helper method to prepare the SQL needed to search for options of type 'user'.
	 *
	 * Creates a 'fake' WP_User_Query with only one result in order to catch the SQL
	 * that it will construct in order to support all of the WP_User_Query arguments.
	 *
	 * @access public
	 *
	 * @param  array  $args
	 * @return string
	 */
	public function get_user_options_sql($args = array())
	{

		$type        = $args['type'];
		$search_term = $args['term'];
		$page = $args['page'];
		$posts_per_page = $args['posts_per_page'];

		unset($args['type'], $args['term'], $args['subtype']);

		/**
		 * Filter the default parameters when fetching terms for a particular field.
		 *
		 * @param array $args The parameters, passed to WP_User_Query::__construct().
		 */
		$filter_name = 'carbon_fields_association_field_options_' . $this->get_base_name() . '_' . $type;

		$args = apply_filters($filter_name, array(
			'number' => $posts_per_page,
			'paged' => $page,
			'search' => '*' . sanitize_text_field($search_term) . '*'
		));

		$users_query = new WP_User_Query($args);

		$final = new stdClass();

		$final->data = $users_query->get_results();
		$final->count = count($users_query->get_results());

		return $final;
	}

	/**
	 * Helper method to prepare the SQL needed to search for options of type 'comment'.
	 *
	 * Creates a 'fake' WP_Comment_Query with only one result in order to catch the SQL
	 * that it will construct in order to support all of the WP_Comment_Query arguments.
	 *
	 * @access public
	 *
	 * @param  array  $args
	 * @return string
	 */
	public function get_comment_options_sql($args = array())
	{
		$type        = $args['type'];
		$search_term = $args['term'];

		unset($args['type'], $args['term'], $args['subtype']);

		/**
		 * Filter the default parameters when fetching comments for a particular field.
		 *
		 * @param array $args The parameters, passed to get_comments().
		 */
		$filter_name = 'carbon_fields_association_field_options_' . $this->get_base_name() . '_' . $type;

		$args = apply_filters($filter_name, array(
			'fields' => 'ids',
			'number' => 1,
			'search' => $search_term,
		));

		add_filter('comments_clauses', array($this, 'get_comments_clauses'));

		$comments_query = new WP_Comment_Query;
		$comments_query->query($args);

		remove_filter('comments_clauses', array($this, 'get_comments_clauses'));

		return $comments_query->request;
	}

	/**
	 * Modify the "SELECT" columns and the clauses for the SQL request
	 * performed by the WP_Comment_Query.
	 *
	 * @access public
	 *
	 * @param  array  $clauses
	 * @return array
	 */
	public function get_comments_clauses($clauses)
	{
		global $wpdb;

		$clauses['fields'] = " {$wpdb->comments}.`comment_ID` AS `ID`, '' AS `title`, 'comment' AS `type`, 'comment' AS `subtype` ";

		unset($clauses['orderby'], $clauses['limits'], $clauses['groupby']);

		return $clauses;
	}

	/**
	 * Used to get the thumbnail of an item.
	 *
	 * Can be overriden or extended by the `carbon_fields_association_field_option_thumbnail` filter.
	 *
	 * @param int $id The database ID of the item.
	 * @param string $type Item type (post, term, user, comment, or a custom one).
	 * @param string $subtype The subtype - "page", "post", "category", etc.
	 * @return string $title The title of the item.
	 */
	public function get_thumbnail_by_type($id, $type, $subtype = '')
	{
		$thumbnail_url = '';

		if ($type === 'post') {
			$thumbnail_url = get_the_post_thumbnail_url($id, 'thumbnail');
		}

		return apply_filters('carbon_fields_association_field_option_thumbnail', $thumbnail_url, $id, $type, $subtype);
	}

	/**
	 * Used to get the title of an item.
	 *
	 * Can be overriden or extended by the `carbon_association_title` filter.
	 *
	 * @param int $id The database ID of the item.
	 * @param string $type Item type (post, term, user, comment, or a custom one).
	 * @param string $subtype The subtype - "page", "post", "category", etc.
	 * @return string $title The title of the item.
	 */
	public function get_title_by_type($id, $type, $subtype = '')
	{
		$title = '';

		$method = 'get_' . $type . '_title';
		$callable = array($this->wp_toolset, $method);
		if (is_callable($callable)) {
			$title = call_user_func($callable, $id, $subtype);
		}

		if ($type === 'comment') {
			$max = apply_filters('carbon_fields_association_field_comment_length', 30, $this->get_base_name());
			if (strlen($title) > $max) {
				$title = substr($title, 0, $max) . '...';
			}
		}

		/**
		 * Filter the title of the association item.
		 *
		 * @param string $title   The unfiltered item title.
		 * @param string $name    Name of the association field.
		 * @param int    $id      The database ID of the item.
		 * @param string $type    Item type (post, term, user, comment, or a custom one).
		 * @param string $subtype Subtype - "page", "post", "category", etc.
		 */
		$title = apply_filters('carbon_fields_association_field_title', $title, $this->get_base_name(), $id, $type, $subtype);

		if (!$title) {
			$title = '(no title) - ID: ' . $id;
		}

		return $title;
	}

	/**
	 * Used to get the label of an item.
	 *
	 * Can be overriden or extended by the `carbon_association_item_label` filter.
	 *
	 * @param int     $id      The database ID of the item.
	 * @param string  $type    Item type (post, term, user, comment, or a custom one).
	 * @param string  $subtype Subtype - "page", "post", "category", etc.
	 * @return string $label The label of the item.
	 */
	public function get_item_label($id, $type, $subtype = '')
	{
		$label = $subtype ? $subtype : $type;

		if ($type === 'post') {
			$post_type_object = get_post_type_object($subtype);
			$label = $post_type_object->labels->singular_name;
		} elseif ($type === 'term') {
			$taxonomy_object = get_taxonomy($subtype);
			$label = $taxonomy_object->labels->singular_name;
		}

		/**
		 * Filter the label of the association item.
		 *
		 * @param string $label   The unfiltered item label.
		 * @param string $name    Name of the association field.
		 * @param int    $id      The database ID of the item.
		 * @param string $type    Item type (post, term, user, comment, or a custom one).
		 * @param string $subtype Subtype - "page", "post", "category", etc.
		 */
		return apply_filters('carbon_fields_association_field_item_label', $label, $this->get_base_name(), $id, $type, $subtype);
	}

	/**
	 * Retrieve the edit link of a particular object.
	 *
	 * @param  array $type Object type.
	 * @param  int $id      ID of the object.
	 * @return string       URL of the edit link.
	 */
	protected function get_object_edit_link($type, $id)
	{
		switch ($type['type']) {

			case 'post':
				$edit_link = get_edit_post_link($id, '');
				break;

			case 'term':
				$edit_link = get_edit_term_link($id, '', $type['type']);
				break;

			case 'comment':
				$edit_link = get_edit_comment_link($id);
				break;

			case 'user':
				$edit_link = get_edit_user_link($id);
				break;

			default:
				$edit_link = false;
		}

		return $edit_link;
	}

	/**
	 * Prepares an option of type 'post' for JS usage.
	 *
	 * @param  Object $data
	 * @return array
	 */
	public function format_post_option($data, $type)
	{
		// Storage
		$format = [];

		// Check if there are posts
		if ($data->have_posts()) {
			// Start the loop
			while ($data->have_posts()) {
				$data->the_post();

				$format[] = [
					'id' => get_the_ID(),
					'title' => html_entity_decode(get_the_title()),
					'thumbnail' => get_the_post_thumbnail_url(),
					'type' => $type['type'],
					'subtype' => $type['post_type'],
					'label'   => $this->get_item_label(get_the_ID(), $type['type'], $type['post_type']),
					'is_trashed' => (get_post_status() == 'trash'),
					'edit_link'  => $this->get_object_edit_link($type, get_the_ID()),
				];
			}
		}

		return $format;
	}

	/**
	 * Prepares an option of type 'term' for JS usage.
	 *
	 * @param  \stdClass  $data
	 * @return array
	 */
	public function format_term_option($data)
	{
		return array(
			'id'         => intval($data->ID),
			'title'      => $this->get_title_by_type($data->ID, $data->type, $data->subtype),
			'thumbnail'  => '',
			'type'       => $data->type,
			'subtype'    => $data->subtype,
			'label'      => $this->get_item_label($data->ID, $data->type, $data->subtype),
			'is_trashed' => false,
			'edit_link'  => $this->get_object_edit_link(get_object_vars($data), $data->ID),
		);
	}

	/**
	 * Prepares an option of type 'comment' for JS usage.
	 *
	 * @param  \stdClass  $data
	 * @return array
	 */
	public function format_comment_option($data)
	{
		return array(
			'id'         => intval($data->ID),
			'title'      => $this->get_title_by_type($data->ID, 'comment'),
			'thumbnail'  => '',
			'type'       => 'comment',
			'subtype'    => 'comment',
			'label'      => $this->get_item_label($data->ID, 'comment'),
			'is_trashed' => false,
			'edit_link'  => $this->get_object_edit_link(get_object_vars($data), $data->ID),
		);
	}

	/**
	 * Prepares an option of type 'user' for JS usage.
	 *
	 * @param  Object $data
	 * @return array
	 */
	public function format_user_option($data, $type)
	{
		// Storage
		$format = [];

		// Check if there are posts
		if (count($data) > 0) {
			// Start the loop
			foreach ($data as $user) {

				$format[] = [
					'id' => intval($user->ID),
					'title' => html_entity_decode(sanitize_text_field($user->nickname)),
					'thumbnail' => get_avatar_url($user->ID, array('size' => 150)),
					'type' => $type['type'],
					'subtype' => $type['type'],
					'label'      => $this->get_item_label($user->ID, $type['type']),
					'is_trashed' => false,
					'edit_link'  => $this->get_object_edit_link($type, $user->ID)
				];
			}
		}

		return $format;
	}
}
