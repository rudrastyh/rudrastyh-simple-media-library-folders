<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMLF_Allow_SVG {
    public function __construct() {
        if( 'yes' !== get_option( '_smlf_allow_svg' ) ) {
            return;
        }
        add_filter( 'upload_mimes', array( $this, 'upload_mimes' ) );
        add_filter( 'wp_check_filetype_and_ext', array( $this, 'check_filetype_and_ext' ), 10, 4 );
        add_filter( 'wp_handle_upload_prefilter', array( $this, 'handle_upload_prefilter' ) );
    }

	// allow svg extensions
    public function upload_mimes( $mimes ) {
        $mimes[ 'svg' ]  = 'image/svg+xml';
        $mimes[ 'svgz' ] = 'image/svg+xml';

        return $mimes;
    }

	// Disable real MIME check (introduced in WordPress 4.7.1)
	// https://wordpress.stackexchange.com/a/252296/44794
    public function check_filetype_and_ext( $data, $file, $filename, $mimes ) {

		global $wp_version;
		// do nothing if it is not 4.7.1/4.7.2
        if ( '4.7.1' !== $wp_version && '4.7.2' !== $wp_version ) {
            return $data;
        }

		$filetype = wp_check_filetype( $filename, $mimes );

		return array(
			'ext'             => $filetype[ 'ext' ],
			'type'            => $filetype[ 'type' ],
			'proper_filename' => $data[ 'proper_filename' ],
		);
    }

	public function init_svg_sanitizer() {

		require_once( __DIR__ . '/../includes/svg-sanitize/data/AttributeInterface.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/data/TagInterface.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/data/AllowedAttributes.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/data/AllowedTags.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/data/XPath.php' );

		require_once( __DIR__ . '/../includes/svg-sanitize/ElementReference/Resolver.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/ElementReference/Subject.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/ElementReference/Usage.php' );

		require_once( __DIR__ . '/../includes/svg-sanitize/Exceptions/NestingException.php' );

		require_once( __DIR__ . '/../includes/svg-sanitize/Helper.php' );
		require_once( __DIR__ . '/../includes/svg-sanitize/Sanitizer.php' );

	}

	// sanitization
    public function handle_upload_prefilter( $file ) {

        if( ! isset( $file[ 'tmp_name' ] ) || 'image/svg+xml' !== $file[ 'type' ] ) {
            return $file;
        }

		// if SVG sanitizer is already in use
		if( interface_exists( 'enshrined\svgSanitize\data\AttributeInterface' ) ) {
			return $file;
		}

		self::init_svg_sanitizer();

		$sanitizer = new \enshrined\svgSanitize\Sanitizer();

		$dirtySVG = file_get_contents( $file[ 'tmp_name' ] );
		// Security fix
		// https://github.com/advisories/ghsa-9rjx-3jch-6vjf
		$dirtySVG = preg_replace( '/<!DOCTYPE[^>]*(?:\[.*?\])?\s*>/si', '', $dirtySVG );

		$cleanSVG = $sanitizer->sanitize( $dirtySVG );

        if ( $cleanSVG ) {
            file_put_contents( $file['tmp_name'], $cleanSVG );
        } else {
            $file[ 'error' ] = 'This file can not be uploaded for security reasons.';
        }

        return $file;
    }
}

new SMLF_Allow_SVG();