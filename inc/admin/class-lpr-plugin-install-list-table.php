<?php
/**
 * Plugin Installer List Table class.
 *
 * @package WordPress
 * @subpackage List_Table
 * @since 3.1.0
 * @access private
 */
class LPR_Plugin_Install_List_Table extends WP_List_Table {

    public $order = 'ASC';
    public $orderby = null;
    public $groups = array();

    private $error;
    private $test_mode = true;
    private $repo_url = 'http://thimpress.com/lprepo/';
    private $upgrader = false;
    public function ajax_user_can() {
        return current_user_can('install_plugins');
    }

    /**
     * Return a list of slugs of installed plugins, if known.
     *
     * Uses the transient data from the updates API to determine the slugs of
     * known installed plugins. This might be better elsewhere, perhaps even
     * within get_plugins().
     *
     * @since 4.0.0
     * @access protected
     */
    protected function get_installed_plugin_slugs() {
        $slugs = array();

        $plugin_info = get_site_transient( 'update_plugins' );
        if ( isset( $plugin_info->no_update ) ) {
            foreach ( $plugin_info->no_update as $plugin ) {
                $slugs[] = $plugin->slug;
            }
        }

        if ( isset( $plugin_info->response ) ) {
            foreach ( $plugin_info->response as $plugin ) {
                $slugs[] = $plugin->slug;
            }
        }

        return $slugs;
    }

    private function _get_items_from_wp(){

        global $tabs, $tab, $paged, $type, $term;
        $tab = 'search';

        wp_reset_vars( array( 'tab' ) );

        $paged = $this->get_pagenum();

        $per_page = 30;


        $args = array(
            'page' => $paged,
            'per_page' => $per_page,
            'fields' => array(
                'last_updated' => true,
                'icons' => true,
                'active_installs' => true
            ),
            // Send the locale and installed plugin slugs to the API so it can provide context-sensitive results.
            'locale' => get_locale(),
            'installed_plugins' => $this->get_installed_plugin_slugs(),
            'search' => 'woocommerce'
        );

        $transient_key = "learn_press_add_ons" . md5( serialize( $args ) );

        if( $plugins = get_transient( $transient_key ) ){
            $this->items = $plugins;
            return;
        }

        $api = plugins_api( 'query_plugins', $args );

        if ( is_wp_error( $api ) ) {
            $this->error = $api;
            return;
        }
        if( is_array( $api->plugins ) ) {
            $plugins = array_filter( $api->plugins, create_function( '$plugin', 'return $plugin->slug != \'learnpress\';' ));
            set_transient( $transient_key, $plugins, 60 * 5 );
        }
        $this->items = $plugins;
    }

    private function _get_items_from_thimpress( $add_ons ){
        $cache = WP_CONTENT_DIR . '/upgrade/' . md5( serialize( $add_ons ) ) . '.cache';
        $timeover = HOUR_IN_SECONDS * 24;
        if( file_exists( $cache ) && ( time() - filemtime( $cache ) ) < $timeover  ){
            $items = maybe_unserialize( file_get_contents( $cache ) );
        }else {
            $repo_url = 'http://thimpress.com/lprepo/';

            foreach ($add_ons as $slug) {
                $item = array(
                    'name' => '',
                    'slug' => '',
                    'version' => '0.0',
                    'author' => '<a href="http://profiles.wordpress.org/thimpress">thimpress</a>',
                    'author_profile' => 'http://profiles.wordpress.org/thimpress',
                    'contributors' => array(),
                    'requires' => '4.0',
                    'tested' => '4.2.2',
                    'rating' => 0,
                    'num_ratings' => 0,
                    'ratings' => array(
                        '5' => 0,
                        '4' => 0,
                        '3' => 0,
                        '2' => 0,
                        '1' => 0
                    ),

                    'active_installs' => 0,
                    'last_updated' => gmdate('Y-m-d h:iA', strtotime('last Friday', time())) . ' GMT',
                    'homepage' => 'http://thimpress.com/learnpress',
                    'short_description' => '',
                    'icons' => array(
                        '2x' => LPR_PLUGIN_URL . '/assets/images/icon-128x128.png',
                        '1x' => LPR_PLUGIN_URL . '/assets/images/icon-128x128.png'
                    )
                );
                $readme = $this->upgrader->get_plugin_info($repo_url . "/{$slug}.zip");
                $item['name'] = $readme['name'];
                $item['slug'] = $slug;
                if (preg_match('!<h4>(.*)<\/h4>!', $readme['sections']['changelog'], $matches)) {
                    $item['version'] = $matches[1];
                }
                $item['requires'] = $readme['requires_at_least'];
                $item['tested'] = $readme['tested_up_to'];

                $items["$slug/$slug.php"] = (object)$item;
            }
            file_put_contents( $cache, serialize($items));
        }
        $this->items = $items;
    }

