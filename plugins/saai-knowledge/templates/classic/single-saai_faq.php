<?php
/**
 * Classic-theme fallback template for the single saai_faq view.
 *
 * The QAPage JSON-LD is wired via Faq_Question (wp_head), not this template
 * file, so a theme overriding this via saai-knowledge/single-saai_faq.php
 * keeps it as long as it renders the answer through the standard
 * the_content() call below. Question = h1, answer directly below (no other
 * markup between them) per docs/DESIGN.md section 7.1's "1 Q&A = 1 URL" rule.
 *
 * @package SAAI\Knowledge
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="saai-faq-single">
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
