<?php
/**
 * Classic-theme fallback template for the single saai_glossary view.
 *
 * Placeholder until the glossary term display (M3) replaces it. Themes may
 * override this by providing saai-knowledge/single-saai_glossary.php.
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
