<?php
/**
 * Plugin Name: Tech Importer
 * Description: Upload a content zip from Claude. Creates draft pages and posts with Elementor layout, Yoast fields, images and featured image, and applies reviewed link and text updates to existing pages.
 * Version: 1.0.13
 * Update URI: https://github.com/MrJoeG/tech-importer
 * Author: Capital of Techs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tech_Importer {

	const SLUG       = 'tech-importer';
	const VERSION    = '1.0.13';
	const UPDATE_URL = 'https://raw.githubusercontent.com/MrJoeG/tech-importer/main/update.json';
	private $log = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_update_cache' ) );
		add_action( 'wp_footer', array( $this, 'sticky_bar' ) );
		add_shortcode( 'cot_price', array( $this, 'price_sc' ) );
		add_filter( 'elementor/frontend/the_content', array( $this, 'price_fill' ), 20 );
		add_filter( 'widget_text', array( $this, 'price_fill' ), 20 );
	}

	/* ---------- Price list (1.0.13) ---------- */

	private function price_defaults() {
		return array(
			'seo'           => array( 'Local SEO plans, from (per month)', '$499' ),
			'care'          => array( 'Website care plan, from (per month, 12-month plan)', '$199' ),
			'care_6'        => array( 'Website care plan, 6-month plan (per month)', '$249' ),
			'care_1'        => array( 'Website care plan, 1 month', '$399' ),
			'care_6_total'  => array( 'Website care plan, 6-month total', '$1,494' ),
			'care_12_total' => array( 'Website care plan, 12-month total', '$2,388' ),
			'care_setup'    => array( 'Care plan setup fee', '$299' ),
			'hourly'        => array( 'Extra edits, per hour', '$75' ),
			'build_starter' => array( 'Website build, Starter from', '$2,500' ),
			'build_growth'  => array( 'Website build, Growth from', '$4,600' ),
			'build_custom'  => array( 'Custom build or online store, around', '$7,000' ),
		);
	}

	private function prices() {
		$p = get_option( 'tech_prices' );
		return is_array( $p ) && $p ? $p : $this->price_defaults();
	}

	public function price_sc( $atts ) {
		$a = shortcode_atts( array( 'key' => '' ), $atts, 'cot_price' );
		$p = $this->prices();
		$k = sanitize_key( $a['key'] );
		return isset( $p[ $k ] ) ? esc_html( $p[ $k ][1] ) : '';
	}

	public function price_fill( $html ) {
		if ( is_string( $html ) && false !== strpos( $html, '[cot_price' ) ) {
			$html = preg_replace_callback( '/\[cot_price\s+key=(?:"|&quot;|&#8221;|&#8243;|\')?([a-z0-9_]+)(?:"|&quot;|&#8221;|&#8243;|\')?\s*\]/i', function ( $m ) {
				return $this->price_sc( array( 'key' => $m[1] ) );
			}, $html );
		}
		return $html;
	}

	private function price_tag( $key ) {
		return '[cot_price key="' . $key . '"]';
	}

	private function price_posts() {
		return get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => array( 'publish', 'draft', 'future', 'private' ), 'numberposts' => -1, 'fields' => 'ids' ) );
	}

	private function price_ref( $post ) {
		return 'page' === $post->post_type ? get_page_uri( $post ) : $post->post_name;
	}

	private function price_rx( $value ) {
		return '/(?<![0-9])' . preg_quote( $value, '/' ) . '(?![0-9]|[,.][0-9])/';
	}

	/* Walk every text setting of a layout and call $fn( element id, widget, field, value ). */
	private function price_walk( $elements, $fn, $el_id = '', $widget = '' ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id = isset( $el['id'] ) ? $el['id'] : $el_id;
			$wt = isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : $widget );
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$this->price_walk_settings( $el['settings'], $fn, $id, $wt );
			}
			if ( ! empty( $el['elements'] ) ) {
				$this->price_walk( $el['elements'], $fn, $id, $wt );
			}
		}
	}

	private function price_walk_settings( $st, $fn, $id, $wt ) {
		foreach ( $st as $key => $val ) {
			if ( is_array( $val ) ) {
				foreach ( $val as $row ) {
					if ( is_array( $row ) ) {
						$this->price_walk_settings( $row, $fn, $id, $wt );
					}
				}
				continue;
			}
			if ( is_string( $val ) && false !== strpos( $val, '$' ) || ( is_string( $val ) && false !== strpos( $val, '[cot_price' ) ) ) {
				$fn( $id, $wt, (string) $key, $val );
			}
		}
	}

	/* Where each price tag is used, and every price still typed out as plain text. */
	private function price_scan() {
		$prices = $this->prices();
		$used   = array();
		$hits   = array();
		foreach ( $this->price_posts() as $pid ) {
			$post   = get_post( $pid );
			$layout = $this->get_layout( $post );
			$fields = array();
			if ( $layout ) {
				$this->price_walk( $layout, function ( $id, $wt, $key, $val ) use ( &$fields ) {
					$fields[] = array( $id, $wt, $key, $val );
				} );
			} elseif ( false !== strpos( $post->post_content, '$' ) || false !== strpos( $post->post_content, '[cot_price' ) ) {
				$fields[] = array( '', 'page content', 'content', $post->post_content );
			}
			foreach ( $fields as $f ) {
				if ( preg_match_all( '/\[cot_price\s+key=["\']?([a-z0-9_]+)/i', $f[3], $m ) ) {
					foreach ( $m[1] as $k ) {
						$used[ strtolower( $k ) ][ $pid ] = true;
					}
				}
				foreach ( $prices as $k => $row ) {
					if ( '' === $row[1] || ! preg_match_all( $this->price_rx( $row[1] ), $f[3], $mm, PREG_OFFSET_CAPTURE ) ) {
						continue;
					}
					foreach ( $mm[0] as $one ) {
						$off   = $one[1];
						$start = max( 0, $off - 60 );
						$ctx   = substr( $f[3], $start, $off - $start + strlen( $row[1] ) + 60 );
						$rel   = $off - $start;
						$hid   = substr( md5( $pid . '|' . $f[0] . '|' . $f[2] . '|' . $off . '|' . $k ), 0, 10 );
						$hits[ $hid ] = array(
							'post'    => $pid,
							'ref'     => $this->price_ref( $post ),
							'title'   => get_the_title( $pid ),
							'where'   => $f[1] . ' / ' . $f[2],
							'key'     => $k,
							'value'   => $row[1],
							'field'   => $pid . '|' . $f[0] . '|' . $f[1] . '|' . $f[2] . '|' . md5( $f[3] ),
							'full'    => $f[3],
							'off'     => $off,
							'ok'      => '' === $f[0] || in_array( $f[2], $this->text_fields(), true ),
							'show'    => wp_strip_all_tags( substr( $ctx, 0, $rel ) ) . '<mark>' . $row[1] . '</mark>' . wp_strip_all_tags( substr( $ctx, $rel + strlen( $row[1] ) ) ),
						);
					}
				}
			}
		}
		return array( $used, $hits );
	}

	private function price_post() {
		$prices = $this->prices();
		if ( isset( $_POST['tech_prices_save'] ) ) {
			check_admin_referer( 'tech_prices' );
			$keys   = isset( $_POST['tp_key'] ) ? (array) wp_unslash( $_POST['tp_key'] ) : array();
			$labels = isset( $_POST['tp_label'] ) ? (array) wp_unslash( $_POST['tp_label'] ) : array();
			$vals   = isset( $_POST['tp_value'] ) ? (array) wp_unslash( $_POST['tp_value'] ) : array();
			$new    = array();
			foreach ( $keys as $i => $k ) {
				$k = sanitize_key( $k );
				$v = isset( $vals[ $i ] ) ? trim( sanitize_text_field( $vals[ $i ] ) ) : '';
				if ( '' === $k || '' === $v ) {
					continue;
				}
				$new[ $k ] = array( isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '', $v );
			}
			if ( $new ) {
				update_option( 'tech_prices', $new );
				$prices = $new;
				if ( class_exists( '\Elementor\Plugin' ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
				delete_post_meta_by_key( '_elementor_element_cache' );
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
				}
				echo '<div class="notice notice-success"><p>Prices saved. Every page that uses a price tag now shows the new price. Elementor and WP Rocket caches cleared. Yoast titles, meta descriptions and images still need to be changed by hand.</p></div>';
			}
		}
		if ( isset( $_POST['tech_price_convert'] ) ) {
			check_admin_referer( 'tech_prices' );
			$hits   = get_transient( 'tech_price_hits' );
			$picked = isset( $_POST['tp_hit'] ) ? array_map( 'sanitize_key', (array) $_POST['tp_hit'] ) : array();
			$groups = array();
			foreach ( $picked as $h ) {
				if ( is_array( $hits ) && ! empty( $hits[ $h ] ) && $hits[ $h ]['ok'] ) {
					$groups[ $hits[ $h ]['field'] ][] = $hits[ $h ];
				}
			}
			$rows = array();
			foreach ( $groups as $list ) {
				usort( $list, function ( $a, $b ) {
					return $b['off'] - $a['off'];
				} );
				$text  = $list[0]['full'];
				if ( wp_kses_post( $text ) !== $text ) {
					$this->note( 'err', 'A price on ' . esc_html( $list[0]['title'] ) . ' sits in text with code the updater can\'t copy safely. Type the price tag there by hand in Elementor.' );
					continue;
				}
				$notes = array();
				foreach ( $list as $x ) {
					$text    = substr_replace( $text, $this->price_tag( $x['key'] ), $x['off'], strlen( $x['value'] ) );
					$notes[] = $x['value'] . ' to ' . $this->price_tag( $x['key'] );
				}
				$rows[] = array(
					'type'    => 'replace',
					'page'    => $list[0]['ref'],
					'find'    => $list[0]['full'],
					'replace' => $text,
					'note'    => 'Price tags: ' . implode( ', ', array_reverse( $notes ) ),
				);
			}
			if ( $rows ) {
				$this->queue_changes( $rows, 'price-tags-' . gmdate( 'Y-m-d' ) );
			} else {
				$this->note( 'err', 'No prices were picked.' );
			}
			$this->print_log();
		}
	}

	private function price_box() {
		$prices = $this->prices();
		list( $used, $hits ) = $this->price_scan();
		set_transient( 'tech_price_hits', $hits, DAY_IN_SECONDS );

		echo '<h2 style="margin-top:30px">Prices</h2>';
		echo '<p>Change a price here and every page that uses its price tag updates by itself. In Elementor, type the tag instead of the price, for example <code>' . esc_html( $this->price_tag( 'seo' ) ) . '</code> shows ' . esc_html( isset( $prices['seo'] ) ? $prices['seo'][1] : '' ) . '. Yoast titles, meta descriptions and prices inside images are not covered, change those by hand.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'tech_prices' );
		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Price tag</th><th>What it is</th><th>Price</th><th>Used on</th></tr></thead><tbody>';
		$prices['']  = array( '', '' );
		foreach ( $prices as $k => $row ) {
			$pages = array();
			foreach ( isset( $used[ $k ] ) ? array_keys( $used[ $k ] ) : array() as $pid ) {
				$pages[] = '<a href="' . esc_url( get_permalink( $pid ) ) . '" target="_blank">' . esc_html( get_the_title( $pid ) ) . '</a>';
			}
			echo '<tr><td>' . ( '' === $k ? '<input name="tp_key[]" placeholder="new_key" style="width:140px">' : '<input type="hidden" name="tp_key[]" value="' . esc_attr( $k ) . '"><code>' . esc_html( $this->price_tag( $k ) ) . '</code>' ) . '</td>';
			echo '<td><input name="tp_label[]" value="' . esc_attr( $row[0] ) . '" style="width:100%"></td>';
			echo '<td><input name="tp_value[]" value="' . esc_attr( $row[1] ) . '" style="width:110px"></td>';
			echo '<td>' . ( $pages ? implode( ', ', $pages ) : '<span style="color:#888">Not used yet</span>' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">To remove a price, clear its Price box and save. Add a new one in the empty last row (lowercase letters, numbers and underscores).</p>';
		submit_button( 'Save prices', 'primary', 'tech_prices_save' );

		echo '<h3>Prices still typed out on pages</h3>';
		if ( ! $hits ) {
			echo '<p>None found. Every price on the site uses a price tag.</p>';
		} else {
			echo '<p>These match a price in the list above but are typed as plain text, so they won\'t change with the list. Tick the ones that are <strong>your</strong> prices (leave market ranges in guides alone), then click the button. They go to "Updates to existing pages" for the usual preview, Apply and Undo.</p>';
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th></th><th>Page</th><th>Price</th><th>Text around it</th></tr></thead><tbody>';
			foreach ( $hits as $id => $x ) {
				echo '<tr><td>' . ( $x['ok'] ? '<input type="checkbox" name="tp_hit[]" value="' . esc_attr( $id ) . '">' : '' ) . '</td>';
				echo '<td><a href="' . esc_url( get_permalink( $x['post'] ) ) . '" target="_blank">' . esc_html( $x['title'] ) . '</a><br><span style="color:#888;font-size:12px">' . esc_html( $x['where'] ) . '</span></td>';
				echo '<td>' . esc_html( $x['value'] ) . '<br><span style="color:#888;font-size:12px">' . esc_html( $x['key'] ) . '</span></td>';
				echo '<td>...' . wp_kses( $x['show'], array( 'mark' => array() ) ) . '...' . ( $x['ok'] ? '' : '<br><em style="color:#9a6700">This spot can\'t be changed automatically. Type the price tag in Elementor by hand.</em>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			submit_button( 'Turn selected into price tags', 'secondary', 'tech_price_convert' );
		}
		echo '</form>';
	}

	/* ---------- Sticky phone bar (1.0.9) ---------- */

	private function sticky_opts() {
		return wp_parse_args( get_option( 'tech_sticky', array() ), array(
			'on'    => 1,
			'phone' => '(210) 852-2353',
			'label' => 'Free consultation',
			'url'   => '/contact/',
			'skip'  => 'contact',
		) );
	}

	public function sticky_bar() {
		$o = $this->sticky_opts();
		if ( empty( $o['on'] ) || ! is_singular( array( 'post', 'page' ) ) || is_admin() ) {
			return;
		}
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		$skip = array_filter( array_map( 'trim', explode( ',', (string) $o['skip'] ) ) );
		if ( in_array( $slug, $skip, true ) ) {
			return;
		}
		$tel  = preg_replace( '/[^0-9+]/', '', $o['phone'] );
		$href = add_query_arg( 'src', 'sticky-' . ( $slug ? $slug : 'home' ), $o['url'] );
		echo '<style>.tech-sticky{display:none}@media (max-width:767px){.tech-sticky{position:fixed;left:0;right:0;bottom:0;z-index:9990;display:flex;gap:10px;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:#2E1D63;box-shadow:0 -4px 18px rgba(0,0,0,.18);transform:translateY(110%);transition:transform .25s ease}.tech-sticky.on{transform:none}.tech-sticky a{flex:1;text-align:center;font-weight:700;font-size:15px;line-height:1.2;padding:12px 8px;border-radius:8px;text-decoration:none}.tech-sticky .t1{border:2px solid #fff;color:#fff}.tech-sticky .t2{background:#FF7033;color:#fff}body.tech-sticky-pad{padding-bottom:72px}}</style>';
		echo '<div class="tech-sticky" id="tech-sticky"><a class="t1" href="tel:' . esc_attr( $tel ) . '">Call ' . esc_html( $o['phone'] ) . '</a><a class="t2" href="' . esc_url( $href ) . '">' . esc_html( $o['label'] ) . '</a></div>';
		echo '<script>(function(){var b=document.getElementById("tech-sticky");if(!b)return;function f(){var h=document.documentElement.scrollHeight-window.innerHeight;var on=h>0&&window.scrollY/h>0.3;b.classList.toggle("on",on);document.body.classList.toggle("tech-sticky-pad",on);}window.addEventListener("scroll",f,{passive:true});f();})();</script>';
	}

	private function sticky_box() {
		if ( isset( $_POST['tech_sticky_save'] ) ) {
			check_admin_referer( 'tech_sticky' );
			update_option( 'tech_sticky', array(
				'on'    => empty( $_POST['tech_sticky_on'] ) ? 0 : 1,
				'phone' => sanitize_text_field( wp_unslash( $_POST['tech_sticky_phone'] ) ),
				'label' => sanitize_text_field( wp_unslash( $_POST['tech_sticky_label'] ) ),
				'url'   => esc_url_raw( wp_unslash( $_POST['tech_sticky_url'] ) ),
				'skip'  => sanitize_text_field( wp_unslash( $_POST['tech_sticky_skip'] ) ),
			) );
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			echo '<div class="notice notice-success"><p>Sticky phone bar saved. WP Rocket cache cleared.</p></div>';
		}
		$o = $this->sticky_opts();
		echo '<h2 style="margin-top:30px">Sticky phone bar</h2><p>Shows on phones only, at the bottom of every page and post, after the visitor scrolls about a third of the way down.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'tech_sticky' );
		echo '<table class="form-table" style="max-width:800px"><tr><th>Show it</th><td><label><input type="checkbox" name="tech_sticky_on" value="1"' . checked( ! empty( $o['on'] ), true, false ) . '> On</label></td></tr>';
		echo '<tr><th>Phone</th><td><input class="regular-text" name="tech_sticky_phone" value="' . esc_attr( $o['phone'] ) . '"></td></tr>';
		echo '<tr><th>Button text</th><td><input class="regular-text" name="tech_sticky_label" value="' . esc_attr( $o['label'] ) . '"></td></tr>';
		echo '<tr><th>Button link</th><td><input class="regular-text" name="tech_sticky_url" value="' . esc_attr( $o['url'] ) . '"><p class="description">The page slug is added as ?src=sticky-page-name so lead emails show where it came from.</p></td></tr>';
		echo '<tr><th>Hide on</th><td><input class="regular-text" name="tech_sticky_skip" value="' . esc_attr( $o['skip'] ) . '"><p class="description">Page slugs, separated by commas.</p></td></tr></table>';
		submit_button( 'Save sticky bar', 'secondary', 'tech_sticky_save' );
		echo '</form>';
	}

	private function remote_info() {
		if ( is_admin() && ! empty( $_GET['force-check'] ) ) {
			delete_transient( 'tech_importer_update' );
		}
		$info = get_transient( 'tech_importer_update' );
		if ( false === $info ) {
			$res = wp_remote_get( self::UPDATE_URL . '?t=' . time(), array( 'timeout' => 15 ) );
			$err = '';
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_message();
			} elseif ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
				$err = 'GitHub answered ' . wp_remote_retrieve_response_code( $res );
			}
			$info = $err ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! $err && ! is_array( $info ) ) {
				$err  = 'update.json could not be read';
				$info = array();
			}
			update_option( 'tech_importer_update_status', array( 'time' => current_time( 'mysql' ), 'error' => $err, 'version' => isset( $info['version'] ) ? $info['version'] : '' ), false );
			set_transient( 'tech_importer_update', $info, $err ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		}
		return is_array( $info ) ? $info : array();
	}

	private function update_box() {
		if ( isset( $_POST['tech_update_now'] ) ) {
			check_admin_referer( 'tech_update_now' );
			delete_transient( 'tech_importer_update' );
			$info = $this->remote_info();
			if ( ! empty( $info['version'] ) && ! empty( $info['package'] ) && version_compare( $info['version'], self::VERSION, '>' ) && current_user_can( 'update_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/misc.php';
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
				$res = $up->install( $info['package'], array( 'overwrite_package' => true, 'clear_update_cache' => true ) );
				if ( true === $res ) {
					delete_site_transient( 'update_plugins' );
					echo '<div class="notice notice-success"><p><strong>Tech Importer updated to ' . esc_html( $info['version'] ) . '.</strong> <a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">Reload this page</a> to use it.</p></div>';
					return;
				}
				$msg = is_wp_error( $res ) ? $res->get_error_message() : 'the update could not be installed';
				echo '<div class="notice notice-error"><p>Update failed: ' . esc_html( $msg ) . '. You can still upload the zip by hand under Plugins &gt; Add New &gt; Upload Plugin.</p></div>';
				return;
			}
		} else {
			// Always ask GitHub fresh on this screen, so a new release shows right away.
			delete_transient( 'tech_importer_update' );
			$this->remote_info();
		}
		$st     = get_option( 'tech_importer_update_status', array() );
		$latest = ! empty( $st['version'] ) ? $st['version'] : '';
		$newer  = $latest && version_compare( $latest, self::VERSION, '>' );
		if ( ! $newer && empty( $st['error'] ) ) {
			return;
		}
		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #FF7033;padding:10px 14px;max-width:1100px;margin:14px 0;display:flex;gap:14px;align-items:center">';
		echo '<div>';
		if ( $newer ) {
			echo '<strong>Tech Importer ' . esc_html( $latest ) . ' is available.</strong> You have ' . esc_html( self::VERSION ) . '.';
		} else {
			echo '<span style="color:#cf222e">Could not check GitHub for updates: ' . esc_html( $st['error'] ) . '</span>';
		}
		echo '</div><form method="post" style="margin:0">';
		wp_nonce_field( 'tech_update_now' );
		echo '<button class="button button-primary" name="tech_update_now" value="1">' . ( $newer ? 'Update' : 'Try again' ) . '</button></form></div>';
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
		$this->update_box();
		echo '<p>Upload the zip from Claude. Everything comes in as a <strong>draft</strong>. Nothing is published.</p>';

		if ( isset( $_POST['tech_remove'] ) || isset( $_POST['tech_publish'] ) ) {
			check_admin_referer( 'tech_remove_item' );
			if ( isset( $_POST['tech_publish'] ) ) {
				$this->publish_item( (int) $_POST['tech_publish'] );
			} else {
				$this->remove_item( (int) $_POST['tech_remove'] );
			}
			echo '<ul style="font-size:14px;line-height:1.8">';
			foreach ( $this->log as $row ) {
				$color = 'ok' === $row[0] ? '#1a7f37' : '#cf222e';
				echo '<li style="color:' . esc_attr( $color ) . '">' . wp_kses_post( $row[1] ) . '</li>';
			}
			echo '</ul>';
			$this->log = array();
		}

		if ( isset( $_POST['tech_apply_changes'] ) || isset( $_POST['tech_undo'] ) || isset( $_POST['tech_discard_changes'] ) ) {
			check_admin_referer( 'tech_changes' );
			if ( isset( $_POST['tech_undo'] ) ) {
				$this->undo_change( sanitize_key( $_POST['tech_undo'] ) );
			} elseif ( isset( $_POST['tech_discard_changes'] ) ) {
				delete_option( 'tech_pending_changes' );
				$this->note( 'ok', 'Pending updates discarded. Nothing was changed.' );
			} else {
				$picked = isset( $_POST['tech_change'] ) ? array_map( 'sanitize_key', (array) $_POST['tech_change'] ) : array();
				$this->apply_changes( $picked );
			}
			$this->print_log();
		}

		if ( isset( $_POST['tech_prices_save'] ) || isset( $_POST['tech_price_convert'] ) ) {
			$this->price_post();
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
		$this->list_changes();
		$this->list_imports();
		$this->price_box();
		$this->sticky_box();
		echo '</div>';
	}

	private function print_log() {
		echo '<h2>Results</h2><ul style="font-size:14px;line-height:1.8">';
		foreach ( $this->log as $row ) {
			$color = 'ok' === $row[0] ? '#1a7f37' : ( 'warn' === $row[0] ? '#9a6700' : '#cf222e' );
			echo '<li style="color:' . esc_attr( $color ) . '">' . wp_kses_post( $row[1] ) . '</li>';
		}
		echo '</ul><hr>';
		$this->log = array();
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
		echo '<p>Publish puts the draft live and clears the WP Rocket cache. Remove deletes the draft and the images that came in with it. Published items can\'t be removed here.</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Published date</th><th>Published time</th><th>Images</th><th></th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || 'trash' === $p->post_status ) {
				continue;
			}
			$imgs = count( $this->item_images( $id ) );
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $p->post_title ) . '</a><br><small>/' . esc_html( $p->post_name ) . '/</small></td>';
			$live = in_array( $p->post_status, array( 'publish', 'future' ), true );
			$date = $live ? get_the_date( 'M j, Y', $p ) : '&mdash;';
			$time = $live ? get_the_time( 'g:i a', $p ) : '&mdash;';
			if ( 'future' === $p->post_status ) {
				$date .= '<br><small>scheduled</small>';
			}
			echo '<td>' . esc_html( $p->post_type ) . '</td><td>' . esc_html( $p->post_status ) . '</td><td>' . wp_kses_post( $date ) . '</td><td>' . wp_kses_post( $time ) . '</td><td>' . (int) $imgs . '</td><td>';
			if ( 'publish' === $p->post_status ) {
				echo '<em>Live</em>';
			} else {
				echo '<form method="post" style="margin:0;display:flex;gap:8px;align-items:center">';
				wp_nonce_field( 'tech_remove_item' );
				echo '<a class="button" href="' . esc_url( get_preview_post_link( $id ) ) . '" target="_blank">Preview</a>';
				echo '<button class="button button-primary" name="tech_publish" value="' . (int) $id . '" onclick="return confirm(\'Publish this now? It goes live on your site.\');">Publish</button>';
				echo '<button class="button button-link-delete" name="tech_remove" value="' . (int) $id . '" onclick="return confirm(\'Remove this draft and its ' . (int) $imgs . ' images for good?\');">Remove</button></form>';
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
		if ( ! is_array( $manifest ) || ( empty( $manifest['items'] ) && empty( $manifest['changes'] ) ) ) {
			$this->note( 'err', 'manifest.json is empty or not valid JSON.' );
			$this->cleanup( $dir );
			return;
		}

		$update = ! empty( $_POST['cot_update'] );
		foreach ( ! empty( $manifest['items'] ) ? $manifest['items'] : array() as $item ) {
			$this->import_item( $item, $root, $update );
		}
		if ( ! empty( $manifest['changes'] ) && is_array( $manifest['changes'] ) ) {
			$this->queue_changes( $manifest['changes'], isset( $manifest['name'] ) ? $manifest['name'] : '' );
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

	private function plain_html( $elements ) {
		$out = '';
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$st = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			if ( ! empty( $st['hide_desktop'] ) && ! empty( $st['hide_tablet'] ) && ! empty( $st['hide_mobile'] ) ) {
				continue;
			}
			$w = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
			if ( 'heading' === $w && ! empty( $st['title'] ) ) {
				$tag  = ( ! empty( $st['header_size'] ) && preg_match( '/^h[1-6]$/', $st['header_size'] ) ) ? $st['header_size'] : 'h2';
				$out .= '<' . $tag . '>' . $st['title'] . '</' . $tag . ">\n";
			} elseif ( 'text-editor' === $w && ! empty( $st['editor'] ) ) {
				$out .= $st['editor'] . "\n";
			} elseif ( 'image' === $w && ! empty( $st['image']['url'] ) ) {
				$alt  = ! empty( $st['image']['id'] ) ? get_post_meta( $st['image']['id'], '_wp_attachment_image_alt', true ) : '';
				$alt  = $alt ? $alt : ( isset( $st['image']['alt'] ) ? $st['image']['alt'] : '' );
				$out .= '<img src="' . esc_url( $st['image']['url'] ) . '" alt="' . esc_attr( $alt ) . "\">\n";
			} elseif ( 'button' === $w && ! empty( $st['text'] ) ) {
				$url  = isset( $st['link']['url'] ) ? $st['link']['url'] : '';
				$out .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $st['text'] ) . "</a></p>\n";
			} elseif ( 'icon-box' === $w ) {
				$out .= '<h3>' . ( isset( $st['title_text'] ) ? $st['title_text'] : '' ) . '</h3><p>' . ( isset( $st['description_text'] ) ? $st['description_text'] : '' ) . "</p>\n";
			} elseif ( 'icon-list' === $w && ! empty( $st['icon_list'] ) ) {
				$out .= '<ul>';
				foreach ( $st['icon_list'] as $li ) {
					$out .= '<li>' . ( isset( $li['text'] ) ? $li['text'] : '' ) . '</li>';
				}
				$out .= "</ul>\n";
			} elseif ( in_array( $w, array( 'accordion', 'toggle', 'tabs' ), true ) && ! empty( $st['tabs'] ) ) {
				foreach ( $st['tabs'] as $t ) {
					$out .= '<h3>' . ( isset( $t['tab_title'] ) ? $t['tab_title'] : '' ) . '</h3>' . ( isset( $t['tab_content'] ) ? $t['tab_content'] : '' ) . "\n";
				}
			}
			if ( ! empty( $el['elements'] ) ) {
				$out .= $this->plain_html( $el['elements'] );
			}
		}
		return $out;
	}

	private function publish_item( $id ) {
		$p = get_post( $id );
		if ( ! $p || ! in_array( $id, $this->imported_ids(), true ) || ! current_user_can( 'publish_post', $id ) ) {
			$this->note( 'err', 'That item was not found or you cannot publish it.' );
			return;
		}
		$res = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $res ) ) {
			$this->note( 'err', 'Could not publish: ' . esc_html( $res->get_error_message() ) );
			return;
		}
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		$url = get_permalink( $id );
		$this->note( 'ok', 'Published: <a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a> (WP Rocket cache cleared). Next: request indexing in Search Console and scrape it in the Facebook Sharing Debugger.' );
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
			$item['content'] = $this->plain_html( $content );
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
	/* ---------- Content updates (1.0.5) ---------- */

	private function text_fields() {
		return array( 'editor', 'title', 'title_text', 'description_text', 'tab_content', 'tab_title', 'text', 'description', 'testimonial_content' );
	}

	private function html_fields() {
		return array( 'editor', 'description_text', 'tab_content', 'description', 'testimonial_content' );
	}

	private function find_target( $ref ) {
		$ref = trim( (string) $ref, '/ ' );
		if ( '' === $ref || 'home' === $ref ) {
			$id = (int) get_option( 'page_on_front' );
			return $id ? get_post( $id ) : null;
		}
		foreach ( array( 'page', 'post' ) as $t ) {
			$p = get_page_by_path( $ref, OBJECT, $t );
			if ( $p ) {
				return $p;
			}
		}
		return null;
	}

	private function queue_changes( $changes, $batch ) {
		$pending = get_option( 'tech_pending_changes', array() );
		$n       = 0;
		foreach ( $changes as $c ) {
			if ( ! is_array( $c ) || empty( $c['page'] ) || ( empty( $c['find'] ) && empty( $c['section'] ) ) ) {
				$this->note( 'err', 'An update in the zip is missing its page or text, skipped.' );
				continue;
			}
			$type = ( isset( $c['type'] ) && in_array( $c['type'], array( 'replace', 'insert' ), true ) ) ? $c['type'] : 'link';
			$row  = array(
				'id'      => substr( md5( wp_json_encode( $c ) . microtime() . $n ), 0, 10 ),
				'batch'   => sanitize_text_field( $batch ),
				'type'    => $type,
				'page'    => sanitize_text_field( $c['page'] ),
				'find'    => (string) $c['find'],
				'url'     => isset( $c['url'] ) ? esc_url_raw( $c['url'] ) : '',
				'replace' => isset( $c['replace'] ) ? wp_kses_post( $c['replace'] ) : '',
				'note'    => isset( $c['note'] ) ? sanitize_text_field( $c['note'] ) : '',
				'paste'   => isset( $c['paste'] ) ? wp_kses_post( $c['paste'] ) : '',
				'after_section' => isset( $c['after_section'] ) ? array_map( 'sanitize_text_field', (array) $c['after_section'] ) : array(),
				'after_heading' => isset( $c['after_heading'] ) ? array_map( 'sanitize_text_field', (array) $c['after_heading'] ) : array(),
				'section' => ( isset( $c['section'] ) && is_array( $c['section'] ) ) ? $c['section'] : array(),
				'anchor'  => isset( $c['anchor'] ) ? (string) $c['anchor'] : '',
				'close'   => isset( $c['close'] ) ? (string) $c['close'] : '',
				'add'     => isset( $c['add'] ) ? wp_kses_post( $c['add'] ) : '',
				'marker'  => isset( $c['marker'] ) ? (string) $c['marker'] : '',
				'before_heading' => isset( $c['before_heading'] ) ? array_map( 'sanitize_text_field', (array) $c['before_heading'] ) : array(),
				'cut_rx'  => isset( $c['cut_rx'] ) ? (string) $c['cut_rx'] : '',
				'gone'    => isset( $c['gone'] ) ? (string) $c['gone'] : '',
			);
			if ( 'insert' === $type ) {
				$row['find'] = isset( $c['label'] ) ? sanitize_text_field( $c['label'] ) : 'New section';
			}
			if ( 'link' === $type && '' === $row['url'] ) {
				$this->note( 'err', 'Link update for "' . esc_html( $row['find'] ) . '" has no URL, skipped.' );
				continue;
			}
			$pending[ $row['id'] ] = $row;
			$n++;
		}
		update_option( 'tech_pending_changes', $pending, false );
		if ( $n ) {
			$this->note( 'ok', '<strong>' . $n . ' page updates are waiting for your review</strong> in "Updates to existing pages" below. Nothing has changed yet.' );
		}
	}

	/* Link the first match of $phrase in HTML text that is not already inside a link or a tag. */
	private function link_html( $html, $phrase, $url, &$found ) {
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$depth = 0;
		$done  = false;
		$rx    = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part && '<' === $part[0] ) {
				if ( preg_match( '/^<a[\s>]/i', $part ) ) {
					$depth++;
				} elseif ( preg_match( '/^<\/a\s*>/i', $part ) ) {
					$depth = max( 0, $depth - 1 );
				}
				continue;
			}
			if ( $depth > 0 ) {
				continue;
			}
			$cnt = preg_match_all( $rx, $part );
			if ( ! $cnt ) {
				continue;
			}
			$found += $cnt;
			if ( ! $done ) {
				$parts[ $i ] = preg_replace_callback( $rx, function ( $m ) use ( $url ) {
					return '<a href="' . esc_url( $url ) . '">' . $m[0] . '</a>';
				}, $part, 1 );
				$done = true;
			}
		}
		return $done ? implode( '', $parts ) : $html;
	}

	/* Walk Elementor elements and apply one change. Returns number of places changed, fills $where with a before/after sample. */
	private function apply_to_elements( &$elements, $c, &$where, &$found ) {
		$changed = 0;
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$changed += $this->apply_to_settings( $el['settings'], $c, $where, $found, isset( $el['widgetType'] ) ? $el['widgetType'] : '', $changed );
			}
			if ( ! empty( $el['elements'] ) ) {
				$changed += $this->apply_to_elements( $el['elements'], $c, $where, $found );
			}
			if ( 'link' === $c['type'] && $changed ) {
				return $changed;
			}
		}
		return $changed;
	}

	/*
	 * Replace text. Exact match first. If the exact text is gone (the editor often rewrites
	 * styles and spacing when a page is saved by hand), fall back to the anchor: find the
	 * anchor words, then the next closing tag, and add the new HTML right after it.
	 */
	private function replace_text( $val, $c, &$cnt ) {
		$cnt = substr_count( $val, $c['find'] );
		if ( $cnt ) {
			return str_replace( $c['find'], $c['replace'], $val );
		}
		if ( ! empty( $c['cut_rx'] ) ) {
			/* 1.0.12: remove (or swap) a block the editor may have reformatted, matched by a pattern. */
			$m = @preg_match_all( $c['cut_rx'], $val );
			if ( 1 === $m ) {
				$cnt = 1;
				return preg_replace( $c['cut_rx'], $c['replace'], $val, 1 );
			}
			return $val;
		}
		if ( empty( $c['anchor'] ) || empty( $c['close'] ) || empty( $c['add'] ) ) {
			return $val;
		}
		if ( ! empty( $c['marker'] ) && false !== strpos( $val, $c['marker'] ) ) {
			return $val;
		}
		$cnt = substr_count( $val, $c['anchor'] );
		if ( 1 !== $cnt ) {
			return $val;
		}
		$pos = strpos( $val, $c['anchor'] );
		$end = stripos( $val, $c['close'], $pos );
		if ( false === $end ) {
			$cnt = 0;
			return $val;
		}
		$end += strlen( $c['close'] );
		return substr( $val, 0, $end ) . $c['add'] . substr( $val, $end );
	}

	private function apply_to_settings( &$st, $c, &$where, &$found, $widget, $already ) {
		$changed = 0;
		foreach ( $st as $key => &$val ) {
			if ( is_array( $val ) ) {
				foreach ( $val as &$row ) {
					if ( is_array( $row ) ) {
						$changed += $this->apply_to_settings( $row, $c, $where, $found, $widget, $already + $changed );
					}
				}
				unset( $row );
				continue;
			}
			if ( ! is_string( $val ) || ! in_array( $key, $this->text_fields(), true ) ) {
				continue;
			}
			if ( 'link' === $c['type'] ) {
				if ( $already + $changed > 0 || ! in_array( $key, $this->html_fields(), true ) ) {
					if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $c['find'], '/' ) . '(?![\p{L}\p{N}])/iu', wp_strip_all_tags( $val ) ) ) {
						$found++;
					}
					continue;
				}
				$f   = 0;
				$new = $this->link_html( $val, $c['find'], $c['url'], $f );
				$found += $f;
				if ( $new !== $val ) {
					$where = array( $widget . ' / ' . $key, $val, $new );
					$val   = $new;
					$changed++;
				}
			} else {
				$cnt = 0;
				$new = $this->replace_text( $val, $c, $cnt );
				if ( $cnt ) {
					$found += $cnt;
					$where  = array( $widget . ' / ' . $key, $val, $new );
					$val    = $new;
					$changed += $cnt;
				}
			}
		}
		unset( $val );
		return $changed;
	}


	private function heading_texts( $el, &$out ) {
		if ( isset( $el['widgetType'] ) && 'heading' === $el['widgetType'] && ! empty( $el['settings']['title'] ) ) {
			$out[] = wp_strip_all_tags( $el['settings']['title'] );
		}
		foreach ( isset( $el['elements'] ) ? (array) $el['elements'] : array() as $ch ) {
			if ( is_array( $ch ) ) {
				$this->heading_texts( $ch, $out );
			}
		}
	}

	/* 1.0.12: put $sec right before the heading widget whose text holds $want, at any depth. */
	private function insert_before_heading( &$elements, $want, $sec, &$label ) {
		foreach ( $elements as $i => &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['widgetType'] ) && 'heading' === $el['widgetType'] && ! empty( $el['settings']['title'] )
				&& false !== stripos( wp_strip_all_tags( $el['settings']['title'] ), $want ) ) {
				$label = 'right above the heading "' . wp_strip_all_tags( $el['settings']['title'] ) . '"';
				if ( null !== $sec ) {
					array_splice( $elements, $i, 0, array( $sec ) );
				}
				return true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				if ( $this->insert_before_heading( $el['elements'], $want, $sec, $label ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* Try each before_heading text in order. $sec null = dry run. */
	private function place_nested( &$layout, $c, $sec, &$label ) {
		foreach ( $c['before_heading'] as $want ) {
			if ( $want && $this->insert_before_heading( $layout, $want, $sec, $label ) ) {
				return true;
			}
		}
		return false;
	}

	private function has_title_anywhere( $elements, $title ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['settings']['_title'] ) && $el['settings']['_title'] === $title ) {
				return true;
			}
			if ( ! empty( $el['elements'] ) && $this->has_title_anywhere( $el['elements'], $title ) ) {
				return true;
			}
		}
		return false;
	}

	/* Index of the top-level section to insert after, or -1. Fills $label with where it was found. */
	private function insert_spot( $layout, $c, &$label ) {
		foreach ( $c['after_section'] as $want ) {
			foreach ( $layout as $i => $el ) {
				$t = isset( $el['settings']['_title'] ) ? $el['settings']['_title'] : '';
				if ( $t && 0 === strcasecmp( trim( $t ), trim( $want ) ) ) {
					$label = 'after the "' . $t . '" section';
					return $i;
				}
			}
		}
		foreach ( $c['after_heading'] as $want ) {
			foreach ( $layout as $i => $el ) {
				$hs = array();
				$this->heading_texts( $el, $hs );
				foreach ( $hs as $h ) {
					if ( false !== stripos( $h, $want ) ) {
						$label = 'after the section with the heading "' . $h . '"';
						return $i;
					}
				}
			}
		}
		return -1;
	}


	private function first_link( $el ) {
		if ( ! empty( $el['settings']['link']['url'] ) ) {
			return $el['settings']['link']['url'];
		}
		foreach ( isset( $el['elements'] ) ? (array) $el['elements'] : array() as $ch ) {
			$l = $this->first_link( $ch );
			if ( $l ) {
				return $l;
			}
		}
		return '';
	}

	private function fresh_ids( &$el ) {
		$el['id'] = substr( md5( wp_generate_password( 12, false ) ), 0, 7 );
		if ( ! empty( $el['elements'] ) ) {
			foreach ( $el['elements'] as &$ch ) {
				$this->fresh_ids( $ch );
			}
		}
	}

	private function has_section_title( $layout, $title ) {
		foreach ( $layout as $el ) {
			if ( isset( $el['settings']['_title'] ) && $el['settings']['_title'] === $title ) {
				return true;
			}
		}
		return false;
	}

	private function get_layout( $post ) {
		$raw = get_post_meta( $post->ID, '_elementor_data', true );
		if ( ! $raw ) {
			return null;
		}
		$data = is_array( $raw ) ? $raw : json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/* Dry run: what would this change do? */
	private function check_change( $c ) {
		$post = $this->find_target( $c['page'] );
		if ( ! $post ) {
			return array( 'status' => 'err', 'msg' => 'Page /' . esc_html( $c['page'] ) . '/ not found.' );
		}
		$layout = $this->get_layout( $post );
		$where  = array();
		$found  = 0;
		if ( 'insert' === $c['type'] ) {
			if ( ! $layout ) {
				return array( 'status' => 'err', 'post' => $post, 'msg' => 'This page is not built with Elementor, so a section can\'t be added here.' );
			}
			$title = isset( $c['section']['settings']['_title'] ) ? $c['section']['settings']['_title'] : '';
			if ( $title && ( $c['before_heading'] ? $this->has_title_anywhere( $layout, $title ) : $this->has_section_title( $layout, $title ) ) ) {
				return array( 'status' => 'skip', 'post' => $post, 'msg' => 'The "' . esc_html( $title ) . '" section is already on this page.' );
			}
			$label = '';
			if ( $c['before_heading'] ) {
				$tmp = $layout;
				if ( ! $this->place_nested( $tmp, $c, null, $label ) ) {
					return array( 'status' => 'err', 'post' => $post, 'msg' => 'Could not find the heading to place it above (looked for: ' . esc_html( implode( ', ', $c['before_heading'] ) ) . ').' );
				}
				return array( 'status' => 'ready', 'post' => $post, 'where' => array( 'insert', $label, '' ), 'layout' => $layout );
			}
			$i     = $this->insert_spot( $layout, $c, $label );
			if ( $i < 0 ) {
				return array( 'status' => 'err', 'post' => $post, 'msg' => 'Could not find the section to place it after (looked for: ' . esc_html( implode( ', ', array_merge( $c['after_section'], $c['after_heading'] ) ) ) . ').' );
			}
			return array( 'status' => 'ready', 'post' => $post, 'where' => array( 'insert', $label, '' ), 'layout' => $layout );
		}
		if ( $layout ) {
			$flat = wp_json_encode( $layout, JSON_UNESCAPED_SLASHES );
			if ( ! empty( $c['marker'] ) && false !== strpos( $flat, $c['marker'] ) ) {
				return array( 'status' => 'skip', 'post' => $post, 'msg' => 'This update is already on the page.' );
			}
			if ( ! empty( $c['gone'] ) && false === strpos( $flat, $c['gone'] ) ) {
				return array( 'status' => 'skip', 'post' => $post, 'msg' => 'Already removed from this page.' );
			}
			if ( 'link' === $c['type'] && ( false !== strpos( $flat, 'href=\"' . $c['url'] . '\"' ) || false !== strpos( $flat, 'href=\"' . home_url( $c['url'] ) . '\"' ) ) ) {
				return array( 'status' => 'skip', 'post' => $post, 'msg' => 'This page already links to ' . esc_html( $c['url'] ) . '.' );
			}
			$n = $this->apply_to_elements( $layout, $c, $where, $found );
		} else {
			$html = $post->post_content;
			if ( 'link' === $c['type'] ) {
				$new = $this->link_html( $html, $c['find'], $c['url'], $found );
			} else {
				$new = $this->replace_text( $html, $c, $found );
			}
			$n     = $new !== $html ? 1 : 0;
			$where = $n ? array( 'page content', $html, $new ) : array();
		}
		if ( ! $n ) {
			$msg = 'link' === $c['type'] ? 'The words "' . esc_html( $c['find'] ) . '" are not on this page outside a link.' : 'The text to replace was not found. It may have been edited by hand.';
			if ( $c['paste'] ) {
				$msg .= ' Add this by hand instead: <code>' . esc_html( $c['paste'] ) . '</code>';
			}
			return array( 'status' => 'err', 'post' => $post, 'msg' => $msg );
		}
		if ( 'replace' === $c['type'] && $found > 1 ) {
			return array( 'status' => 'err', 'post' => $post, 'msg' => 'That text appears ' . $found . ' times on the page, so it was not changed. Claude needs to make the text more specific.' );
		}
		return array( 'status' => 'ready', 'post' => $post, 'where' => $where, 'layout' => $layout );
	}

	private function snippet( $before, $after ) {
		$b = wp_strip_all_tags( $before );
		$a = $after;
		$p = strpos( $a, '<a href=' );
		if ( false === $p ) {
			$plain = wp_strip_all_tags( $a );
			return esc_html( mb_strimwidth( $plain, 0, 260, '...' ) );
		}
		$start = max( 0, $p - 140 );
		$frag  = substr( $a, $start, 420 );
		$frag  = wp_kses( $frag, array( 'a' => array( 'href' => true ) ) );
		$frag  = preg_replace( '/<a href="([^"]*)">/', '<a href="$1" style="background:#fff3b0;font-weight:600">', $frag, 1 );
		return ( $start ? '...' : '' ) . $frag . '...';
	}

	private function list_changes() {
		$pending = get_option( 'tech_pending_changes', array() );
		$log     = get_option( 'tech_change_log', array() );
		if ( ! $pending && ! $log ) {
			return;
		}
		echo '<h2 style="margin-top:30px">Updates to existing pages</h2>';
		if ( $pending ) {
			echo '<p>Review each update. Nothing changes until you click <strong>Apply selected</strong>. Each page is backed up first, then checked after the change. If the check fails, the page is put back automatically.</p>';
			echo '<form method="post">';
			wp_nonce_field( 'tech_changes' );
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:30px"></th><th>Page</th><th>Update</th><th>Preview of the change</th></tr></thead><tbody>';
			foreach ( $pending as $c ) {
				$r     = $this->check_change( $c );
				$ready = 'ready' === $r['status'];
				$title = ! empty( $r['post'] ) ? $r['post']->post_title : $c['page'];
				$what  = 'insert' === $c['type']
					? 'Add the <strong>' . esc_html( $c['find'] ) . '</strong> section'
					: ( 'link' === $c['type']
					? 'Link <strong>"' . esc_html( $c['find'] ) . '"</strong> to <code>' . esc_html( $c['url'] ) . '</code>'
					: ( '' === $c['replace'] && $c['note'] ? 'Remove an old block'
					: 'Replace <strong>"' . esc_html( wp_strip_all_tags( $c['find'] ) ) . '"</strong> with <strong>"' . esc_html( wp_strip_all_tags( $c['replace'] ) ) . '"</strong>' ) );
				if ( $c['note'] ) {
					$what .= '<br><small>' . esc_html( $c['note'] ) . '</small>';
				}
				echo '<tr><td>' . ( $ready ? '<input type="checkbox" name="tech_change[]" value="' . esc_attr( $c['id'] ) . '" checked>' : '' ) . '</td>';
				echo '<td>' . esc_html( $title ) . '<br><small>/' . esc_html( trim( $c['page'], '/' ) ) . '/</small></td><td>' . $what . '</td><td>';
				if ( $ready && 'insert' === $c['type'] ) {
					echo 'Goes in ' . esc_html( $r['where'][1] ) . '.' . ( $c['note'] ? '' : '' );
				} elseif ( $ready ) {
					echo '<small style="color:#666">' . esc_html( $r['where'][0] ) . '</small><br>' . $this->snippet( $r['where'][1], $r['where'][2] );
				} else {
					$color = 'skip' === $r['status'] ? '#9a6700' : '#cf222e';
					echo '<span style="color:' . $color . '">' . wp_kses_post( $r['msg'] ) . '</span>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table><p style="display:flex;gap:10px">';
			echo '<button class="button button-primary" name="tech_apply_changes" value="1" onclick="return confirm(\'Apply the selected updates to your live pages?\');">Apply selected</button>';
			echo '<button class="button" name="tech_discard_changes" value="1">Discard all</button></p></form>';
		}
		if ( $log ) {
			echo '<h3>Applied updates</h3><form method="post">';
			wp_nonce_field( 'tech_changes' );
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>When</th><th>Page</th><th>What changed</th><th>Check</th><th></th></tr></thead><tbody>';
			foreach ( array_reverse( $log, true ) as $key => $row ) {
				echo '<tr><td>' . esc_html( $row['time'] ) . '</td><td><a href="' . esc_url( get_permalink( $row['post'] ) ) . '" target="_blank">' . esc_html( get_the_title( $row['post'] ) ) . '</a></td>';
				echo '<td>' . wp_kses_post( implode( '<br>', $row['what'] ) ) . '</td><td>' . wp_kses_post( $row['check'] ) . '</td><td>';
				echo ! empty( $row['undone'] ) ? '<em>Undone</em>' : '<button class="button" name="tech_undo" value="' . esc_attr( $key ) . '" onclick="return confirm(\'Put this page back the way it was before this update?\');">Undo</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></form>';
		}
	}

	private function page_snapshot( $post_id ) {
		$url = add_query_arg( 'tech_check', time() . wp_rand( 100, 999 ), get_permalink( $post_id ) );
		$res = wp_remote_get( $url, array( 'timeout' => 25, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'err' => $res->get_error_message() );
		}
		$body = wp_remote_retrieve_body( $res );
		return array(
			'ok'    => true,
			'code'  => (int) wp_remote_retrieve_response_code( $res ),
			'len'   => strlen( $body ),
			'h1'    => preg_match_all( '/<h1[\s>]/i', $body ),
			'h2'    => preg_match_all( '/<h2[\s>]/i', $body ),
			'imgs'  => preg_match_all( '/<img\s/i', $body ),
			'forms' => preg_match_all( '/<form[\s>]/i', $body ),
			'fatal' => (bool) preg_match( '/critical error|Fatal error|Parse error/i', $body ),
			'body'  => $body,
		);
	}

	private function count_nodes( $elements, &$ids ) {
		$n = 0;
		foreach ( (array) $elements as $el ) {
			if ( is_array( $el ) ) {
				$n++;
				$ids[] = isset( $el['id'] ) ? $el['id'] : '';
				if ( ! empty( $el['elements'] ) ) {
					$n += $this->count_nodes( $el['elements'], $ids );
				}
			}
		}
		return $n;
	}

	private function tag_gap( $html, $t ) {
		return preg_match_all( '/<' . $t . '[\s>]/i', $html ) - preg_match_all( '/<\/' . $t . '\s*>/i', $html );
	}

	/* True when the change did not open or close any tag it shouldn't have. */
	private function tags_balanced( $before, $after ) {
		foreach ( array( 'a', 'strong', 'em', 'b', 'p', 'ul', 'ol', 'li', 'span', 'div', 'h2', 'h3' ) as $t ) {
			if ( $this->tag_gap( $before, $t ) !== $this->tag_gap( $after, $t ) ) {
				return false;
			}
		}
		return true;
	}

	private function clear_page_cache( $post_id ) {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_css' );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
	}

	private function save_page( $post_id, $layout, $content ) {
		if ( null !== $layout ) {
			update_metadata( 'post', $post_id, '_elementor_data', wp_slash( is_string( $layout ) ? $layout : wp_json_encode( $layout ) ) );
		}
		if ( null !== $content ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}
		$this->clear_page_cache( $post_id );
	}

	private function apply_changes( $picked ) {
		$pending = get_option( 'tech_pending_changes', array() );
		$by_page = array();
		foreach ( $picked as $cid ) {
			if ( isset( $pending[ $cid ] ) ) {
				$post = $this->find_target( $pending[ $cid ]['page'] );
				if ( $post ) {
					$by_page[ $post->ID ][] = $pending[ $cid ];
				}
			}
		}
		if ( ! $by_page ) {
			$this->note( 'warn', 'No updates were selected.' );
			return;
		}
		$log = get_option( 'tech_change_log', array() );
		foreach ( $by_page as $post_id => $changes ) {
			$post = get_post( $post_id );
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$this->note( 'err', 'You cannot edit ' . esc_html( $post->post_title ) . '.' );
				continue;
			}
			$old_raw     = get_post_meta( $post_id, '_elementor_data', true );
			$old_content = $post->post_content;
			$layout      = $this->get_layout( $post );
			$content     = $old_content;
			$what        = array();
			$ids0        = array();
			$nodes0      = $layout ? $this->count_nodes( $layout, $ids0 ) : 0;
			$problems    = array();
			$expect      = array();

			$added = 0;
			foreach ( $changes as $c ) {
				$where = array();
				$found = 0;
				if ( 'insert' === $c['type'] ) {
					$label = '';
					$sec   = $c['section'];
					if ( ! empty( $c['before_heading'] ) ) {
						if ( ! $layout || ! $sec ) {
							$problems[] = 'Could not place the "' . esc_html( $c['find'] ) . '" section';
							continue;
						}
						$this->fresh_ids( $sec );
						if ( ! $this->place_nested( $layout, $c, $sec, $label ) ) {
							$problems[] = 'Could not find the heading to place the "' . esc_html( $c['find'] ) . '" section above';
							continue;
						}
						$tmp    = array();
						$added += $this->count_nodes( array( $sec ), $tmp );
						$what[] = 'Added the "' . esc_html( $c['find'] ) . '" block ' . esc_html( $label );
						$link   = $this->first_link( $sec );
						$expect[] = $link ? $link : '';
						unset( $pending[ $c['id'] ] );
						continue;
					}
					$i     = $layout ? $this->insert_spot( $layout, $c, $label ) : -1;
					if ( $i < 0 || ! $sec ) {
						$problems[] = 'Could not place the "' . esc_html( $c['find'] ) . '" section';
						continue;
					}
					$this->fresh_ids( $sec );
					$tmp    = array();
					$added += $this->count_nodes( array( $sec ), $tmp );
					array_splice( $layout, $i + 1, 0, array( $sec ) );
					$what[] = 'Added the "' . esc_html( $c['find'] ) . '" section ' . esc_html( $label );
					$link   = $this->first_link( $sec );
					$expect[] = $link ? $link : '';
					unset( $pending[ $c['id'] ] );
					continue;
				}
				if ( $layout ) {
					$n = $this->apply_to_elements( $layout, $c, $where, $found );
				} else {
					$n = 0;
				}
				if ( 'link' === $c['type'] ) {
					$f       = 0;
					$content = $this->link_html( $content, $c['find'], $c['url'], $f );
				} else {
					$f       = 0;
					$content = $this->replace_text( $content, $c, $f );
				}
				if ( ! $layout ) {
					$n = 1;
				}
				if ( ! $n || ( 'replace' === $c['type'] && $found > 1 ) ) {
					$problems[] = 'Could not apply "' . esc_html( wp_strip_all_tags( $c['find'] ) ) . '"';
					continue;
				}
				if ( $where && ! $this->tags_balanced( $where[1], $where[2] ) ) {
					$problems[] = 'The change to "' . esc_html( wp_strip_all_tags( $c['find'] ) ) . '" would leave broken HTML';
					continue;
				}
				$what[]   = 'link' === $c['type'] ? 'Linked "' . esc_html( $c['find'] ) . '" to ' . esc_html( $c['url'] ) : ( '' === $c['replace'] && $c['note'] ? 'Removed: ' . esc_html( $c['note'] ) : 'Replaced "' . esc_html( wp_strip_all_tags( $c['find'] ) ) . '"' );
				if ( 'link' === $c['type'] ) {
					$expect[] = 'href="' . esc_url( $c['url'] ) . '"';
				} elseif ( preg_match( '/href="([^"]+)"/', $c['replace'], $hm ) ) {
					$expect[] = 'href="' . $hm[1] . '"';
				} else {
					$expect[] = mb_substr( wp_strip_all_tags( $this->price_fill( $c['replace'] ) ), 0, 40 );
				}
				unset( $pending[ $c['id'] ] );
			}

			if ( $problems ) {
				$this->note( 'err', esc_html( $post->post_title ) . ': ' . implode( '; ', $problems ) . '. This page was not changed.' );
				foreach ( $changes as $c ) {
					$pending[ $c['id'] ] = $c;
				}
				continue;
			}
			if ( $layout ) {
				$ids1   = array();
				$nodes1 = $this->count_nodes( $layout, $ids1 );
				$json   = wp_json_encode( $layout );
				if ( $nodes1 !== $nodes0 + $added || array_diff( $ids0, $ids1 ) || ! is_array( json_decode( $json, true ) ) ) {
					$this->note( 'err', esc_html( $post->post_title ) . ': layout check failed (sections or widgets would change). This page was not changed.' );
					foreach ( $changes as $c ) {
						$pending[ $c['id'] ] = $c;
					}
					continue;
				}
			}

			$before = $this->page_snapshot( $post_id );
			$key    = 'u' . time() . $post_id;
			update_post_meta( $post_id, '_tech_backup_' . $key, wp_slash( wp_json_encode( array( 'data' => $old_raw, 'content' => $old_content ) ) ) );
			$this->save_page( $post_id, $layout ? $json : null, $content !== $old_content ? $content : null );
			$after = $this->page_snapshot( $post_id );

			$check = $this->verify( $before, $after, $expect );
			if ( 'fail' === $check[0] ) {
				$this->save_page( $post_id, $old_raw ? $old_raw : null, $old_content );
				$this->note( 'err', esc_html( $post->post_title ) . ': the check after the update failed (' . esc_html( $check[1] ) . '), so the page was put back the way it was.' );
				foreach ( $changes as $c ) {
					$pending[ $c['id'] ] = $c;
				}
				delete_post_meta( $post_id, '_tech_backup_' . $key );
				continue;
			}
			$log[ $key ] = array(
				'time'  => current_time( 'mysql' ),
				'post'  => $post_id,
				'what'  => $what,
				'check' => 'pass' === $check[0] ? '<span style="color:#1a7f37">Passed</span>' : '<span style="color:#9a6700">' . esc_html( $check[1] ) . '</span>',
			);
			$this->note( 'pass' === $check[0] ? 'ok' : 'warn', esc_html( $post->post_title ) . ': ' . count( $what ) . ' update(s) applied. Check: ' . esc_html( $check[1] ) . ' <a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank">View page</a>' );
		}
		update_option( 'tech_pending_changes', $pending, false );
		update_option( 'tech_change_log', array_slice( $log, -100, null, true ), false );
	}

	private function verify( $before, $after, $expect ) {
		if ( ! $after['ok'] || ! $before['ok'] ) {
			return array( 'warn', 'Could not load the page automatically, please open it and look it over' );
		}
		if ( 200 !== $after['code'] ) {
			return array( 'fail', 'page returned error ' . $after['code'] );
		}
		if ( $after['fatal'] && ! $before['fatal'] ) {
			return array( 'fail', 'page shows a PHP error' );
		}
		foreach ( array( 'h1' => 'H1 headings', 'h2' => 'H2 headings', 'imgs' => 'images', 'forms' => 'forms' ) as $k => $label ) {
			if ( $after[ $k ] < $before[ $k ] ) {
				return array( 'fail', 'fewer ' . $label . ' than before (' . $before[ $k ] . ' to ' . $after[ $k ] . ')' );
			}
		}
		if ( $after['len'] < $before['len'] * 0.9 ) {
			return array( 'fail', 'page got much shorter' );
		}
		foreach ( array_filter( $expect ) as $e ) {
			if ( false === strpos( $after['body'], $e ) ) {
				return array( 'warn', 'Saved, but the new text is not showing yet (probably cache). Clear the WP Rocket cache and look at the page' );
			}
		}
		return array( 'pass', 'page loads, headings, images and forms all still there, new content showing' );
	}

	private function undo_change( $key ) {
		$log = get_option( 'tech_change_log', array() );
		if ( empty( $log[ $key ] ) || ! empty( $log[ $key ]['undone'] ) ) {
			$this->note( 'err', 'That update was not found or was already undone.' );
			return;
		}
		$post_id = (int) $log[ $key ]['post'];
		$backup  = json_decode( get_post_meta( $post_id, '_tech_backup_' . $key, true ), true );
		if ( ! is_array( $backup ) || ! current_user_can( 'edit_post', $post_id ) ) {
			$this->note( 'err', 'The backup for that update is missing, so it can\'t be undone here.' );
			return;
		}
		$this->save_page( $post_id, ! empty( $backup['data'] ) ? $backup['data'] : null, isset( $backup['content'] ) ? $backup['content'] : null );
		$log[ $key ]['undone'] = true;
		update_option( 'tech_change_log', $log, false );
		delete_post_meta( $post_id, '_tech_backup_' . $key );
		$this->note( 'ok', esc_html( get_the_title( $post_id ) ) . ' is back the way it was before that update. If you edited the page in Elementor after the update, those later edits were undone too.' );
	}
}

new Tech_Importer();
