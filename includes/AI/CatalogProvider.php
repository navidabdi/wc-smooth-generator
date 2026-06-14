<?php
/**
 * AI catalog generation adapter.
 *
 * @package SmoothGenerator\AI
 */

namespace WC\SmoothGenerator\AI;

/**
 * Generates and validates AI-backed catalog data.
 */
class CatalogProvider {
	/**
	 * Generate a batch catalog for an industry.
	 *
	 * @param string $industry Industry prompt supplied by the user.
	 * @param int    $amount   Number of catalog items to request.
	 * @param array  $context  Additional generation context.
	 * @return array|\WP_Error
	 */
	public static function generate_catalog( string $industry, int $amount, array $context = array() ) {
		$industry = sanitize_text_field( $industry );

		if ( '' === $industry ) {
			return new \WP_Error(
				'smoothgenerator_ai_invalid_industry',
				'The --industry argument must not be empty.'
			);
		}

		/**
		 * Filter the raw AI catalog response.
		 *
		 * Returning null lets Smooth Generator use the detected WordPress AI connector.
		 *
		 * @since 1.3.0
		 *
		 * @param mixed  $response Raw catalog response.
		 * @param string $industry Industry prompt.
		 * @param int    $amount   Number of products.
		 * @param array  $context  Generation context.
		 */
		$response = apply_filters( 'smoothgenerator_ai_catalog_response', null, $industry, $amount, $context );

		if ( null === $response ) {
			/**
			 * Filter the callable AI catalog provider.
			 *
			 * @since 1.3.0
			 *
			 * @param callable|null $provider Provider callback.
			 * @param string        $industry Industry prompt.
			 * @param int           $amount   Number of products.
			 * @param array         $context  Generation context.
			 */
			$provider = apply_filters( 'smoothgenerator_ai_catalog_provider', null, $industry, $amount, $context );

			if ( is_callable( $provider ) ) {
				$response = call_user_func( $provider, $industry, $amount, $context );
			} else {
				$response = self::request_text_from_connector( $industry, $amount, $context );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::validate_catalog_response( $response, $amount );
	}

	/**
	 * Generate an image attachment if the AI runtime supports image generation.
	 *
	 * @param string $prompt  Image prompt.
	 * @param array  $context Additional generation context.
	 * @return int|\WP_Error
	 */
	public static function generate_image( string $prompt, array $context = array() ) {
		/**
		 * Filter the generated image attachment ID for an AI image prompt.
		 *
		 * Returning 0 lets Smooth Generator use the detected WordPress AI image connector.
		 *
		 * @since 1.3.0
		 *
		 * @param int|\WP_Error $attachment_id Attachment ID or error.
		 * @param string        $prompt        Image prompt.
		 * @param array         $context       Generation context.
		 */
		$attachment_id = apply_filters( 'smoothgenerator_ai_image_attachment_id', 0, $prompt, $context );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( $attachment_id ) {
			return absint( $attachment_id );
		}

		if ( function_exists( 'wp_ai_generate_image' ) ) {
			$attachment_id = wp_ai_generate_image( $prompt, array( 'return' => 'attachment_id' ) );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return absint( $attachment_id );
		}

		return new \WP_Error(
			'smoothgenerator_ai_image_unavailable',
			'The WordPress AI connector does not support image generation in this environment.'
		);
	}

	/**
	 * Validate and normalize a catalog response.
	 *
	 * @param mixed $response Raw response.
	 * @param int   $amount   Expected number of products.
	 * @return array|\WP_Error
	 */
	public static function validate_catalog_response( $response, int $amount ) {
		if ( is_string( $response ) ) {
			$decoded = json_decode( $response, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error(
					'smoothgenerator_ai_malformed_json',
					'The AI connector returned malformed JSON.'
				);
			}

			$response = $decoded;
		}

		if ( ! is_array( $response ) ) {
			return new \WP_Error(
				'smoothgenerator_ai_invalid_catalog',
				'The AI connector returned an invalid catalog response.'
			);
		}

		$items = isset( $response['products'] ) ? $response['products'] : $response;

		if ( ! is_array( $items ) || count( $items ) < $amount ) {
			return new \WP_Error(
				'smoothgenerator_ai_invalid_catalog',
				sprintf( 'The AI connector must return at least %d product items.', $amount )
			);
		}

		$catalog = array();

		for ( $i = 0; $i < $amount; $i++ ) {
			if ( ! isset( $items[ $i ] ) || ! is_array( $items[ $i ] ) ) {
				return new \WP_Error(
					'smoothgenerator_ai_invalid_catalog',
					'Each AI catalog product must be an object.'
				);
			}

			$item       = $items[ $i ];
			$required   = array( 'name', 'description', 'short_description', 'categories', 'tags', 'attributes', 'image_prompt' );
			$missing    = array();
			$normalized = array();

			foreach ( $required as $field ) {
				if ( ! array_key_exists( $field, $item ) ) {
					$missing[] = $field;
				}
			}

			if ( $missing ) {
				return new \WP_Error(
					'smoothgenerator_ai_invalid_catalog',
					'AI catalog product is missing required fields: ' . implode( ', ', $missing ) . '.'
				);
			}

			$normalized['name']              = sanitize_text_field( $item['name'] );
			$normalized['description']       = wp_kses_post( $item['description'] );
			$normalized['short_description'] = wp_kses_post( $item['short_description'] );
			$normalized['categories']        = self::normalize_string_list( $item['categories'] );
			$normalized['tags']              = self::normalize_string_list( $item['tags'] );
			$normalized['brands']            = self::normalize_string_list( $item['brands'] ?? array() );
			$normalized['attributes']        = self::normalize_attributes( $item['attributes'] );
			$normalized['image_prompt']      = sanitize_text_field( $item['image_prompt'] );

			if ( '' === $normalized['name'] || '' === $normalized['description'] || '' === $normalized['short_description'] || '' === $normalized['image_prompt'] ) {
				return new \WP_Error(
					'smoothgenerator_ai_invalid_catalog',
					'AI catalog products must include non-empty name, descriptions, and image prompt.'
				);
			}

			$catalog[] = $normalized;
		}

		return $catalog;
	}

	/**
	 * Request text generation from a detected WordPress AI connector.
	 *
	 * @param string $industry Industry prompt.
	 * @param int    $amount   Product count.
	 * @param array  $context  Additional generation context.
	 * @return string|\WP_Error
	 */
	protected static function request_text_from_connector( string $industry, int $amount, array $context = array() ) {
		$prompt = self::build_prompt( $industry, $amount, $context );

		if ( function_exists( 'wp_ai_generate_text' ) ) {
			return wp_ai_generate_text(
				$prompt,
				array(
					'response_format' => 'json',
				)
			);
		}

		if ( function_exists( 'wp_ai_generate' ) ) {
			return wp_ai_generate(
				$prompt,
				array(
					'type'            => 'text',
					'response_format' => 'json',
				)
			);
		}

		return new \WP_Error(
			'smoothgenerator_ai_unavailable',
			'The --industry option requires a WordPress AI connector with text generation support. Configure an AI provider, then try again.'
		);
	}

	/**
	 * Build the structured catalog prompt.
	 *
	 * @param string $industry Industry prompt.
	 * @param int    $amount   Product count.
	 * @param array  $context  Additional generation context.
	 * @return string
	 */
	protected static function build_prompt( string $industry, int $amount, array $context = array() ): string {
		$type = isset( $context['type'] ) ? sanitize_text_field( (string) $context['type'] ) : 'simple or variable';

		return sprintf(
			'Generate %1$d fictional but realistic WooCommerce catalog products for the "%2$s" industry. Product type context: %3$s. Return only valid JSON with a top-level "products" array. Each product must include: name, description, short_description, categories array, tags array, brands array, attributes array of objects with name and values array, and image_prompt. Use plausible fictional brands only. Do not include real product names, markdown, comments, or pricing.',
			$amount,
			$industry,
			$type
		);
	}

	/**
	 * Normalize a list of strings.
	 *
	 * @param mixed $values Raw values.
	 * @return array
	 */
	protected static function normalize_string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $values as $value ) {
			$value = sanitize_text_field( (string) $value );

			if ( '' !== $value && ! in_array( $value, $normalized, true ) ) {
				$normalized[] = $value;
			}
		}

		return array_slice( $normalized, 0, 8 );
	}

	/**
	 * Normalize AI attribute data.
	 *
	 * @param mixed $attributes Raw attributes.
	 * @return array
	 */
	protected static function normalize_attributes( $attributes ): array {
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) || empty( $attribute['name'] ) || empty( $attribute['values'] ) || ! is_array( $attribute['values'] ) ) {
				continue;
			}

			$name   = sanitize_text_field( (string) $attribute['name'] );
			$values = self::normalize_string_list( $attribute['values'] );

			if ( '' !== $name && $values ) {
				$normalized[] = array(
					'name'   => $name,
					'values' => $values,
				);
			}
		}

		return array_slice( $normalized, 0, 5 );
	}
}
