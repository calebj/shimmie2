<?php

class ArrowkeyNavigation extends Extension
{
    /**
     * Adds functionality for post/view on images.
     */
    public function onDisplayingImage(DisplayingImageEvent $event)
    {
        $prev_url = make_http(make_link("post/prev/".$event->image->id));
        $next_url = make_http(make_link("post/next/".$event->image->id));
        $this->add_arrowkeys_code($prev_url, $next_url);
    }

    /**
     * Adds functionality for post/list.
     */
    public function onPageRequest(PageRequestEvent $event)
    {
        if ($event->page_matches("post/list")) {
            $pageinfo = $this->get_list_pageinfo($event);
            $prev_url = make_http(make_link("post/list/".$pageinfo["prev"]));
            $next_url = make_http(make_link("post/list/".$pageinfo["next"]));
            $this->add_arrowkeys_code($prev_url, $next_url);
        }
    }

    /**
     * Adds the javascript to the page with the given urls.
     */
    private function add_arrowkeys_code(string $prev_url, string $next_url)
    {
        global $page;

        $page->add_html_header("<script type=\"text/javascript\">
			(function($){
				$(document).keyup(function(e) {
					if($(e.target).is('input', 'textarea')){ return; }
					if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) { return; }
					if (e.keyCode == 37) { window.location.href = '{$prev_url}'; }
					else if (e.keyCode == 39) { window.location.href = '{$next_url}'; }
				});
			})(jQuery);
			</script>", 60);
    }

    /**
     * Returns info about the current page number.
     */
    private function get_list_pageinfo(PageRequestEvent $event): array
    {
        global $config, $database;

        // get the amount of images per page
        $images_per_page = $config->get_int(IndexConfig::IMAGES);

        if ($event->count_args() > 1) {
            // if there are tags, use pages with tags
            $prefix = url_escape($event->get_arg(0)) . "/";
            $page_number = $event->try_page_num(1);
            $total_pages = ceil($database->get_one(
                "SELECT count FROM tags WHERE tag=:tag",
                ["tag"=>$event->get_arg(0)]
            ) / $images_per_page);
        } else {
            // if there are no tags, use default
            $prefix = "";
            $page_number = $event->try_page_num(0);
            $total_pages = ceil($database->get_one(
                "SELECT COUNT(*) FROM images"
            ) / $images_per_page);
        }

        // creates previous & next values
        // When previous first page, go to last page
        if ($page_number <= 1) {
            $prev = $total_pages;
        } else {
            $prev = $page_number-1;
        }
        if ($page_number >= $total_pages) {
            $next = 1;
        } else {
            $next = $page_number+1;
        }

        // Create return array
        $pageinfo = [
            "prev" => $prefix.$prev,
            "next" => $prefix.$next,
        ];

        return $pageinfo;
    }
}
