<?php
/*
Plugin Name: Git Updates
Plugin URI: https://github.com/kasparsd/git-update
GitHub URI: https://github.com/kasparsd/git-update
Description: Provides automatic updates for themes and plugins hosted at GitHub.
Author: Kaspars Dambis
Version: 1.4.1
*/


GitUpdate::instance();


class GitUpdate {

	static $instance;

	private $git_uris = array( 
		'github' => array(
			'header' => 'GitHub URI'
		) 
	);


	private function __construct() {

		add_filter( 'extra_theme_headers', array( $this, 'enable_gitupdate_headers' ) );
		add_filter( 'extra_plugin_headers', array( $this, 'enable_gitupdate_headers' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_check_plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'update_check_themes' ) );

		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 5, 3 );
		add_action( 'core_upgrade_preamble', array( $this, 'show_gitupdate_log' ) );

	}


	public static function instance() {

		if ( ! self::$instance )
			self::$instance = new self();

		return self::$instance;

	}


	function enable_gitupdate_headers( $headers ) {

		foreach ( $this->git_uris as $uri )
			if ( ! in_array( $uri['header'], $headers ) )
				$headers[] = $uri['header'];

		return $headers;

	}


	function update_check_themes( $updates ) {

		// Run only after WP has done its own API check
		if ( empty( $updates->checked ) )
			return $updates;

		return $this->update_check( $updates, $this->get_themes() );

	}


	function update_check_plugins( $updates ) {

		// Run only after WP has done its own API check
		if ( empty( $updates->checked ) )
			return $updates;

		return $this->update_check( $updates, get_plugins() );
	
	}


	function update_check( $updates, $extensions ) {

		$to_check = array();

		// Filter out plugins/themes with known headers
		foreach ( $extensions as $item => $item_details )
			foreach ( $this->git_uris as $uri )
				if ( isset( $item_details[ $uri['header'] ] ) && ! empty( $item_details[ $uri['header'] ] ) )
					$to_check[ $item ] = $item_details;

		if ( empty( $to_check ) )
			return $updates;

		foreach ( $to_check as $item => $item_details ) {

			// Don't re-check for updates
			if ( isset( $updates->response[ $item ] ) )
				continue;

			$url = sprintf(
					'%s/tags', 
					str_replace( '//github.com/', '//api.github.com/repos/', rtrim( $item_details['GitHub URI'], '/' ) ) 
				);

			$api_response = wp_remote_get( 
					$url, 
					array( 
						'sslverify' => false 
					) 
				);

			// Log errors
			if ( is_wp_error( $api_response ) || 200 != wp_remote_retrieve_response_code( $api_response ) ) {
				update_site_option( 'git-update-error', array( $item, $item_details, $api_response ) );
				continue;
			}

			$response_json = json_decode( wp_remote_retrieve_body( $api_response ), true );

			// Make sure this repo has any tags
			if ( empty( $response_json ) || ! is_array( $response_json ) )
				continue;

			foreach ( $response_json as $tag ) {

				if ( version_compare( $tag['name'], $item_details['Version'], '>' ) ) {

					$response = array(
							'new_version' => $tag['name'],
							'slug' => dirname( $item ),
							'package' => $tag['zipball_url']
						);

					if ( isset( $item_details['ThemeURI'] ) )
						$updates->response[ $item ] = $response;
					elseif ( isset( $item_details['PluginURI'] ) )
						$updates->response[ $item ] = (object) $response;

					break;

				}

			}

		}

		return $updates;

	}


	// Make this return the same structure as get_plugins()
	function get_themes() {

		$themes = array();

		$theme_headers = array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
		);

		$extra_theme_headers = apply_filters( 'extra_theme_headers', array() );

		// Make keys and values equal
		$extra_theme_headers = array_combine( $extra_theme_headers, $extra_theme_headers );

		// Merge default headers with extra headers
		$theme_headers = apply_filters( 
				'extra_theme_headers', 
				array_merge( $theme_headers, $extra_theme_headers )
			);

		$themes_available = wp_get_themes();

		foreach ( $themes_available as $theme )
			foreach ( $theme_headers as $header_slug => $header_label )
				$themes[ $theme->get_template() ][ $header_slug ] = $theme->get( $header_slug );

		return $themes;

	}


	// Move them back into the original folder
	function upgrader_post_install( $res, $extra, $result ) {

		global $wp_filesystem;

		if ( is_wp_error( $res ) )
			return $res;

		$move = null;

		if ( isset( $extra['plugin'] ) )
			$move = $this->upgrade_move_plugin( $extra['plugin'], $result );
		
		if ( isset( $extra['theme'] ) )
			$move = $this->upgrade_move_theme( $extra['theme'], $result );

		if ( is_wp_error( $move ) )
			return $move;

		return $res;

	}


	function upgrade_move_plugin( $plugin, $result ) {

		global $wp_filesystem;

		$plugins_dir = trailingslashit( $wp_filesystem->wp_plugins_dir( $plugin ) );

		// Don't move if it's in the correct directory already
		if ( $result['destination'] == $plugins_dir )
			return true;

		return $wp_filesystem->move( $result['destination'], $plugins_dir );

	}


	function upgrade_move_theme( $theme, $result ) {

		global $wp_filesystem;

		$themes_dir = trailingslashit( $wp_filesystem->wp_themes_dir( $theme ) );

		// Don't move if it's in the correct directory already
		if ( $result['destination'] == $themes_dir )
			return true;

		return $wp_filesystem->move( $result['destination'], $themes_dir );

	}


	function show_gitupdate_log() {

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			printf( 
				'<h3>Git Update Error</h3>
				<pre style="overflow:auto;width:100%%;">%s</pre>', 
				esc_html( print_r( get_site_option( 'git-update-error' ), true ) )
			);

	}


}

