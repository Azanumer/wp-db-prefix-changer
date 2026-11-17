<?php
/**
 * Plugin Name: WP DB Prefix Changer
 * Plugin URI: https://github.com/Azanumer/wp-db-prefix-changer
 * Description: Safely rename the WordPress database table prefix — renames every table, fixes prefixed option and user-meta keys, and rewrites wp-config.php with a timestamped backup. Dry-run preview first, live run behind an "I UNDERSTAND" gate.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-db-prefix-changer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WDPC_VERSION', '1.0.0' );
define( 'WDPC_OPTION', 'wdpc_last_run' );

/**
 * A valid prefix: letters, digits and underscores, must end with "_".
 */
function wdpc_is_valid_prefix( $prefix ) {
    return is_string( $prefix )
        && '' !== $prefix
        && 1 === preg_match( '/^[A-Za-z0-9_]+_$/', $prefix );
}

/**
 * All tables currently using the given prefix.
 */
function wdpc_prefixed_tables( $prefix ) {
    global $wpdb;
    $like   = $wpdb->esc_like( $prefix ) . '%';
    $tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
    return is_array( $tables ) ? $tables : array();
}

/**
 * Locate wp-config.php (same dir as ABSPATH, or one level up).
 */
function wdpc_config_path() {
    $in_root = ABSPATH . 'wp-config.php';
    if ( file_exists( $in_root ) ) {
        return $in_root;
    }
    $one_up = dirname( ABSPATH ) . '/wp-config.php';
    if ( file_exists( $one_up ) ) {
        return $one_up;
    }
    return $in_root;
}

/**
 * Dry-run summary for a target prefix. Never writes anything.
 *
 * @return array{ok: bool, errors: string[], old: string, new: string,
 *               tables: string[], options: int, usermeta: int,
 *               config: string, config_writable: bool}
 */
function wdpc_plan( $new_prefix ) {
    global $wpdb;
    $old    = $wpdb->prefix;
    $errors = array();

    if ( is_multisite() ) {
        $errors[] = __( 'Multisite is not supported — each subsite shares tables this tool cannot safely rename.', 'wp-db-prefix-changer' );
    }
    if ( ! wdpc_is_valid_prefix( $new_prefix ) ) {
        $errors[] = __( 'New prefix must contain only letters, digits and underscores and end with an underscore.', 'wp-db-prefix-changer' );
    }
    if ( $new_prefix === $old ) {
        $errors[] = __( 'The new prefix is the same as the current one.', 'wp-db-prefix-changer' );
    }

    $tables = wdpc_prefixed_tables( $old );
    if ( empty( $tables ) ) {
        $errors[] = __( 'No tables found with the current prefix.', 'wp-db-prefix-changer' );
    }

    // Refuse if any table already uses the target prefix.
    if ( wdpc_is_valid_prefix( $new_prefix ) ) {
        $clash = wdpc_prefixed_tables( $new_prefix );
        if ( ! empty( $clash ) ) {
            $errors[] = sprintf(
                /* translators: %s: comma-separated table names. */
                __( 'Tables already use the target prefix: %s', 'wp-db-prefix-changer' ),
                implode( ', ', $clash )
            );
        }
    }

    $options  = 0;
    $usermeta = 0;
    if ( empty( $errors ) ) {
        $options  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $old ) . '%'
        ) );
        $usermeta = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like( $old ) . '%'
        ) );
    }

    $config          = wdpc_config_path();
    $config_writable = file_exists( $config ) && is_writable( $config ) && is_writable( dirname( $config ) );
    if ( ! file_exists( $config ) ) {
        $errors[] = __( 'wp-config.php was not found — the prefix in it must be updated by hand.', 'wp-db-prefix-changer' );
    } elseif ( ! $config_writable ) {
        $errors[] = __( 'wp-config.php (or its directory) is not writable — the file will need a manual edit after the rename.', 'wp-db-prefix-changer' );
    }

    return array(
        'ok'               => empty( $errors ),
        'errors'           => $errors,
        'old'              => $old,
        'new'              => $new_prefix,
        'tables'           => $tables,
        'options'          => $options,
        'usermeta'         => $usermeta,
        'config'           => $config,
        'config_writable'  => $config_writable,
    );
}

/**
 * Perform the rename. Assumes wdpc_plan() returned ok.
 *
 * @return array{renamed: int, options: int, usermeta: int, config_backup: string, config_updated: bool}
 */
