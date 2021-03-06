<?php

class CronUploaderTheme extends Themelet
{
    public function display_documentation(
        bool $running,
        array $queue_dirinfo,
        array $uploaded_dirinfo,
        array $failed_dirinfo,
        string $cron_cmd,
        string $cron_url,
        ?array $log_entries
    ) {
        global $page;



        $info_html = "<b>Information</b>
			<br>
			<table style='width:470px;'>
			" . ($running ? "<tr><td colspan='4'><b style='color:red'>Cron upload is currently running</b></td></tr>" : "") . "
			<tr>
			<td style='width:90px;'><b>Directory</b></td>
			<td style='width:90px;'><b>Files</b></td>
			<td style='width:90px;'><b>Size (MB)</b></td>
			<td style='width:200px;'><b>Directory Path</b></td>
			</tr><tr>
			<td>Queue</td>
			<td>{$queue_dirinfo['total_files']}</td>
			<td>{$queue_dirinfo['total_mb']}</td>
			<td>{$queue_dirinfo['path']}</td>
			</tr><tr>
			<td>Uploaded</td>
			<td>{$uploaded_dirinfo['total_files']}</td>
			<td>{$uploaded_dirinfo['total_mb']}</td>
			<td>{$uploaded_dirinfo['path']}</td>
			</tr><tr>
			<td>Failed</td>
			<td>{$failed_dirinfo['total_files']}</td>
			<td>{$failed_dirinfo['total_mb']}</td>
			<td>{$failed_dirinfo['path']}</td>
			</tr></table>
	
			<br>Cron Command: <input type='text' size='60' value='$cron_cmd'><br>
			Create a cron job with the command above.<br/>
				Read the documentation if you're not sure what to do.<br>";

        $install_html = "
			This cron uploader is fairly easy to use but has to be configured first.
			<ol>
			    <li>Install & activate this plugin.</li>
			    <li>Go to the <a href='".make_link("setup")."'>Board Config</a> and change any settings to match your preference.</li>
			    <li>Copy the cron command above.</li>
			    <li>Create a cron job or something else that can open a url on specified times.
                    <br/>cron is a service that runs commands over and over again on a a schedule. You can set up cron (or any similar tool) to run the command above to trigger the import on whatever schedule you desire.
			        <br />If you're not sure how to do this, you can give the command to your web host and you can ask them to create the cron job for you.
			        <br />When you create the cron job, you choose when to upload new images.</li>
            </ol>";

        $usage_html = "Upload your images you want to be uploaded to the queue directory using your FTP client or other means. 
<br />(<b>{$queue_dirinfo['path']}</b>)
                    <ol>
                        <li>Any sub-folders will be turned into tags.</li>
                        <li>If the file name matches \"## - tag1 tag2.png\" the tags will be used.</li>
                        <li>If both are found, they will all be used.</li>
                        <li>The character \";\" will be changed into \":\" in any tags.</li>
                        <li>You can inherit categories by creating a folder that ends with \";\". For instance category;\\tag1 would result in the tag category:tag1. This allows creating a category folder, then creating many subfolders that will use that category.</li>                            
                    </ol>
                    The cron uploader works by importing files from the queue folder whenever this url is visited:
                <br/><pre><a href='$cron_url'>$cron_url</a></pre>

            <ul>
                <li>If an import is already running, another cannot start until it is done.</li>
                <li>Each time it runs it will import up to ".CronUploaderConfig::get_count()." file(s). This is controlled from <a href='".make_link("setup")."'>Board Config</a>.</li>
                <li>Uploaded images will be moved to the 'uploaded' directory into a subfolder named after the time the import started. It's recommended that you remove everything out of this directory from time to time. If you have admin controls enabled, this can be done from <a href='".make_link("admin")."'>Board Admin</a>.</li> 
                <li>If you enable the db logging extension, you can view the log output on this screen. Otherwise the log will be written to a file at ".CronUploaderConfig::get_dir().DIRECTORY_SEPARATOR."uploads.log</li>
			</ul>
        ";

        $page->set_title("Cron Uploader");
        $page->set_heading("Cron Uploader");

        $block = new Block("Cron Uploader", $info_html, "main", 10);
        $block_install = new Block("Setup Guide", $install_html, "main", 30);
        $block_usage= new Block("Usage Guide", $usage_html, "main", 20);
        $page->add_block($block);
        $page->add_block($block_install);
        $page->add_block($block_usage);

        if (!empty($log_entries)) {
            $log_html = "<table class='log'>";
            foreach ($log_entries as $entry) {
                $log_html .= "<tr><th>{$entry["date_sent"]}</th><td>{$entry["message"]}</td></tr>";
            }
            $log_html .= "</table>";
            $block = new Block("Log", $log_html, "main", 40);
            $page->add_block($block);
        }
    }

    public function display_form(array $failed_dirs)
    {
        global $page, $database;

        $link = make_http(make_link("cron_upload"));
        $html = "<a href='$link'>Cron uploader documentation</a>";

        $html .= make_form(make_link("admin/cron_uploader_restage"));
        $html .= "<table class='form'>";
        $html .= "<tr><th>Failed dir</th><td><select name='failed_dir' required='required'><option></option>";

        foreach ($failed_dirs as $dir) {
            $html .= "<option value='$dir'>$dir</option>";
        }

        $html .= "</select></td></tr>";
        $html .= "<tr><td colspan='2'><input type='submit' value='Re-stage files to queue' /></td></tr>";
        $html .= "</table></form>";

        $html .= make_form(make_link("admin/cron_uploader_clear_queue"), "POST", false, "", "return confirm('Are you sure you want to delete everything in the queue folder?');")
            ."<table class='form'><tr><td>"
            ."<input type='submit' value='Clear queue folder'></td></tr></table></form>";
        $html .= make_form(make_link("admin/cron_uploader_clear_uploaded"), "POST", false, "", "return confirm('Are you sure you want to delete everything in the uploaded folder?');")
            ."<table class='form'><tr><td>"
            ."<input type='submit' value='Clear uploaded folder'></td></tr></table></form>";
        $html .= make_form(make_link("admin/cron_uploader_clear_failed"), "POST", false, "", "return confirm('Are you sure you want to delete everything in the failed folder?');")
            ."<table class='form'><tr><td>"
            ."<input type='submit' value='Clear failed folder'></td></tr></table></form>";
        $html .= "</table>\n";
        $page->add_block(new Block("Cron Upload", $html));
    }
}
