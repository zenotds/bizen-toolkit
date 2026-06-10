<?php
/**
 * Adapted from cf7-coder v1.0.1 by Wow-Company (GPL-2.0+)
 * Source: https://wordpress.org/plugins/cf7-coder/
 *
 * Class renamed CF7_Coder_Bizen to avoid conflicts if the original plugin
 * is installed alongside Bizen Toolkit.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'CF7_Coder_Bizen' ) ) {
	return;
}

class CF7_Coder_Bizen {

	public function __construct() {
		add_action( 'admin_enqueue_scripts',        [ $this, 'style_script' ] );
		add_action( 'wpcf7_admin_misc_pub_section', [ $this, 'wpcf7_add_test_mode' ] );
		add_filter( 'wpcf7_contact_form_properties', [ $this, 'wpcf7_add_properties' ] );
		add_action( 'wpcf7_save_contact_form',      [ $this, 'wpcf7_save' ] );
		add_filter( 'do_shortcode_tag',             [ $this, 'wpcf7_frontend' ], 10, 4 );

		if ( get_option( 'wpcf7_load_assets_shortcode' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_cf7_assets' ], 100 );
		}
	}

	public function style_script( $hook ): void {
		if ( 'toplevel_page_wpcf7' !== $hook && 'contact_page_wpcf7-new' !== $hook ) {
			return;
		}

		$ver  = '1.1';
		$base = plugin_dir_url( __FILE__ ) . 'assets/';

		wp_enqueue_script( 'code-editor' );
		wp_enqueue_style( 'code-editor' );
		wp_enqueue_script( 'htmlhint' );
		wp_enqueue_style( 'bizen-cf7-coder',          $base . 'style.css',   [], $ver );
		wp_enqueue_style( 'bizen-cf7-coder-material',  $base . 'material.css', [], $ver );
		wp_enqueue_script( 'bizen-cf7-coder',          $base . 'script.js',   [ 'jquery' ], $ver, false );
	}

	public function wpcf7_add_test_mode(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by CF7
		$post_id          = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : -1;
		$redirect_enabled = get_post_meta( $post_id, '_wpcf7_redirect_enabled', true );
		$redirect_url     = get_post_meta( $post_id, '_wpcf7_redirect_url', true );
		$redirect_acf     = get_post_meta( $post_id, '_wpcf7_redirect_acf_field', true );
		$redirect_new_tab = get_post_meta( $post_id, '_wpcf7_redirect_new_tab', true );
		$redirect_dl      = get_post_meta( $post_id, '_wpcf7_redirect_download', true );
		$hide_form        = get_post_meta( $post_id, '_wpcf7_hide_form', true );
		$remove_refill    = get_post_meta( $post_id, '_wpcf7_remove_refill', true );
		$disable_submit   = get_post_meta( $post_id, '_wpcf7_disable_submit', true );
		$prefill_url      = get_post_meta( $post_id, '_wpcf7_prefill_url', true );
		$ga_event         = get_post_meta( $post_id, '_wpcf7_ga_event', true );
		$ga_event_name    = get_post_meta( $post_id, '_wpcf7_ga_event_name', true );
		$scroll           = get_post_meta( $post_id, '_wpcf7_scroll_to_message', true );
		$auto_hide        = get_post_meta( $post_id, '_wpcf7_auto_hide_message', true );
		$auto_hide_t      = get_post_meta( $post_id, '_wpcf7_auto_hide_timeout', true ) ?: 5;
		?>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-test-mode" <?php checked( get_post_meta( $post_id, '_wpcf7_test_mode', true ) ); ?>>
				<?php esc_html_e( 'Test Mode', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip" data-tooltip="The Form will only be displayed for administrators.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-remove-auto-tags" <?php checked( get_post_meta( $post_id, '_wpcf7_remove_auto_tags', true ) ); ?>>
				<?php esc_html_e( 'Remove Auto tags p and br', 'bizen-toolkit' ); ?>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_codemiror_dark" name="wpcf7_codemiror_dark" <?php checked( get_post_meta( $post_id, '_wpcf7_codemiror_dark', true ) ); ?>>
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
				<input type="url" name="wpcf7-redirect-url" value="<?php echo esc_attr( $redirect_url ); ?>"
				       placeholder="https://example.com/thank-you" style="width:100%;">
				<?php if ( class_exists( 'ACF' ) ) : ?>
					<input type="text" name="wpcf7-redirect-acf-field" value="<?php echo esc_attr( $redirect_acf ); ?>"
					       placeholder="ACF field name" style="width:100%;margin-top:5px;">
					<small style="color:#666;">ACF field from current page (overrides URL above)</small>
				<?php endif; ?>
				<label style="display:block;margin-top:8px;">
					<input type="checkbox" name="wpcf7-redirect-new-tab" value="1" <?php checked( $redirect_new_tab ); ?>>
					<?php esc_html_e( 'Open in new tab', 'bizen-toolkit' ); ?>
				</label>
				<label style="display:block;margin-top:4px;">
					<input type="checkbox" name="wpcf7-redirect-download" value="1" <?php checked( $redirect_dl ); ?>>
					<?php esc_html_e( 'Force download', 'bizen-toolkit' ); ?>
				</label>
			</div>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-hide-form" <?php checked( $hide_form ); ?>>
				<?php esc_html_e( 'Hide form after submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Hide the form after successful submission, show only the success message.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-remove-refill" <?php checked( $remove_refill ); ?>>
				<?php esc_html_e( 'Remove refill', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Clear form fields after validation error instead of keeping entered values.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-disable-submit" <?php checked( $disable_submit ); ?>>
				<?php esc_html_e( 'Disable submit button while sending', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Disable submit button during form submission to prevent double submissions.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-prefill-url" <?php checked( $prefill_url ); ?>>
				<?php esc_html_e( 'Pre-fill fields from URL', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Fill form fields from URL parameters (e.g., ?your-email=test@example.com).">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_ga_event" name="wpcf7-ga-event" <?php checked( $ga_event ); ?>>
				<?php esc_html_e( 'GA/GTM Event on submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Send event to Google Analytics/GTM dataLayer on successful submission.">ℹ</sup>
			</label>
			<div id="wpcf7-ga-event-wrap" style="margin-top:8px;<?php echo ! empty( $ga_event ) ? '' : 'display:none;'; ?>">
				<input type="text" name="wpcf7-ga-event-name" value="<?php echo esc_attr( $ga_event_name ); ?>"
				       placeholder="cf7_form_submit" style="width:100%;">
				<small style="color:#666;">Event name for dataLayer</small>
			</div>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" name="wpcf7-scroll-to-message" <?php checked( $scroll ); ?>>
				<?php esc_html_e( 'Scroll to message after submit', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Automatically scroll to success/error message after form submission.">ℹ</sup>
			</label>
		</div>

		<div class="misc-pub-section">
			<label><input value="1" type="checkbox" id="wpcf7_auto_hide_message" name="wpcf7-auto-hide-message" <?php checked( $auto_hide ); ?>>
				<?php esc_html_e( 'Auto-hide success message', 'bizen-toolkit' ); ?>
				<sup class="has-tooltip on-left" data-tooltip="Automatically hide success message after specified seconds.">ℹ</sup>
			</label>
			<div id="wpcf7-auto-hide-wrap" style="margin-top:8px;<?php echo ! empty( $auto_hide ) ? '' : 'display:none;'; ?>">
				<input type="number" name="wpcf7-auto-hide-timeout" value="<?php echo esc_attr( $auto_hide_t ); ?>"
				       min="1" max="60" style="width:60px;"> <?php esc_html_e( 'seconds', 'bizen-toolkit' ); ?>
			</div>
		</div>
		<?php
	}

	public function wpcf7_add_properties( array $properties ): array {
		return array_merge(
			[
				'wpcf7_test_mode'        => '',
				'wpcf7_remove_auto_tags' => '',
				'wpcf7_codemiror_dark'   => '',
			],
			$properties
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by CF7
	public function wpcf7_save( $contact_form ): void {
		$post_id    = $contact_form->id();
		$properties = $contact_form->get_properties();

		$properties['wpcf7_test_mode']        = isset( $_POST['wpcf7-test-mode'] ) ? '1' : '';
		$properties['wpcf7_remove_auto_tags'] = isset( $_POST['wpcf7-remove-auto-tags'] ) ? '1' : '';
		$properties['wpcf7_codemiror_dark']   = isset( $_POST['wpcf7_codemiror_dark'] ) ? '1' : '';

		$contact_form->set_properties( $properties );

		update_option( 'wpcf7_load_assets_shortcode', isset( $_POST['wpcf7_load_assets_shortcode'] ) ? '1' : '' );

		$fields = [
			'_wpcf7_redirect_enabled'   => [ 'wpcf7-redirect-enabled',   'flag' ],
			'_wpcf7_redirect_url'       => [ 'wpcf7-redirect-url',        'url' ],
			'_wpcf7_redirect_acf_field' => [ 'wpcf7-redirect-acf-field',  'text' ],
			'_wpcf7_redirect_new_tab'   => [ 'wpcf7-redirect-new-tab',    'flag' ],
			'_wpcf7_redirect_download'  => [ 'wpcf7-redirect-download',   'flag' ],
			'_wpcf7_hide_form'          => [ 'wpcf7-hide-form',           'flag' ],
			'_wpcf7_remove_refill'      => [ 'wpcf7-remove-refill',       'flag' ],
			'_wpcf7_disable_submit'     => [ 'wpcf7-disable-submit',      'flag' ],
			'_wpcf7_prefill_url'        => [ 'wpcf7-prefill-url',         'flag' ],
			'_wpcf7_ga_event'           => [ 'wpcf7-ga-event',            'flag' ],
			'_wpcf7_ga_event_name'      => [ 'wpcf7-ga-event-name',       'text' ],
			'_wpcf7_scroll_to_message'  => [ 'wpcf7-scroll-to-message',   'flag' ],
			'_wpcf7_auto_hide_message'  => [ 'wpcf7-auto-hide-message',   'flag' ],
		];

		foreach ( $fields as $meta_key => [ $post_key, $type ] ) {
			if ( 'flag' === $type ) {
				update_post_meta( $post_id, $meta_key, isset( $_POST[ $post_key ] ) ? '1' : '' );
			} elseif ( 'url' === $type ) {
				update_post_meta( $post_id, $meta_key, isset( $_POST[ $post_key ] ) ? esc_url_raw( wp_unslash( $_POST[ $post_key ] ) ) : '' );
			} else {
				update_post_meta( $post_id, $meta_key, isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '' );
			}
		}

		$timeout = isset( $_POST['wpcf7-auto-hide-timeout'] ) ? absint( $_POST['wpcf7-auto-hide-timeout'] ) : 5;
		update_post_meta( $post_id, '_wpcf7_auto_hide_timeout', max( 1, min( 60, $timeout ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public function wpcf7_frontend( string $output, string $tag, $atts, $m ): string {
		if ( 'contact-form-7' !== $tag ) {
			return $output;
		}

		if ( get_option( 'wpcf7_load_assets_shortcode' ) ) {
			$this->enqueue_cf7_assets();
		}

		if ( ! function_exists( 'wpcf7_get_contact_form_by_hash' ) ) {
			return $output;
		}

		$form = wpcf7_get_contact_form_by_hash( $atts['id'] );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			return $output;
		}

		$id = $form->id();

		if ( get_post_meta( $id, '_wpcf7_remove_auto_tags', true ) ) {
			$output = str_replace( [ '<p>', '</p>', '<br>', '<br/>', '<br />' ], '', $output );
		}

		if ( get_post_meta( $id, '_wpcf7_test_mode', true ) && ! current_user_can( 'administrator' ) ) {
			return '';
		}

		$output .= $this->render_redirect_script( $id );
		$output .= $this->render_hide_form_script( $id );
		$output .= $this->render_remove_refill_script( $id );
		$output .= $this->render_disable_submit_script( $id );
		$output .= $this->render_prefill_script( $id );
		$output .= $this->render_ga_event_script( $id, $form );
		$output .= $this->render_scroll_script( $id );
		$output .= $this->render_auto_hide_script( $id );

		return $output;
	}

	private function render_redirect_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_redirect_enabled', true ) ) {
			return '';
		}

		$redirect_url   = '';
		$acf_field_name = get_post_meta( $id, '_wpcf7_redirect_acf_field', true );

		if ( $acf_field_name && function_exists( 'get_field' ) ) {
			$acf_url = get_field( $acf_field_name, get_the_ID() );
			if ( $acf_url ) {
				$redirect_url = $acf_url;
			}
		}

		if ( ! $redirect_url && ! $acf_field_name ) {
			$redirect_url = get_post_meta( $id, '_wpcf7_redirect_url', true );
		}

		if ( ! $redirect_url ) {
			return '';
		}

		$new_tab  = get_post_meta( $id, '_wpcf7_redirect_new_tab', true );
		$download = get_post_meta( $id, '_wpcf7_redirect_download', true );

		if ( $download ) {
			$action = 'var l=document.createElement("a");l.href="' . esc_url( $redirect_url ) . '";l.download="";document.body.appendChild(l);l.click();document.body.removeChild(l);';
		} elseif ( $new_tab ) {
			$action = 'window.open("' . esc_url( $redirect_url ) . '","_blank");';
		} else {
			$action = 'window.location.href="' . esc_url( $redirect_url ) . '";';
		}

		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . (int) $id . '){' . $action . '}});</script>';
	}

	private function render_hide_form_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_hide_form', true ) ) {
			return '';
		}
		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . (int) $id . '){var w=e.target.closest(".wpcf7");if(w){var f=w.querySelector("form");if(f){Array.from(f.children).forEach(function(c){if(!c.classList.contains("wpcf7-response-output"))c.style.display="none";});}}}});</script>';
	}

	private function render_remove_refill_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_remove_refill', true ) ) {
			return '';
		}
		return '<script>document.addEventListener("wpcf7invalid",function(e){if(e.detail.contactFormId==' . (int) $id . '){var w=e.target.closest(".wpcf7");if(w){var f=w.querySelector("form");if(f)f.reset();}}});</script>';
	}

	private function render_disable_submit_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_disable_submit', true ) ) {
			return '';
		}
		$sending = esc_js( __( 'Sending...', 'bizen-toolkit' ) );
		return <<<JS
<script>(function(){var fId={$id};function getBtn(e){var w=e.target.closest(".wpcf7");return w?w.querySelector("input[type=submit],button[type=submit]"):null;}function restore(btn){if(!btn)return;btn.disabled=false;btn.tagName==="INPUT"?btn.value=btn.dataset.originalValue:btn.textContent=btn.dataset.originalValue;}document.addEventListener("wpcf7beforesubmit",function(e){if(e.detail.contactFormId!=fId)return;var btn=getBtn(e);if(!btn)return;btn.disabled=true;btn.dataset.originalValue=btn.value||btn.textContent;btn.tagName==="INPUT"?btn.value="{$sending}":btn.textContent="{$sending}";});["wpcf7mailsent","wpcf7mailfailed","wpcf7invalid"].forEach(function(ev){document.addEventListener(ev,function(e){if(e.detail.contactFormId==fId)restore(getBtn(e));});});})()</script>
JS;
	}

	private function render_prefill_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_prefill_url', true ) ) {
			return '';
		}
		return '<script>(function(){var w=document.querySelector(".wpcf7[data-wpcf7-id=\"' . (int) $id . '\"]");if(!w)return;var p=new URLSearchParams(window.location.search);p.forEach(function(v,k){var f=w.querySelector("[name=\""+k+"\"]");if(!f)return;if(f.type==="checkbox"||f.type==="radio"){if(f.value===v||v==="1"||v==="true")f.checked=true;}else if(f.tagName==="SELECT"){var o=f.querySelector("option[value=\""+v+"\"]");if(o)f.value=v;}else{f.value=v;}});})();</script>';
	}

	private function render_ga_event_script( int $id, WPCF7_ContactForm $form ): string {
		if ( ! get_post_meta( $id, '_wpcf7_ga_event', true ) ) {
			return '';
		}
		$event_name = get_post_meta( $id, '_wpcf7_ga_event_name', true ) ?: 'cf7_form_submit';
		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . (int) $id . '&&typeof dataLayer!=="undefined"){dataLayer.push({"event":"' . esc_js( $event_name ) . '","cf7FormId":' . (int) $id . ',"cf7FormTitle":"' . esc_js( $form->title() ) . '"});}});</script>';
	}

	private function render_scroll_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_scroll_to_message', true ) ) {
			return '';
		}
		return '<script>(function(){var fId=' . (int) $id . ';function scroll(e){if(e.detail.contactFormId!=fId)return;var w=e.target.closest(".wpcf7");if(w){var m=w.querySelector(".wpcf7-response-output");if(m)m.scrollIntoView({behavior:"smooth",block:"center"});}}["wpcf7mailsent","wpcf7mailfailed","wpcf7invalid"].forEach(function(ev){document.addEventListener(ev,scroll);});})();</script>';
	}

	private function render_auto_hide_script( int $id ): string {
		if ( ! get_post_meta( $id, '_wpcf7_auto_hide_message', true ) ) {
			return '';
		}
		$timeout = (int) ( get_post_meta( $id, '_wpcf7_auto_hide_timeout', true ) ?: 5 );
		$ms      = $timeout * 1000;
		return '<script>document.addEventListener("wpcf7mailsent",function(e){if(e.detail.contactFormId==' . (int) $id . '){var w=e.target.closest(".wpcf7");if(w){var m=w.querySelector(".wpcf7-response-output");if(m){setTimeout(function(){m.style.transition="opacity 0.5s";m.style.opacity="0";setTimeout(function(){m.style.display="none";m.style.opacity="1";},500);},' . $ms . ');}}}});</script>';
	}

	public function enqueue_cf7_assets(): void {
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
