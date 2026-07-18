<?php
/**
 * Classic-theme fallback template for the single saai_kb view.
 *
 * Placeholder until the KB two-column layout (M2) replaces it. Themes may
 * override this by providing saai-knowledge/single-saai_kb.php.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-knowledge-placeholder">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class(); ?>>
			<h1><?php the_title(); ?></h1>
			<?php the_content(); ?>
		</article>
		<?php
	endwhile;
	?>
</main>

<?php
get_footer();
