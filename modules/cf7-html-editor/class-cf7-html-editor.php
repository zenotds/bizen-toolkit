<?php
/**
 * CF7 HTML Editor — implementation.
 *
 * Every per-form setting is registered as a Contact Form 7 property, so CF7
 * itself loads, saves and duplicates it. Properties are stored as `_<name>`
 * post meta, which keeps the `_wpcf7_*` keys the vendored CF7 Coder wrote.
 */

defined( 'ABSPATH' ) || exit;

class Bizen_CF7_HTML_Editor {

	/** Hidden field marking a POST that comes from the form editor screen. */
	private const MARKER = 'bizen-cf7-html-editor';

	/** Property => [ POST field, type, default ]. */
	private const SETTINGS = [
		'wpcf7_test_mode'           => [ 'wpcf7-test-mode',          'flag', '' ],
		'wpcf7_remove_auto_tags'    => [ 'wpcf7-remove-auto-tags',   'flag', '' ],
		'wpcf7_codemiror_dark'      => [ 'wpcf7_codemiror_dark',     'flag', '' ],
		'wpcf7_redirect_enabled'    => [ 'wpcf7-redirect-enabled',   'flag', '' ],
		'wpcf7_redirect_url'        => [ 'wpcf7-redirect-url',       'url',  '' ],
		'wpcf7_redirect_acf_field'  => [ 'wpcf7-redirect-acf-field', 'text', '' ],
		'wpcf7_redirect_new_tab'    => [ 'wpcf7-redirect-new-tab',   'flag', '' ],
		'wpcf7_redirect_download'   => [ 'wpcf7-redirect-download',  'flag', '' ],
		'wpcf7_hide_form'           => [ 'wpcf7-hide-form',          'flag', '' ],
		'wpcf7_remove_refill'       => [ 'wpcf7-remove-refill',      'flag', '' ],
		'wpcf7_disable_submit'      => [ 'wpcf7-disable-submit',     'flag', '' ],
		'wpcf7_prefill_url'         => [ 'wpcf7-prefill-url',        'flag', '' ],
		'wpcf7_ga_event'            => [ 'wpcf7-ga-event',           'flag', '' ],
		'wpcf7_ga_event_name'       => [ 'wpcf7-ga-event-name',      'text', '' ],
		'wpcf7_scroll_to_message'   => [ 'wpcf7-scroll-to-message',  'flag', '' ],
		'wpcf7_auto_hide_message'   => [ 'wpcf7-auto-hide-message',  'flag', '' ],
		'wpcf7_auto_hide_timeout'   => [ 'wpcf7-auto-hide-timeout',  'secs', 5 ],
	];

	/** Contact form handed over by the shortcode callback, consumed by do_shortcode_tag. */
	private ?WPCF7_ContactForm $rendered_form = null;

	private bool $assets_enqueued = false;

