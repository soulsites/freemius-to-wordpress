<?php
/**
 * Entfernt die Plugin-Einstellungen beim Deinstallieren.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'fsd_settings' );
delete_option( 'fsd_email_settings' );
