<?php
/*
 * Name: Tag Filter
 * Author: Dazl <dazzle@risingslash.net> and CalebJ <me@calebj.io>
 * Link: http://code.shishnet.org/shimmie2/
 * License: GPLv2
 * Description: Allow users to hide images by tag name.
 * Documentation:
 *  This shimmie extension allows users to specify tags they don't want to see,
 *  and allows the admin to specify the filtered tags for anonymous users.
 *  Newly created users have the initial filters of the anonymous list.
 */

class Filters extends Extension {
	protected $db_support = ['mysql'];
	public function get_priority() {return 70;}

	public function onInitExt(InitExtEvent $event) {
		$this->install();
	}

	// Generate tag filter configuration block for board settings page
	public function onSetupBuilding(SetupBuildingEvent $event) {
		global $config, $page;
		$page->add_http_header("Cache-Control: no-cache, must-revalidate");
		$anon = User::by_id($config->get_int('anon_id'));
		$value = implode(' ', self::get_filters($anon));
		$html = "<input name='tags' type='text' placeholder='(no filters)' class='autocomplete_tags' autocomplete='off' value='$value'>
				 <input type='submit' value='Set filters' formaction='./index.php?q=/filter/set_default'>";
		$sb = new SetupBlock("Default/anonymous tag filters");
		$sb->body = $html;
		$event->panel->add_block($sb);
	}

	// Show filter config on user profile page
	public function onUserPageBuilding(UserPageBuildingEvent $event) {
		global $page, $user;
		$showuser = $event->display_user;
		if($user->is_admin() || $user == $showuser) {
			$page->add_http_header("Cache-Control: no-cache, must-revalidate");
			$value = implode(' ', self::get_filters($showuser));
			$default = self::using_default($showuser);
			$this->theme->display_user_filters($showuser, $value, $default);
		}
	}

	public function onPageRequest(PageRequestEvent $event) {
		global $user, $page, $config;

		if($event->page_matches("post/prev") ||	$event->page_matches("post/next")) {
			$image_id = int_escape($event->get_arg(0));

			if(isset($_GET['search'])) {
				$search_terms = explode(' ', $_GET['search']);
				$query = "#search=".url_escape($_GET['search']);
			}
			else {
				$search_terms = array();
				$query = null;
			}

			$image = Image::by_id($image_id);
			if(is_null($image)) {
				return;
			}

			if($event->page_matches("post/next")) {
				$image = self::get_next_filtered($image_id, $search_terms, $user, true);
			}
			else {
				$image = self::get_prev_filtered($image_id, $search_terms, $user);
			}

			if(is_null($image)) {
					return;
			}

			$page->set_mode("redirect");
			$page->set_redirect(make_link("post/view/{$image->id}", $query));
		}

		// Process POST requests for filter configuration
		if($event->page_matches("filter") && $user->check_auth_token()) {
			$page->add_http_header("Cache-Control: no-cache, must-revalidate");
			$tags = isset($_POST['tags']) ? $_POST['tags'] : "";

			// Admin
			if($event->get_arg(0) == "set_default") {
				if(!$user->is_admin()) {
					throw new PermissionDeniedException();
				}
				$setuser = User::by_id($config->get_int("anon_id", 0));
				self::set_filters($setuser, $tags);
				flash_message("Default filters saved");
				$page->set_mode("redirect");
				$page->set_redirect(make_link("setup"));
			}

			// Regular user, or admin view of user page
			if($event->get_arg(0) == "set_user") {
				$clear = isset($_POST['clear']);
				$setuser = $user;
				if(isset($_POST['uid'])) {
					$uid =$_POST['uid'];
					if($uid != $user->id) {
						if(!$user->is_admin()) {
							throw new PermissionDeniedException();
						} else {
							$setuser = User::by_id($uid);
						}
					}
				}
				if($clear) {
					self::clear_filters($setuser);
				} else {
					self::set_filters($setuser, $tags);
				}
				$page->set_mode("redirect");
				$page->set_redirect(make_link("user/".$setuser->name));
			}
		}
	}

	public function onTagList(TagListEvent $event) {
		global $user;
		$querylet = self::get_tags_filter_querylet($user);
		$event->query->append($querylet);
	}

	public function onSearchTermParse(SearchTermParseEvent $event) {
		global $user;

		if(is_null($event->term)) {
			$querylet = self::get_image_filter_querylet($user);
			$event->add_querylet($querylet);
		}
	}

	public function onDisplayingImage(DisplayingImageEvent $event) {
		global $user, $page;
		$filtered = array_intersect($event->image->get_tag_array(),
									self::get_filters($user));
		if(!empty($filtered)) {
			$page->set_mode("redirect");
			$page->set_redirect(make_link("post/list"));
			flash_message("One or more of your tag filters (".
						  implode(', ', $filtered).
						  ") blocked image #".$event->image->id.'.');
		}
	}

	private function get_next_filtered($id, $tags=array(), User $user, $next=true) {
		assert('is_array($tags)');
		assert('is_bool($next)');
		assert('is_int($id)');
		global $database;

		if($next) {
			$gtlt = "<";
			$dir = "DESC";
		}
		else {
			$gtlt = ">";
			$dir = "ASC";
		}

		if(count($tags) === 0) {
					$querylet = new Querylet('SELECT images.* FROM images
						WHERE images.id'. $gtlt . $id, array()
					);
		} else {
					$tags[] = 'id'. $gtlt . $id;
					$querylet = Image::build_search_querylet($tags);
		}
		$filter = self::get_image_filter_querylet($user);
				$querylet->append_sql(" AND ");
				$querylet->append($filter);
				$querylet->append_sql(' ORDER BY images.id '.$dir.' LIMIT 1');
				$row = $database->get_row($querylet->sql, $querylet->variables);

		return ($row ? new Image($row) : null);
	}

