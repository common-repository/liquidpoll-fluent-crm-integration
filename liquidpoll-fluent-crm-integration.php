<?php
/**
 * Plugin Name: LiquidPoll - FluentCRM Integration
 * Plugin URI: https://liquidpoll.com/plugin/liquidpoll-fluent-crm-integration
 * Description: Integration with FluentCRM
 * Version: 1.0.4
 * Author: LiquidPoll
 * Text Domain: liquidpoll-fluent-crm-integration
 * Domain Path: /languages/
 * Author URI: https://liquidpoll.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use WPDK\Utils;

defined( 'ABSPATH' ) || exit;
defined( 'LIQUIDPOLL_FLUENTCRM_PLUGIN_URL' ) || define( 'LIQUIDPOLL_FLUENTCRM_PLUGIN_URL', WP_PLUGIN_URL . '/' . plugin_basename( dirname( __FILE__ ) ) . '/' );
defined( 'LIQUIDPOLL_FLUENTCRM_PLUGIN_DIR' ) || define( 'LIQUIDPOLL_FLUENTCRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'LIQUIDPOLL_FLUENTCRM_PLUGIN_FILE' ) || define( 'LIQUIDPOLL_FLUENTCRM_PLUGIN_FILE', plugin_basename( __FILE__ ) );


if ( ! class_exists( 'LIQUIDPOLL_Integration_fluent_crm' ) ) {
	/**
	 * Class LIQUIDPOLL_Integration_fluent_crm
	 */
	class LIQUIDPOLL_Integration_fluent_crm {

		protected static $_instance = null;

		/**
		 * LIQUIDPOLL_Integration_fluent_crm constructor.
		 */
		function __construct() {


			load_plugin_textdomain( 'liquidpoll-fluent-crm-integration', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );

			add_filter( 'LiquidPoll/Filters/poll_meta_field_sections', array( $this, 'add_field_sections' ) );

			add_action( 'liquidpoll_email_added_local', array( $this, 'add_emails_to_fluentcrm' ) );
		}


		/**
		 * Add emails to FluentCRM
		 *
		 * @param $args
		 */
		function add_emails_to_fluentcrm( $args ) {

			global $wpdb;

			$poll_id       = Utils::get_args_option( 'poll_id', $args );
			$poller_id_ip  = Utils::get_args_option( 'poller_id_ip', $args );
			$email_address = Utils::get_args_option( 'email_address', $args );
			$first_name    = Utils::get_args_option( 'first_name', $args );
			$last_name     = Utils::get_args_option( 'last_name', $args );
			$datetime      = Utils::get_args_option( 'datetime', $args );
			$fcrm_lists    = Utils::get_meta( 'poll_form_int_fcrm_lists', $poll_id, array() );
			$fcrm_tags     = Utils::get_meta( 'poll_form_int_fcrm_tags', $poll_id, array() );
			$polled_value  = $wpdb->get_var( $wpdb->prepare( "SELECT polled_value FROM " . LIQUIDPOLL_RESULTS_TABLE . " WHERE poll_id = %d AND poller_id_ip = %s ORDER BY datetime DESC LIMIT 1", $poll_id, $poller_id_ip ) );


			if ( ! empty( $polled_value ) ) {
				$poll         = liquidpoll_get_poll( $poll_id );
				$poll_options = $poll->get_poll_options();
				$poll_type    = $poll->get_type();

				foreach ( $poll_options as $option_id => $option ) {
					if ( $polled_value == $option_id ) {

						if ( 'poll' == $poll_type ) {
							$fcrm_tags = array_merge( $fcrm_tags, Utils::get_args_option( 'fcrm_tags', $option, array() ) );
						}

						if ( 'nps' == $poll_type ) {
							$fcrm_tags = array_merge( $fcrm_tags, Utils::get_args_option( 'fcrm_nps_tags', $option, array() ) );
						}

						break;
					}
				}
			}

			if ( function_exists( 'FluentCrmApi' ) ) {
				FluentCrmApi( 'contacts' )->createOrUpdate(
					array(
						'email'      => $email_address,
						'first_name' => $first_name,
						'last_name'  => $last_name,
						'created_at' => $datetime,
						'status'     => 'subscribed',
						'lists'      => $fcrm_lists,
						'tags'       => array_unique( $fcrm_tags ),
					)
				);
			}
		}


		/**
		 * Add section in form field
		 *
		 * @param $field_sections
		 *
		 * @return array
		 */
		function add_field_sections( $field_sections ) {

			if ( function_exists( 'FluentCrmApi' ) ) {

				$field_sections['poll_form']['fields'][] = array(
					'type'       => 'subheading',
					'content'    => esc_html__( 'Integration - FluentCRM', 'wp-poll' ),
					'dependency' => array( '_type', 'any', 'poll,nps,reaction', 'all' ),
				);

				$field_sections['poll_form']['fields'][] = array(
					'id'         => 'poll_form_int_fcrm_enable',
					'title'      => esc_html__( 'Enable Integration', 'wp-poll' ),
					'label'      => esc_html__( 'This will store the submissions in FluentCRM.', 'wp-poll' ),
					'type'       => 'switcher',
					'default'    => false,
					'dependency' => array( '_type', 'any', 'poll,nps,reaction', 'all' ),
				);

				$field_sections['poll_form']['fields'][] = array(
					'id'         => 'poll_form_int_fcrm_lists',
					'title'      => esc_html__( 'Select Lists', 'wp-poll' ),
					'subtitle'   => esc_html__( 'Select FluentCRM lists', 'wp-poll' ),
					'type'       => 'select',
					'multiple'   => true,
					'chosen'     => true,
					'options'    => $this->get_fluent_crm_lists(),
					'dependency' => array( '_type|poll_form_int_fcrm_enable', 'any|==', 'poll,nps,reaction|true', 'all' ),
				);

				$field_sections['poll_form']['fields'][] = array(
					'id'         => 'poll_form_int_fcrm_tags',
					'title'      => esc_html__( 'Select Tags', 'wp-poll' ),
					'subtitle'   => esc_html__( 'Select FluentCRM tags', 'wp-poll' ),
					'type'       => 'select',
					'multiple'   => true,
					'chosen'     => true,
					'options'    => $this->get_fluent_crm_tags(),
					'dependency' => array( '_type|poll_form_int_fcrm_enable', 'any|==', 'poll,nps,reaction|true', 'all' ),
				);

				foreach ( Utils::get_args_option( 'fields', $field_sections['poll_options'], array() ) as $index => $arr_field ) {
					if ( isset( $arr_field['id'] ) && 'poll_meta_options' == $arr_field['id'] ) {
						$field_sections['poll_options']['fields'][ $index ]['fields'][] = array(
							'id'         => 'fcrm_tags',
							'title'      => esc_html__( 'Select Tags', 'wp-poll' ),
							'subtitle'   => esc_html__( 'Select FluentCRM tags', 'wp-poll' ),
							'type'       => 'select',
							'multiple'   => true,
							'chosen'     => true,
							'options'    => $this->get_fluent_crm_tags(),
							'dependency' => array( '_type', '==', 'poll', 'all' ),
						);
						break;
					}
				}

				foreach ( Utils::get_args_option( 'fields', $field_sections['poll_options'], array() ) as $index => $arr_field ) {
					if ( isset( $arr_field['id'] ) && 'poll_meta_options_nps' == $arr_field['id'] ) {
						$field_sections['poll_options']['fields'][ $index ]['fields'][] = array(
							'id'         => 'fcrm_nps_tags',
							'title'      => esc_html__( 'Select Tags', 'wp-poll' ),
							'subtitle'   => esc_html__( 'Select FluentCRM tags', 'wp-poll' ),
							'type'       => 'select',
							'multiple'   => true,
							'chosen'     => true,
							'options'    => $this->get_fluent_crm_tags(),
							'dependency' => array( '_type', '==', 'nps', 'all' ),
						);
						break;
					}
				}
			}

			return $field_sections;
		}


		/**
		 * Return FluentCRM tags
		 *
		 * @return array
		 */
		function get_fluent_crm_tags() {

			if ( ! function_exists( 'FluentCrmApi' ) ) {
				return array();
			}

			$tags          = Tag::orderBy( 'title', 'ASC' )->get();
			$formattedTags = [];
			foreach ( $tags as $tag ) {
				$formattedTags[ strval( $tag->id ) ] = $tag->title;
			}

			return $formattedTags;
		}


		/**
		 * Return FluentCRM lists
		 *
		 * @return array
		 */
		function get_fluent_crm_lists() {

			if ( ! function_exists( 'FluentCrmApi' ) ) {
				return array();
			}

			$lists          = Lists::orderBy( 'title', 'ASC' )->get();
			$formattedLists = [];
			foreach ( $lists as $list ) {
				$formattedLists[ $list->id ] = $list->title;
			}

			return $formattedLists;
		}


		/**
		 * @return \LIQUIDPOLL_Integration_fluent_crm|null
		 */
		public static function instance() {

			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}


if ( ! class_exists( 'TGM_Plugin_Activation' ) ) {
	require_once LIQUIDPOLL_FLUENTCRM_PLUGIN_DIR . 'class-tgm-plugin-activation.php';
}


if ( ! function_exists( 'liquidpoll_fluentcrm_integration_required_plugins' ) ) {
	/**
	 * Add require plugins
	 *
	 * @return void
	 */
	function liquidpoll_fluentcrm_integration_required_plugins() {

		$plugins = array(
			array(
				'name'             => esc_html( 'LiquidPoll - Advanced Polls for Creators and Brands' ),
				'slug'             => 'wp-poll',
				'required'         => true,
				'force_activation' => true,
			),
			array(
				'name'             => esc_html( 'FluentCRM - Marketing Automation For WordPress' ),
				'slug'             => 'fluent-crm',
				'required'         => true,
				'force_activation' => true,
			),
		);

		$config = array(
			'id'          => 'liquidpoll-required-plugins',
			'menu'        => 'liquidpoll-required-plugins',
			'parent_slug' => 'edit.php?post_type=poll',
			'capability'  => 'manage_options',
			'has_notices' => true,
			'dismissable' => true,
			'message'     => '',
			'strings'     => array(
				'page_title'                   => __( 'Install Required Plugins', 'liquidpoll-fluent-crm-integration' ),
				'menu_title'                   => __( 'Install Plugins', 'liquidpoll-fluent-crm-integration' ),
				'nag_type'                     => 'updated',
				'notice_can_activate_required' => _n_noop(
					'The following plugin is required to use <i>Liquidpoll Plugin</i> %1$s.',
					'The following plugins are required to use <i>Liquidpoll Plugin</i> %1$s.',
					'liquidpoll-fluent-crm-integration'
				),
			),
		);

		tgmpa( $plugins, $config );
	}
}


add_action( 'tgmpa_register', 'liquidpoll_fluentcrm_integration_required_plugins' );


add_action( 'wpdk_init_wp_poll', array( 'LIQUIDPOLL_Integration_fluent_crm', 'instance' ) );
