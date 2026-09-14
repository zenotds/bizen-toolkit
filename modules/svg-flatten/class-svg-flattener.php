<?php
/**
 * SVG flattener.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * Rewrites an SVG so that nothing it carries is global: CSS declarations move
 * onto the elements as presentation attributes, the <style> block goes away,
 * and the ids that survive are namespaced per file.
 *
 * The rewrite is conservative. Anything that cannot be reduced to element-level
 * attributes with the same result (at-rules, pseudo-classes, descendant
 * selectors) aborts the pass and the file is returned untouched, rather than
 * flattened into something that renders differently.
 */

defined( 'ABSPATH' ) || exit;

class Bizen_SVG_Flattener {

	/** Prefix given to surviving ids; doubles as the marker of an already-processed file. */
	private const ID_PREFIX = 'bz';

	/** Matches an id this class has already namespaced, so a second pass is a no-op. */
	private const ID_PREFIX_PATTERN = '/^bz[0-9a-f]{6}-/';

	/**
	 * CSS properties that are also SVG presentation attributes with identical syntax.
	 * Everything else stays in a style attribute on the element — still element-level,
	 * so it cannot collide, just not overridable from the page.
	 *
	 * `transform` and `d` are deliberately absent: they exist as attributes but the
	 * CSS syntax differs (units, function names), so moving them would change output.
	 */
	private const PRESENTATION_ATTRIBUTES = [
		'alignment-baseline', 'baseline-shift', 'clip', 'clip-path', 'clip-rule', 'color',
		'color-interpolation', 'color-interpolation-filters', 'cursor', 'direction', 'display',
		'dominant-baseline', 'enable-background', 'fill', 'fill-opacity', 'fill-rule', 'filter',
		'flood-color', 'flood-opacity', 'font-family', 'font-size', 'font-size-adjust',
		'font-stretch', 'font-style', 'font-variant', 'font-weight', 'image-rendering',
		'isolation', 'letter-spacing', 'lighting-color', 'marker-end', 'marker-mid',
		'marker-start', 'mask', 'mix-blend-mode', 'opacity', 'overflow', 'paint-order',
		'pointer-events', 'shape-rendering', 'stop-color', 'stop-opacity', 'stroke',
		'stroke-dasharray', 'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin',
		'stroke-miterlimit', 'stroke-opacity', 'stroke-width', 'text-anchor', 'text-decoration',
		'text-rendering', 'unicode-bidi', 'vector-effect', 'visibility', 'white-space',
		'word-spacing', 'writing-mode',
	];

	/** Elements whose whitespace is content and must survive the minify pass. */
	private const TEXT_ELEMENTS = [ 'text', 'tspan', 'textPath', 'title', 'desc', 'style', 'script' ];

	/**
	 * Containers where an invisible shape is still doing work — clipping, masking,
	 * defining a pattern, catching clicks — so it is never dropped as a slice.
	 */
	private const FUNCTIONAL_CONTAINERS = [ 'clipPath', 'mask', 'defs', 'pattern', 'marker', 'symbol', 'a' ];

	/** Returns the flattened markup, or the input unchanged when it cannot be flattened safely. */
	public static function flatten( string $svg ): string {
		if ( ! str_contains( $svg, '<svg' ) || ! class_exists( 'DOMDocument' ) ) {
			return $svg;
		}

		// Nothing here can collide once inlined: leave the file byte-identical.
		if ( ! preg_match( '/<style[\s>]|\sclass=|\sstyle=|\sid=|<!--/i', $svg ) ) {
			return $svg;
		}

		$dom = self::load( $svg );
		if ( ! $dom || ! $dom->documentElement || 'svg' !== $dom->documentElement->localName ) {
			return $svg;
		}

		$xpath = new DOMXPath( $dom );

		$rules = self::collect_rules( $xpath );
		if ( null === $rules ) {
			return $svg; // CSS this class cannot reproduce on the elements.
		}

		self::apply_rules( $xpath, $rules );
		self::remove_nodes( $xpath->query( '//*[local-name()="style"]' ) );
		self::namespace_ids( $xpath, self::ID_PREFIX . substr( md5( $svg ), 0, 6 ) . '-' );
		self::remove_slices( $xpath );
		self::remove_empty_defs( $xpath );
		self::remove_nodes( $xpath->query( '//comment()' ) );
		self::collapse_whitespace( $xpath );

		$out = $dom->saveXML( $dom->documentElement );

		return is_string( $out ) ? trim( $out ) : $svg;
	}

