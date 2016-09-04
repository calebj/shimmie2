<?php
class FiltersTheme extends Themelet {
	public function display_user_filters(User $user, $value, $default) {
		global $page;
		$uid = $user->id;
		$placeholder = $default ? '(no defaults)' : '(no filters)';
		$cleared = $default ? ' checked' : '';
		$readonly = $default ? ' readonly' : '';

		$html = make_form(make_link("filter/set_user"), 'POST')."
				<input name='uid' type='hidden' value='$uid'>
				<label for='tags'>Tags:&nbsp;</label><input name='tags' value='$value' type='text' placeholder='$placeholder' class='autocomplete_tags' autocomplete='off' $readonly><br>
				<input name='clear' type='checkbox' value='clear' $cleared><label for='clear'>Use defaults?</label>
				<input type='submit' value='Set filters'>
			</form>";
		$page->add_block(new Block("Filtered Tags", $html, "main"));
	}
}
