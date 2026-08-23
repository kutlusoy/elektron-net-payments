<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Renders a standalone message (optionally with a single link onward)
 * directly into the current response, in place of the page a controller
 * decided not to show (not logged in, listing not found, wallet missing,
 * etc.).
 *
 * Deliberately not `osc_add_flash_error_message() + osc_redirect_to()`:
 * every one of this plugin's own routes is rendered through Osclass's
 * custom-route pipeline (`CWebCustom`), whose theme template
 * (`custom.php`/`user-custom.php`) always renders the theme's own
 * `header.php` -- unconditionally, before this plugin's controller file is
 * even `include`d (confirmed against the real, currently deployed theme,
 * `oc-content/themes/sigma/{custom,user-custom}.php`: both call
 * `osc_current_web_theme_path('header.php')` before `osc_render_file()`,
 * which is what actually includes this plugin's controller). By the time
 * any guard clause in one of this plugin's controllers runs,
 * `osc_redirect_to()` is calling `header()` well after real page content
 * has already been buffered. `osc_redirect_to()`'s own handling for that
 * case (`oc-includes/osclass/utils.php`: `if (ob_get_length() > 0) {
 * ob_end_flush(); }`) flushes -- i.e. actually transmits -- that buffered
 * content to the client first, which is itself what finally sends the
 * response headers; the `header('Location: ...')` call right after that is
 * then guaranteed to fail with "headers already sent", and the browser
 * never actually navigates anywhere. Confirmed live: this is exactly the
 * "Cannot modify header information - headers already sent ... in
 * oc-includes/osclass/core/Cookie.php on line 137 ... utils.php:2624"
 * warning trail a real install showed instead of a working redirect.
 * Rendering here instead of redirecting sidesteps the problem entirely,
 * rather than fighting Osclass's own output-buffering internals.
 */
function elektron_escrow_render_notice(string $message, ?string $linkUrl = null, ?string $linkText = null): void
{
    require osc_plugins_path() . 'osclass-escrow/views/notice.php';
}
