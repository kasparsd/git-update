<?php
/*
Plugin Name: Git Updates
Plugin URI: https://github.com/kasparsd/git-update
GitHub URI: https://github.com/kasparsd/git-update
Description: Provides automatic updates for themes and plugins hosted at GitHub.
Author: Kaspars Dambis
Version: 1.2
*/


new GitUpdate;

class GitUpdate {

	static $instance;

	private $git_uris = array( 
		'github' => array(
			'header' => 'GitHub URI'
		) 
	);


	function GitUpdate() {
		// Make sure that others can interact with us
		self::$instance = $this;

		add_filter( 'extra_theme_headers', array( $this, 'enable_gitupdate_headers' ) );
		add_filter( 'extra_plugin_headers', array( $this, 'enable_gitupdate_headers' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_check_plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'update_check_themes' ) );

	}


	/**
	 * Enable custom update URI headers in theme and plugin headers
	 * @param  array $headers Other custom headers
	 * @return array          Our custom headers
	 */
	function enable_gitupdate_headers( $headers ) {

		foreach ( $this->git_uris as $uri )
			if ( ! in_array( $uri['header'], $headers ) )
				$headers[] = $uri['header'];

		return $headers;

	}


	/**
	 * Check for theme updates
	 * @param  object $updates Update transient
	 * @return object          Modified transient with update responses from our APIs
	 */
	function update_check_themes( $updates ) {
		
		// Run only after WP has done its own API check
		if ( empty( $updates->checked ) )
			return $updates;

		return $this->update_check( $updates, $this->get_themes() );

	}

	/**
	 * Check for plugin updates
	 * @param  object $updates Update transient
	 * @return object          Modified transient with update responses from our APIs
	 */
	function update_check_plugins( $updates ) {

		// Run only after WP has done its own API check
		if ( empty( $updates->checked ) )
			return $updates;

		return $this->update_check( $updates, get_plugins() );
	
	}


	/**
	 * Check for updates from external APIs
	 * @param  object $updates    Update transient from WP core
	 * @param  array $extensions  Available plugins or themes
	 * @return object             Updated transient with information from our APIs
	 */
	function update_check( $updates, $extensions ) {

		$to_check = array();

		// Bail out if we did this already
		if ( isset( $updates->git_update_checked ) )
			return $updates;

		// Filter out plugins/themes with known headers
		foreach ( $extensions as $item => $item_details )
			foreach ( $this->git_uris as $uri )
				if ( ! empty( $item_details[ $uri['header'] ] ) )
					$to_check[ $item ] = $item_details;

		if ( empty( $to_check ) )
			return $updates;

		foreach ( $to_check as $item => $item_details ) {
			$api_response = wp_remote_get( 
					sprintf( 
						'%s/tags', 
						str_replace( '//github.com/', '//api.github.com/repos/', rtrim( $item_details['GitHub URI'], '/' ) ) 
					) 
				);

			print_r( $api_response );

			if ( is_wp_error( $api_response ) || wp_remote_retrieve_response_code( $api_response ) != 200 )
				continue;

			$response_json = json_decode( wp_remote_retrieve_body( $api_response ), true );

			// Make sure this repo has any tags
			if ( empty( $response_json ) || ! is_array( $response_json ) )
				continue;

			foreach ( $response_json as $tag )
				if ( version_compare( $tag['name'], $item_details['Version'], '>' ) )
					$updates->response[ $item ] = (object) array(
							'new_version' => $tag['name'],
							'slug' => $item, //dirname( $item ),
							'package' => $tag['zipball_url'],
							'url' => $item_details['PluginURI']
						);
		}

		print_r( $updates );

		// Make sure we run this only once per admin request
		$updates->git_update_checked = true;
		
		return $updates;
	}


	/**
	 * Make this return the same structure as get_plugins()
	 * @return array Available themes and their meta data
	 */
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

}

