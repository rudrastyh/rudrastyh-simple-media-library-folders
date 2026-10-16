<?php
class SMLF_Term_List_Walker extends Walker {

	var $db_fields = array(
		'parent' => 'parent',
		'id'     => 'term_id',
	);

	function start_lvl( &$output, $depth = 0, $args = array() ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "$indent<ul class='children' style='margin-left:18px;'>\n";
	}

	function end_lvl( &$output, $depth = 0, $args = array() ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "$indent</ul>\n";
	}

	function start_el( &$output, $term, $depth = 0, $args = array(), $id = 0 ) {

		$output .= sprintf(
			"\n<li id='%s-%s'><label class='selectit'><input value='%s' type='checkbox' name='%s' id='%s' data-id='%d' %s %s /> %s</label>",
			$term->taxonomy,
			$term->term_id,
			$term->slug,
			"smlf_folders_input[{$term->slug}]",
			"in-{$term->taxonomy}-{$term->term_id}",
			$term->term_id,
			checked( in_array( $term->term_id, $args[ 'selected_cats' ] ), true, false ),
			disabled( empty( $args[ 'disabled' ] ), false, false ),
			esc_html( $term->name )
		);

	}

	function end_el( &$output, $term, $depth = 0, $args = array() ) {
		$output .= "</li>\n";
	}

}