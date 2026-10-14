<?php

defined( 'ABSPATH' ) || exit;

global $wpdb;

if ( empty( $attributes['selectedFolder'] ) ) {
    return '';
}

$where_arr   = array( '1 = 1' );
$ids         = array_map(function($item) {return intval($item);},$attributes['selectedFolder'] );
$where_arr[] = '`folder_id` IN (' . implode( ',', $ids ) . ')';
$in_not_in   = $wpdb->get_col( "SELECT `attachment_id` FROM {$wpdb->prefix}fbv_attachment_folder" . ' WHERE ' . implode( ' AND ', apply_filters( 'fbv_in_not_in_where_query', $where_arr, $ids ) ) );

if ( empty( $in_not_in ) ) {
    return '';
}

$is_custom_order = ( 'custom' === $attributes['sortBy'] );

$query = new \WP_Query(
    array(
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'post__in'       => $in_not_in,
        // 'custom' is not a valid WP_Query orderby; fall back to date and reorder below.
        'orderby'        => $is_custom_order ? 'date' : sanitize_text_field( $attributes['sortBy'] ),
        'order'          => $is_custom_order ? 'DESC' : sanitize_text_field( $attributes['sortType'] ),
        'post_status'    => 'inherit',
    )
);
$posts = $query->get_posts();

if ( $is_custom_order && ! empty( $attributes['customOrder'] ) ) {
    $order_map = array_flip( array_map( 'intval', $attributes['customOrder'] ) );
    usort(
        $posts,
        function( $img1, $img2 ) use ( $order_map ) {
            // Images present in the saved order come first (in that order);
            // anything not yet ordered (e.g. newly added) sinks to the end.
            $pos1 = isset( $order_map[ $img1->ID ] ) ? $order_map[ $img1->ID ] : PHP_INT_MAX;
            $pos2 = isset( $order_map[ $img2->ID ] ) ? $order_map[ $img2->ID ] : PHP_INT_MAX;
            return $pos1 <=> $pos2;
        }
    );
}
if ( $attributes['sortBy'] == 'file_name' ) {
    if ( $attributes['sortType'] == 'ASC' ) {
        usort(
            $posts,
            function( $img1, $img2 ) {
                return ( basename( $img1->guid ) > basename( $img2->guid ) ) ? 1 : -1;
            }
        );
    } else {
        usort(
            $posts,
            function( $img1, $img2 ) {
                return ( basename( $img1->guid ) > basename( $img2->guid ) ) ? -1 : 1;
            }
        );
    }
}

$ulClass = 'filebird-block-filebird-gallery';

if ( 'flex' === $attributes['layout'] ) {
    $ulClass .= ' wp-block-gallery blocks-gallery-grid';
} elseif ( 'grid' === $attributes['layout'] ) {
    $ulClass .= ' layout-grid';
} elseif ( 'masonry' === $attributes['layout'] ) {
    $ulClass .= ' layout-masonry';
}

$ulClass .= ! empty( $attributes['className'] ) ? ' ' . esc_attr( $attributes['className'] ) : '';
$ulClass .= ' columns-' . esc_attr( $attributes['columns'] );
$ulClass .= $attributes['isCropped'] ? ' is-cropped' : '';

if ( count( $posts ) < 1 ) {
    return '';
}

$styles  = '--columns: ' . esc_attr( $attributes['columns'] ) . ';';
$styles .= '--space: ' . esc_attr( $attributes['spaceAroundImage'] ) . 'px;';
$styles .= '--min-width: ' . esc_attr( $attributes['imgMinWidth'] ) . 'px;';

$html = '';
$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => $ulClass,
		'style' => $styles,
	)
);
$html .= '<ul ' . $wrapper_attributes . '>';

