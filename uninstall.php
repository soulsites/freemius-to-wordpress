<?php
/**
 * Entfernt die Plugin-Einstellungen beim Deinstallieren.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'fsd_settings' );
delete_option( 'fsd_email_settings' );
delete_option( 'fsd_activecampaign_settings' );
delete_option( 'fsd_brevo_settings' );
delete_option( 'fsd_brevo_log' );
delete_option( 'fsd_brevo_deleted_contacts' );
delete_option( 'fsd_brevo_daily_last' );
wp_clear_scheduled_hook( 'fsd_brevo_daily_reconcile' );
wp_clear_scheduled_hook( 'fsd_brevo_daily_reconcile_step' );