	/** Parses the file as XML. Malformed markup returns null and the file is left alone. */
	private static function load( string $svg ): ?DOMDocument {
		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;

		$previous = libxml_use_internal_errors( true );
		// No LIBXML_NOENT and no LIBXML_DTDLOAD: entities are never expanded, nothing is fetched.
		$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	/**
	 * Reads every <style> block into an ordered rule list.
	 *
	 * @return array<int, array{element: ?string, id: ?string, classes: string[], specificity: int, order: int, declarations: array<string, array{value: string, important: bool}>}>|null
	 */
	private static function collect_rules( DOMXPath $xpath ): ?array {
		$css = '';
		foreach ( $xpath->query( '//*[local-name()="style"]' ) as $style ) {
			$css .= $style->textContent . "\n";
		}

		return '' === trim( $css ) ? [] : self::parse_css( $css );
	}

	/** @return array<int, array>|null Null when the stylesheet contains something that cannot be flattened. */
	private static function parse_css( string $css ): ?array {
		// Comments first — they can hide braces and confuse the block match.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		// @media, @supports, @font-face, @import: conditional or external by nature.
		if ( str_contains( $css, '@' ) ) {
			return null;
		}

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER );

		// Anything left outside a rule block means the stylesheet was not understood.
		if ( '' !== trim( (string) preg_replace( '/([^{}]+)\{([^{}]*)\}/s', '', $css ) ) ) {
			return null;
		}

		$rules = [];
		$order = 0;

		foreach ( $blocks as $block ) {
			$declarations = self::parse_declarations( $block[2] );
			if ( null === $declarations ) {
				return null;
			}
			if ( ! $declarations ) {
				continue;
			}

			foreach ( explode( ',', $block[1] ) as $selector ) {
				$parsed = self::parse_selector( $selector );
				if ( null === $parsed ) {
					return null;
				}
				$parsed['declarations'] = $declarations;
				$parsed['order']        = $order++;
				$rules[]                = $parsed;
			}
		}

		return $rules;
	}

	/**
	 * Accepts a single compound selector — an optional element name followed by any
	 * number of .class and #id parts. Combinators, pseudo-classes and attribute
	 * selectors depend on context that an attribute cannot carry, so they return null.
	 *
	 * @return array{element: ?string, id: ?string, classes: string[], specificity: int}|null
	 */
	private static function parse_selector( string $selector ): ?array {
		$selector = trim( $selector );

		if ( '' === $selector || ! preg_match( '/^([a-zA-Z][\w-]*)?((?:[.#][\w-]+)+)?$/', $selector, $match ) ) {
			return null;
		}

		$element = '' !== ( $match[1] ?? '' ) ? $match[1] : null;
		$id      = null;
		$classes = [];

		if ( '' !== ( $match[2] ?? '' ) ) {
			preg_match_all( '/([.#])([\w-]+)/', $match[2], $parts, PREG_SET_ORDER );
			foreach ( $parts as $part ) {
				if ( '#' === $part[1] ) {
					if ( null !== $id ) {
						return null; // Two ids never match the same element.
					}
					$id = $part[2];
				} else {
					$classes[] = $part[2];
				}
			}
		}

		if ( null === $element && null === $id && ! $classes ) {
			return null;
		}

		return [
			'element'     => $element,
			'id'          => $id,
			'classes'     => $classes,
			'specificity' => ( null !== $id ? 100 : 0 ) + ( count( $classes ) * 10 ) + ( null !== $element ? 1 : 0 ),
		];
	}

	/**
	 * @return array<string, array{value: string, important: bool}>|null
	 */
	private static function parse_declarations( string $body ): ?array {
		$declarations = [];

		foreach ( explode( ';', $body ) as $declaration ) {
			if ( '' === trim( $declaration ) ) {
				continue;
			}
			// A value holding a semicolon (a data: URI, say) lands here split in half and fails.
			if ( ! preg_match( '/^\s*([-a-zA-Z]+)\s*:\s*(.+?)\s*$/s', $declaration, $match ) ) {
				return null;
			}

			$value     = $match[2];
			$important = false;

			if ( preg_match( '/^(.*?)\s*!\s*important$/is', $value, $bang ) ) {
				$value     = trim( $bang[1] );
				$important = true;
			}

			$declarations[ strtolower( $match[1] ) ] = [
				'value'     => $value,
				'important' => $important,
			];
		}

		return $declarations;
	}

