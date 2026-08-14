<?php
/**
 * Classic-theme fallback template for the single saai_glossary view.
 *
 * The DefinedTerm JSON-LD and saai_glossary_after_definition insertion point
 * are wired via Glossary_Term (wp_head / the_content filters), not this
 * template file, so a theme overriding this via
 * saai-knowledge/single-saai_glossary.php keeps both as long as it renders
 * the body through the standard the_content() call below.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-glossary-term">
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