function wdpc_run( $plan ) {
    global $wpdb;
    $old = $plan['old'];
    $new = $plan['new'];

    // 1. Rename tables in one RENAME TABLE statement (atomic per table).
    $pairs = array();
    foreach ( $plan['tables'] as $table ) {
        $pairs[] = '`' . str_replace( '`', '', $table ) . '` TO `' . str_replace( '`', '', $new . substr( $table, strlen( $old ) ) ) . '`';
    }
    // MySQL renames all listed tables atomically: if this fails, nothing
    // was renamed, so bail before touching keys or wp-config.php.
    if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ) ) {
        return false;
    }
    $renamed = count( $plan['tables'] );

    // 2. Rewrite prefixed keys in options and usermeta. $wpdb->prefix is
    //    still the OLD prefix here, so $wpdb->options points at the renamed
    //    table name only after we update the prefix property.
    $wpdb->set_prefix( $new );

    $options = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s",
        $new,
        strlen( $old ) + 1,
        $wpdb->esc_like( $old ) . '%'
    ) );
    $usermeta = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->usermeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
        $new,
        strlen( $old ) + 1,
        $wpdb->esc_like( $old ) . '%'
    ) );

    // 3. Rewrite $table_prefix in wp-config.php (backup first).
    $config_path   = $plan['config'];
    $backup_path   = $config_path . '.bak-' . gmdate( 'Ymd-His' );
    $config_updated = false;
    $contents      = file_get_contents( $config_path );
    if ( false !== $contents ) {
        $updated = preg_replace(
            "/(\\\$table_prefix\s*=\s*')[^']*(')/",
            '${1}' . $new . '${2}',
            $contents,
            1
        );
        // Verify with the same flexible spacing the regex allows — a
        // wp-config.php written as `$table_prefix='wp_';` must pass too.
        if ( null !== $updated && $updated !== $contents && 1 === preg_match( "/\\\$table_prefix\s*=\s*'" . preg_quote( $new, '/' ) . "'/", $updated ) ) {
            if ( copy( $config_path, $backup_path ) && false !== file_put_contents( $config_path, $updated, LOCK_EX ) ) {
                $config_updated = true;
            }
        }
    }

    return array(
        'renamed'        => $renamed,
        'options'        => (int) $options,
        'usermeta'       => (int) $usermeta,
        'config_backup'  => $config_updated ? $backup_path : '',
        'config_updated' => $config_updated,
    );
}

/**
 * Tools page.
 */
function wdpc_admin_menu() {
    add_management_page(
        __( 'DB Prefix Changer', 'wp-db-prefix-changer' ),
        __( 'DB Prefix Changer', 'wp-db-prefix-changer' ),
        'manage_options',
        'wdpc-prefix-changer',
        'wdpc_tools_page'
    );
}
add_action( 'admin_menu', 'wdpc_admin_menu' );