    /**
     * Get list of addons
     */
    public function prepare_items() {
        global $learn_press_add_ons;
        $this->upgrader = new LPR_Upgrader();

        $page = ! empty( $_REQUEST['page'] ) ? $_REQUEST['page'] : '';
        $tab = ! empty( $_REQUEST['tab'] ) ? $_REQUEST['tab'] : '';
        if( 'learn_press_add_ons' != $page ) return;

        if( 'more' == $tab ){
            $add_ons = $learn_press_add_ons['more'];
        }elseif( 'bundle_activate' == $tab ){
            $add_ons = $learn_press_add_ons['bundle_activate'];
        }
        if( ! $this->test_mode ){
            $this->_get_items_from_wp();
        }else{
            $this->_get_items_from_thimpress( $add_ons );
        }
    }


    public function no_items() {
        $message = __( 'No plugins found.' );

        echo '<div class="no-plugin-results">' . $message . '</div>';
    }

    protected function get_views() {
        global $tabs, $tab;

        $display_tabs = array();
        foreach ( (array) $tabs as $action => $text ) {
            $class = ( $action == $tab ) ? ' current' : '';
            $href = self_admin_url('plugin-install.php?tab=' . $action);
            $display_tabs['plugin-install-'.$action] = "<a href='$href' class='$class'>$text</a>";
        }
        // No longer a real tab.
        unset( $display_tabs['plugin-install-upload'] );

        return $display_tabs;
    }

    /**
     * Override parent views so we can use the filter bar display.
     */
    public function views() {
        $views = $this->get_views();

        /** This filter is documented in wp-admin/inclues/class-wp-list-table.php */
        $views = apply_filters( "views_{$this->screen->id}", $views );

        ?>
        <div class="wp-filter">
            <ul class="filter-links">
                <?php
                if ( ! empty( $views ) ) {
                    foreach ( $views as $class => $view ) {
                        $views[ $class ] = "\t<li class='$class'>$view";
                    }
                    echo implode( " </li>\n", $views ) . "</li>\n";
                }
                ?>
            </ul>

            <?php install_search_form( isset( $views['plugin-install-search'] ) ); ?>
        </div>
    <?php
    }

    /**
     * Override the parent display() so we can provide a different container.
     */
    public function display() {
        $data_attr = '';
        ?>
        <div class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">

            <div id="the-list"<?php echo $data_attr; ?>>
                <?php $this->display_rows_or_placeholder(); ?>
            </div>
        </div>
        <?php
        $this->display_tablenav( 'bottom' );
    }

    /**
     * @param string $which
     */
    protected function display_tablenav( $which ) {
        if ( 'top' ==  $which ) {
            wp_referer_field();
            ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <?php
                    /**
                     * Fires before the Plugin Install table header pagination is displayed.
                     *
                     * @since 2.7.0
                     */
                    do_action( 'install_plugins_table_header' ); ?>
                </div>
                <?php $this->pagination( $which ); ?>
                <br class="clear" />
            </div>
        <?php } else { ?>
            <div class="tablenav bottom">
                <?php $this->pagination( $which ); ?>
                <br class="clear" />
            </div>
        <?php
        }
    }

    protected function get_table_classes() {
        return array( 'widefat', $this->_args['plural'] );
    }

    public function get_columns() {
        return array();
    }

    /**
     * @param object $plugin_a
     * @param object $plugin_b
     * @return int
     */
    private function order_callback( $plugin_a, $plugin_b ) {
        $orderby = $this->orderby;
        if ( ! isset( $plugin_a->$orderby, $plugin_b->$orderby ) ) {
            return 0;
        }

        $a = $plugin_a->$orderby;
        $b = $plugin_b->$orderby;

        if ( $a == $b ) {
            return 0;
        }

        if ( 'DESC' == $this->order ) {
            return ( $a < $b ) ? 1 : -1;
        } else {
            return ( $a < $b ) ? -1 : 1;
        }
    }

