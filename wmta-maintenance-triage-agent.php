<?php
/**
 * Plugin Name: WebPenter Maintenance & Guest Request Triage Agent
 * Description: Lets tenants/guests report an issue from the property/listing page. Auto-classifies urgency, drafts a vendor message, and tracks status — works on Houzez (property + rent/sale) and Homey (listing/booking) automatically. No n8n or other workflow tool required; an optional free Groq API key sharpens the classification.
 * Version: 1.2.0
 * Author: WebPenter
 * Text Domain: wmta
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WMTA_VERSION', '1.2.0' );
define( 'WMTA_PATH', plugin_dir_path( __FILE__ ) );
define( 'WMTA_URL', plugin_dir_url( __FILE__ ) );

class WMTA_Plugin {

	const CPT = 'wmta_request';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'maybe_detect_mode' ) );
		add_shortcode( 'wmta_report_form', array( $this, 'render_form' ) );
		add_action( 'admin_post_wmta_submit', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_wmta_submit', array( $this, 'handle_submit' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'admin_column_content' ), 10, 2 );
		add_action( 'wp_ajax_wmta_update_status', array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_wmta_mark_vendor_sent', array( $this, 'ajax_mark_vendor_sent' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( $this, 'add_vendor_meta_box' ) );

		// Local dev installs (Laragon, localhost, etc.) commonly fail to
		// send ANY mail — not just ours — because WordPress's default
		// From address is "wordpress@<site-host>", and PHPMailer rejects
		// that as invalid whenever the host has no dot (e.g. "localhost").
		// This has nothing to do with SMTP: the send is rejected before a
		// server connection is ever attempted. Falling back to the site's
		// own admin email (which the admin already entered as a real,
		// dot-containing address) sidesteps it without requiring SMTP.
		add_filter( 'wp_mail_from', array( $this, 'fix_invalid_local_from_address' ) );
		add_filter( 'wp_mail_from_name', function ( $name ) {
			$site_name = get_bloginfo( 'name' );
			return $site_name ? $site_name : $name;
		} );

		// Auto-inject the "Report an issue" form (+ confirmation banner) on the
		// relevant single templates. Uses wp_footer rather than the_content:
		// Houzez's non-Elementor property templates (simple/tabs/tabs-vertical)
		// and Homey's single-listing templates both render the description via
		// get_template_part(), not the_content(), so the_content filter never
		// fires on a real site. wp_footer always fires on every single
		// template regardless of layout.
		add_action( 'wp_footer', array( $this, 'maybe_append_form' ) );
	}

	/**
	 * Detect which theme/platform we're running on, so the rest of the
	 * plugin can adapt without any manual configuration.
	 * - houzez : Houzez theme ("property" CPT, sale + rent mixed per listing)
	 * - homey  : Homey theme ("listing" CPT, every listing is a rental/booking)
	 * - generic: anything else with no recognizable CPT
	 */
	public function maybe_detect_mode() {
		if ( post_type_exists( 'property' ) ) {
			$this->mode = 'houzez';
		} elseif ( post_type_exists( 'listing' ) ) {
			$this->mode = 'homey';
		} else {
			$this->mode = 'generic';
		}
	}

	public $mode = 'generic';

	public function linked_post_type() {
		if ( $this->mode === 'houzez' ) return 'property';
		if ( $this->mode === 'homey' ) return 'listing';
		return 'post';
	}

	/**
	 * On Houzez, a site mixes for-sale and for-rent listings on the SAME cpt,
	 * so we only show the "report an issue" form on listings tagged as a
	 * rental status. On Homey every listing is inherently a rental, so it
	 * always applies there.
	 */
	public function is_rental_context( $post_id ) {
		if ( $this->mode === 'homey' ) {
			return true;
		}
		if ( $this->mode === 'houzez' ) {
			$terms = get_the_terms( $post_id, 'property_status' );
			if ( is_array( $terms ) ) {
				$keywords = apply_filters( 'wmta_houzez_rent_keywords', array( 'rent', 'lease', 'let' ) );
				foreach ( $terms as $term ) {
					foreach ( $keywords as $kw ) {
						if ( stripos( $term->slug, $kw ) !== false || stripos( $term->name, $kw ) !== false ) {
							return true;
						}
					}
				}
			}
			return false;
		}
		return false;
	}

	public function register_cpt() {
		register_post_type( self::CPT, array(
			'label'        => 'Maintenance Requests',
			'labels'       => array(
				'name'          => 'Maintenance Requests',
				'singular_name' => 'Maintenance Request',
				'menu_name'     => 'Maintenance Requests',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-admin-tools',
			'supports'     => array( 'title' ),
			'capability_type' => 'post',
		) );
	}

	public function admin_menu() {
		add_submenu_page( 'edit.php?post_type=' . self::CPT, 'Triage Agent Settings', 'Settings', 'manage_options', 'wmta-settings', array( $this, 'settings_page' ) );
		register_setting( 'wmta_settings', 'wmta_groq_api_key' );
		register_setting( 'wmta_settings', 'wmta_groq_model' );
		register_setting( 'wmta_settings', 'wmta_notify_email' );
		register_setting( 'wmta_settings', 'wmta_vendor_email' );
		register_setting( 'wmta_settings', 'wmta_accent_color' );
		register_setting( 'wmta_settings', 'wmta_places_api_key' );
	}

	public function settings_page() {
		?>
		<div class="wrap">
			<h1>Maintenance Triage Agent — Settings</h1>
			<p>Detected platform: <strong><?php echo esc_html( $this->mode ); ?></strong> (auto-detected from installed theme/CPTs)</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wmta_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">Groq API key (optional)</th>
						<td>
							<input type="text" name="wmta_groq_api_key" value="<?php echo esc_attr( get_option( 'wmta_groq_api_key' ) ); ?>" class="regular-text" placeholder="gsk_..." />
							<p class="description">Calls Groq's API directly from this plugin (free account at console.groq.com) for sharper urgency classification and a drafted vendor message. No n8n, no workflow tool, no premium plan needed. Leave blank to use the built-in keyword classifier only — that one costs nothing and needs no key at all.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Groq model</th>
						<td>
							<input type="text" name="wmta_groq_model" value="<?php echo esc_attr( get_option( 'wmta_groq_model', 'openai/gpt-oss-20b' ) ); ?>" class="regular-text" />
							<p class="description">Only used if an API key is set above. Check console.groq.com's current model list if this one ever stops working.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Notification email</th>
						<td><input type="email" name="wmta_notify_email" value="<?php echo esc_attr( get_option( 'wmta_notify_email', get_option( 'admin_email' ) ) ); ?>" class="regular-text" />
						<p class="description">Who gets emailed the moment a new request comes in.</p></td>
					</tr>
					<tr>
						<th scope="row">Vendor email</th>
						<td><input type="email" name="wmta_vendor_email" value="<?php echo esc_attr( get_option( 'wmta_vendor_email' ) ); ?>" class="regular-text" placeholder="plumber@example.com" />
						<p class="description">Used by the "Send to vendor" button in the request list — pre-fills a vendor email with the drafted message. Leave blank to just copy/forward the draft manually.</p></td>
					</tr>
					<tr>
						<th scope="row">Accent color</th>
						<td><input type="text" name="wmta_accent_color" value="<?php echo esc_attr( get_option( 'wmta_accent_color', '#1d2327' ) ); ?>" class="regular-text" placeholder="#1d2327" />
						<p class="description">Color of the floating "Report an issue" button and modal accents — match it to the client's brand.</p></td>
					</tr>
					<tr>
						<th scope="row">Google Places API key (optional)</th>
						<td>
							<input type="text" name="wmta_places_api_key" value="<?php echo esc_attr( get_option( 'wmta_places_api_key' ) ); ?>" class="regular-text" placeholder="AIza..." />
							<p class="description">When set, every new request automatically looks up real nearby vendors (electricians, plumbers, HVAC, handymen) for that trade near the property's address — name, rating, phone and a map link, shown on the request and in the notification email, so you just pick one instead of searching yourself. Get a free key at <a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank" rel="noopener">console.cloud.google.com</a> (enable "Places API"). Leave blank to skip this — everything else still works.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Cheap, free, built-in first-pass classifier. Used whenever no AI
	 * key is configured, or as a fallback if the Groq call fails.
	 */
	public function classify_keywords( $message ) {
		$message = strtolower( $message );

		$emergency = array( 'leak', 'flood', 'gas smell', 'gas leak', 'fire', 'no heat', 'no power', 'no electricity', 'locked out', 'lockout', 'break-in', 'burst pipe', 'smoke', 'sewage' );
		$standard  = array( 'ac', 'air condition', 'heater', 'plumbing', 'appliance', 'fridge', 'refrigerator', 'washer', 'dryer', 'toilet', 'faucet', 'tap', 'electrical', 'wifi', 'internet', 'lock' );

		$trade_map = array(
			'plumbing'   => array( 'leak', 'pipe', 'faucet', 'tap', 'toilet', 'sewage', 'drain' ),
			'electrical' => array( 'power', 'electric', 'outlet', 'wiring', 'light', 'breaker' ),
			'hvac'       => array( 'ac', 'air condition', 'heater', 'heat', 'furnace', 'thermostat' ),
			'general'    => array(),
		);

		$urgency = 'cosmetic';
		foreach ( $emergency as $kw ) {
			if ( $this->contains_keyword( $message, $kw ) ) { $urgency = 'emergency'; break; }
		}
		if ( $urgency === 'cosmetic' ) {
			foreach ( $standard as $kw ) {
				if ( $this->contains_keyword( $message, $kw ) ) { $urgency = 'standard'; break; }
			}
		}

		$trade = 'general';
		foreach ( $trade_map as $t => $kws ) {
			foreach ( $kws as $kw ) {
				if ( $this->contains_keyword( $message, $kw ) ) { $trade = $t; break 2; }
			}
		}

		return array( 'urgency' => $urgency, 'trade' => $trade );
	}

	/**
	 * Word-boundary match instead of a bare strpos(). A couple of the
	 * keyword list's short entries — "ac", "tap", "lock", "heat" — are
	 * substrings of completely unrelated everyday words ("each", "tape",
	 * "unlock", "heater" aside, even plain "that app"), so a plain
	 * substring search silently misclassifies anything mentioning them.
	 * \b doesn't work for a keyword like "ac" that starts mid-word in
	 * some languages, but for this ASCII keyword list \b is exactly the
	 * boundary we want.
	 */
	private function contains_keyword( $haystack, $keyword ) {
		if ( strpos( $keyword, ' ' ) !== false ) {
			// Multi-word phrases ("gas leak", "no power") are specific
			// enough that a plain substring match is safe.
			return strpos( $haystack, $keyword ) !== false;
		}
		return (bool) preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/', $haystack );
	}

	/**
	 * Optional: call Groq's API directly (no n8n, no middleman workflow
	 * tool — just one HTTP call from WordPress to console.groq.com) for a
	 * sharper classification and a ready-to-send vendor message draft.
	 * Falls back silently to the free keyword classifier on any failure —
	 * missing key, API error, bad response — so the form never breaks.
	 */
	public function classify_and_draft( $message, $context = array() ) {
		$fallback = $this->classify_keywords( $message );
		$fallback['vendor_draft'] = sprintf(
			"New %s request (%s) for %s.\n\nTenant/guest message: %s\n\nPlease confirm availability.",
			$fallback['urgency'], $fallback['trade'], isset( $context['listing_title'] ) ? $context['listing_title'] : 'the property', $message
		);

		$api_key = get_option( 'wmta_groq_api_key' );
		if ( empty( $api_key ) ) {
			$this->log_classification_source( 'FALLBACK (no key set)' );
			return $fallback;
		}

		$model = get_option( 'wmta_groq_model', 'openai/gpt-oss-20b' );
		if ( 'llama-3.1-8b-instant' === $model ) {
			// This model was retired by Groq (16 Aug 2026) and always 404s now.
			// A site that saved the old default before the retirement would
			// otherwise be stuck silently falling back to keywords forever.
			$model = 'openai/gpt-oss-20b';
		}

		$prompt = "You triage maintenance/guest requests for a rental property. "
			. "Given the tenant/guest message below, respond with ONLY a JSON object "
			. "(no markdown, no explanation) with exactly these keys: "
			. "\"urgency\" (one of: emergency, standard, cosmetic), "
			. "\"trade\" (one of: plumbing, electrical, hvac, general), "
			. "\"vendor_draft\" (a short, ready-to-send message to a vendor describing the issue, property, and urgency).\n\n"
			. "Property: " . ( $context['listing_title'] ?? 'the property' ) . "\n"
			. "Tenant/guest message: " . $message;

		$response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body' => wp_json_encode( array(
				'model'       => $model,
				'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
				'temperature' => 0.2,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log_classification_source( 'FALLBACK (Groq request error: ' . $response->get_error_message() . ')' );
			return $fallback;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $body['choices'][0]['message']['content'] ?? '';

		// Strip accidental markdown code fences before decoding.
		$text = preg_replace( '/^```(json)?|```$/m', '', trim( $text ) );
		$parsed = json_decode( trim( $text ), true );

		if ( ! is_array( $parsed ) || empty( $parsed['urgency'] ) ) {
			$this->log_classification_source( 'FALLBACK (Groq HTTP ' . $status . ', unparseable response: ' . substr( wp_remote_retrieve_body( $response ), 0, 200 ) . ')' );
			return $fallback;
		}

		$this->log_classification_source( 'GROQ (model=' . $model . ')' );

		return array(
			'urgency'      => sanitize_text_field( $parsed['urgency'] ),
			'trade'        => isset( $parsed['trade'] ) ? sanitize_text_field( $parsed['trade'] ) : $fallback['trade'],
			'vendor_draft' => isset( $parsed['vendor_draft'] ) ? sanitize_textarea_field( $parsed['vendor_draft'] ) : $fallback['vendor_draft'],
		);
	}

	/**
	 * Audit trail: which classifier actually produced this request's
	 * result. Useful beyond today's testing too — lets you glance at the
	 * log later and see whether Groq is being used, falling back, or
	 * erroring in production, without digging through postmeta.
	 */
	public function log_classification_source( $source ) {
		$log = WP_CONTENT_DIR . '/wmta-classify-log.txt';
		file_put_contents( $log, sprintf( "[%s] %s\n", date( 'Y-m-d H:i:s' ), $source ), FILE_APPEND );
	}

	public function log_vendor_lookup( $message ) {
		$log = WP_CONTENT_DIR . '/wmta-vendors-log.txt';
		file_put_contents( $log, sprintf( "[%s] %s\n", date( 'Y-m-d H:i:s' ), $message ), FILE_APPEND );
	}

	/**
	 * A single "street, city, state zip" string for the property/listing,
	 * read straight from whichever theme is active — Houzez stores the
	 * street address as post meta and city/state/country as taxonomies;
	 * Homey stores all of it as post meta under its own prefix. Returns ''
	 * if nothing usable is found, so callers can skip the vendor lookup
	 * instead of searching Google for a blank address.
	 */
	public function get_property_location_string( $post_id ) {
		$parts = array();

		if ( $this->mode === 'houzez' ) {
			$address = get_post_meta( $post_id, 'fave_property_address', true );
			if ( $address ) { $parts[] = $address; }
			foreach ( array( 'property_city', 'property_state', 'property_country' ) as $tax ) {
				$terms = get_the_terms( $post_id, $tax );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					$parts[] = $terms[0]->name;
				}
			}
		} elseif ( $this->mode === 'homey' ) {
			$address = get_post_meta( $post_id, 'homey_listing_address', true );
			if ( $address ) { $parts[] = $address; }
			foreach ( array( 'listing_city', 'listing_state', 'listing_country' ) as $tax ) {
				$terms = get_the_terms( $post_id, $tax );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					$parts[] = $terms[0]->name;
				}
			}
		}

		return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Maps our fixed trade categories to the phrase that actually finds the
	 * right kind of business on Google ("plumbing" the trade vs. "plumber"
	 * the business you'd search for).
	 */
	public function trade_search_term( $trade ) {
		$map = array(
			'plumbing'   => 'plumber',
			'electrical' => 'electrician',
			'hvac'       => 'HVAC repair technician',
			'general'    => 'handyman',
		);
		return $map[ $trade ] ?? 'handyman';
	}

	/**
	 * The "research nearby vendors" step: one call to Google's Places API
	 * (New) Text Search endpoint for "<trade> near <property address>".
	 * Unlike the old/legacy Places endpoints, this one returns phone number,
	 * website and a ready map link in the SAME call — no separate Details
	 * lookup needed. Every name, address, phone and rating here is exactly
	 * what Google returns; nothing is invented if a field is missing.
	 * Returns an empty array on any failure (no key, no location, API
	 * error) and logs why, so a request never fails to save just because
	 * this lookup didn't work.
	 */
	public function find_nearby_vendors( $trade, $location ) {
		$api_key = get_option( 'wmta_places_api_key' );
		if ( empty( $api_key ) ) {
			return array();
		}
		if ( empty( $location ) ) {
			$this->log_vendor_lookup( 'SKIPPED (no property address available)' );
			return array();
		}

		$query = $this->trade_search_term( $trade ) . ' near ' . $location;

		$search = wp_remote_post( 'https://places.googleapis.com/v1/places:searchText', array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'X-Goog-Api-Key'   => $api_key,
				'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.rating,places.userRatingCount,places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri',
			),
			'body' => wp_json_encode( array(
				'textQuery'      => $query,
				'maxResultCount' => 3,
			) ),
		) );

		if ( is_wp_error( $search ) ) {
			$this->log_vendor_lookup( 'FAILED (request error: ' . $search->get_error_message() . ') query="' . $query . '"' );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $search ), true );

		if ( ! empty( $data['error'] ) ) {
			$this->log_vendor_lookup( 'FAILED (status=' . ( $data['error']['status'] ?? 'unknown' ) . ', message=' . ( $data['error']['message'] ?? 'none' ) . ') query="' . $query . '"' );
			return array();
		}

		if ( empty( $data['places'] ) ) {
			$this->log_vendor_lookup( 'FAILED (no results) query="' . $query . '"' );
			return array();
		}

		$vendors = array();
		foreach ( array_slice( $data['places'], 0, 3 ) as $place ) {
			$vendors[] = array(
				'name'     => $place['displayName']['text'] ?? '',
				'address'  => $place['formattedAddress'] ?? '',
				'rating'   => $place['rating'] ?? null,
				'reviews'  => $place['userRatingCount'] ?? null,
				'phone'    => $place['nationalPhoneNumber'] ?? '',
				'website'  => $place['websiteUri'] ?? '',
				'maps_url' => $place['googleMapsUri'] ?? '',
			);
		}

		$this->log_vendor_lookup( 'OK (' . count( $vendors ) . ' result(s)) query="' . $query . '"' );

		return $vendors;
	}

	/**
	 * Renders as a fixed floating button + popup modal, not inline content.
	 * This is deliberate: wp_footer (the only hook that reliably fires on
	 * every template variant across both themes) always lands below the
	 * theme's visible footer in the DOM. A fixed-position floating button
	 * sidesteps that entirely — it displays correctly regardless of where
	 * in the HTML it's injected, and it's a familiar UX pattern (same idea
	 * as a chat widget) rather than content awkwardly tacked on below the
	 * footer.
	 *
	 * Includes a honeypot field (hidden from humans via CSS, irresistible
	 * to bots that blindly fill every input) and a render-timestamp field
	 * (rejects submissions faster than a human could plausibly type) —
	 * both checked in handle_submit().
	 */
	public function render_form( $atts ) {
		$atts = shortcode_atts( array( 'id' => get_the_ID() ), $atts );
		$post_id = absint( $atts['id'] );

		if ( ! $post_id || ! $this->is_rental_context( $post_id ) ) {
			return '';
		}

		$accent = get_option( 'wmta_accent_color', '#1d2327' );

		$dismiss_key = 'wmta_bar_dismissed_' . $post_id;

		ob_start();
		?>
		<style>
			.wmta-widget{--wmta-accent: <?php echo esc_html( $accent ); ?>;}
			/* A slim, full-width utility bar — the same visual family as a
			 * cookie/consent notice, not a cornered chat-launcher bubble.
			 * No overlay-colored backdrop, no round pill, nothing that reads
			 * as "a chat window is about to open." */
			.wmta-bar{position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#fff;border-top:3px solid var(--wmta-accent);box-shadow:0 -4px 18px rgba(0,0,0,.08);display:flex;align-items:center;gap:14px;padding:12px 18px;font-size:14px;color:#2b2b2b;flex-wrap:wrap;transform:translateY(0);transition:transform .2s ease,opacity .2s ease;}
			.wmta-bar.wmta-hidden{transform:translateY(100%);opacity:0;pointer-events:none;}
			.wmta-bar .wmta-bar-icon{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;background:rgba(0,0,0,.06);flex:none;font-size:15px;}
			.wmta-bar .wmta-bar-text{flex:1 1 220px;line-height:1.3;}
			.wmta-bar .wmta-bar-text strong{display:block;font-size:13.5px;}
			.wmta-bar .wmta-bar-text span{color:#666;font-size:12.5px;}
			.wmta-bar .wmta-bar-action{background:var(--wmta-accent);color:#fff;border:none;border-radius:6px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;flex:none;white-space:nowrap;}
			.wmta-bar .wmta-bar-action:hover{filter:brightness(1.1);}
			.wmta-bar .wmta-bar-dismiss{background:none;border:none;font-size:18px;line-height:1;color:#999;cursor:pointer;padding:4px;flex:none;}
			.wmta-bar .wmta-bar-dismiss:hover{color:#444;}
			@media (max-width:480px){.wmta-bar{padding:10px 12px;}.wmta-bar .wmta-bar-text span{display:none;}}
			.wmta-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,15,20,.55);z-index:100000;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
			.wmta-modal-overlay.wmta-open{display:flex;}
			.wmta-modal{background:#fff;border-radius:12px;padding:28px;width:92%;max-width:440px;max-height:90vh;overflow:auto;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3);}
			.wmta-modal h3{margin:0 0 4px;font-size:19px;}
			.wmta-modal .wmta-sub{margin:0 0 18px;color:#666;font-size:13px;}
			.wmta-modal .wmta-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;line-height:1;color:#888;}
			.wmta-modal .wmta-close:hover{color:#222;}
			.wmta-modal label{display:block;margin-bottom:14px;font-size:13px;font-weight:600;color:#333;}
			.wmta-modal input[type=text],.wmta-modal input[type=file],.wmta-modal textarea{width:100%;padding:10px;margin-top:5px;border:1px solid #d6d6d6;border-radius:6px;box-sizing:border-box;font:inherit;font-weight:400;}
			.wmta-modal input[type=text]:focus,.wmta-modal textarea:focus{outline:none;border-color:var(--wmta-accent);box-shadow:0 0 0 3px rgba(0,0,0,.08);}
			.wmta-modal .wmta-submit-btn{background:var(--wmta-accent);color:#fff;border:none;border-radius:6px;padding:11px 18px;cursor:pointer;font-weight:600;width:100%;font-size:14px;}
			.wmta-modal .wmta-submit-btn:hover{filter:brightness(1.1);}
			.wmta-hp-field{position:absolute !important;left:-9999px !important;top:-9999px !important;height:0;width:0;overflow:hidden;}
		</style>
		<div class="wmta-widget">
			<div class="wmta-bar" id="wmta-bar-<?php echo esc_attr( $post_id ); ?>">
				<span class="wmta-bar-icon" aria-hidden="true">&#128295;</span>
				<span class="wmta-bar-text"><strong>Something not right with this place?</strong><span>Let the host know and we'll route it to the right person.</span></span>
				<button type="button" class="wmta-bar-action" onclick="document.getElementById('wmta-modal-<?php echo esc_attr( $post_id ); ?>').classList.add('wmta-open')">Report an issue</button>
				<button type="button" class="wmta-bar-dismiss" aria-label="Dismiss" onclick="document.getElementById('wmta-bar-<?php echo esc_attr( $post_id ); ?>').classList.add('wmta-hidden');try{sessionStorage.setItem('<?php echo esc_js( $dismiss_key ); ?>','1');}catch(e){}">&times;</button>
			</div>
			<div class="wmta-modal-overlay" id="wmta-modal-<?php echo esc_attr( $post_id ); ?>">
				<div class="wmta-modal">
					<button type="button" class="wmta-close" onclick="document.getElementById('wmta-modal-<?php echo esc_attr( $post_id ); ?>').classList.remove('wmta-open')">&times;</button>
					<h3>Report an issue</h3>
					<p class="wmta-sub">We'll get this to the right person right away.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="wmta_submit" />
						<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
						<input type="hidden" name="wmta_render_ts" value="<?php echo esc_attr( time() ); ?>" />
						<div class="wmta-hp-field" aria-hidden="true">
							<label>Leave this field empty<input type="text" name="wmta_website" tabindex="-1" autocomplete="off" /></label>
						</div>
						<?php wp_nonce_field( 'wmta_submit_' . $post_id, 'wmta_nonce' ); ?>
						<label>Your name<input type="text" name="wmta_name" required /></label>
						<label>Phone or WhatsApp<input type="text" name="wmta_contact" required /></label>
						<label>Describe the issue<textarea name="wmta_message" required rows="4"></textarea></label>
						<label>Photo (optional)<input type="file" name="wmta_photo" accept="image/*" /></label>
						<button type="submit" class="wmta-submit-btn">Submit request</button>
					</form>
				</div>
			</div>
		</div>
		<script>
			(function(){
				try {
					if ( sessionStorage.getItem( '<?php echo esc_js( $dismiss_key ); ?>' ) === '1' ) {
						var bar = document.getElementById( 'wmta-bar-<?php echo esc_js( $post_id ); ?>' );
						if ( bar ) { bar.classList.add( 'wmta-hidden' ); bar.style.display = 'none'; }
					}
				} catch (e) {}
			})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * A small dismissible confirmation banner shown after a successful
	 * submission (?wmta_submitted=REF in the URL). Before this, the only
	 * evidence a tenant had that anything happened was the reference code
	 * sitting quietly in the address bar — easy to miss, and it read as
	 * broken to anyone not looking closely.
	 */
	public function maybe_show_confirmation() {
		if ( empty( $_GET['wmta_submitted'] ) ) {
			return;
		}
		$ref = sanitize_text_field( wp_unslash( $_GET['wmta_submitted'] ) );
		if ( ! preg_match( '/^RT-[A-Z0-9]+$/', $ref ) ) {
			return;
		}
		?>
		<div id="wmta-confirm-banner" style="position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:100001;background:#1e3f2c;color:#fff;padding:14px 20px;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.3);font-size:14px;max-width:92%;display:flex;align-items:center;gap:12px;">
			<span>✓ Request received — reference <strong><?php echo esc_html( $ref ); ?></strong>. We'll be in touch.</span>
			<button type="button" onclick="document.getElementById('wmta-confirm-banner').remove()" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;opacity:.8;">&times;</button>
		</div>
		<script>
			setTimeout(function(){ var b=document.getElementById('wmta-confirm-banner'); if(b) b.remove(); }, 8000);
		</script>
		<?php
	}

	/**
	 * Auto-append the form (and confirmation banner) on single property
	 * (Houzez, rent-tagged only) or single listing (Homey, always) pages,
	 * so clients don't have to manually place a shortcode in every
	 * template. Runs on wp_footer so it works regardless of which template
	 * layout/version the listing uses (top-area v1-v7, simple/tabs/
	 * tabs-vertical, Elementor, etc.) — it does not depend on the theme
	 * calling the_content() anywhere.
	 */
	public function maybe_append_form() {
		$this->maybe_show_confirmation();
		if ( $this->mode === 'generic' || ! is_singular( $this->linked_post_type() ) ) {
			return;
		}
		echo $this->render_form( array( 'id' => get_queried_object_id() ) );
	}

	public function handle_submit() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! isset( $_POST['wmta_nonce'] ) || ! wp_verify_nonce( $_POST['wmta_nonce'], 'wmta_submit_' . $post_id ) ) {
			wp_die( 'Invalid request.' );
		}

		// Honeypot: a real visitor never sees or fills this field (hidden via
		// CSS, off the tab order); a bot filling every input blindly will.
		if ( ! empty( $_POST['wmta_website'] ) ) {
			$redirect = wp_get_referer() ? wp_get_referer() : home_url();
			wp_safe_redirect( add_query_arg( 'wmta_submitted', 'RT-' . strtoupper( substr( uniqid(), -5 ) ), $redirect ) );
			exit; // Pretend success — don't tip the bot off, don't create a record.
		}

		// Time-trap: a human needs at least a couple of seconds to read the
		// form and type a message; treat near-instant submits as automated.
		$rendered_at = isset( $_POST['wmta_render_ts'] ) ? absint( $_POST['wmta_render_ts'] ) : 0;
		if ( $rendered_at && ( time() - $rendered_at ) < 2 ) {
			wp_die( 'Please try again.' );
		}

		$name    = sanitize_text_field( $_POST['wmta_name'] ?? '' );
		$contact = sanitize_text_field( $_POST['wmta_contact'] ?? '' );
		$message = sanitize_textarea_field( $_POST['wmta_message'] ?? '' );

		$classification = $this->classify_and_draft( $message, array(
			'listing_title' => get_the_title( $post_id ),
			'listing_id'    => $post_id,
			'reporter_name' => $name,
		) );

		$ref = 'RT-' . strtoupper( substr( uniqid(), -5 ) );

		$request_id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_title'  => $ref . ' — ' . get_the_title( $post_id ),
			'post_status' => 'publish',
		) );

		update_post_meta( $request_id, 'wmta_ref', $ref );
		update_post_meta( $request_id, 'wmta_linked_post', $post_id );
		update_post_meta( $request_id, 'wmta_name', $name );
		update_post_meta( $request_id, 'wmta_contact', $contact );
		update_post_meta( $request_id, 'wmta_message', $message );
		update_post_meta( $request_id, 'wmta_urgency', $classification['urgency'] );
		update_post_meta( $request_id, 'wmta_trade', $classification['trade'] );
		update_post_meta( $request_id, 'wmta_vendor_draft', $classification['vendor_draft'] );
		update_post_meta( $request_id, 'wmta_status', 'new' );
		update_post_meta( $request_id, 'wmta_vendor_sent', '0' );

		// "Research" step: look up real nearby vendors for this trade near
		// the property's own address, so the admin gets a shortlist to pick
		// from instead of having to go find one themselves. Silently empty
		// if no Places key is set or nothing is found — never blocks saving
		// the request itself.
		$nearby_vendors = $this->find_nearby_vendors( $classification['trade'], $this->get_property_location_string( $post_id ) );
		update_post_meta( $request_id, 'wmta_nearby_vendors', $nearby_vendors );

		$this->maybe_handle_photo_upload( $request_id );

		$this->notify_admin( $request_id, $ref, $classification, $nearby_vendors );

		$redirect = wp_get_referer() ? wp_get_referer() : home_url();
		wp_safe_redirect( add_query_arg( 'wmta_submitted', $ref, $redirect ) );
		exit;
	}

	/**
	 * Optional photo attached to the request. Stored as a real media
	 * library attachment (so it benefits from WP's own thumbnailing) and
	 * linked to the request via meta + featured image, so it shows in the
	 * admin list and the post edit screen without any extra UI work.
	 */
	public function maybe_handle_photo_upload( $request_id ) {
		if ( empty( $_FILES['wmta_photo'] ) || empty( $_FILES['wmta_photo']['name'] ) ) {
			return;
		}
		if ( ! empty( $_FILES['wmta_photo']['error'] ) && $_FILES['wmta_photo']['error'] !== UPLOAD_ERR_OK ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// media_handle_upload validates it's an actual image via WP's own
		// allowed-mime checks and wires up the attachment + metadata for us.
		$attachment_id = media_handle_upload( 'wmta_photo', $request_id );

		if ( is_wp_error( $attachment_id ) ) {
			return;
		}

		update_post_meta( $request_id, 'wmta_photo_id', $attachment_id );
		set_post_thumbnail( $request_id, $attachment_id );
	}

	/**
	 * See the comment in __construct(): swaps an invalid-format From
	 * address (no dot in the domain part — true of any "...@localhost"
	 * site) for the admin's own email, which is guaranteed dot-containing
	 * since WordPress requires a real-looking address at setup.
	 */
	public function fix_invalid_local_from_address( $email ) {
		if ( strpos( $email, '@' ) !== false ) {
			$domain = substr( strrchr( $email, '@' ), 1 );
			if ( strpos( $domain, '.' ) !== false ) {
				return $email; // Already a valid-format domain, leave it alone.
			}
		}
		$admin_email = get_option( 'admin_email' );
		return $admin_email ? $admin_email : $email;
	}

	public function notify_admin( $request_id, $ref, $classification, $nearby_vendors = array() ) {
		$to = get_option( 'wmta_notify_email', get_option( 'admin_email' ) );
		$subject = ( $classification['urgency'] === 'emergency' ? '🚨 EMERGENCY — ' : '' ) . 'New maintenance request ' . $ref;
		$body = "Urgency: {$classification['urgency']}\nTrade: {$classification['trade']}\n\nDrafted vendor message:\n{$classification['vendor_draft']}";

		if ( ! empty( $nearby_vendors ) ) {
			$body .= "\n\nNearby " . $this->trade_search_term( $classification['trade'] ) . "s (AI-suggested, pick one):\n";
			$n = 1;
			foreach ( $nearby_vendors as $v ) {
				$line = $n++ . ". " . $v['name'];
				if ( ! empty( $v['rating'] ) ) {
					$line .= " — ★{$v['rating']}" . ( ! empty( $v['reviews'] ) ? " ({$v['reviews']} reviews)" : '' );
				}
				if ( ! empty( $v['phone'] ) ) {
					$line .= " — {$v['phone']}";
				}
				$body .= $line . "\n";
				if ( ! empty( $v['address'] ) ) {
					$body .= "   {$v['address']}\n";
				}
				if ( ! empty( $v['maps_url'] ) ) {
					$body .= "   {$v['maps_url']}\n";
				}
			}
		}

		$body .= "\n\nReview: " . admin_url( 'post.php?post=' . $request_id . '&action=edit' );
		wp_mail( $to, $subject, $body );
	}

	public function admin_columns( $columns ) {
		$columns['wmta_photo']   = 'Photo';
		$columns['wmta_urgency'] = 'Urgency';
		$columns['wmta_trade']   = 'Trade';
		$columns['wmta_status']  = 'Status';
		$columns['wmta_vendor']  = 'Vendor';
		$columns['wmta_nearby']  = 'Nearby Vendors';
		$columns['wmta_linked']  = 'Property/Listing';
		return $columns;
	}

	public function admin_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'wmta_photo':
				$photo_id = get_post_meta( $post_id, 'wmta_photo_id', true );
				if ( $photo_id ) {
					echo wp_get_attachment_image( $photo_id, array( 48, 48 ), false, array( 'style' => 'border-radius:4px;object-fit:cover;' ) );
				} else {
					echo '<span style="color:#aaa;">—</span>';
				}
				break;
			case 'wmta_urgency':
				$u = get_post_meta( $post_id, 'wmta_urgency', true );
				$color = $u === 'emergency' ? '#d63638' : ( $u === 'standard' ? '#dba617' : '#2271b1' );
				echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( ucfirst( $u ) ) . '</span>';
				break;
			case 'wmta_trade':
				echo esc_html( ucfirst( get_post_meta( $post_id, 'wmta_trade', true ) ) );
				break;
			case 'wmta_status':
				$status = get_post_meta( $post_id, 'wmta_status', true );
				echo '<select class="wmta-status-select" data-id="' . esc_attr( $post_id ) . '">';
				foreach ( array( 'new', 'assigned', 'in_progress', 'resolved' ) as $s ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $status, $s, false ), esc_html( ucwords( str_replace( '_', ' ', $s ) ) ) );
				}
				echo '</select>';
				break;
			case 'wmta_vendor':
				$sent = get_post_meta( $post_id, 'wmta_vendor_sent', true );
				$draft = get_post_meta( $post_id, 'wmta_vendor_draft', true );
				$vendor_email = get_option( 'wmta_vendor_email' );
				$subject = rawurlencode( 'Maintenance request — ' . get_the_title( $post_id ) );
				$body = rawurlencode( $draft );
				$mailto = $vendor_email ? 'mailto:' . rawurlencode( $vendor_email ) . '?subject=' . $subject . '&body=' . $body : '#';
				if ( $sent === '1' ) {
					echo '<span style="color:#00a32a;font-weight:600;">✓ Sent</span>';
				} else {
					echo '<a href="' . esc_url( $mailto ) . '" class="button button-small wmta-send-vendor" data-id="' . esc_attr( $post_id ) . '"' . ( $vendor_email ? '' : ' title="Set a vendor email in Settings first"' ) . '>Send to vendor</a>';
				}
				break;
			case 'wmta_nearby':
				$vendors = get_post_meta( $post_id, 'wmta_nearby_vendors', true );
				if ( empty( $vendors ) || ! is_array( $vendors ) ) {
					$key_set = get_option( 'wmta_places_api_key' );
					echo '<span style="color:#aaa;">' . ( $key_set ? 'None found' : '—' ) . '</span>';
					break;
				}
				$top = $vendors[0];
				echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '"><strong>' . esc_html( $top['name'] ) . '</strong></a>';
				if ( ! empty( $top['rating'] ) ) {
					echo ' <span style="color:#dba617;">★' . esc_html( $top['rating'] ) . '</span>';
				}
				if ( count( $vendors ) > 1 ) {
					echo '<br><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#wmta-nearby-vendors" style="font-size:12px;">+' . ( count( $vendors ) - 1 ) . ' more</a>';
				}
				break;
			case 'wmta_linked':
				$linked = get_post_meta( $post_id, 'wmta_linked_post', true );
				if ( $linked ) {
					echo '<a href="' . esc_url( get_permalink( $linked ) ) . '">' . esc_html( get_the_title( $linked ) ) . '</a>';
				}
				break;
		}
	}

	/**
	 * Inline JS for the admin list screen: wires the status dropdown and
	 * the "Send to vendor" button to the AJAX handlers below. Only loaded
	 * on this CPT's own admin screens.
	 */
	public function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== self::CPT ) {
			return;
		}
		$nonce = wp_create_nonce( 'wmta_admin_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$inline = <<<JS
		document.addEventListener('change', function(e){
			if (!e.target.classList.contains('wmta-status-select')) return;
			var id = e.target.getAttribute('data-id');
			var status = e.target.value;
			fetch('{$ajax_url}', {
				method: 'POST',
				headers: {'Content-Type':'application/x-www-form-urlencoded'},
				body: 'action=wmta_update_status&id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(status) + '&_ajax_nonce={$nonce}'
			});
		});
		document.addEventListener('click', function(e){
			var btn = e.target.closest('.wmta-send-vendor');
			if (!btn) return;
			var id = btn.getAttribute('data-id');
			fetch('{$ajax_url}', {
				method: 'POST',
				headers: {'Content-Type':'application/x-www-form-urlencoded'},
				body: 'action=wmta_mark_vendor_sent&id=' + encodeURIComponent(id) + '&_ajax_nonce={$nonce}'
			}).then(function(){
				btn.outerHTML = '<span style="color:#00a32a;font-weight:600;">&#10003; Sent</span>';
			});
		});
		document.addEventListener('click', function(e){
			var btn = e.target.closest('.wmta-copy-draft');
			if (!btn) return;
			var text = btn.getAttribute('data-draft') || '';
			var done = function(){ var old = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = old; }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, done);
			} else {
				var ta = document.createElement('textarea');
				ta.value = text; document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); } catch (e) {}
				document.body.removeChild(ta);
				done();
			}
		});
