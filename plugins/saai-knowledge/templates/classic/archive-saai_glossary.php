<?php
/**
 * Classic-theme fallback template for the saai_glossary post type archive.
 *
 * Renders the same 五十音/A–Z glossary index as the block-theme template
 * (block-templates/archive-saai_glossary.html). Themes may override this by
 * providing saai-knowledge/archive-saai_glossary.php.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-glossary-archive">
	<h1><?php the_archive_title(); ?></h1>

	<?php echo do_blocks( '<!-- wp:saai-knowledge/glossary-index /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
</main>

<?php
get_footer();
