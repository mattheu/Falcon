<?php

class bbSubscriptions_Admin extends bbSubscriptions_Autohooker {
	/**
	 * Should we wipe the handler-specific options?
	 *
	 * When the handler is changed, we can't keep the same handler-specific
	 * options saved, so this flag ensures that we wipe the data.
	 *
	 * @var boolean
	 */
	protected static $wipe_handler_options = false;

	protected static $registered_handler_settings = false;

	/**
	 * Bootstrap the class
	 *
	 * Ensures all necessary hooks are added
	 */
	public static function bootstrap() {
		self::register_hooks();
	}

	/**
	 * Initialization
	 *
	 * @wp-action admin_init
	 */
	public static function init() {
		if (!is_admin()) {
			return false;
		}

		register_setting( 'bbsub_options', 'bbsub_handler_type', array(__CLASS__, 'validate_type') );
		register_setting( 'bbsub_options', 'bbsub_handler_options', array(__CLASS__, 'validate_handler_options') );

		add_settings_section('bbsub_options_global', 'Main Settings', array(__CLASS__, 'settings_section_main'), 'bbsub_options');
		add_settings_field('bbsub_options_global_type', 'Messaging Handler', array(__CLASS__, 'settings_field_type'), 'bbsub_options', 'bbsub_options_global');

		// Note: title is false so that we can handle it ourselves
		add_settings_section('bbsub_options_handleroptions', false, array(__CLASS__, 'settings_section_handler'), 'bbsub_options');
	}

	/**
	 * Add our menu item
	 *
	 * @wp-action admin_menu
	 */
	public static function register_menu() {
		add_options_page('Reply by Email', 'Reply by Email', 'manage_options', 'bbsub_options', array(__CLASS__, 'admin_page'));
	}

	/**
	 * Print the content
	 */
	public static function admin_page() {
?>
		<div class="wrap">
			<h2>bbPress Reply by Email Options</h2>
			<form method="post" action="options.php">
				<?php settings_fields('bbsub_options') ?>
				<?php do_settings_sections('bbsub_options') ?>
				<?php submit_button() ?>
			</form>
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$('#bbsub_options_global_type').on('change', function (e) {
					$('#bbsub_options_global_type').after(' <img src="<?php echo esc_js( esc_url( admin_url( 'images/loading.gif' ) ) ); ?>" id="bbsub-loading" />' );
					$.ajax({
						url: ajaxurl,
						data: {
							action: 'bbsub_handler_section',
							handler: $(this).val()
						},
						success: function (response) {
							// Replace the title and form with the contents
							$('#bbsub-handlersettings-title').next().remove().end().remove();
							//$('#bbsub-handlersettings-title').replaceWith(response);
							$('#bbsub-handlersettings-insert').after(response);
							$('#bbsub-loading').remove();
						},
						error: function (response) {
							// Replace just the form with the error message
							$('#bbsub-handlersettings-title').next().replaceWith(response.responseText);
							$('#bbsub-loading').remove();
						}
					});
				});
			})
		</script>
<?php
	}

	/**
	 * Handle an AJAX request for the handler section
	 *
	 * @wp-action wp_ajax_bbsub_handler_section
	 */
	public static function ajax_handler_section() {
		try {
			if (!isset($_REQUEST['handler'])) {
				throw new Exception('Invalid handler type');
			}

			// Setup the handler settings for the newly selected handler
			$handler = self::validate_type($_REQUEST['handler']);
			if (!$handler) {
				throw new Exception('Invalid handler');
			}

			$options = get_option('bbsub_handler_options', array());
			// validate_type() will set this flag if the type isn't equal to
			// the current one
			if (self::$wipe_handler_options) {
				$options = array();
			}
			self::register_handler_settings_fields('bbsub_options', 'bbsub_options_handleroptions', $handler, $options);
			self::$registered_handler_settings = true;

			// Now, output the section
			$page = 'bbsub_options';
			$section = 'bbsub_options_handleroptions';


			global $wp_settings_fields;
			self::settings_section_handler();

			if ( !isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section]) )
				die();

			echo '<table class="form-table">';
			do_settings_fields($page, $section);
			echo '</table>';

			// Special field to ensure we don't wipe settings fully
			echo '<input type="hidden" name="bbsub_used_ajax" value="1" />';
		}
		catch (Exception $e) {
			header('X-Excepted: true', true, 500);
			echo '<div class="error" style="width:317px" id="bbsub-handlersettings-error"><p>' . $e->getMessage() . '</p></div>';
		}

		die();
	}

	/**
	 * Print description for the main settings section
	 *
	 * @see self::init()
	 */
	public static function settings_section_main() {
		echo '<p>Main settings for the plugin</p>';
	}

	/**
	 * Print field for the handler type
	 *
	 * @see self::init()
	 */
	public static function settings_field_type() {
		$current = get_option('bbsub_handler_type', false);
		$available = bbSubscriptions::get_handlers();

		if (empty($available)) {
			echo '<p class="error">No handlers are available!</p>';
		}

		echo '<select name="bbsub_handler_type" id="bbsub_options_global_type">';
		foreach ($available as $type => $class) {
			echo '<option value="' . esc_attr($type) . '"' . selected($current, $type) . '>' . esc_html($class::get_name()) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Validate the handler type
	 *
	 * Ensures that the selected type can actually be selected
	 * @param string $input Selected class name
	 * @return string|bool Selected class name if valid, otherwise false
	 */
	public static function validate_type($input) {
		if ( in_array( $input, array_keys(bbSubscriptions::get_handlers()) ) ) {
			if ($input !== get_option('bbsub_handler_type', false) && empty($_POST['bbsub_used_ajax'])) {
				self::$wipe_handler_options = true;
			}
			return $input;
		}

		return false;
	}

	public static function settings_section_handler() {
		if (!self::$registered_handler_settings) {
			self::register_handler_settings_fields('bbsub_options', 'bbsub_options_handleroptions');
			self::$registered_handler_settings = true;
		}

		echo '<div id="bbsub-handlersettings-insert"></div>';

		global $wp_settings_fields;

		// If the handler didn't register any options, don't bother to print the
		// title for the section
		$page = 'bbsub_options';
		$section = 'bbsub_options_handleroptions';
		if ( !isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section]) )
			return;

		echo '<h3 id="bbsub-handlersettings-title">' . __('Handler Settings', 'bbsub') . '</h3>';
	}

	/**
	 * Notify the handler to register handler-specific options
	 *
	 * @see self::init()
	 */
	public static function register_handler_settings_fields($group, $section, $handler_type = null, $current = null) {
		if ($current === null) {
			$current = get_option('bbsub_handler_options', array());
		}
		try {
			$handler = bbSubscriptions::get_handler_class($handler_type);
		}
		catch (Exception $e) {
			return false;
		}
		$handler::register_option_fields($group, $section, $current);
	}

	/**
	 * Validate the handler-specific options via the handler's methods
	 *
	 * @see self::init()
	 */
	public static function validate_handler_options($input) {
		if (self::$wipe_handler_options) {
			return array();
		}

		try {
			$handler = bbSubscriptions::get_handler_class();
		}
		catch (Exception $e) {
			return array();
		}
		return $handler::validate_options($input);
	}
}