JS;
		wp_register_script( 'wmta-admin', false, array(), WMTA_VERSION, true );
		wp_enqueue_script( 'wmta-admin' );
		wp_add_inline_script( 'wmta-admin', $inline );
	}

	public function ajax_update_status() {
		check_ajax_referer( 'wmta_admin_nonce' );
		$id = absint( $_POST['id'] ?? 0 );
		$status = sanitize_text_field( $_POST['status'] ?? '' );
		if ( $id && in_array( $status, array( 'new', 'assigned', 'in_progress', 'resolved' ), true ) ) {
			update_post_meta( $id, 'wmta_status', $status );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function ajax_mark_vendor_sent() {
		check_ajax_referer( 'wmta_admin_nonce' );
		$id = absint( $_POST['id'] ?? 0 );
		if ( $id ) {
			update_post_meta( $id, 'wmta_vendor_sent', '1' );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function add_vendor_meta_box() {
		add_meta_box(
			'wmta_nearby_vendors',
			'Nearby Vendors (AI-suggested)',
			array( $this, 'render_vendor_meta_box' ),
			self::CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Shows the real Google Places results found for this request's trade
	 * near the property's address — the admin reads it and decides who to
	 * assign; nothing here is auto-contacted or auto-booked. Every field
	 * shown is exactly what Google returned (name, rating, phone, address,
	 * map link); a missing field is just left out, never guessed.
	 */
	public function render_vendor_meta_box( $post ) {
		echo '<div id="wmta-nearby-vendors">';

		if ( empty( get_option( 'wmta_places_api_key' ) ) ) {
			echo '<p>Add a <a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT . '&page=wmta-settings' ) ) . '">Google Places API key in Settings</a> to have nearby vendors looked up automatically for every new request.</p></div>';
			return;
		}

		$vendors = get_post_meta( $post->ID, 'wmta_nearby_vendors', true );
		$draft   = get_post_meta( $post->ID, 'wmta_vendor_draft', true );

		if ( empty( $vendors ) || ! is_array( $vendors ) ) {
			echo '<p>No nearby vendors were found for this request\'s trade/location. You can still use the drafted message with your own <a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT . '&page=wmta-settings' ) ) . '">Vendor email</a> from Settings.</p></div>';
			return;
		}

		echo '<style>
			.wmta-vcard{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin-bottom:10px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;}
			.wmta-vcard-info strong{font-size:14px;}
			.wmta-vcard-info .wmta-vmeta{color:#646970;font-size:12.5px;margin-top:3px;}
			.wmta-vcard-actions{display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap;}
			.wmta-vcard-actions .button{font-size:12px;}
		</style>';
		echo '<p class="description" style="margin-top:0;">Found via Google Places — pick one and use its phone/site to reach out, or copy the drafted message below to send yourself.</p>';

		foreach ( $vendors as $i => $v ) {
			echo '<div class="wmta-vcard">';
			echo '<div class="wmta-vcard-info">';
			echo '<strong>' . esc_html( $v['name'] ) . '</strong>';
			if ( ! empty( $v['rating'] ) ) {
				echo ' <span style="color:#dba617;">★' . esc_html( $v['rating'] ) . '</span>' . ( ! empty( $v['reviews'] ) ? ' <span style="color:#646970;">(' . esc_html( $v['reviews'] ) . ' reviews)</span>' : '' );
			}
			if ( ! empty( $v['address'] ) ) {
				echo '<div class="wmta-vmeta">' . esc_html( $v['address'] ) . '</div>';
			}
			if ( ! empty( $v['phone'] ) ) {
				echo '<div class="wmta-vmeta">' . esc_html( $v['phone'] ) . '</div>';
			}
			echo '</div>';
			echo '<div class="wmta-vcard-actions">';
			if ( ! empty( $v['phone'] ) ) {
				echo '<a class="button button-small" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $v['phone'] ) ) . '">Call</a>';
			}
			if ( ! empty( $v['website'] ) ) {
				echo '<a class="button button-small" href="' . esc_url( $v['website'] ) . '" target="_blank" rel="noopener">Website</a>';
			}
			if ( ! empty( $v['maps_url'] ) ) {
				echo '<a class="button button-small" href="' . esc_url( $v['maps_url'] ) . '" target="_blank" rel="noopener">Map</a>';
			}
			echo '<button type="button" class="button button-small wmta-copy-draft" data-draft="' . esc_attr( $draft ) . '">Copy message</button>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}
}

new WMTA_Plugin();
