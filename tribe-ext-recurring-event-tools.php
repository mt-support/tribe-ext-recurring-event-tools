<?php
/**
 * Plugin Name:       The Events Calendar Pro Extension: Recurring Event Tools
 * Plugin URI:        https://theeventscalendar.com/extensions/---the-extension-article-url---/
 * GitHub Plugin URI: https://github.com/mt-support/tribe-ext-recurring-event-tools
 * Description:       Show recurring events filters in the posts edit screen for Events.
 * Version:           1.0.0
 * Extension Class:   Tribe\Extensions\Recurring_Event_Tools\Main
 * Author:            Modern Tribe, Inc.
 * Author URI:        http://m.tri.be/1971
 * License:           GPL version 3 or any later version
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       tribe-ext-recurring-event-tools
 *
 *     This plugin is free software: you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation, either version 3 of the License, or
 *     any later version.
 *
 *     This plugin is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *     GNU General Public License for more details.
 */

namespace Tribe\Extensions\Recurring_Event_Tools;

use Tribe__Extension;

// Do not load unless Tribe Common is fully loaded and our class does not yet exist.
if (
	class_exists( 'Tribe__Extension' )
	&& ! class_exists( Main::class )
) {
	/**
	 * Extension main class, class begins loading on init() function.
	 */
	class Main extends Tribe__Extension {

		/**
		 * Setup the Extension's properties.
		 *
		 * This always executes even if the required plugins are not present.
		 */
		public function construct() {
			$this->add_required_plugin( 'Tribe__Events__Pro__Main', '5.0.0' );
		}

		/**
		 * Extension initialization and hooks.
		 */
		public function init() {
			// Load plugin textdomain
			// Don't forget to generate the 'languages/tribe-ext-recurring-event-tools.pot' file
			load_plugin_textdomain( 'tribe-ext-recurring-event-tools', false,
				basename( dirname( __FILE__ ) ) . '/languages/' );

			if ( ! $this->php_version_check() ) {
				return;
			}

			add_filter( 'views_edit-tribe_events', [ $this, 'filter_views_edit' ] );
			add_filter( 'query_vars', [ $this, 'filter_query_vars' ] );
			add_action( 'pre_get_posts', [ $this, 'on_pre_get_posts' ] );
		}

		/**
		 * Check if we have a sufficient version of PHP. Admin notice if we don't and user should see it.
		 *
		 * @link https://theeventscalendar.com/knowledgebase/php-version-requirement-changes/ All extensions require PHP 5.6+.
		 *
		 * @return bool
		 */
		private function php_version_check() {
			$php_required_version = '5.6';

			if ( version_compare( PHP_VERSION, $php_required_version, '<' ) ) {
				if (
					is_admin()
					&& current_user_can( 'activate_plugins' )
				) {
					$message = '<p>';
					$message .= sprintf( __( '%s requires PHP version %s or newer to work. Please contact your website host and inquire about updating PHP.',
						'tribe-ext-recurring-event-tools' ), $this->get_name(), $php_required_version );
					$message .= sprintf( ' <a href="%1$s">%1$s</a>', 'https://wordpress.org/about/requirements/' );
					$message .= '</p>';

					tribe_notice( 'tribe-ext-recurring-event-tools' . '-php-version', $message, [ 'type' => 'error' ] );
				}

				return false;
			}

			return true;
		}

		/**
		 * Casts string 'true' and 'false' values to actual boolean values.
		 *
		 * @param mixed $candidate The candidate to try and cast to boolean.
		 *
		 * @return bool|mixed The candidate value cast to boolean, or the original value.
		 */
		protected function cast_bool( $candidate ) {
			if ( $candidate !== 'true' && $candidate !== 'false' ) {
				return $candidate;
			}

			return $candidate === 'true';
		}

		/**
		 * Runs a function suspending the function hooked to `pre_get_posts` action.
		 *
		 * @param callable $do The function to run.
		 *
		 * @return mixed The function return value.
		 */
		function suspending_pre_get_posts( callable $do ) {
			remove_filter( 'pre_get_posts', [ $this, 'on_pre_get_posts' ] );
			$result = $do();
			add_filter( 'pre_get_posts', [ $this, 'on_pre_get_posts' ] );

			return $result;
		}

		/**
		 * Filters the list of links at the top of the edit screen to add the recurring event related ones.
		 *
		 * @param array<string,string> $views The original list of links.
		 *
		 * @return array<string,string> The filtered list of links.
		 */
		public function filter_views_edit( $views ) {
			list( $recurring_events_count, $master_recurring_events_count ) = $this->get_counts();
			$views['tribe-recurring-all'] = sprintf(
				'<a href="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( [
					'post_status' => 'any',
					'post_type'   => 'tribe_events',
					// Get only events that are in a series, either the "master" or "children" events.
					'tribe-where' => 'in_series,true',
				], 'edit.php' ) ),
				'Recurring',
				$recurring_events_count
			);

			$views['tribe-recurring-first'] = sprintf(
				'<a href="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( [
					'post_status' => 'any',
					'post_type'   => 'tribe_events',
					// Get events that are in a series and not have a parent, this means the "master" ones.
					'tribe-where' => [ 'in_series,true', 'parent,0' ],
				], 'edit.php' ) ),
				'Recurring (master)',
				$master_recurring_events_count
			);

			return $views;
		}

		/**
		 * Filters the publicly available query variables to add the `tribe-orm` and `tribe-where` ones.
		 *
		 * @param array<string> $public_query_vars The list of publicly available query vars.
		 *
		 * @return array<string> The modified list of publicly available query vars.
		 */
		public function filter_query_vars( array $public_query_vars = [] ) {
			$public_query_vars[] = 'tribe-orm';
			$public_query_vars[] = 'tribe-where';

			return $public_query_vars;
		}

		/**
		 * Fires on `pre_get_posts` to populate, or intersect, the `post__in` clause of the query.
		 *
		 * The function will pick up the `tribe-orm` and `tribe-where` query args to allow almost 1:1 use of the ORM in queries.
		 *
		 * @param \WP_Query $wp_query The WordPress query object to alter if required.
		 */
		public function on_pre_get_posts( \WP_Query $wp_query ) {
			$orm_clauses       = $wp_query->get( 'tribe-orm', false );
			$orm_where_clauses = $wp_query->get( 'tribe-where', false );

			if ( empty( $orm_clauses ) && empty( $orm_where_clauses ) ) {
				return;
			}

			$orm_args = [];

			// The distinction is just sugar, the ORM will not distinguish.
			$orm_clauses = array_merge( (array) $orm_clauses, (array) $orm_where_clauses );

			if ( ! empty( $orm_clauses ) ) {
				foreach ( $orm_clauses as $orm_clause ) {
					list( $key, $value ) = explode( ',', $orm_clause );
					$orm_args[ $key ] = $this->cast_bool( $value );
				}
			}

			// Since we're already running on `pre_get_posts` we need to avoid infinite loops.
			$ids = $this->suspending_pre_get_posts( static function () use ( $orm_args, $wp_query ) {
				$orm_args['status'] = $wp_query->get( 'post_status', 'any' ) ?: 'any';

				return tribe_events()->by_args( $orm_args )->per_page( - 1 )->get_ids();
			} );

			// Either we fill the `post__in` query argument, or we intersect with an existing one.
			$post__in = $wp_query->get( 'post__in', false );
			if ( empty( $post__in ) ) {
				$post__in = $ids;
			} else {
				$post__in = array_intersect( array_map( 'absint', $post__in ), $ids );
			}

			// To avoid having to deal with state we do it like this and nicely remove the function when done.
			$void_query = static function ( $posts, $the_query ) use ( $wp_query, &$void_query ) {
				if ( $the_query === $wp_query ) {
					remove_filter( 'posts_pre_query', $void_query );

					return [];
				}
			};

			if ( empty( $post__in ) ) {
				// The query will not return any value, setting `post__in` to an empty array would throw an error.
				add_filter( 'posts_pre_query', $void_query, 10, 2 );
			} else {
				// Only include posts that are among the ones we want.
				$wp_query->set( 'post__in', $post__in );
			}
		}

		/**
		 * Returns the total count of recurring events and master recurring events.
		 *
		 * @return array<int,int> The count of all recurring events and master recurring events.
		 */
		protected function get_counts() {
			$cache = tribe_cache();

			$what_fetch = [
				'count'        => static function () {
					return tribe_events()->where( 'in_series', true )->where( 'status', 'any' )->found();
				},
				'master-count' => static function () {
					return tribe_events()
						->where( 'in_series', true )
						->where( 'status', 'any' )
						->where( 'parent', 0 )
						->found();
				}
			];

			foreach ( $what_fetch as $what => $fetch ) {
				$trigger  = \Tribe__Cache_Listener::TRIGGER_SAVE_POST;
				$cache_id = 'tribe-ext-recurring-event-tools-' . $what;
				$cached   = $cache->get( $cache_id, $trigger, false, 0 );

				$value = $fetch();

				$value = false === $cached ?
					$cache->set( $cache_id, $value, 0, $trigger )
					: $cached;

				$values[] = $value;
			}


			return $values;
		}
	}
}