function wdpc_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    global $wpdb;

    echo '<div class="wrap"><h1>' . esc_html__( 'DB Prefix Changer', 'wp-db-prefix-changer' ) . '</h1>';
    echo '<p>' . esc_html__( 'Renames every WordPress table to a new prefix, fixes prefixed option and user-meta keys (capabilities, user levels), and rewrites $table_prefix in wp-config.php. Take a full database backup first — this cannot be undone from WordPress.', 'wp-db-prefix-changer' ) . '</p>';

    $last = get_option( WDPC_OPTION, array() );
    if ( ! empty( $last['time'] ) ) {
        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__( 'Last run:', 'wp-db-prefix-changer' ),
            esc_html( sprintf(
                /* translators: 1: date, 2: old prefix, 3: new prefix, 4: table count. */
                __( '%1$s — %2$s → %3$s (%4$d tables renamed)', 'wp-db-prefix-changer' ),
                $last['time'],
                $last['old'],
                $last['new'],
                $last['renamed']
            ) )
        );
    }

    // Live run.
    if ( isset( $_POST['wdpc_run'] ) ) {
        check_admin_referer( 'wdpc_run' );
        $new_prefix = isset( $_POST['wdpc_new_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['wdpc_new_prefix'] ) ) : '';
        $confirm    = isset( $_POST['wdpc_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['wdpc_confirm'] ) ) : '';

        if ( 'I UNDERSTAND' !== $confirm ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Type "I UNDERSTAND" to confirm — nothing was changed.', 'wp-db-prefix-changer' ) . '</p></div>';
        } else {
            $plan = wdpc_plan( $new_prefix );
            if ( ! $plan['ok'] ) {
                echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', $plan['errors'] ) ) . '</p></div>';
            } else {
                $result = wdpc_run( $plan );
                if ( false === $result ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'The table rename failed — nothing was changed. Check the database user has ALTER/CREATE privileges and try again.', 'wp-db-prefix-changer' ) . '</p></div>';
                } else {
                    update_option( WDPC_OPTION, array(
                        'time'    => current_time( 'mysql' ),
                        'old'     => $plan['old'],
                        'new'     => $plan['new'],
                        'renamed' => $result['renamed'],
                    ) );
                echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
                    /* translators: 1: tables renamed, 2: options updated, 3: user-meta keys updated. */
                    __( 'Done: %1$d tables renamed, %2$d option keys and %3$d user-meta keys updated.', 'wp-db-prefix-changer' ),
                    $result['renamed'],
                    $result['options'],
                    $result['usermeta']
                ) ) . '</p></div>';
                if ( $result['config_updated'] ) {
                    echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
                        /* translators: %s: backup file path. */
                        __( 'wp-config.php updated. Backup saved at %s', 'wp-db-prefix-changer' ),
                        $result['config_backup']
                    ) ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'wp-config.php could NOT be updated — edit $table_prefix by hand now, or the site will break.', 'wp-db-prefix-changer' ) . '</p></div>';
                }
                }
            }
        }
    }

    // Dry-run preview (or the default form).
    $preview = null;
    if ( isset( $_POST['wdpc_preview'] ) ) {
        check_admin_referer( 'wdpc_preview' );
        $preview = wdpc_plan( isset( $_POST['wdpc_new_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['wdpc_new_prefix'] ) ) : '' );
    }

    echo '<form method="post"><h2>' . esc_html__( 'New prefix', 'wp-db-prefix-changer' ) . '</h2>';
    echo '<p>' . esc_html( sprintf(
        /* translators: %s: current table prefix. */
        __( 'Current prefix: %s', 'wp-db-prefix-changer' ),
        $wpdb->prefix
    ) ) . '</p>';
    printf(
        '<p><label>%s <input type="text" name="wdpc_new_prefix" value="%s" placeholder="wp_x7k9_" class="regular-text"></label></p>',
        esc_html__( 'New prefix (letters, digits, underscores, must end with _):', 'wp-db-prefix-changer' ),
        isset( $_POST['wdpc_new_prefix'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['wdpc_new_prefix'] ) ) ) : ''
    );
    wp_nonce_field( 'wdpc_preview' );
    submit_button( __( 'Preview changes', 'wp-db-prefix-changer' ), 'secondary', 'wdpc_preview' );
    echo '</form>';

    if ( $preview ) {
        echo '<h2>' . esc_html__( 'Preview', 'wp-db-prefix-changer' ) . '</h2>';
        if ( ! $preview['ok'] ) {
            echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', $preview['errors'] ) ) . '</p></div>';
        } else {
            printf(
                '<p>%s</p>',
                esc_html( sprintf(
                    /* translators: 1: table count, 2: option keys, 3: user-meta keys. */
                    __( '%1$d tables will be renamed, %2$d option keys and %3$d user-meta keys updated, and $table_prefix in wp-config.php rewritten.', 'wp-db-prefix-changer' ),
                    count( $preview['tables'] ),
                    $preview['options'],
                    $preview['usermeta']
                ) )
            );
            echo '<ul>';
            foreach ( $preview['tables'] as $table ) {
                printf(
                    '<li><code>%s</code> &rarr; <code>%s</code></li>',
                    esc_html( $table ),
                    esc_html( $preview['new'] . substr( $table, strlen( $preview['old'] ) ) )
                );
            }
            echo '</ul>';

            echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'This renames your database tables. Did you take a backup?', 'wp-db-prefix-changer' ) ) . '\')">';
            printf( '<input type="hidden" name="wdpc_new_prefix" value="%s">', esc_attr( $preview['new'] ) );
            printf(
                '<p><label>%s <input type="text" name="wdpc_confirm" placeholder="I UNDERSTAND" class="regular-text"></label></p>',
                esc_html__( 'Type "I UNDERSTAND" to run it:', 'wp-db-prefix-changer' )
            );
            wp_nonce_field( 'wdpc_run' );
            submit_button( __( 'Rename tables now', 'wp-db-prefix-changer' ), 'primary', 'wdpc_run' );
            echo '</form>';
        }
    }

    echo '</div>';
}