	/** Resolves the cascade for every element and writes the result onto it. */
	private static function apply_rules( DOMXPath $xpath, array $rules ): void {
		// Only class names the stylesheet actually used are dropped; hand-written
		// hooks on the same element are left for the page to target.
		$consumed = [];
		foreach ( $rules as $rule ) {
			foreach ( $rule['classes'] as $class ) {
				$consumed[ $class ] = true;
			}
		}

		foreach ( $xpath->query( '//*' ) as $element ) {
			$inline = [];

			if ( $element->hasAttribute( 'style' ) ) {
				$inline = self::parse_declarations( $element->getAttribute( 'style' ) );
				if ( null === $inline ) {
					continue; // Unparseable style attribute: leave the element exactly as it is.
				}
			}

			$winners = [];

			foreach ( $rules as $rule ) {
				if ( ! self::matches( $element, $rule ) ) {
					continue;
				}
				foreach ( $rule['declarations'] as $property => $declaration ) {
					// Ordered by weight, then specificity, then position in the stylesheet.
					$priority = [ $declaration['important'] ? 1 : 0, $rule['specificity'], $rule['order'] ];
					if ( ! isset( $winners[ $property ] ) || $priority > $winners[ $property ]['priority'] ) {
						$winners[ $property ] = [
							'priority' => $priority,
							'value'    => $declaration['value'],
						];
					}
				}
			}

			// The element's own style attribute outranks every rule in the stylesheet.
			foreach ( $inline as $property => $declaration ) {
				$winners[ $property ] = [ 'value' => $declaration['value'] ];
			}

			self::write_declarations( $element, $winners );
			self::strip_classes( $element, $consumed );
		}
	}

	private static function matches( DOMElement $element, array $rule ): bool {
		if ( null !== $rule['element'] && $rule['element'] !== $element->localName ) {
			return false;
		}
		if ( null !== $rule['id'] && $rule['id'] !== $element->getAttribute( 'id' ) ) {
			return false;
		}
		if ( ! $rule['classes'] ) {
			return true;
		}

		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		return ! array_diff( $rule['classes'], $classes );
	}

	/** @param array<string, array{value: string}> $winners */
	private static function write_declarations( DOMElement $element, array $winners ): void {
		$leftover = [];

		foreach ( $winners as $property => $winner ) {
			if ( in_array( $property, self::PRESENTATION_ATTRIBUTES, true ) ) {
				$element->setAttribute( $property, $winner['value'] );
			} else {
				$leftover[] = $property . ':' . $winner['value'];
			}
		}

		if ( $leftover ) {
			$element->setAttribute( 'style', implode( ';', $leftover ) );
		} else {
			$element->removeAttribute( 'style' );
		}
	}

	/** @param array<string, bool> $consumed */
	private static function strip_classes( DOMElement $element, array $consumed ): void {
		if ( ! $consumed || ! $element->hasAttribute( 'class' ) ) {
			return;
		}

		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$keep    = array_filter( $classes, static fn( $class ) => ! isset( $consumed[ $class ] ) );

		if ( $keep ) {
			$element->setAttribute( 'class', implode( ' ', $keep ) );
		} else {
			$element->removeAttribute( 'class' );
		}
	}

