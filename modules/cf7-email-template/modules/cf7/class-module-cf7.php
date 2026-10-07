<?php

/**
 * CF7HETE_Module_Cf7
 * Class responsible to manage all CF7 stuff
 *
 * Depends: dependence
 *
 * @package         Cf7_Html_Email_Template_Extension
 * @subpackage      CF7HETE_Module_Cf7
 * @since           1.0.0
 *
 */

// If this file is called directly, call the cops.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

if ( ! class_exists( 'CF7HETE_Module_Cf7' ) ) {

    class CF7HETE_Module_Cf7 extends CF7HETE_Module_Base {

        /**
         * Metadada slug
         *
         * @since    1.0.0
         * @access   public
         * @var      string
         */
        const METADATA = 'html_template';

        /**
         * Run
         *
         * @since    1.0.0
         */
        public function run() {
            $module = $this->core->get_module( 'dependence' );

            // Checking Dependences
            $module->add_dependence( 'contact-form-7/wp-contact-form-7.php', 'Contact Form 7', 'contact-form-7' );
        }

        /**
         * Define hooks
         *
         * @since    1.0.0
         * @param    Cf7_Html_Email_Template_Extension      $core   The Core object
         */
        public function define_hooks() {
            $this->core->add_action( 'admin_init', array( $this, 'admin_init' ) );
            $this->core->add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
            $this->core->add_action( 'wpcf7_save_contact_form', array( $this, 'wpcf7_save_contact_form' ) );
            $this->core->add_action( 'wpcf7_admin_misc_pub_section', array( $this, 'wpcf7_admin_misc_pub_section' ) );

            $this->core->add_filter( 'wpcf7_editor_panels', array( $this, 'wpcf7_editor_panels' ) );
            $this->core->add_filter( 'wpcf7_contact_form_properties', array( $this, 'wpcf7_contact_form_properties' ), 10, 2 );
            $this->core->add_filter( 'wpcf7_pre_construct_contact_form_properties', array( $this, 'wpcf7_contact_form_properties' ), 10, 2 );
            $this->core->add_filter( 'wpcf7_mail_components', array( $this, 'wpcf7_mail_components' ), 20, 3 );
        }

        /**
         * Action: 'admin_init'
         * Run code on admin initialization
         *
         * @return void
         */
        public function admin_init() {
            global $plugin_page;

            if ( empty( $plugin_page ) || $plugin_page !== 'wpcf7' || ! class_exists( 'WPCF7_ContactForm' ) ) {
                return;
            }

            $cf7hete_action = sanitize_text_field( $_GET['cf7hete'] ?? '' );
            if ( $cf7hete_action !== 'preview' ) {
                return;
            }

            // Action: Preview
            $contactform = WPCF7_ContactForm::get_instance( $_GET['post'] ?? '' );
            $this->render_mail_preview( $contactform );
            exit;
        }

        /**
         * Action: 'admin_enqueue_scripts'
         * Add scripts to CF7 panels
         *
         * @return void
         */
        public function admin_enqueue_scripts() {
            /**
             * Filter: cf7hete_disable_ace_editor
             *
             * You can return true to avoid add Ace Editor scripts and styles
             *
             * @since 2.1.0
             */
            if ( apply_filters( 'cf7hete_disable_ace_editor', false ) ) {
                return;
            }

            // Make sure we're on the CF7 form editor: "Add Contact Form" (wpcf7-new)
            // or an existing form (wpcf7 + post). Bizen: the form list shares the
            // wpcf7 slug and has no template panel, so Ace threw there.
            $page = sanitize_key( $_GET['page'] ?? '' );
            if ( $page !== 'wpcf7-new' && ( $page !== 'wpcf7' || empty( $_GET['post'] ) ) ) {
                return;
            }

            wp_enqueue_script( 'cf7hete-ace-editor-script', CF7HETE_PLUGIN_URL . '/modules/cf7/includes/assets/ace-editor/ace.js', array('jquery'), CF7HETE_VERSION, true );
            wp_enqueue_script( 'cf7hete-script', CF7HETE_PLUGIN_URL . '/modules/cf7/includes/assets/cf7hete-script.js', array('jquery', 'cf7hete-ace-editor-script', 'wpcf7-admin'), CF7HETE_VERSION, true );
            wp_enqueue_style( 'cf7hete-style', CF7HETE_PLUGIN_URL . '/modules/cf7/includes/assets/cf7hete-styles.css', array('contact-form-7-admin'), CF7HETE_VERSION );
        }

        /**
         * Action: 'wpcf7_save_contact_form'
         * Save the contact form options
         *
         * @param WPCF7_ContactForm  $contactform
         * @return  void
         */
        public function wpcf7_save_contact_form( $contact_form ) {
            $metadata = CF7HETE_Module_Cf7::METADATA;

            $new_property = ['activate' => '0'];

            if ( ! empty( $_POST['cf7hete-html-template-module-activate'] ) ) {
                $new_property['activate'] = '1';
            }

            if ( isset( $_POST['cf7hete-module-html-template-header-html'] ) ) {
                $new_property['header-html'] = trim( wp_unslash( $_POST['cf7hete-module-html-template-header-html'] ) );
            }

            if ( isset( $_POST['cf7hete-module-html-template-footer-html'] ) ) {
                $new_property['footer-html'] = trim( wp_unslash( $_POST['cf7hete-module-html-template-footer-html'] ) );
            }

            // Save settings
            $properties = $contact_form->get_properties();
            $properties[ $metadata ] = $new_property;
            $contact_form->set_properties( $properties );
        }

        /**
         * Action: 'wpcf7_admin_misc_pub_section'
         * Add pub section to CF7
         *
         * @return void
         */
        public function wpcf7_admin_misc_pub_section() {
            $contactform = wpcf7_get_current_contact_form();

            if ( empty( $contactform ) || $contactform->initial() ) {
                return;
            }

            require CF7HETE_PLUGIN_PATH . '/modules/cf7/includes/view/html-misc-pub-section.php';
        }

        /**
         * Filter: 'wpcf7_editor_panels'
         * Add CF7 panels
         *
         * @return void
         */
        public function wpcf7_editor_panels( $panels ) {
            $panels['cf7hete-html-template-panel'] = array(
                'title'     => __( 'HTML Template', 'cf7-html-email-template-extension' ),
                'callback'  => [ $this, 'render_html_template_panel' ],
            );

            return $panels;
        }

        /**
         * Filter: 'wpcf7_contact_form_properties'
         * Add necessary properties
         *
         * @return void
         */
        public function wpcf7_contact_form_properties( $properties, $instance ) {
            $metadata = CF7HETE_Module_Cf7::METADATA;

            if ( empty( $properties[ $metadata ] ) ) {
                $properties[ $metadata ] = [];
            }

            if ( empty( $properties[ $metadata ]['header-html'] ) ) {
                $properties[ $metadata ]['header-html'] = $this->get_default_template( 'header' );
            }

            if ( empty( $properties[ $metadata ]['footer-html'] ) ) {
                $properties[ $metadata ]['footer-html'] = $this->get_default_template( 'footer' );
            }

            if ( isset( $properties[ $metadata ]['activate'] ) && $properties[ $metadata ]['activate'] ) {
                $properties['mail']['use_html'] = '1';

                if ( empty( $properties['mail_2'] ) ) {
                    $properties['mail_2'] = [];
                }

                $properties['mail_2']['use_html'] = '1';
            }

            return $properties;
        }

        /**
         * Filter: 'wpcf7_mail_components'
         * Description of the filter
         *
         * @param array
         * @param WPCF7_ContactForm
         * @return void
         */
        public function wpcf7_mail_components( $components, $contactform, $mail = null ) {
            $properties = $contactform->get_properties();

            if ( ! isset( $properties[ CF7HETE_Module_Cf7::METADATA ] ) ) {
                return $components;
            }

            $properties = $properties[ CF7HETE_Module_Cf7::METADATA ];

            if ( ! empty( $properties['activate'] ) ) {
                $context = array(
                    'contact_form' => $contactform,
                    'autoreply'    => $mail instanceof WPCF7_Mail && 'mail_2' === $mail->name(),
                );

                // Bizen: CF7 has already wrapped the body in a full document (htmlize()),
                // so concatenating header + body + footer gave <table><!doctype html>…
                // Keep only what is inside <body> and build one document around it.
                $body = $this->replace_tags( $properties['header-html'] ?? '', $context );
                $body .= $this->extract_body( $components['body'] );
                $body .= $this->replace_tags( $properties['footer-html'] ?? '', $context );

                $components['body'] = $this->wrap_document( $body, $components['subject'] ?? '', $contactform->locale() );
            }

            return $components;
        }

        /**
         * Callback to add panel HTML
         *
         * @since    1.0.0
         * @param    WPCF7_ContactForm  $contactform
         */
        public function render_html_template_panel( WPCF7_ContactForm $contactform ) {
            require CF7HETE_PLUGIN_PATH . '/modules/cf7/includes/view/html-template-panel-html.php';
        }

        /**
         * Render the preview of template mail
         *
         * @return void
         */
        private function render_mail_preview( $contactform ) {
            if ( empty( $contactform ) || empty( $contactform->prop( CF7HETE_Module_Cf7::METADATA ) ) ) {
                echo $this->wrap_document(
                    $this->get_default_template_for_preview( 'header' )
                    . $this->get_default_template_for_preview( 'body' )
                    . $this->get_default_template_for_preview( 'footer' )
                );
                return;
            }

            $data = $contactform->prop( CF7HETE_Module_Cf7::METADATA );

            $body = $contactform->prop('mail');
            $body = $body ? ( $body['body'] ?? null ) : null;

            if ( $body === null ) {
                $body = $this->get_default_template_for_preview( 'body' );
            }

            $body = wpautop( $body );

            if ( ! empty( $data['activate'] ) ) {
                $context = array( 'contact_form' => $contactform );
                $body = $this->replace_tags( $data['header-html'], $context ) . $body;
                $body .= $this->replace_tags( $data['footer-html'], $context );
                $body = $this->wrap_document( $body, $contactform->title(), $contactform->locale() );
            }

            echo $body;
        }

        /**
         * Get the default markup file
         *
         * @since    1.0.0
         */
        private function get_default_template( $name ) {
            /**
             * Filter: cf7hete_default_template
             *
             * Override the default template content.
             *
             * @param string $default_template The content. Return null to use the default content.
             * @param string $name                          The template name.
             *
             * @since 2.1.0
             */
            $default_template = apply_filters( 'cf7hete_default_template', null, $name );

            if ( $default_template !== null ) {
                return $default_template;
            }

            $ext = 'style' === $name ? '.css' : '.htm';

            return file_get_contents( CF7HETE_PLUGIN_PATH . '/modules/cf7/includes/templates/default-' . $name . $ext );
        }

        /**
         * Replace tags in $string
         *
         * @since    1.0.0
         * @param    string     $string     Text to be processed
         */
        private function replace_tags( $text, $context = array() ) {
            $text = str_replace( '[home_url]', home_url(), $text );
            $text = str_replace( '[site_name]', get_bloginfo( "name" ), $text );

            $text = str_replace( '[_EXAMPLE_HELLO]', get_bloginfo( "name" ), $text );
            $text = str_replace( '[_EXAMPLE_CHEERS]', get_bloginfo( "name" ), $text );

            // Bizen tags
            $text = str_replace( '[site_domain]', esc_html( preg_replace( '@^www\.@', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ), $text );

            if ( str_contains( $text, '[site_logo]' ) ) {
                $text = str_replace( '[site_logo]', $this->get_logo_html(), $text );
            }

            if ( str_contains( $text, '[mail_note]' ) ) {
                $text = str_replace( '[mail_note]', esc_html( $this->get_mail_note( $context ) ), $text );
            }

            // [company_*] reads the field of the same name from the ACF options page
            // ("Anagrafica"), so company data are kept in one place instead of every form.
            $text = preg_replace_callback( '/\[(company_[a-z_]+)\]/', function ( $m ) {
                $value = function_exists( 'get_field' ) ? get_field( $m[1], 'option' ) : null;
                $value = is_scalar( $value ) ? trim( (string) $value ) : '';

                if ( '' === $value && 'company_name' === $m[1] ) {
                    $value = get_bloginfo( 'name' );
                }

                /**
                 * Filter: bizen_cf7_email_template_company_tag
                 *
                 * @param string $value Value printed for the tag.
                 * @param string $field Tag name, e.g. company_phone.
                 */
                return esc_html( apply_filters( 'bizen_cf7_email_template_company_tag', $value, $m[1] ) );
            }, $text );

            return $text;
        }

        /**
         * [site_logo]: the site's custom logo scaled into a 210×84 box, or the site
         * name in bold when there is none. Logos made for the email (white on the
         * header colour) can be passed through the bizen_cf7_email_template_logo filter.
         */
        private function get_logo_html() {
            $name     = get_bloginfo( 'name' );
            $logo_id  = (int) get_theme_mod( 'custom_logo' );
            $image    = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
            $url      = $image ? $image[0] : '';
            $width    = $image ? (int) $image[1] : 0;
            $height   = $image ? (int) $image[2] : 0;

            /**
             * Filter: bizen_cf7_email_template_logo
             *
             * @param array $logo [ 'url' => string, 'width' => int, 'height' => int ] — empty url prints the site name.
             */
            $logo = apply_filters( 'bizen_cf7_email_template_logo', array( 'url' => $url, 'width' => $width, 'height' => $height ) );

            if ( empty( $logo['url'] ) ) {
                return '<span style="font-family:Helvetica,Arial,sans-serif; font-size:24px; line-height:1.2; font-weight:bold; color:#ffffff;">' . esc_html( $name ) . '</span>';
            }

            $width  = (int) ( $logo['width'] ?? 0 );
            $height = (int) ( $logo['height'] ?? 0 );

            if ( $width > 0 && $height > 0 ) {
                $scale  = min( 210 / $width, 84 / $height, 1 );
                $width  = (int) round( $width * $scale );
                $height = (int) round( $height * $scale );
                $size   = sprintf( ' width="%1$d" height="%2$d" style="display:block; width:%1$dpx; height:%2$dpx; border:0;"', $width, $height );
            } else {
                $size = ' width="210" style="display:block; width:210px; max-width:210px; height:auto; border:0;"';
            }

            return '<img src="' . esc_url( $logo['url'] ) . '"' . $size . ' alt="' . esc_attr( $name ) . '">';
        }

        /**
         * [mail_note]: the closing line under the template. The autoresponder (Mail 2)
         * usually leaves from a no-reply address, the notification goes to staff who
         * do reply to it. The language follows the form, not the site: CF7 does not
         * switch locale while sending.
         */
        private function get_mail_note( $context ) {
            $notes = array(
                'it' => array( 'Messaggio inviato automaticamente da %s. Ti preghiamo di non rispondere a questo indirizzo.', 'Notifica automatica dal sito %s.' ),
                'en' => array( 'This message was sent automatically by %s. Please do not reply to this address.', 'Automatic notification from the %s website.' ),
                'fr' => array( 'Message envoyé automatiquement par %s. Merci de ne pas répondre à cette adresse.', 'Notification automatique du site %s.' ),
                'es' => array( 'Mensaje enviado automáticamente por %s. Te rogamos que no respondas a esta dirección.', 'Notificación automática del sitio %s.' ),
                'de' => array( 'Diese Nachricht wurde automatisch von %s gesendet. Bitte antworten Sie nicht an diese Adresse.', 'Automatische Benachrichtigung von der Website %s.' ),
            );

            $form   = $context['contact_form'] ?? null;
            $locale = $form instanceof WPCF7_ContactForm && $form->locale() ? $form->locale() : get_locale();
            $set    = $notes[ substr( (string) $locale, 0, 2 ) ] ?? $notes['en'];
            $note   = sprintf( $set[ empty( $context['autoreply'] ) ? 1 : 0 ], get_bloginfo( 'name' ) );

            /**
             * Filter: bizen_cf7_email_template_mail_note
             *
             * @param string $note    The note.
             * @param array  $context [ 'contact_form' => WPCF7_ContactForm|null, 'autoreply' => bool ]
             */
            return apply_filters( 'bizen_cf7_email_template_mail_note', $note, $context );
        }

        /**
         * Returns what is inside <body> when $html is a full document, $html otherwise.
         */
        private function extract_body( $html ) {
            if ( preg_match( '@<body\b[^>]*>(.*)</body>@is', $html, $m ) ) {
                return $m[1];
            }

            return $html;
        }

        /**
         * Builds the email document: one <head> carrying the template's base styles
         * and the media query that stacks the layout on phones.
         */
        private function wrap_document( $inner, $title = '', $locale = '' ) {
            $locale = $locale ?: get_locale();
            $lang   = esc_attr( str_replace( '_', '-', $locale ) );
            $dir    = function_exists( 'wpcf7_is_rtl' ) && wpcf7_is_rtl( $locale ) ? 'rtl' : 'ltr';
            $title  = esc_html( $title ?: get_bloginfo( 'name' ) );

            /**
             * Filter: bizen_cf7_email_template_style
             *
             * @param string $css Contents of the <style> element.
             */
            $css = apply_filters( 'bizen_cf7_email_template_style', $this->get_default_template( 'style' ) );

            return '<!doctype html>
<html lang="' . $lang . '" dir="' . $dir . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>' . $title . '</title>
<style>
' . $css . '
</style>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4;">
' . $inner . '
</body>
</html>';
        }

        /**
         * Get the default template for preview
         *
         * @since    1.0.0
         * @param    string     $string     Text to be processed
         */
        private function get_default_template_for_preview( $name ) {
            $content = $this->get_default_template( $name );
            $content = $this->replace_tags( $content );

            $content = str_replace( 'Hello', __( 'Hello', 'cf7-html-email-template-extension' ), $content );
            $content = str_replace( 'Cheers', __( 'Cheers', 'cf7-html-email-template-extension' ), $content );
            $content = str_replace( 'This is a example body for your templates. Please preview your mail within the CF7 administration.', __( 'This is a example body for your templates. Please preview your mail within the CF7 administration.', 'cf7-html-email-template-extension' ), $content );
            $content = str_replace( 'Field', __( 'Field', 'cf7-html-email-template-extension' ), $content );

            return $content;
        }

    }

}
