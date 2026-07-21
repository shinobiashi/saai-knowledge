<?php
/**
 * Classic-theme fallback template for the saai_kb post type archive (the KB hub).
 *
 * Renders the same two-column layout as the block-theme template
 * (block-templates/archive-saai_kb.html). Themes may override this by
 * providing saai-knowledge/archive-saai_kb.php.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-kb-layout saai-kb-layout--archive">
<div class="saai-kb-layout__grid">
	<details class="saai-kb-layout__sidebar" open>
		<summary><?php esc_html_e( 'Categories', 'saai-knowledge' ); ?></summary>
		<?php echo do_blocks( '<!-- wp:saai-knowledge/kb-sidebar /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
	</details>

	<div class="saai-kb-layout__content">
		<?php echo do_blocks( '<!-- wp:saai-knowledge/breadcrumbs /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() output of a trusted, hardcoded block. ?>
		<h1><?php the_archive_title(); ?></h1>

		<?php if ( have_posts() ) : ?>
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class(); ?>>
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				</article>
				<?php
			endwhile;

			the_posts_pagination();
		else :
			?>
			<p><?php esc_html_e( 'No knowledge base articles found.', 'saai-knowledge' ); ?></p>
			<?php
		endif;
		?>
	</div>
</div>
</main>

<?php
get_footer();