	/**
	 * Drops ids nothing points at (Layer_1 and friends) and prefixes the ones that
	 * survive, so two exports on the same page no longer share a gradient or a clip path.
	 */
	private static function namespace_ids( DOMXPath $xpath, string $prefix ): void {
		$referenced = [];

		foreach ( $xpath->query( '//@*' ) as $attribute ) {
			if ( preg_match_all( '/url\(\s*[\'"]?#([\w:.-]+)/', $attribute->value, $urls ) ) {
				foreach ( $urls[1] as $id ) {
					$referenced[ $id ] = true;
				}
			}
			if ( 'href' === $attribute->localName && str_starts_with( $attribute->value, '#' ) ) {
				$referenced[ substr( $attribute->value, 1 ) ] = true;
			}
		}

		$renamed = [];

		foreach ( $xpath->query( '//*[@id]' ) as $element ) {
			$id = $element->getAttribute( 'id' );

			if ( ! isset( $referenced[ $id ] ) ) {
				$element->removeAttribute( 'id' );
				continue;
			}
			if ( preg_match( self::ID_PREFIX_PATTERN, $id ) ) {
				continue; // Already namespaced by an earlier pass.
			}

			$renamed[ $id ] = $prefix . $id;
			$element->setAttribute( 'id', $renamed[ $id ] );
		}

		foreach ( $xpath->query( '//@data-name' ) as $attribute ) {
			$attribute->ownerElement->removeAttribute( 'data-name' );
		}

		if ( ! $renamed ) {
			return;
		}

		foreach ( $xpath->query( '//@*' ) as $attribute ) {
			$value = preg_replace_callback(
				'/url\(\s*([\'"]?)#([\w:.-]+)\1\s*\)/',
				static fn( $match ) => isset( $renamed[ $match[2] ] )
					? 'url(' . $match[1] . '#' . $renamed[ $match[2] ] . $match[1] . ')'
					: $match[0],
				$attribute->value
			);

			if ( 'href' === $attribute->localName && str_starts_with( $value, '#' ) ) {
				$target = substr( $value, 1 );
				$value  = isset( $renamed[ $target ] ) ? '#' . $renamed[ $target ] : $value;
			}

			if ( $value !== $attribute->value ) {
				self::set_attribute( $attribute, $value );
			}
		}
	}

	/** setAttribute() cannot take a prefixed name in an XML document, so namespaced attributes go the long way. */
	private static function set_attribute( DOMAttr $attribute, string $value ): void {
		if ( null !== $attribute->namespaceURI ) {
			$attribute->ownerElement->setAttributeNS( $attribute->namespaceURI, $attribute->nodeName, $value );
			return;
		}
		$attribute->ownerElement->setAttribute( $attribute->name, $value );
	}

	/**
	 * Illustrator exports its slices as rectangles that paint nothing and sometimes
	 * sit outside the viewBox. Only shapes that render nothing and do no other work
	 * qualify — a fill="none" rect with a stroke is a visible outline, and one inside
	 * a clipPath is the clip itself.
	 */
	private static function remove_slices( DOMXPath $xpath ): void {
		$containers = implode( ' or ', array_map(
			static fn( $name ) => sprintf( 'local-name()="%s"', $name ),
			self::FUNCTIONAL_CONTAINERS
		) );

		$rects = $xpath->query( sprintf( '//*[local-name()="rect"][not(ancestor::*[%s])]', $containers ) );

		foreach ( iterator_to_array( $rects ) as $rect ) {
			$fill   = strtolower( trim( $rect->getAttribute( 'fill' ) ) );
			$stroke = strtolower( trim( $rect->getAttribute( 'stroke' ) ) );

			if ( 'none' !== $fill || ( '' !== $stroke && 'none' !== $stroke ) ) {
				continue;
			}
			if ( $rect->hasAttribute( 'id' ) || $rect->hasAttribute( 'style' ) || $rect->hasChildNodes() ) {
				continue;
			}

			$rect->parentNode->removeChild( $rect );
		}
	}

	private static function remove_empty_defs( DOMXPath $xpath ): void {
		$defs = $xpath->query( '//*[local-name()="defs"][not(*)]' );
		self::remove_nodes( $defs );
	}

	/** Strips indentation between elements, leaving the whitespace inside text nodes alone. */
	private static function collapse_whitespace( DOMXPath $xpath ): void {
		$protected = implode( ' or ', array_map(
			static fn( $name ) => sprintf( 'local-name()="%s"', $name ),
			self::TEXT_ELEMENTS
		) );

		$nodes = $xpath->query( sprintf(
			'//text()[not(normalize-space())][not(ancestor::*[%s])]',
			$protected
		) );

		self::remove_nodes( $nodes );
	}

	private static function remove_nodes( DOMNodeList $nodes ): void {
		foreach ( iterator_to_array( $nodes ) as $node ) {
			$node->parentNode?->removeChild( $node );
		}
	}
}
