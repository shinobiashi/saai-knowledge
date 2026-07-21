<?php
/**
 * Classic-theme fallback template for the single saai_kb view.
 *
 * Renders the same two-column layout as the block-theme template
 * (block-templates/single-saai_kb.html) via do_blocks(). Themes may
 * override this by providing saai-knowledge/single-saai_kb.php.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-kb-layout saai-kb-layout--article">
<div class="saai-kb-layout__grid">
	<details class="saai-kb-layout__sidebar" open>
		<summary><?php esc_html_e( 'Categories', 'saai-knowledge' ); ?></summary>
		<?php echo do_blocks( '<!-- wp:saai-knowledge/kb-sidebar /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
	</details>

	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'saai-kb-layout__content' ); ?>>
			<?php echo do_blocks( '<!-- wp:saai-knowledge/breadcrumbs /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
			<h1><?php the_title(); ?></h1>
			<?php the_content(); ?>
		</article>
		<?php
	endwhile;
	?>

	<details class="saai-kb-layout__toc" open>
		<summary><?php esc_html_e( 'Table of contents', 'saai-knowledge' ); ?></summary>
		<?php echo do_blocks( '<!-- wp:saai-knowledge/kb-toc /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
	</details>
</div>
</main>

<?php
get_footer();
