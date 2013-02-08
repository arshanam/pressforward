<?php

/**
 * Classes and functions for dealing with feed items
 */

/**
 * Database class for manipulating feed items
 */
class PF_Feed_Item {
	protected $filter_data = array();

	public function __construct() {
		$this->post_type = pf_feed_item_post_type();
		$this->tag_taxonomy = pf_feed_item_tag_taxonomy();
	}

	public function get( $args = array() ) {
		$wp_args = array(
			'post_type'        => $this->post_type,
			'post_status'      => 'publish',
			'suppress_filters' => false,
		);

		$query_filters = array();

		// WP_Query does not accept a 'guid' param, so we filter hackishly
		if ( isset( $args['url'] ) ) {
			$this->filter_data['guid'] = $args['url'];
			unset( $args['url'] );
			$query_filters['posts_where'][] = '_filter_where_guid';
		}

		foreach ( $query_filters as $hook => $filters ) {
			foreach ( $filters as $f ) {
				add_filter( $hook, array( $this, $f ) );
			}
		}

		// Other WP_Query args pass through
		$wp_args = wp_parse_args( $args, $wp_args );

		$posts = get_posts( $wp_args );

		foreach ( $query_filters as $hook => $filters ) {
			foreach ( $filters as $f ) {
				remove_filter( $hook, array( $this, $f ) );
			}
		}

		// Fetch some handy pf-specific data
		if ( ! empty( $posts ) ) {
			foreach ( $posts as &$post ) {
				$post->word_count = get_post_meta( $post->ID, 'pf_feed_item_word_count', true );
				$post->source     = get_post_meta( $post->ID, 'pf_feed_item_source', true );
				$post->tags       = wp_get_post_terms( $post->ID, $this->tag_taxonomy );
			}
		}

		return $posts;
	}

	public function create( $args = array() ) {
		$r = wp_parse_args( $args, array(
			'title'   => '',
			'url'     => '',
			'content' => '',
			'source'  => '',
			'date'    => '',
			'tags'    => array(),
		) );

		// Sanitization
		// Conversion should be done upstream
		if ( ! is_numeric( $r['date'] ) ) {
			return new WP_Error( 'Date should be in UNIX format' );
		}

		$wp_args = array(
			'post_type'    => $this->post_type,
			'post_status'  => 'publish',
			'post_title'   => $r['title'],
			'post_content' => wp_specialchars_decode( $r['content'], ENT_COMPAT ), // todo
			'guid'         => $r['url'],
			'post_date'    => date( 'Y-m-d H:i:s', $r['date'] ),
			'tax_input'    => array( $this->tag_taxonomy => $r['tags'] ),
		);

		$post_id = wp_insert_post( $wp_args );

		if ( $post_id ) {
			self::set_word_count( $post_id, $r['content'] );
			self::set_source( $post_id, $r['source'] );

		}

		return $post_id;
	}

	public function _filter_where_guid( $where ) {
		global $wpdb;
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.guid = %s ", $this->filter_data['guid'] );
		return $where;
	}

	// STATIC UTILITY METHODS

	public static function set_word_count( $post_id, $content = false ) {
		if ( false === $content ) {
			$post = get_post( $post_id );
			$content = $post->post_content;
		}

		$content_array = explode( ' ', strip_tags( $content ) );
		$word_count = count( $content_array );

		return update_post_meta( $post_id, 'pf_feed_item_word_count', $word_count );
	}

	public static function set_source( $post_id, $source ) {
		return update_post_meta( $post_id, 'pf_feed_item_source', $source );
	}

