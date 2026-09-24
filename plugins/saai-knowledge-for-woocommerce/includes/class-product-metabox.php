<?php
/**
 * The reverse-linking meta box on the product edit screen.
 *
 * @package SAAI\KnowledgeWoo
 */

namespace SAAI\KnowledgeWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "SAAI Knowledge" meta box on products and enqueues the script
 * that fills it (docs/DESIGN.md section 6.1, product side).
 *
 * The list itself is rendered client-side from
 * `saai-knowledge-woo/v1/products/<id>/linked-content` rather than printed
 * here: listing, adding, and removing all go through that one route, so the
 * markup has a single source instead of a PHP copy that has to stay in step
 * with the JS one after every add or remove.
 *
 * There is no nonce field because nothing in this box is submitted with the
 * product: every write is a REST request carrying the `X-WP-Nonce` header
 * that `wp-api-fetch` installs, and Links_Controller re-checks the
 * capabilities on its own.
 */
final class Product_Metabox {

	/**
	 * Meta box ID.
	 *
	 * @var string
	 */
	public const BOX_ID = 'saai-knowledge-links';

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	public const HANDLE = 'saai-knowledge-woo-product-links';

	/**
	 * Hooks the meta box and its assets into WordPress.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Link_Resolver::PRODUCT_POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the meta box to the product edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			self::BOX_ID,
			__( 'SAAI Knowledge', 'saai-knowledge-for-woocommerce' ),
			array( $this, 'render' ),
			Link_Resolver::PRODUCT_POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Renders the meta box container the script mounts into.
	 *
	 * @param \WP_Post $post The product being edited.
	 */
	public function render( \WP_Post $post ): void {
		?>
		<div class="saai-woo-product-links" data-product-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<noscript>
				<p><?php esc_html_e( 'Linking FAQs, knowledge base articles, and glossary terms to this product requires JavaScript. You can also edit the link from the content itself.', 'saai-knowledge-for-woocommerce' ); ?></p>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Enqueues the meta box script and style on the product edit screen only.
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || Link_Resolver::PRODUCT_POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file   = SAAI_KNOWLEDGE_WOO_DIR . 'assets/js/product-links-metabox.asset.php';
		$dependencies = array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-url' );
		$version      = SAAI_KNOWLEDGE_WOO_VERSION;

		// See Content_Editor::enqueue_panel_script() for why an asset file is
		// honoured even though nothing generates one yet.
		if ( file_exists( $asset_file ) ) {
			$asset        = require $asset_file;
			$dependencies = $asset['dependencies'];
			$version      = $asset['version'];
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/product-links-metabox.js', SAAI_KNOWLEDGE_WOO_DIR . 'saai-knowledge-for-woocommerce.php' ),
			$dependencies,
			$version,
			true
		);

		// Keeps the REST namespace declared in one place (PHP) instead of
		// repeating the literal in the script.
		wp_add_inline_script(
			self::HANDLE,
			'window.saaiKnowledgeWooLinks = ' . wp_json_encode( array( 'restNamespace' => Links_Controller::NAMESPACE_ROUTE ) ) . ';',
			'before'
		);

		wp_set_script_translations( self::HANDLE, 'saai-knowledge-for-woocommerce' );

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/css/product-links-metabox.css', SAAI_KNOWLEDGE_WOO_DIR . 'saai-knowledge-for-woocommerce.php' ),
			array( 'wp-components' ),
			$version
		);
	}
}
