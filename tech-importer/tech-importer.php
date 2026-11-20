<?php
/**
 * Plugin Name: Tech Importer
 * Description: Upload a content zip from Claude. Creates draft pages and posts with Elementor layout, Yoast fields, images and featured image.
 * Version: 1.0.3
 * Update URI: https://github.com/MrJoeG/tech-importer
 * Author: Capital of Techs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tech_Importer {

	const SLUG       = 'tech-importer';
	const VERSION    = '1.0.3';
	const UPDATE_URL = 'https://raw.githubusercontent.com/MrJoeG/tech-importer/main/update.json';
	private $log = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_update_cache' ) );
	}

	private function remote_info() {
		$info = get_transient( 'tech_importer_update' );
		if ( false === $info ) {
			$res  = wp_remote_get( self::UPDATE_URL . '?t=' . time(), array( 'timeout' => 10 ) );
			$info = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) )
				? json_decode( wp_remote_retrieve_body( $res ), true )
				: array();
			set_transient( 'tech_importer_update', $info, 6 * HOUR_IN_SECONDS );
		}
		return is_array( $info ) ? $info : array();
	}

	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $update;
		}
		$info = $this->remote_info();
		if ( empty( $info['version'] ) || empty( $info['package'] ) ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $info['version'],
			'url'          => 'https://github.com/MrJoeG/tech-importer',
			'package'      => $info['package'],
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '7.4',
		);
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$info = $this->remote_info();
		return (object) array(
			'name'          => 'Tech Importer',
			'slug'          => self::SLUG,
			'version'       => isset( $info['version'] ) ? $info['version'] : self::VERSION,
			'author'        => 'Capital of Techs',
			'homepage'      => 'https://github.com/MrJoeG/tech-importer',
			'download_link' => isset( $info['package'] ) ? $info['package'] : '',
			'sections'      => array( 'changelog' => isset( $info['changelog'] ) ? wp_kses_post( $info['changelog'] ) : '' ),
		);
	}

	public function clear_update_cache() {
		delete_transient( 'tech_importer_update' );
	}

	public function menu() {
		add_management_page( 'Tech Importer', 'Tech Importer', 'manage_options', self::SLUG, array( $this, 'screen' ) );
	}

	private function note( $type, $msg ) {
		$this->log[] = array( $type, $msg );
	}

	public function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		echo '<div class="wrap"><h1>Tech Importer</h1>';
		echo '<p>Upload the zip from Claude. Everything comes in as a <strong>draft</strong>. Nothing is published.</p>';

		if ( isset( $_POST['tech_remove'] ) ) {
			check_admin_referer( 'tech_remove_item' );
			$this->remove_item( (int) $_POST['tech_remove'] );
			echo '<ul style="font-size:14px;line-height:1.8">';
			foreach ( $this->log as $row ) {
				$color = 'ok' === $row[0] ? '#1a7f37' : '#cf222e';
				echo '<li style="color:' . esc_attr( $color ) . '">' . wp_kses_post( $row[1] ) . '</li>';
			}
			echo '</ul>';
			$this->log = array();
		}

		if ( isset( $_POST['cot_import'] ) ) {
			check_admin_referer( 'cot_import_zip' );
			$this->handle_upload();
			echo '<h2>Results</h2><ul style="font-size:14px;line-height:1.8">';
			foreach ( $this->log as $row ) {
				$color = 'ok' === $row[0] ? '#1a7f37' : ( 'warn' === $row[0] ? '#9a6700' : '#cf222e' );
				echo '<li style="color:' . esc_attr( $color ) . '">' . wp_kses_post( $row[1] ) . '</li>';
			}
			echo '</ul><hr>';
		}

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'cot_import_zip' );
		echo '<p><input type="file" name="cot_zip" accept=".zip" required></p>';
		echo '<p><label><input type="checkbox" name="cot_update" value="1"> Update existing pages or posts with the same slug (otherwise they are skipped)</label></p>';
		submit_button( 'Import', 'primary', 'cot_import' );
		echo '</form>';
		$this->list_imports();
		echo '</div>';
	}

	private function imported_ids() {
		$ids = get_posts( array(
			'post_type'   => array( 'page', 'post' ),
			'post_status' => 'any',
			'numberposts' => 100,
			'fields'      => 'ids',
			'meta_key'    => '_tech_imported',
		) );
		$atts = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 500,
			'meta_key'    => '_tech_import_file',
		) );
		foreach ( $atts as $a ) {
			if ( $a->post_parent ) {
				$ids[] = (int) $a->post_parent;
			}
		}
		$ids = array_unique( array_map( 'intval', $ids ) );
		rsort( $ids );
		return $ids;
	}

	private function list_imports() {
		$ids = $this->imported_ids();
		echo '<h2 style="margin-top:30px">Imported content</h2>';
		if ( ! $ids ) {
			echo '<p>Nothing imported yet.</p>';
			return;
		}
		echo '<p>Remove deletes the draft and the images that came in with it. Published items can\'t be removed here.</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Images</th><th></th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || 'trash' === $p->post_status ) {
				continue;
			}
			$imgs = count( $this->item_images( $id ) );
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $p->post_title ) . '</a><br><small>/' . esc_html( $p->post_name ) . '/</small></td>';
			echo '<td>' . esc_html( $p->post_type ) . '</td><td>' . esc_html( $p->post_status ) . '</td><td>' . (int) $imgs . '</td><td>';
			if ( 'publish' === $p->post_status ) {
				echo '<em>Live</em>';
			} else {
				echo '<form method="post" style="margin:0" onsubmit="return confirm(\'Remove this draft and its ' . (int) $imgs . ' images for good?\');">';
				wp_nonce_field( 'tech_remove_item' );
				echo '<button class="button button-link-delete" name="tech_remove" value="' . (int) $id . '">Remove</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function item_images( $id ) {
		return get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 100,
			'fields'      => 'ids',
			'post_parent' => $id,
			'meta_key'    => '_tech_import_file',
		) );
	}

	private function remove_item( $id ) {
		$p = get_post( $id );
		if ( ! $p || ! in_array( $id, $this->imported_ids(), true ) ) {
			$this->note( 'err', 'That item was not found or was not imported by Tech Importer.' );
			return;
		}
		if ( 'publish' === $p->post_status || ! current_user_can( 'delete_post', $id ) ) {
			$this->note( 'err', 'Published items can\'t be removed here.' );
			return;
		}
		$n = 0;
		foreach ( $this->item_images( $id ) as $aid ) {
			if ( wp_delete_attachment( $aid, true ) ) {
				$n++;
			}
		}
		wp_delete_post( $id, true );
		$this->note( 'ok', 'Removed "' . esc_html( $p->post_title ) . '" and ' . $n . ' images.' );
	}

	private function handle_upload() {
		if ( empty( $_FILES['cot_zip']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['cot_zip']['error'] ) {
			$this->note( 'err', 'No zip received. Check the upload size limit.' );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		WP_Filesystem();

		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'tech-import-' . wp_generate_password( 12, false );
		wp_mkdir_p( $dir );
		$res = unzip_file( $_FILES['cot_zip']['tmp_name'], $dir );
		if ( is_wp_error( $res ) ) {
			$this->note( 'err', 'Could not open the zip: ' . esc_html( $res->get_error_message() ) );
			$this->cleanup( $dir );
			return;
		}

		$root = $this->find_manifest_dir( $dir );
		if ( ! $root ) {
			$this->note( 'err', 'No manifest.json found in the zip.' );
			$this->cleanup( $dir );
			return;
		}
		$manifest = json_decode( file_get_contents( $root . '/manifest.json' ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['items'] ) ) {
			$this->note( 'err', 'manifest.json is empty or not valid JSON.' );
			$this->cleanup( $dir );
			return;
		}

		$update = ! empty( $_POST['cot_update'] );
		foreach ( $manifest['items'] as $item ) {
			$this->import_item( $item, $root, $update );
		}
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		$this->cleanup( $dir );
	}

	private function find_manifest_dir( $dir ) {
		if ( file_exists( $dir . '/manifest.json' ) ) {
			return $dir;
		}
		foreach ( glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
			if ( file_exists( $sub . '/manifest.json' ) ) {
				return $sub;
			}
		}
		return false;
	}

	private function cleanup( $dir ) {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	private function safe_path( $root, $name ) {
		$name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return false;
		}
		$path = $root . '/' . $name;
		return file_exists( $path ) ? $path : false;
	}

	private function import_image( $img, $root ) {
		$file = isset( $img['file'] ) ? $img['file'] : '';
		$base = sanitize_file_name( basename( $file ) );

		$found = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_tech_import_file',
			'meta_value'  => $base,
		) );
		if ( $found ) {
			$this->note( 'warn', 'Image already in Media Library, reused: ' . esc_html( $base ) );
			return (int) $found[0];
		}

		$path = $this->safe_path( $root, $file );
		$type = wp_check_filetype( $base );
		if ( ! $path || empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			$this->note( 'err', 'Image missing or not an image: ' . esc_html( $file ) );
			return 0;
		}
		$tmp = wp_tempnam( $base );
		copy( $path, $tmp );
		$id = media_handle_sideload(
			array( 'name' => $base, 'tmp_name' => $tmp ),
			0,
			null,
			array_filter( array(
				'post_title'   => isset( $img['title'] ) ? $img['title'] : '',
				'post_excerpt' => isset( $img['caption'] ) ? $img['caption'] : '',
				'post_content' => isset( $img['description'] ) ? $img['description'] : '',
			) )
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			$this->note( 'err', 'Image upload failed (' . esc_html( $base ) . '): ' . esc_html( $id->get_error_message() ) );
			return 0;
		}
		if ( ! empty( $img['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $img['alt'] ) );
		}
		update_post_meta( $id, '_tech_import_file', $base );
		$this->note( 'ok', 'Image added: ' . esc_html( $base ) );
		return (int) $id;
	}

	private function fill_images( &$node, $media ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['cot_file'] ) ) {
			$key = basename( $node['cot_file'] );
			unset( $node['cot_file'] );
			if ( ! empty( $media[ $key ] ) ) {
				$node['id']     = $media[ $key ];
				$node['url']    = wp_get_attachment_url( $media[ $key ] );
				$node['source'] = 'library';
			} else {
				$this->note( 'warn', 'Layout points to an image that was not in the zip: ' . esc_html( $key ) );
			}
		}
		foreach ( $node as &$child ) {
			if ( is_array( $child ) ) {
				$this->fill_images( $child, $media );
			}
		}
	}

	private function import_item( $item, $root, $update ) {
		$type  = ( isset( $item['type'] ) && 'post' === $item['type'] ) ? 'post' : 'page';
		$title = isset( $item['title'] ) ? $item['title'] : 'Untitled';
		$slug  = sanitize_title( isset( $item['slug'] ) ? $item['slug'] : $title );

		$existing = get_page_by_path( $slug, OBJECT, $type );
		if ( $existing && ! $update ) {
			$this->note( 'warn', 'Skipped "' . esc_html( $title ) . '": a ' . $type . ' with the slug /' . esc_html( $slug ) . '/ already exists. Tick "Update existing" to overwrite it.' );
			return;
		}
		if ( $existing && 'publish' === $existing->post_status ) {
			$this->note( 'err', 'Skipped "' . esc_html( $title ) . '": /' . esc_html( $slug ) . '/ is already published. The importer never changes live content.' );
			return;
		}

		$media = array();
		if ( ! empty( $item['images'] ) ) {
			foreach ( $item['images'] as $img ) {
				$id = $this->import_image( $img, $root );
				if ( $id ) {
					$media[ sanitize_file_name( basename( $img['file'] ) ) ] = $id;
				}
			}
		}

		$meta = array();

		if ( ! empty( $item['elementor'] ) ) {
			$path = $this->safe_path( $root, $item['elementor'] );
			$tpl  = $path ? json_decode( file_get_contents( $path ), true ) : null;
			if ( ! is_array( $tpl ) ) {
				$this->note( 'err', 'Elementor file missing or invalid for "' . esc_html( $title ) . '".' );
				return;
			}
			$content = isset( $tpl['content'] ) ? $tpl['content'] : $tpl;
			$this->fill_images( $content, $media );
			$json = wp_json_encode( $content );
			$json = str_replace(
				'https:\/\/staging.capitaloftechs.com\/wp\/wp-content\/uploads\/',
				str_replace( '/', '\/', trailingslashit( wp_upload_dir()['baseurl'] ) ),
				$json
			);
			$meta['_elementor_data']          = $json;
			$meta['_elementor_edit_mode']     = 'builder';
			$meta['_elementor_template_type'] = 'post' === $type ? 'wp-post' : 'wp-page';
			$meta['_elementor_version']       = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';
			if ( ! empty( $tpl['page_settings'] ) && is_array( $tpl['page_settings'] ) ) {
				$meta['_elementor_page_settings'] = $tpl['page_settings'];
			}
			$meta['_wp_page_template'] = isset( $item['page_template'] ) ? $item['page_template'] : 'elementor_header_footer';
		}

		$y   = isset( $item['yoast'] ) ? $item['yoast'] : array();
		$map = array(
			'title'               => '_yoast_wpseo_title',
			'description'         => '_yoast_wpseo_metadesc',
			'focus_keyphrase'     => '_yoast_wpseo_focuskw',
			'social_title'        => '_yoast_wpseo_opengraph-title',
			'social_description'  => '_yoast_wpseo_opengraph-description',
			'twitter_title'       => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
		);
		foreach ( $map as $k => $mk ) {
			if ( ! empty( $y[ $k ] ) ) {
				$meta[ $mk ] = sanitize_text_field( $y[ $k ] );
			}
		}
		if ( ! empty( $y['social_image'] ) ) {
			$k = sanitize_file_name( basename( $y['social_image'] ) );
			if ( ! empty( $media[ $k ] ) ) {
				$url = wp_get_attachment_url( $media[ $k ] );
				$meta['_yoast_wpseo_opengraph-image']    = $url;
				$meta['_yoast_wpseo_opengraph-image-id'] = $media[ $k ];
				$meta['_yoast_wpseo_twitter-image']      = $url;
				$meta['_yoast_wpseo_twitter-image-id']   = $media[ $k ];
			}
		}
		if ( ! empty( $item['featured_image'] ) ) {
			$k = sanitize_file_name( basename( $item['featured_image'] ) );
			if ( ! empty( $media[ $k ] ) ) {
				$meta['_thumbnail_id'] = $media[ $k ];
			} else {
				$this->note( 'warn', 'Featured image not found in the zip: ' . esc_html( $k ) );
			}
		}

		$meta['_tech_imported'] = current_time( 'mysql' );

		$post = array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'draft',
			'post_excerpt' => isset( $item['excerpt'] ) ? $item['excerpt'] : '',
			'post_content' => isset( $item['content'] ) ? $item['content'] : '',
			'meta_input'   => $meta,
		);
		if ( ! empty( $item['parent_slug'] ) && 'page' === $type ) {
			$parent = get_page_by_path( sanitize_title( $item['parent_slug'] ), OBJECT, 'page' );
			if ( $parent ) {
				$post['post_parent'] = $parent->ID;
			}
		}
		if ( 'post' === $type && ! empty( $item['category'] ) ) {
			$term = term_exists( $item['category'], 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $item['category'], 'category' );
			}
			if ( ! is_wp_error( $term ) ) {
				$post['post_category'] = array( (int) $term['term_id'] );
			}
		}

		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$id         = wp_update_post( wp_slash_strings_only( $post ), true );
		} else {
			$id = wp_insert_post( wp_slash_strings_only( $post ), true );
		}
		if ( is_wp_error( $id ) ) {
			$this->note( 'err', 'Could not create "' . esc_html( $title ) . '": ' . esc_html( $id->get_error_message() ) );
			return;
		}

		foreach ( $media as $mid ) {
			if ( ! wp_get_post_parent_id( $mid ) ) {
				wp_update_post( array( 'ID' => $mid, 'post_parent' => $id ) );
			}
		}

		$edit = admin_url( 'post.php?post=' . $id . '&action=elementor' );
		$prev = get_preview_post_link( $id );
		$this->note(
			'ok',
			'<strong>Draft ready:</strong> ' . esc_html( $title ) . ' (' . $type . ', /' . esc_html( $slug ) . '/) &nbsp; <a href="' . esc_url( $prev ) . '" target="_blank">Preview</a> &middot; <a href="' . esc_url( $edit ) . '" target="_blank">Edit with Elementor</a> &middot; <a href="' . esc_url( get_edit_post_link( $id ) ) . '" target="_blank">Edit in WordPress</a>'
		);
	}
}

new Tech_Importer();