    public function display_rows() {
        $plugins_allowedtags = array(
            'a' => array( 'href' => array(),'title' => array(), 'target' => array() ),
            'abbr' => array( 'title' => array() ),'acronym' => array( 'title' => array() ),
            'code' => array(), 'pre' => array(), 'em' => array(),'strong' => array(),
            'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array(), 'br' => array()
        );

        $plugins_group_titles = array(
            'Performance' => _x( 'Performance', 'Plugin installer group title' ),
            'Social'      => _x( 'Social',      'Plugin installer group title' ),
            'Tools'       => _x( 'Tools',       'Plugin installer group title' ),
        );

        $tab = ! empty( $_REQUEST['tab'] ) ? $_REQUEST['tab'] : '';
        $group = null;

        foreach ( (array) $this->items as $kk => $plugin ) {
            if ( is_object( $plugin ) ) {
                $plugin = (array) $plugin;
            }

            // Display the group heading if there is one
            if ( isset( $plugin['group'] ) && $plugin['group'] != $group ) {
                if ( isset( $this->groups[ $plugin['group'] ] ) ) {
                    $group_name = $this->groups[ $plugin['group'] ];
                    if ( isset( $plugins_group_titles[ $group_name ] ) ) {
                        $group_name = $plugins_group_titles[ $group_name ];
                    }
                } else {
                    $group_name = $plugin['group'];
                }

                // Starting a new group, close off the divs of the last one
                if ( ! empty( $group ) ) {
                    echo '</div></div>';
                }

                echo '<div class="plugin-group"><h3>' . esc_html( $group_name ) . '</h3>';
                // needs an extra wrapping div for nth-child selectors to work
                echo '<div class="plugin-items">';

                $group = $plugin['group'];
            }
            $title = wp_kses( $plugin['name'], $plugins_allowedtags );

            // Remove any HTML from the description.
            $description = strip_tags( $plugin['short_description'] );
            $version = wp_kses( $plugin['version'], $plugins_allowedtags );

            $name = strip_tags( $title . ' ' . $version );

            $author = wp_kses( $plugin['author'], $plugins_allowedtags );
            if ( ! empty( $author ) ) {
                $author = ' <cite>' . sprintf( __( 'By %s' ), $author ) . '</cite>';
            }

            $action_links = array();

            if ( current_user_can( 'install_plugins' ) || current_user_can( 'update_plugins' ) ) {

                if( $this->test_mode ){
                    $status = array(
                        'status'    => file_exists( WP_PLUGIN_DIR . '/' . $plugin['slug'] ) ? 'newer_installed' : 'install' ,
                        'url'       => admin_url( 'admin-ajax.php?action=learnpress_plugin_install&plugin=' . $plugin['slug'] )
                    );
                }else{
                    $status = install_plugin_install_status( $plugin );
                }

                switch ( $status['status'] ) {
                    case 'install':
                        if ( $status['url'] ) {
                            /* translators: 1: Plugin name and version. */
                            $action_links[] = '<a class="install-now button thimpress" data-action="install-now" data-slug="' . esc_attr( $plugin['slug'] ) . '" href="' . esc_url( $status['url'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Install %s now' ), $name ) ) . '" data-name="' . esc_attr( $name ) . '">' . __( 'Install Now' ) . '</a>';
                        }

                        break;
                    case 'update_available':
                        if ( $status['url'] ) {
                            /* translators: 1: Plugin name and version */
                            $action_links[] = '<a class="update-now button" data-plugin="' . esc_attr( $status['file'] ) . '" data-slug="' . esc_attr( $plugin['slug'] ) . '" href="' . esc_url( $status['url'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Update %s now' ), $name ) ) . '" data-name="' . esc_attr( $name ) . '">' . __( 'Update Now' ) . '</a>';
                        }

                        break;
                    case 'latest_installed':
                    case 'newer_installed':
                        $action_links[] = '<span class="button button-disabled" title="' . esc_attr__( 'This plugin is already installed and is up to date' ) . ' ">' . _x( 'Installed', 'plugin' ) . '</span>';
                        break;
                }
            }

            /*$details_link   = self_admin_url( 'plugin-install.php?tab=plugin-information&amp;plugin=' . $plugin['slug'] .
                '&amp;TB_iframe=true&amp;width=600&amp;height=550' );


            $action_links[] = '<a href="' . esc_url( $details_link ) . '" class="thickbox" aria-label="' . esc_attr( sprintf( __( 'More information about %s' ), $name ) ) . '" data-title="' . esc_attr( $name ) . '">' . __( 'More Details' ) . '</a>';
            */
            if ( !empty( $plugin['icons']['svg'] ) ) {
                $plugin_icon_url = $plugin['icons']['svg'];
            } elseif ( !empty( $plugin['icons']['2x'] ) ) {
                $plugin_icon_url = $plugin['icons']['2x'];
            } elseif ( !empty( $plugin['icons']['1x'] ) ) {
                $plugin_icon_url = $plugin['icons']['1x'];
            } else {
                $plugin_icon_url = $plugin['icons']['default'];
            }

            /**
             * Filter the install action links for a plugin.
             *
             * @since 2.7.0
             *
             * @param array $action_links An array of plugin action hyperlinks. Defaults are links to Details and Install Now.
             * @param array $plugin       The plugin currently being listed.
             */
            $action_links = apply_filters( 'plugin_install_action_links', $action_links, $plugin );

            $date_format = __( 'M j, Y @ H:i' );
            $last_updated_timestamp = strtotime( $plugin['last_updated'] );
            $details_link = "";
            $message = null;
            if( 'bundle_activate' == $tab ){
                if( file_exists( WP_PLUGIN_DIR . '/' . $kk ) ) {
                    if (is_plugin_active($kk)) {
                        $message = sprintf('<span class="enabled">%s</span>', __('Enabled', 'learn_press'));
                    } else {
                        $message = sprintf('<span class="disabled">%s</span>', __('Disabled', 'learn_press'));
                    }
                }
            }

            ?>
            <div class="plugin-card plugin-card-<?php echo sanitize_html_class( $plugin['slug'] ); ?>">
                <div class="plugin-card-top">
                    <a href="<?php echo esc_url( $details_link ); ?>" class="thickbox plugin-icon"><img src="<?php echo esc_attr( $plugin_icon_url ) ?>" /></a>
                    <div class="name column-name">
                        <h4><a href="<?php echo esc_url( $details_link ); ?>" class="thickbox"><?php echo $title; ?></a></h4>
                    </div>
                    <div class="action-links">
                        <?php
                        if ( $action_links ) {
                            echo '<ul class="plugin-action-buttons"><li>' . implode( '</li><li>', $action_links ) . '</li></ul>';
                        }
                        echo $message;
                        ?>
                    </div>
                    <div class="desc column-description">
                        <p><?php echo $description; ?></p>
                        <p class="authors"><?php echo $author; ?></p>
                    </div>
                </div>
                <div class="plugin-card-bottom">
                    <div class="vers column-rating">
                        <?php wp_star_rating( array( 'rating' => $plugin['rating'], 'type' => 'percent', 'number' => $plugin['num_ratings'] ) ); ?>
                        <span class="num-ratings">(<?php echo number_format_i18n( $plugin['num_ratings'] ); ?>)</span>
                    </div>
                    <div class="column-updated">
                        <strong><?php _e( 'Last Updated:' ); ?></strong> <span title="<?php echo esc_attr( date_i18n( $date_format, $last_updated_timestamp ) ); ?>">
						<?php printf( __( '%s ago' ), human_time_diff( $last_updated_timestamp ) ); ?>
					</span>
                    </div>
                    <div class="column-downloaded">
                        <?php
                        if ( $plugin['active_installs'] >= 1000000 ) {
                            $active_installs_text = _x( '1+ Million', 'Active plugin installs' );
                        } else {
                            $active_installs_text = number_format_i18n( $plugin['active_installs'] ) . '+';
                        }
                        printf( __( '%s Active Installs' ), $active_installs_text );
                        ?>
                    </div>
                    <div class="column-compatibility">
                        <?php
                        if ( ! empty( $plugin['tested'] ) && version_compare( substr( $GLOBALS['wp_version'], 0, strlen( $plugin['tested'] ) ), $plugin['tested'], '>' ) ) {
                            echo '<span class="compatibility-untested">' . __( 'Untested with your version of WordPress' ) . '</span>';
                        } elseif ( ! empty( $plugin['requires'] ) && version_compare( substr( $GLOBALS['wp_version'], 0, strlen( $plugin['requires'] ) ), $plugin['requires'], '<' ) ) {
                            echo '<span class="compatibility-incompatible">' . __( '<strong>Incompatible</strong> with your version of WordPress' ) . '</span>';
                        } else {
                            echo '<span class="compatibility-compatible">' . __( '<strong>Compatible</strong> with your version of WordPress' ) . '</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        <?php
        }

        // Close off the group divs of the last one
        if ( ! empty( $group ) ) {
            echo '</div></div>';
        }
    }
}