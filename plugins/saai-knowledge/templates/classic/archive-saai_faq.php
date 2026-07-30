<?php
/**
 * Classic-theme fallback template for the saai_faq post type archive.
 *
 * Renders the same per-category FAQ accordion as the block-theme template
 * (block-templates/archive-saai_faq.html). Themes may override this by
 * providing saai-knowledge/archive-saai_faq.php.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-faq-archive">
	<h1><?php the_archive_title(); ?></h1>

	<?php echo do_blocks( '<!-- wp:saai-knowledge/faq-list {"groupByCategory":true} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
</main>

<?php
get_footer();
