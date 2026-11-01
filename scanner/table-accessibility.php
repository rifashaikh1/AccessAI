<?php
/**
 * AccessAI — Table Accessibility Scanner
 * WCAG 2.1 Success Criterion 1.3.1 Info and Relationships (Level A)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan published post/page content for table accessibility issues.
 *
 * @param int $post_id Target post ID (0 for all published).
 * @return array Array of table accessibility findings.
 */
function accessai_scan_table_accessibility( $post_id = 0 ) {
	$issues = array();

	$args = array(
		'post_type'      => array( 'page', 'post' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	);

	if ( $post_id ) {
		$args['p'] = absint( $post_id );
	}

	$items = get_posts( $args );

	foreach ( $items as $item ) {
		$html = $item->post_content;
		if ( empty( $html ) ) {
			continue;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

		$tables = $dom->getElementsByTagName( 'table' );

		foreach ( $tables as $table ) {
			$role = strtolower( trim( $table->getAttribute( 'role' ) ) );
			$is_presentation = in_array( $role, array( 'presentation', 'none' ), true );

			$ths = $table->getElementsByTagName( 'th' );
			$tds = $table->getElementsByTagName( 'td' );
			$trs = $table->getElementsByTagName( 'tr' );

			$th_count = $ths->length;
			$td_count = $tds->length;
			$tr_count = $trs->length;

			// If it's explicitly marked as presentation or single-cell wrapper
			if ( $is_presentation ) {
				// If a presentation table contains <th> elements, that creates confusing conflicting semantics
				if ( $th_count > 0 ) {
					$issues[] = array(
						'category'    => 'Table Accessibility',
						'type'        => 'Header in Layout Table',
						'wcag'        => '1.3.1 Info and Relationships (Level A)',
						'severity'    => 'Medium',
						'status'      => 'Verified Failure',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>&lt;table role="' . esc_attr( $role ) . '"&gt;</code>',
						'table_summary' => 'Layout table with ' . $th_count . ' <th> tags',
						'message'     => 'A table marked with role="presentation" or role="none" contains <th> elements, which conflicts with layout presentation semantics.',
						'remediation' => 'Remove <th> elements from layout tables, or remove role="presentation" if the table contains genuine tabular data.',
					);
				}
				continue;
			}

			// Single cell or 1-row 1-col tables are often layout tables
			if ( $tr_count <= 1 && $td_count <= 1 && $th_count === 0 ) {
				$issues[] = array(
					'category'    => 'Table Accessibility',
					'type'        => 'Table Used for Layout',
					'wcag'        => '1.3.1 Info and Relationships (Level A)',
					'severity'    => 'Low',
					'status'      => 'Manual Review Required',
					'page_id'     => $item->ID,
					'page_title'  => $item->post_title,
					'element'     => '<code>&lt;table&gt; (' . $tr_count . ' row, ' . $td_count . ' cell)</code>',
					'table_summary' => 'Single-cell table structure',
					'message'     => 'This table has only 1 cell and may be used for visual layout rather than presenting tabular data.',
					'remediation' => 'Use modern CSS Flexbox or Grid for visual positioning instead of HTML table markup.',
				);
				continue;
			}

			// Genuine data table check: Has multiple rows and cells
			if ( $tr_count >= 2 && $td_count >= 2 ) {
				// Check 1: Missing table headers
				if ( $th_count === 0 ) {
					$issues[] = array(
						'category'    => 'Table Accessibility',
						'type'        => 'Data Table Missing Header Cells',
						'wcag'        => '1.3.1 Info and Relationships (Level A)',
						'severity'    => 'High',
						'status'      => 'Verified Failure',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>&lt;table&gt; (' . $tr_count . ' rows, ' . $td_count . ' data cells)</code>',
						'table_summary' => 'Data table with 0 <th> headers',
						'message'     => 'Data table has no <th> header cells. Screen reader users cannot correlate cell contents with column or row topics.',
						'remediation' => 'Convert the top row or leading column cells from <td> to <th>, and include scope="col" or scope="row".',
					);
					continue;
				}

				// Check 2: Inspect header scope associations
				$missing_scope_count = 0;
				$empty_th_count      = 0;
				foreach ( $ths as $th ) {
					$th_text = trim( $th->textContent );
					if ( $th_text === '' ) {
						$empty_th_count++;
					}
					if ( ! $th->hasAttribute( 'scope' ) && ! $th->hasAttribute( 'id' ) ) {
						$missing_scope_count++;
					}
				}

				if ( $empty_th_count > 0 ) {
					$issues[] = array(
						'category'    => 'Table Accessibility',
						'type'        => 'Empty Table Header Cell',
						'wcag'        => '1.3.1 Info and Relationships (Level A)',
						'severity'    => 'Medium',
						'status'      => 'Verified Failure',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>&lt;th&gt;&lt;/th&gt;</code> (' . $empty_th_count . ' empty headers)',
						'table_summary' => $empty_th_count . ' empty <th> cell(s)',
						'message'     => 'One or more <th> elements contain no text. Screen readers cannot announce a topic for the associated column or row.',
						'remediation' => 'Add descriptive text to all <th> header cells.',
					);
				}

				// If there are complex tables (multiple rows of th) without scope
				if ( $th_count > 1 && $missing_scope_count > 0 ) {
					$issues[] = array(
						'category'    => 'Table Accessibility',
						'type'        => 'Table Header Missing Scope Attribute',
						'wcag'        => '1.3.1 Info and Relationships (Level A)',
						'severity'    => 'Low',
						'status'      => 'Potential Issue',
						'page_id'     => $item->ID,
						'page_title'  => $item->post_title,
						'element'     => '<code>&lt;th&gt; (' . $missing_scope_count . ' of ' . $th_count . ' missing scope)</code>',
						'table_summary' => $missing_scope_count . ' <th> headers without scope',
						'message'     => 'Table headers lack the scope attribute (scope="col" or scope="row") to explicitly declare direction for assistive tech.',
						'remediation' => 'Add scope="col" for column headers and scope="row" for row headers.',
					);
				}
			}
		}

		libxml_clear_errors();
	}

	return $issues;
}