	private static function get_prev_filtered($id, $tags=array(), User $user) {
		return self::get_next_filtered($id, $tags, $user, false);
	}

	private function install() {
		global $database, $config;

		if($config->get_int("ext_filter_version") < 1) {
			// If user has never set their filters, user.filter will be False,
			// and the default set will be used. This prevents duplicates from filling
			// up the filter table and allows future changes to the default filters to
			// apply to all users that haven't set theirs.
			// This is necessary because there is no way to obtain a uid from
			// UserCreationEvent, and hence no uid to copy filters to.
			$database->Execute("ALTER TABLE users ADD COLUMN filter INT(1) NOT NULL DEFAULT 0;");
			$database->Execute(
				"CREATE TABLE filters (
					user_id INT(11) NOT NULL,
					tag_id INT(11) NOT NULL,
					PRIMARY KEY (user_id, tag_id),
					FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
					FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
				);");
			$config->set_int("ext_filter_version", 1);
		}
	}

	// Remove all tags and set user flag to use board defaults
	public static function clear_filters(User $user) {
		global $database;
		$database->execute("DELETE FROM filters WHERE user_id=:id", array("id"=>$user->id));
		$database->execute("UPDATE users SET filter=0 WHERE id=:id", array("id"=>$user->id));
	}

	// Replace user filters with new ones. Takes user and tags list (string)
	public static function set_filters(User $user, $tags) {
		global $database;

		// Clear tags
		self::clear_filters($user);

		if(!empty($tags)) {
			$tags = Tag::explode($tags);
			$tags = array_map(array('Tag', 'sanitise'), $tags);
			$tags = Tag::resolve_aliases($tags);

			foreach($tags as $tag) {
				$id = $database->get_one($database->scoreql_to_sql(
					"SELECT id FROM tags WHERE SCORE_STRNORM(tag) = SCORE_STRNORM(:tag)"
					), array("tag"=>$tag));
				if(empty($id)) {
					continue;
				} else {
					$database->execute("INSERT INTO filters (user_id, tag_id) VALUES(:uid, :tid)",
														array("uid"=>$user->id, "tid"=>$id));
				}
			}
		}

		// Clear default flag
		$database->execute("UPDATE users SET filter=1 WHERE id=:id", array("id"=>$user->id));
	}

	// Inverse of set_filters(), retrieves array of filtered tag names
	public static function get_filters(User $user) {
		global $database, $config;
		if(!self::using_default($user) || $user->is_anonymous()) {
			$db_result = $database->get_col("SELECT DISTINCT t.tag
			FROM `filters` f
			LEFT JOIN `tags` t
			ON t.id = f.tag_id
			WHERE f.user_id = :uid", array("uid" => $user->id));
			return $db_result;
		} else {
			$anon = User::by_id($config->get_int('anon_id', 0));
			return self::get_filters($anon);
		}
	}

	// For convenience, tells whetheror not user has their own filters set
	public static function using_default(User $user) {
		global $database;
		$user_filter = $database->get_one("select filter from users where id = :uid",
											  array("uid" => $user->id));
		return $user_filter == 0 || $user->is_anonymous();
	}

	public static function get_image_filter_querylet(User $user) {
		global $config;
		$anon = User::by_id($config->get_int('anon_id'));
		$query = new Querylet("
		images.id not in (
			select distinct it.image_id
			from filters f
			inner join image_tags it
			on f.tag_id = it.tag_id
			where f.user_id=IF(
				(SELECT filter FROM users WHERE id=:uid) = 1,
			:uid, :anon)
		)", array("uid"=>$user->id, "anon"=>$anon->id));
		return $query;
	}

	public static function get_comment_filter_querylet(User $user) {
		global $config;
		$anon = User::by_id($config->get_int('anon_id'));
		$query = new Querylet("
		comments.image_id not in (
			select distinct it.image_id
			from filters f
			inner join image_tags it
			on f.tag_id = it.tag_id
			where f.user_id=IF(
				(SELECT filter FROM users WHERE id=:uid) = 1,
			:uid, :anon)
		)", array("uid"=>$user->id, "anon"=>$anon->id));
		return $query;
	}

	public static function get_tags_filter_querylet(User $user) {
		global $config;
		$anon = User::by_id($config->get_int('anon_id'));
		/* $query = new Querylet(" and tags.id not in (
			SELECT DISTINCT tag_id
			FROM filters
			WHERE filters.user_id=IF(
				(SELECT filter FROM users WHERE id=:uid) = 1, :uid, :anon
			)
		)", array("uid"=>$user->id, "anon"=>$anon->id)); */
		$query = new Querylet(" AND tags.id IN (
			SELECT tag_id FROM (
				SELECT image_id 
				FROM image_tags
				GROUP BY image_id
				HAVING SUM(
					tag_id IN(
						SELECT tag_id FROM filters WHERE user_id=IF(
							(SELECT filter FROM users WHERE id=:uid) = 1,
						:uid, :anon)
					)
				) = 0
			) AS v1
			JOIN image_tags it
			ON v1.image_id = it.image_id
		)", array("uid"=>$user->id, "anon"=>$anon->id));
		return $query;
	}
}
