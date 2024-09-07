<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'vnszojtc_wp885' );

/** Database username */
define( 'DB_USER', 'vnszojtc_wp885' );

/** Database password */
define( 'DB_PASSWORD', 'kSz![419pW' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'rtgbj4xqhicmg26ybboxgol67e9nhjno2r3bayhx9szyihnu0z2qfyzqnyhfi6kn' );
define( 'SECURE_AUTH_KEY',  'cr37nmvsmmdvlabouysc4hdzf10zxkmytupnxcgzxn38m1f3jjc0tj6h60qkffio' );
define( 'LOGGED_IN_KEY',    'woxalit01ftoff1gmsrmqkp01unox1npt8ihgrw32vf8mswhwvtc2zgf2ss1mog8' );
define( 'NONCE_KEY',        'yoesdeht7ackeqokbydyi5ygusn2rmubmam5lxwo2h2gvfwkif4mprne5c2zpiqx' );
define( 'AUTH_SALT',        'azwbcppgoe7pasqcfemzbxclgxndtqugen24i0x6uyivvo4etfsiygmmam5abayo' );
define( 'SECURE_AUTH_SALT', 'pyp71vgxwpru1odgwjxptngypmtonilcox0nj5kpfhd00blznmlsoy0edrgii6hx' );
define( 'LOGGED_IN_SALT',   'rvjyvxexhmhmsy2hbbip8gssvobqvly9nzradp16z95a01emat7muus9zwra3ycl' );
define( 'NONCE_SALT',       'islfrvuuhfjw1jeutosxrzrt960bgfwi6shq4kulalqiszbfpriurbofovhdwrcn' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wpjp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