	# This function feeds items to our display feed function pf_reader_builder.
	# It is just taking our database of rssarchival items and putting them into a
	# format that the builder understands.
	public static function archive_feed_to_display($pageTop = 0) {
		global $wpdb, $post;
		//$args = array(
		//				'post_type' => array('any')
		//			);
		//$pageBottom = $pageTop + 20;
		$args = pf_feed_item_post_type();
		//$archiveQuery = new WP_Query( $args );
		 $dquerystr = "
			SELECT $wpdb->posts.*, $wpdb->postmeta.*
			FROM $wpdb->posts, $wpdb->postmeta
			WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id
			AND $wpdb->posts.post_type = '" . pf_feed_item_post_type() . "'
			AND $wpdb->postmeta.meta_key = 'sortable_item_date'
			ORDER BY $wpdb->postmeta.meta_value DESC
			LIMIT $pageTop, 20
		 ";
		// print_r($dquerystr);
		 # DESC here because we are sorting by UNIX datestamp, where larger is later.
		 //Provide an alternative to load by feed date order.
		# This is how we do a custom query, when WP_Query doesn't do what we want it to.
		$archivalposts = $wpdb->get_results($dquerystr, OBJECT);
		//print_r(count($rssarchivalposts)); die();
		$feedObject = array();
		$c = 0;

		if ($archivalposts):

			foreach ($archivalposts as $post) :
			# This takes the $post objects and translates them into something I can do the standard WP functions on.
			setup_postdata($post);
			# I need this data to check against existing transients.
			$post_id = get_the_ID();
			$id = get_post_meta($post_id, 'item_id', true); //die();
			//Switch the delete on to wipe rss archive posts from the database for testing.
			//wp_delete_post( $post_id, true );
			//print_r($id);
			# If the transient exists than there is no reason to do any extra work.
			if ( false === ( $feedObject['rss_archive_' . $c] = get_transient( 'pf_archive_' . $id ) ) ) {

				$item_id = get_post_meta($post_id, 'item_id', true);
				$source_title = get_post_meta($post_id, 'source_title', true);
				$item_date = get_post_meta($post_id, 'item_date', true);
				$item_author = get_post_meta($post_id, 'item_author', true);
				$item_link = get_post_meta($post_id, 'item_link', true);
				$item_feat_img = get_post_meta($post_id, 'item_feat_img', true);
				$item_wp_date = get_post_meta($post_id, 'item_wp_date', true);
				$item_tags = get_post_meta($post_id, 'item_tags', true);
				$source_repeat = get_post_meta($post_id, 'source_repeat', true);

				$contentObj = new htmlchecker(get_the_content());
				$item_content = $contentObj->closetags(get_the_content());

				$feedObject['rss_archive_' . $c] = pf_feed_object(
											get_the_title(),
											$source_title,
											$item_date,
											$item_author,
											$item_content,
											$item_link,
											$item_feat_img,
											$item_id,
											$item_wp_date,
											$item_tags,
											//Manual ISO 8601 date for pre-PHP5 systems.
											get_the_date('o-m-d\TH:i:sO'),
											$source_repeat
											);
				set_transient( 'pf_archive_' . $id, $feedObject['rss_archive_' . $c], 60*10 );

			}
			$c++;
			endforeach;


		endif;
		wp_reset_postdata();
		return $feedObject;
	}

	# The function we add to the action to clean our database.
	public static function disassemble_feed_items() {
		//delete rss feed items with a date past a certian point.
		add_filter( 'posts_where', array( 'PF_Feed_Item', 'filter_where_older_sixty_days') );
		$queryForDel = new WP_Query( array( 'post_type' => pf_feed_item_post_type() ) );
		remove_filter( 'posts_where', array( 'PF_Feed_Item', 'filter_where_older_sixty_days') );

		// The Loop
		while ( $queryForDel->have_posts() ) : $queryForDel->the_post();
			# All the posts in this loop are older than 60 days from 'now'.
			# Delete them all.
			$postid = get_the_ID();
			wp_delete_post( $postid, true );

		endwhile;

		// Reset Post Data
		wp_reset_postdata();

	}

