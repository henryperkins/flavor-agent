<?php
/**
 * Minimal classic-theme template for helper-ability smoke coverage.
 *
 * @package FlavorAgentClassicE2E
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main>
	<h1><?php bloginfo( 'name' ); ?></h1>
	<p>Classic theme fixture for Flavor Agent helper ability smoke coverage.</p>
</main>
<?php wp_footer(); ?>
</body>
</html>
