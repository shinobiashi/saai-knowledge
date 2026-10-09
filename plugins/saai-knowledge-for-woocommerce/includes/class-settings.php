<?php
/**
 * The add-on's settings: a section on the free plugin's settings page.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the "WooCommerce" section — the three product-page auto-insertion
 * toggles — through the free plugin's public `saai_settings_sections` /
 * `saai_default_settings` filters (docs/DESIGN-HOOKS-API.md section 3.5),
 * and reads the saved values back.
 *
 * Nothing here is an option, page, or sanitizer of this plugin's own: the
 * free plugin's Settings API registration renders these fields, sanitizes
 * them (a checkbox absent from the submission is stored as false), and
 * persists them inside its `saai_knowledge_settings` option next to its own
 * fields. Reading goes straight to get_option() on that option name, which
 * is a documented public identifier (section 6) — the free plugin exposes no
 * read service, and the stored array is the only thing both sides agree on.
 */
final class Settings {

	/**
	 * The free plugin's option name, a public identifier per
	 * docs/DESIGN-HOOKS-API.md section 6. Read-only from this side: every
	 * write goes through the free plugin's settings page.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'saai_knowledge_settings';

	/**
	 * The settings-page section this add-on contributes.
	 *
	 * @var string
	 */
	public const SECTION_ID = 'woocommerce';

	/**
	 * Field: add a FAQ tab to product pages.
	 *
	 * @var string
	 */
	public const FAQ_TAB = 'wc_product_faq_tab';

	/**
	 * Field: list the linked KB articles below the product summary.
	 *
	 * @var string
	 */
	public const KB_LINKS = 'wc_product_kb_links';

	/**
	 * Field: auto-link glossary terms in product descriptions.
	 *
	 * @var string
	 */
	public const TOOLTIPS = 'wc_product_tooltips';

	/**
	 * Default values, also the complete list of fields this class owns.
	 *
	 * Every auto-insertion is on by default: an administrator who installs
	 * the add-on and links content to a product expects it to show up
	 * without a second trip to the settings page.
	 *
	 * @var array<string, bool>
	 */
	private const DEFAULTS = array(
		self::FAQ_TAB  => true,
		self::KB_LINKS => true,
		self::TOOLTIPS => true,
	);

	/**
	 * Hooks the section and its defaults into the free plugin's settings.
	 */
	public function register(): void {
		add_filter( 'saai_settings_sections', array( $this, 'add_section' ) );
		add_filter( 'saai_default_settings', array( $this, 'add_defaults' ) );
	}

	/**
	 * Adds the WooCommerce section to the free plugin's settings page.
	 *
	 * The `saai_settings_sections` callback.
	 *
	 * @param mixed $sections Section definitions keyed by section ID.
	 * @return mixed
	 */
	public function add_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$sections[ self::SECTION_ID ] = array(
			'title'  => __( 'WooCommerce', 'saai-knowledge-for-woocommerce' ),
			'fields' => $this->fields(),
		);

		return $sections;
	}

	/**
	 * Adds this add-on's defaults to the free plugin's.
	 *
	 * The `saai_default_settings` callback. Makes a bare
	 * `get_option( 'saai_knowledge_settings' )` already carry these keys on
	 * a site that never saved the settings page.
	 *
	 * @param mixed $defaults Default option values.
	 * @return mixed
	 */
	public function add_defaults( $defaults ) {
		if ( ! is_array( $defaults ) ) {
			return $defaults;
		}

		return array_merge( $defaults, self::DEFAULTS );
	}

	/**
	 * Whether one of this add-on's toggles is on.
	 *
	 * The stored value wins. A key missing from the stored array falls back
	 * to this class's own default rather than to "off": a site that saved
	 * the free plugin's settings before activating this add-on has an option
	 * row without these keys, and the free plugin's `default_option_*`
	 * filter only fills defaults in when the whole row is absent.
	 *
	 * @param string $field_id One of the FAQ_TAB / KB_LINKS / TOOLTIPS constants.
	 * @return bool False for a field this class doesn't own.
	 */
	public function is_enabled( string $field_id ): bool {
		if ( ! array_key_exists( $field_id, self::DEFAULTS ) ) {
			return false;
		}

		$settings = get_option( self::OPTION_KEY );

		if ( is_array( $settings ) && array_key_exists( $field_id, $settings ) ) {
			return ! empty( $settings[ $field_id ] );
		}

		return self::DEFAULTS[ $field_id ];
	}

	/**
	 * The section's field definitions, in the list-with-`id` shape
	 * docs/DESIGN-HOOKS-API.md section 3.5 documents for consumers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fields(): array {
		return array(
			array(
				'id'             => self::FAQ_TAB,
				'type'           => 'checkbox',
				'label'          => __( 'FAQ tab', 'saai-knowledge-for-woocommerce' ),
				'checkbox_label' => __( 'Add a FAQ tab to product pages, listing the FAQs linked to the product or its categories.', 'saai-knowledge-for-woocommerce' ),
				'default'        => self::DEFAULTS[ self::FAQ_TAB ],
			),
			array(
				'id'             => self::KB_LINKS,
				'type'           => 'checkbox',
				'label'          => __( 'Related documentation', 'saai-knowledge-for-woocommerce' ),
				'checkbox_label' => __( 'List the knowledge base articles linked to the product below its summary.', 'saai-knowledge-for-woocommerce' ),
				'default'        => self::DEFAULTS[ self::KB_LINKS ],
			),
			array(
				'id'             => self::TOOLTIPS,
				'type'           => 'checkbox',
				'label'          => __( 'Glossary tooltips', 'saai-knowledge-for-woocommerce' ),
				'checkbox_label' => __( 'Auto-link the glossary terms linked to the product in its short and long descriptions.', 'saai-knowledge-for-woocommerce' ),
				'default'        => self::DEFAULTS[ self::TOOLTIPS ],
			),
		);
	}
}