	# Method to manually delete rssarchival entries on user action.
	public static function reset_feed() {
		global $wpdb, $post;
		//$args = array(
		//				'post_type' => array('any')
		//			);
		$args = 'post_type=' . pf_feed_item_post_type();
		//$archiveQuery = new WP_Query( $args );
		$dquerystr = "
			SELECT $wpdb->posts.*, $wpdb->postmeta.*
			FROM $wpdb->posts, $wpdb->postmeta
			WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id
			AND $wpdb->posts.post_type ='" . pf_feed_item_post_type() .
		 "'";
		# This is how we do a custom query, when WP_Query doesn't do what we want it to.
		$rssarchivalposts = $wpdb->get_results($dquerystr, OBJECT);
		//print_r(count($rssarchivalposts)); die();
		$feedObject = array();
		$c = 0;

		if ($rssarchivalposts):

			foreach ($rssarchivalposts as $post) :
			# This takes the $post objects and translates them into something I can do the standard WP functions on.
			setup_postdata($post);
			$post_id = get_the_ID();
			//Switch the delete on to wipe rss archive posts from the database for testing.
			wp_delete_post( $post_id, true );
			endforeach;


		endif;
		wp_reset_postdata();
		print_r(__('All archives deleted.', 'pf'));

	}

	public function assemble_feed_for_pull($feedObj = 0) {
		ignore_user_abort(true);
		set_time_limit(0);
		# Chunking control, the goal here is to ensure that no feed assembly occurs while the feed assembly is already occuring.
		$is_chunk_going = get_option( PF_SLUG . '_chunk_assembly_status', 0);
		if ($is_chunk_going === 1){ exit; }
		else { update_option( PF_SLUG . '_chunk_assembly_status', 1 ); }

		# This pulls the RSS feed into a set of predetermined objects.
		# The rss_object function takes care of all the feed pulling and item arraying so we can just do stuff with the feed output.
		if ($feedObj == 0){
			$feedObj = pressforward()->source_data_object();
		}

		# We need to init $sourceRepeat so it can be if 0 if nothing is happening.
		$sourceRepeat = 0;
		# We'll need this for our fancy query.
		global $wpdb;
		# Since rss_object places all the feed items into an array of arrays whose structure is standardized throughout,
		# We can do stuff with it, using the same structure of items as we do everywhere else.
		foreach($feedObj as $item) {
			$thepostscheck = 0;
			$thePostsDoubleCheck = 0;
			$item_id 		= $item['item_id'];
			$sourceRepeat = 0;
			//$queryForCheck = new WP_Query( array( 'post_type' => 'rssarchival', 'meta_key' => 'item_id', 'meta_value' => $item_id ) );
			 # Originally this query tried to get every archive post earlier than 'now' to check.
			 # But it occured to me that, since I'm doing a custom query anyway, I could just query for items with the ID I want.
			 # Less query results, less time.

			 //Perhaps I should do this outside of the foreach? One query and search it for each item_id and then return those not in?
			 $querystr = "
				SELECT $wpdb->posts.*, $wpdb->postmeta.*
				FROM $wpdb->posts, $wpdb->postmeta
				WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id
				AND $wpdb->postmeta.meta_key = 'item_id'
				AND $wpdb->postmeta.meta_value = '" . $item_id . "'
				AND $wpdb->posts.post_type = '" . pf_feed_item_post_type() . "'
				ORDER BY $wpdb->posts.post_date DESC
			 ";
			 // AND $wpdb->posts.post_date < NOW() <- perhaps by removing we can better prevent simultaneous duplications?
			 # Since I've altered the query, I could change this to just see if there are any items in the query results
			 # and check based on that. But I haven't yet.
			$checkposts = $wpdb->get_results($querystr, OBJECT);
			//print_r($checkposts);
				if ($checkposts):
					global $post;
					foreach ($checkposts as $post):
						setup_postdata($post);
						//print_r(get_the_ID());
						//print_r('< the ID');
						if ((get_post_meta($post->ID, 'item_id', $item_id, true)) == $item_id){ $thepostscheck++; }
					endforeach;
				endif;
				wp_reset_query();
				if ($thepostscheck == 0){
					$queryMoreStr = "
						SELECT $wpdb->posts.*, $wpdb->postmeta.*
						FROM $wpdb->posts, $wpdb->postmeta
						WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id
						AND $wpdb->postmeta.meta_key = 'item_link'
						AND $wpdb->posts.post_type = " . pf_feed_item_post_type() . "
						ORDER BY $wpdb->posts.post_date DESC
					 ";
					$checkpoststwo = $wpdb->get_results($queryMoreStr, OBJECT);
					if ($checkpoststwo):
						foreach ($checkpoststwo as $post):
							setup_postdata($post);

								# Post comparative values.
								$theTitle = $post->post_title;
								$postID = $post->ID;
								$postDate = strtotime($post->post_date);
								$postItemLink = get_post_meta($post->ID, 'item_link', true);
								# Item comparative values.
								$itemDate = strtotime($item['item_date']);
								$itemTitle = $item['item_title'];
								$itemLink = $item['item_link'];

								# First check if it more recent than the currently stored item.
								if((($theTitle == $itemTitle) || ($postItemLink == $itemLink))){
									$thePostsDoubleCheck++;
									$sourceRepeat = get_post_meta($postID, 'source_repeat', true);
									if (($itemDate > $postDate)) {
										# If it is more recent, than this is the new dominant post.
										$sourceRepeat++;
									} elseif (($itemData <= $postDate)) {
										# if it is less recent, then we need to increment the source count.
										$sourceRepeat++;
										if ($thePostsDoubleCheck > $sourceRepeat) {
											update_post_meta($postID, 'source_repeat', $sourceRepeat);
										}
										$thepostscheck++;
									} else {
										$thepostscheck = 0;
									}
								} else {
									# If it isn't duplicated at all, then we need to give it a source repeat count of 0
									$sourceRepeat = 0;
								}


						endforeach;
					endif;
				}
				wp_reset_query();
			# Why an increment here instead of a bool?
			# If I start getting errors, I can use this to check how many times an item is in the database.
			# Potentially I could even use this to clean the database from duplicates that might occur if
			# someone were to hit the refresh button at the same time as another person.


#			$fo = fopen(PF_ROOT . "/modules/rss-import/rss-import.txt", 'a') or print_r('Can\'t open log file.');
#			if ($fo != false){
#				fwrite($fo, "\nSending " . $item['item_title'] . " to post table.");
#				fclose($fo);
#			}
			if ( $thepostscheck == 0) {
				$item_title 	= $item['item_title'];
				$item_content 	= $item['item_content'];
				$item_feat_img 	= $item['item_feat_img'];
				$source_title 	= $item['source_title'];
				$item_date 		= $item['item_date'];
				$item_author 	= $item['item_author'];
				$item_link 		= $item['item_link'];
				$item_wp_date	= $item['item_wp_date'];
				$item_tags		= $item['item_tags'];
				$source_repeat  = $sourceRepeat;

			# Trying to prevent bad or malformed HTML from entering the database.
			$item_content = strip_tags($item_content, '<p> <strong> <bold> <i> <em> <emphasis> <del> <h1> <h2> <h3> <h4> <h5> <a> <img>');
			//$item_content = wpautop($item_content);
			//$postcontent = sanitize_post($item_content);
			//If we use the @ to prevent showing errors, everything seems to work. But it is still dedicating crap to the database...
			//Perhaps sanitize_post isn't the cause? What is then?

			# Do we want or need the post_status to be published?
				$data = array(
					'post_status' => 'published',
					'post_type' => pf_feed_item_post_type(),
					'post_date' => $_SESSION['cal_startdate'],
					'post_title' => $item_title,
					'post_content' => $item_content,

				);

				//RIGHT HERE is where the content is getting assigned a bunch of screwed up tags.
				//The content is coming in from the rss_object assembler a-ok. But something here saves them to the database screwy.
				//It looks like sanitize post is screwing them up terribly. But what to do about it without removing the security measures which we need to apply?

				# The post gets created here, the $newNomID variable contains the new post's ID.
				$newNomID = wp_insert_post( $data );
				//$posttest = get_post($newNomID);
				//print_r($posttest->post_content);

				# Somewhere in the process links with complex queries at the end (joined by ampersands) are getting encoded.
				# I don't want that, so I turn it back here.
				# For some reason this is only happening to the ampersands, so that's the only thing I'm changing.
				$item_link = str_replace('&amp;','&', $item_link);

				# If it doesn't have a featured image assigned already, I use the set_ext_as_featured function to try and find one.
				# It also, if it finds one, sets it as the featured image for that post.

				if ($_POST['item_feat_img'] != ''){
					# Turned off set_ext_as_featured here, as that should only occur when items are nominated.
					# Before nominations, the featured image should remain a meta field with an external link.
					if ( false === ( $itemFeatImg = get_transient( 'feed_img_' . $itemUID ) ) ) {
						set_time_limit(0);
						# Because many systems can't process https through php, we try and remove it.
						$itemLink = pf_de_https($itemLink);
						# if it forces the issue when we try and get the image, there's nothing we can do.
						$itemLink = str_replace('&amp;','&', $itemLink);
						if (OpenGraph::fetch($itemLink)){
							//If there is no featured image passed, let's try and grab the opengraph image.
							$node = OpenGraph::fetch($itemLink);
							$itemFeatImg = $node->image;

						}

						if ($itemFeatImg == ''){
							//Thinking of starting a method here to pull the first image from the body of a post.
							//http://stackoverflow.com/questions/138313/how-to-extract-img-src-title-and-alt-from-html-using-php
							//http://stackoverflow.com/questions/1513418/get-all-images-url-from-string
							//http://stackoverflow.com/questions/7479835/getting-the-first-image-in-string-with-php
							//preg_match_all('/<img[^>]+>/i',$itemContent, $imgResult);
							//$imgScript = $imgResult[0][0];
						}
						//Most RSS feed readers don't store the image locally. Should we?
						set_transient( 'feed_img_' . $itemUID, $itemFeatImg, 60*60*24 );
					}
				}

				# adding the meta info about the feed item to the post's meta.
				add_post_meta($newNomID, 'item_id', $item_id, true);
				add_post_meta($newNomID, 'source_title', $source_title, true);
				add_post_meta($newNomID, 'item_date', $item_date, true);
				add_post_meta($newNomID, 'item_author', $item_author, true);
				add_post_meta($newNomID, 'item_link', $item_link, true);
				add_post_meta($newNomID, 'item_feat_img', $item_feat_img, true);
				// The item_wp_date allows us to sort the items with a query.
				add_post_meta($newNomID, 'item_wp_date', $item_wp_date, true);
				//We can't just sort by the time the item came into the system (for when mult items come into the system at once)
				//So we need to create a machine sortable date for use in the later query.
				add_post_meta($newNomID, 'sortable_item_date', strtotime($item_date), true);
				add_post_meta($newNomID, 'item_tags', $item_tags, true);
				add_post_meta($newNomID, 'source_repeat', $source_repeat, true);
			}

		}
		update_option( PF_SLUG . '_chunk_assembly_status', 0 );
		pf_rss_import::advance_feeds();
		//die('Refreshing...');

	}


	/**
	 * Filter 'posts_where' to return only posts older than sixty days
	 */
	public static function filter_where_older_sixty_days( $where = '' ) {
		// posts before the last 60 days
		$where .= " AND post_date < '" . date('Y-m-d', strtotime('-60 days')) . "'";
		return $where;
	}

	/**
	 * Set a feed item's tags
	 *
	 * @param int $post_id
	 * @param array $tags
	 * @param bool $append True if you want to append rather than replace
	 */
	public static function set_tags( $post_id, $tags, $append = false ) {
		return wp_set_object_terms( $post_id, $tags, $this->tag_taxonomy, $append );
	}

	/**
	 * Converts a raw tag array to a list appropriate for a tax_query
	 *
	 * Will create the necessary tags if they're not found
	 */
	public static function convert_raw_tags( $tags ) {
		$retval = array( $this->tag_taxonomy => $tags );
		return $retval;
	}

	public static function get_term_slug_from_tag( $tag ) {
//		return 'pf_feed_item_' .
	}
}