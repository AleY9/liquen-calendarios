<?php
/**
 * Al borrar el plugin: se quitan sus ajustes, la tabla de ocupaciones, las fuentes guardadas
 * en las fichas y la tarea programada. Las fichas no se tocan.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lc_ocupaciones" ); // phpcs:ignore
delete_option( 'lc_ajustes' );
delete_option( 'lc_db_version' );
delete_option( 'lc_cache_version' );
delete_option( 'lc_ultima_ronda' );
delete_site_transient( 'lc_ultima_release' );
delete_transient( 'lc_leyendo' );
delete_post_meta_by_key( '_lc_fuentes' );
delete_post_meta_by_key( '_lc_estado' );
delete_post_meta_by_key( '_lc_modo' );
wp_clear_scheduled_hook( 'lc_sincronizar_todo' );