	public function __construct() {
		add_action( 'admin_enqueue_scripts',                       [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wpcf7_admin_misc_pub_section',                [ $this, 'render_settings' ] );
		add_filter( 'wpcf7_pre_construct_contact_form_properties', [ $this, 'register_properties' ] );
		add_action( 'wpcf7_save_contact_form',                     [ $this, 'save' ], 10, 3 );
		add_filter( 'wpcf7_autop_or_not',                          [ $this, 'autop_or_not' ], 10, 2 );
		add_action( 'wpcf7_shortcode_callback',                    [ $this, 'capture_rendered_form' ] );
		add_filter( 'do_shortcode_tag',                            [ $this, 'filter_shortcode_output' ], 10, 2 );

		if ( get_option( 'wpcf7_load_assets_shortcode' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_cf7_assets' ], 100 );
		}
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * The edit screen is a submenu page since CF7 6.2 (the top-level slug became
	 * wpcf7-dashboard), so its hook went from toplevel_page_wpcf7 to
	 * <menu>_page_wpcf7 — and <menu> is the translated menu title. Match the
	 * page slug at the end of the hook instead of the whole string.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( ! preg_match( '/(?:^toplevel|_page)_wpcf7(-new)?$/', $hook, $m ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check
		if ( empty( $m[1] ) && empty( $_GET['post'] ) ) {
			return; // Contact form list, not the editor.
		}

		$ver  = BIZEN_TOOLKIT_VERSION;
		$base = plugin_dir_url( __FILE__ ) . 'assets/';

		// False when the user turned syntax highlighting off in their profile.
		$code_editor = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );

		wp_enqueue_style( 'bizen-cf7-html-editor',          $base . 'style.css',    [], $ver );
		wp_enqueue_style( 'bizen-cf7-html-editor-material', $base . 'material.css', [], $ver );
		wp_enqueue_script(
			'bizen-cf7-html-editor',
			$base . 'script.js',
			false === $code_editor ? [ 'jquery' ] : [ 'jquery', 'underscore', 'code-editor' ],
			$ver,
			true
		);
		wp_add_inline_script(
			'bizen-cf7-html-editor',
			'var bizenCf7HtmlEditor = ' . wp_json_encode( [ 'codeEditor' => false !== $code_editor ] ) . ';',
			'before'
		);
	}

	public function render_settings( $post_id = -1 ): void {
		$form = (int) $post_id > 0 ? wpcf7_contact_form( (int) $post_id ) : null;
		$get  = static fn( string $prop ) => $form ? $form->prop( $prop ) : self::SETTINGS[ $prop ][2];

		$redirect_enabled = $get( 'wpcf7_redirect_enabled' );
		$ga_event         = $get( 'wpcf7_ga_event' );
		$auto_hide        = $get( 'wpcf7_auto_hide_message' );
		?>

		<input type="hidden" name="<?php echo esc_attr( self::MARKER ); ?>" value="1">

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-test-mode" <?php checked( $get( 'wpcf7_test_mode' ) ); ?>>
				<?php esc_html_e( 'Test Mode', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip" data-tooltip="The Form will only be displayed for administrators.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-remove-auto-tags" <?php checked( $get( 'wpcf7_remove_auto_tags' ) ); ?>>
				<?php esc_html_e( 'Remove Auto tags p and br', 'bizen-toolkit' ); ?>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_codemiror_dark" name="wpcf7_codemiror_dark" <?php checked( $get( 'wpcf7_codemiror_dark' ) ); ?>>
				<?php esc_html_e( 'Enable dark theme (Material)', 'bizen-toolkit' ); ?>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7_load_assets_shortcode" <?php checked( get_option( 'wpcf7_load_assets_shortcode' ) ); ?>>
				<?php esc_html_e( 'Load scripts only on pages with shortcode', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="CF7 scripts and styles will only be loaded on pages containing the contact form shortcode.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_redirect_enabled" name="wpcf7-redirect-enabled" <?php checked( $redirect_enabled ); ?>>
				<?php esc_html_e( 'Redirect after submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Redirect to a URL after successful form submission.">ℹ</sup>
			</label>
			<div id="wpcf7-redirect-url-wrap" style="margin-top:8px;<?php echo $redirect_enabled ? '' : 'display:none;'; ?>">
				<input type="url" name="wpcf7-redirect-url" value="<?php echo esc_attr( $get( 'wpcf7_redirect_url' ) ); ?>"
				       placeholder="https://example.com/thank-you" style="width:100%;">
				<?php if ( class_exists( 'ACF' ) ) : ?>
					<input type="text" name="wpcf7-redirect-acf-field" value="<?php echo esc_attr( $get( 'wpcf7_redirect_acf_field' ) ); ?>"
					       placeholder="ACF field name" style="width:100%;margin-top:5px;">
					<small style="color:#666;">ACF field from current page (overrides URL above)</small>
				<?php endif; ?>
				<label style="display:block;margin-top:8px;">
					<input type="checkbox" name="wpcf7-redirect-new-tab" value="1" <?php checked( $get( 'wpcf7_redirect_new_tab' ) ); ?>>
					<?php esc_html_e( 'Open in new tab', 'bizen-toolkit' ); ?>
				</label>
				<label style="display:block;margin-top:4px;">
					<input type="checkbox" name="wpcf7-redirect-download" value="1" <?php checked( $get( 'wpcf7_redirect_download' ) ); ?>>
					<?php esc_html_e( 'Force download', 'bizen-toolkit' ); ?>
				</label>
			</div>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-hide-form" <?php checked( $get( 'wpcf7_hide_form' ) ); ?>>
				<?php esc_html_e( 'Hide form after submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Hide the form after successful submission, show only the success message.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-remove-refill" <?php checked( $get( 'wpcf7_remove_refill' ) ); ?>>
				<?php esc_html_e( 'Remove refill', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Clear form fields after validation error instead of keeping entered values.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-disable-submit" <?php checked( $get( 'wpcf7_disable_submit' ) ); ?>>
				<?php esc_html_e( 'Disable submit button while sending', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Disable submit button during form submission to prevent double submissions.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-prefill-url" <?php checked( $get( 'wpcf7_prefill_url' ) ); ?>>
				<?php esc_html_e( 'Pre-fill fields from URL', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Fill form fields from URL parameters (e.g., ?your-email=test@example.com).">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_ga_event" name="wpcf7-ga-event" <?php checked( $ga_event ); ?>>
				<?php esc_html_e( 'GA/GTM Event on submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Send event to Google Analytics/GTM dataLayer on successful submission.">ℹ</sup>
			</label>
			<div id="wpcf7-ga-event-wrap" style="margin-top:8px;<?php echo $ga_event ? '' : 'display:none;'; ?>">
				<input type="text" name="wpcf7-ga-event-name" value="<?php echo esc_attr( $get( 'wpcf7_ga_event_name' ) ); ?>"
				       placeholder="cf7_form_submit" style="width:100%;">
				<small style="color:#666;">Event name for dataLayer</small>
			</div>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-scroll-to-message" <?php checked( $get( 'wpcf7_scroll_to_message' ) ); ?>>
				<?php esc_html_e( 'Scroll to message after submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Automatically scroll to success/error message after form submission.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_auto_hide_message" name="wpcf7-auto-hide-message" <?php checked( $auto_hide ); ?>>
				<?php esc_html_e( 'Auto-hide success message', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Automatically hide success message after specified seconds.">ℹ</sup>
			</label>
			<div id="wpcf7-auto-hide-wrap" style="margin-top:8px;<?php echo $auto_hide ? '' : 'display:none;'; ?>">
				<input type="number" name="wpcf7-auto-hide-timeout" value="<?php echo esc_attr( $get( 'wpcf7_auto_hide_timeout' ) ?: 5 ); ?>"
				       min="1" max="60" style="width:60px;"> <?php esc_html_e( 'seconds', 'bizen-toolkit' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Registered before construction rather than through wpcf7_contact_form_properties:
	 * only properties known at that point are read back from post meta, and only
	 * those survive a save that does not come from the editor (REST, duplicate).
	 */
	public function register_properties( array $properties ): array {
		foreach ( self::SETTINGS as $prop => [ , , $default ] ) {
			$properties[ $prop ] = $default;
		}
		return $properties;
	}

	/**
	 * wpcf7_save_contact_form also fires for REST saves and for the editor's
	 * live config check (context "dry-run", posting only the mail/form fields).
	 * Only the editor form carries the marker, so anything else keeps the stored
	 * values instead of having every unchecked box reset them.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by CF7
	public function save( $contact_form, $data = [], $context = 'save' ): void {
		if ( 'save' !== $context || empty( $_POST[ self::MARKER ] ) ) {
			return;
		}

		$properties = [];

		foreach ( self::SETTINGS as $prop => [ $field, $type ] ) {
			$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';

			$properties[ $prop ] = match ( $type ) {
				'flag'  => '' !== $raw ? '1' : '',
				'url'   => esc_url_raw( (string) $raw ),
				'secs'  => max( 1, min( 60, absint( $raw ) ?: 5 ) ),
				default => sanitize_text_field( (string) $raw ),
			};
		}

		$contact_form->set_properties( $properties );

		// Site-wide option, even though it is offered on every form's screen.
		update_option( 'wpcf7_load_assets_shortcode', isset( $_POST['wpcf7_load_assets_shortcode'] ) ? '1' : '' );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/* -------------------------------------------------------------- front end */

	/**
	 * Turns off wpautop for the form template only; mail bodies keep CF7's default.
	 * Unlike stripping tags from the rendered HTML, this leaves explicit <p>/<br>
	 * in the template and CF7's own markup (screen-reader response) intact.
	 */
	public function autop_or_not( $autop, $options = '' ) {
		if ( is_array( $options ) && isset( $options['for'] ) && 'form' !== $options['for'] ) {
			return $autop;
		}

		$form = wpcf7_get_current_contact_form();

		return $form && $form->prop( 'wpcf7_remove_auto_tags' ) ? false : $autop;
	}

	/** Fires once CF7 has resolved the form, whichever id, hash or title the shortcode used. */
	public function capture_rendered_form( $contact_form ): void {
		$this->rendered_form = $contact_form instanceof WPCF7_ContactForm ? $contact_form : null;
	}

	public function filter_shortcode_output( $output, string $tag ) {
		if ( 'contact-form-7' !== $tag && 'contact-form' !== $tag ) {
			return $output;
		}

		if ( get_option( 'wpcf7_load_assets_shortcode' ) ) {
			$this->enqueue_cf7_assets();
		}

		$form                = $this->rendered_form;
		$this->rendered_form = null;

		if ( ! $form || ! is_string( $output ) ) {
			return $output;
		}

		if ( $form->prop( 'wpcf7_test_mode' ) && ! current_user_can( 'administrator' ) ) {
			return '';
		}

		$id = (int) $form->id();

		$output .= $this->render_redirect_script( $form, $id );
		$output .= $this->render_hide_form_script( $form, $id );
		$output .= $this->render_remove_refill_script( $form, $id );
		$output .= $this->render_disable_submit_script( $form, $id );
		$output .= $this->render_prefill_script( $form, $id );
		$output .= $this->render_ga_event_script( $form, $id );
		$output .= $this->render_scroll_script( $form, $id );
		$output .= $this->render_auto_hide_script( $form, $id );

		return $output;
	}

	private function render_redirect_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_redirect_enabled' ) ) {
			return '';
		}

		$redirect_url   = '';
		$acf_field_name = $form->prop( 'wpcf7_redirect_acf_field' );

		if ( $acf_field_name && function_exists( 'get_field' ) ) {
			$redirect_url = (string) get_field( $acf_field_name, get_the_ID() );
		}

		// An empty ACF field on this page falls back to the URL set on the form.
		if ( ! $redirect_url ) {
			$redirect_url = (string) $form->prop( 'wpcf7_redirect_url' );
		}

		$redirect_url = esc_url_raw( $redirect_url );

		if ( ! $redirect_url ) {
			return '';
		}

		// JSON, not esc_url(): inside <script> the &#038; it emits is not decoded.
		$url = wp_json_encode( $redirect_url, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES );

		if ( $form->prop( 'wpcf7_redirect_download' ) ) {
			$action = 'var l=document.createElement("a");l.href=' . $url . ';l.download="";document.body.appendChild(l);l.click();document.body.removeChild(l);';
		} elseif ( $form->prop( 'wpcf7_redirect_new_tab' ) ) {
			$action = 'window.open(' . $url . ',"_blank");';
		} else {
			$action = 'window.location.href=' . $url . ';';
		}

		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . $id . '){' . $action . '}});</script>';
	}

	private function render_hide_form_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_hide_form' ) ) {
			return '';
		}
		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . $id . '){var w=e.target.closest(".wpcf7");if(w){var f=w.querySelector("form");if(f){Array.from(f.children).forEach(function(c){if(!c.classList.contains("wpcf7-response-output"))c.style.display="none";});}}}});</script>';
	}

	private function render_remove_refill_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_remove_refill' ) ) {
			return '';
		}
		return '<script>document.addEventListener("wpcf7invalid",function(e){if(e.detail.contactFormId==' . $id . '){var w=e.target.closest(".wpcf7");if(w){var f=w.querySelector("form");if(f)f.reset();}}});</script>';
	}

	private function render_disable_submit_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_disable_submit' ) ) {
			return '';
		}
		$sending = esc_js( __( 'Sending...', 'bizen-toolkit' ) );
		// wpcf7submit follows every server response, whatever its status (spam included).
		return <<<JS
<script>(function(){var fId={$id};function getBtn(e){var w=e.target.closest(".wpcf7");return w?w.querySelector("input[type=submit],button[type=submit]"):null;}function restore(btn){if(!btn||!btn.disabled)return;btn.disabled=false;btn.tagName==="INPUT"?btn.value=btn.dataset.originalValue:btn.textContent=btn.dataset.originalValue;}document.addEventListener("wpcf7beforesubmit",function(e){if(e.detail.contactFormId!=fId)return;var btn=getBtn(e);if(!btn)return;btn.disabled=true;btn.dataset.originalValue=btn.tagName==="INPUT"?btn.value:btn.textContent;btn.tagName==="INPUT"?btn.value="{$sending}":btn.textContent="{$sending}";});document.addEventListener("wpcf7submit",function(e){if(e.detail.contactFormId==fId)restore(getBtn(e));});})()</script>
JS;
	}

	private function render_prefill_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_prefill_url' ) ) {
			return '';
		}
		// Checkbox groups are posted as name[], so both spellings are looked up.
		// Hidden fields are filled too (UTM tracking), except CF7's own _wpcf7*.
		return '<script>(function(){var w=document.querySelectorAll(".wpcf7[data-wpcf7-id=\"' . $id . '\"]");if(!w.length)return;w=w[w.length-1];var p=new URLSearchParams(window.location.search);p.forEach(function(v,k){var n=CSS.escape(k);w.querySelectorAll("[name=\""+n+"\"],[name=\""+n+"[]\"]").forEach(function(f){if(f.type==="checkbox"||f.type==="radio"){if(f.value===v||(f.type==="checkbox"&&(v==="1"||v==="true")))f.checked=true;}else if(f.tagName==="SELECT"){if(Array.from(f.options).some(function(o){return o.value===v;}))f.value=v;}else if(f.type!=="file"&&f.name.indexOf("_wpcf7")!==0){f.value=v;}});});})();</script>';
	}

	private function render_ga_event_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_ga_event' ) ) {
			return '';
		}
		$event_name = $form->prop( 'wpcf7_ga_event_name' ) ?: 'cf7_form_submit';
		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . $id . '&&typeof dataLayer!=="undefined"){dataLayer.push({"event":"' . esc_js( $event_name ) . '","cf7FormId":' . $id . ',"cf7FormTitle":"' . esc_js( $form->title() ) . '"});}});</script>';
	}

	private function render_scroll_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_scroll_to_message' ) ) {
			return '';
		}
		return '<script>(function(){var fId=' . $id . ';function scroll(e){if(e.detail.contactFormId!=fId)return;var w=e.target.closest(".wpcf7");if(w){var m=w.querySelector(".wpcf7-response-output");if(m)m.scrollIntoView({behavior:"smooth",block:"center"});}}["wpcf7mailsent","wpcf7mailfailed","wpcf7invalid"].forEach(function(ev){document.addEventListener(ev,scroll);});})();</script>';
	}

	private function render_auto_hide_script( WPCF7_ContactForm $form, int $id ): string {
		if ( ! $form->prop( 'wpcf7_auto_hide_message' ) ) {
			return '';
		}
		$ms = max( 1, (int) ( $form->prop( 'wpcf7_auto_hide_timeout' ) ?: 5 ) ) * 1000;
		// The hidden message is shown again on the next submit, or later responses would stay invisible.
		return '<script>(function(){var fId=' . $id . ',t;function msg(e){var w=e.target.closest(".wpcf7");return w?w.querySelector(".wpcf7-response-output"):null;}document.addEventListener("wpcf7beforesubmit",function(e){if(e.detail.contactFormId!=fId)return;clearTimeout(t);var m=msg(e);if(m){m.style.display="";m.style.opacity="";}});document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId!=fId)return;var m=msg(e);if(!m)return;clearTimeout(t);t=setTimeout(function(){m.style.transition="opacity 0.5s";m.style.opacity="0";t=setTimeout(function(){m.style.display="none";m.style.opacity="";},500);},' . $ms . ');});})();</script>';
	}

	/* ----------------------------------------------- conditional asset loading */

	public function enqueue_cf7_assets(): void {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;

		if ( function_exists( 'wpcf7_enqueue_scripts' ) ) {
			wpcf7_enqueue_scripts();
		}
		if ( function_exists( 'wpcf7_enqueue_styles' ) ) {
			wpcf7_enqueue_styles();
		}
		if ( function_exists( 'wpcf7_recaptcha_enqueue_scripts' ) ) {
			wpcf7_recaptcha_enqueue_scripts();
		}
	}

	public function dequeue_cf7_assets(): void {
		wp_dequeue_script( 'contact-form-7' );
		wp_dequeue_style( 'contact-form-7' );
		wp_dequeue_script( 'wpcf7-recaptcha' );
		wp_dequeue_script( 'google-recaptcha' );
	}
}