foreach ( $posts as $post ) {
    if ( ! wp_attachment_is_image( $post ) ) {
        continue;
    }
    $href     = '';
    $imageSrc = wp_get_attachment_image_src( $post->ID, 'full' );
    $imageSrc = $imageSrc[0];
    $imageAlt = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
    $imageAlt = empty( $imageAlt ) ? $post->post_title : $imageAlt;
    switch ( $attributes['linkTo'] ) {
        case 'media':
            $href = $imageSrc;
            break;
        case 'attachment':
            $href = get_attachment_link( $post->ID );

            break;
        default:
            break;
    }

    $img  = '<img src="' . esc_attr( $imageSrc ) . '" alt="' . esc_attr( $imageAlt ) . '"';
    $img .= ' class="' . "wp-image-{$post->ID}" . '"/>';

    $hoverAnimation = $attributes['imageHoverAnimation'];

    $li  = '<li class="blocks-gallery-item fb-block-hover-animation-' . esc_attr( $hoverAnimation ) . '">';
    $li .= '<figure>';

    $li .= empty( $href ) ? $img : '<a href="' . esc_attr( $href ) . '">' . $img . '</a>';

    if ( $attributes['hasCaption'] ) {
        $li .= empty( $post->post_excerpt ) ? '' : '<figcaption class="blocks-gallery-item__caption">' . wp_kses_post( $post->post_excerpt ) . '</figcaption>';
    }

    $li .= '</figure>';
    $li .= '</li>';

    $html .= $li;
}

$html .= '</ul>';

if ( $attributes['hasLightbox'] ) {
    wp_enqueue_style( 'fbv-photoswipe' );
    wp_enqueue_style( 'fbv-photoswipe-default-skin' );

    wp_enqueue_script( 'fbv-photoswipe' );
    wp_enqueue_script( 'fbv-photoswipe-ui-default' );
    wp_enqueue_script( 'filebird-gallery' );

    /**
     * Filter the buttons shown in the folder gallery lightbox top bar.
     *
     * Each key maps to whether the corresponding button is displayed. Set a value
     * to false to hide a button, or add custom entries to render extra buttons.
     *
     * @param array $buttons {
     *     @type bool|array $close  Close button.
     *     @type bool|array $share  Share button.
     *     @type bool|array $fs     Toggle fullscreen button.
     *     @type bool|array $zoom   Zoom in/out button.
     * }
     */
    $lightbox_buttons = apply_filters(
        'filebird_gallery_lightbox_buttons',
        array(
            'close' => array(
                'class' => 'pswp__button--close',
                'title' => __( 'Close (Esc)', 'bizen-toolkit' ),
            ),
            'share' => array(
                'class' => 'pswp__button--share',
                'title' => __( 'Share', 'bizen-toolkit' ),
            ),
            'fs'    => array(
                'class' => 'pswp__button--fs',
                'title' => __( 'Toggle fullscreen', 'bizen-toolkit' ),
            ),
            'zoom'  => array(
                'class' => 'pswp__button--zoom',
                'title' => __( 'Zoom in/out', 'bizen-toolkit' ),
            ),
        )
    );

    /**
     * Filter the sub-items shown in the folder gallery lightbox share menu.
     *
     * Each entry is passed to PhotoSwipe's `shareButtons` option. Use the `{{url}}`,
     * `{{text}}`, `{{image_url}}` and `{{raw_image_url}}` placeholders in `url`.
     * Set `download` to true to force a download instead of opening a share link.
     *
     * @param array $share_buttons List of share button definitions.
     */
    $share_buttons = apply_filters(
        'filebird_gallery_share_buttons',
        array(
            array(
                'id'    => 'facebook',
                'label' => __( 'Share on Facebook', 'bizen-toolkit' ),
                'url'   => 'https://www.facebook.com/sharer/sharer.php?u={{url}}',
            ),
            array(
                'id'    => 'twitter',
                'label' => __( 'Tweet', 'bizen-toolkit' ),
                'url'   => 'https://twitter.com/intent/tweet?text={{text}}&url={{url}}',
            ),
            array(
                'id'    => 'pinterest',
                'label' => __( 'Pin it', 'bizen-toolkit' ),
                'url'   => 'http://www.pinterest.com/pin/create/button/?url={{url}}&media={{image_url}}&description={{text}}',
            ),
            array(
                'id'       => 'download',
                'label'    => __( 'Download image', 'bizen-toolkit' ),
                'url'      => '{{raw_image_url}}',
                'download' => true,
            ),
        )
    );

    wp_localize_script(
        'filebird-gallery',
        'filebirdGalleryConfig',
        array(
            'buttons'      => $lightbox_buttons,
            'shareButtons' => array_values( $share_buttons ),
        )
    );
}

echo $html;