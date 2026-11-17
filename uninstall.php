<?php
/**
 * Uninstall: remove the last-run record.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wdpc_last_run' );
