<?php
/*
Plugin Name: Git Update
Plugin URI: https://github.com/kasparsd/git-update
GitHub URI: https://github.com/kasparsd/git-update
Description: Provides automatic updates for themes and plugins hosted at GitHub.
Author: Kaspars Dambis
Version: 1.0
*/


new GitUpdate;


class GitUpdate {

	var $instance;


	function GitUpdate() {

		add_filter( 'extra_theme_headers', array( $this, 'enable_gitupdate_headers' ) );
		add_filter( 'extra_plugin_headers', array( $this, 'enable_gitupdate_headers' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_check' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'update_check' ) );
		
	}


	function enable_gitupdate_headers( $headers ) {
		if ( ! in_array( 'GitHub URI', $headers ) )
			$headers[] = 'GitHub URI';
		
		return $headers;
	}


	function update_check( $updates ) {
		$to_check = array();
		$all_plugins_themes = array_merge( get_plugins(), get_themes() );

		foreach ( $all_plugins_themes as $item => $item_details )
			if ( ! empty( $item_details['GitHub URI'] ) )
				$to_check[ $item ] = $item_details;

		print_r($to_check);

		foreach ( $to_check as $item => $item_details ) {
			$api_response = wp_remote_get( 
					sprintf( 
						'%s/tags', 
						str_replace( '//github.com/', '//api.github.com/repos/', rtrim( $item_details['GitHub URI'], '/' ) ) 
					) 
				);

			$response_json = json_decode( wp_remote_retrieve_body( $api_response ), true );

			//if ( is_wp_error( $raw_response ) || 200 != wp_remote_retrieve_response_code( $raw_response ) )
			if ( $response_json )
				print_r($response_json);
		}
		die;
		return $updates;
	}

}

