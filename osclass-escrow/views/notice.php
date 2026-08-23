<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var string $message */
/** @var string|null $linkUrl */
/** @var string|null $linkText */
?>

<div class="elektron-escrow-notice">
    <p><?php echo osc_esc_html($message); ?></p>
    <?php if ($linkUrl !== null) { ?>
        <p><a href="<?php echo osc_esc_html($linkUrl); ?>"><?php echo osc_esc_html($linkText); ?></a></p>
    <?php } ?>
</div>
