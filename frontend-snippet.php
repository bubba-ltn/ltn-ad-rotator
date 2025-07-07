<?php
// Place in your theme template where you want the banner:
if ( function_exists('ltn_get_active_ad') ) {
    $ad = ltn_get_active_ad();
    if ( ! empty( $ad['image'] ) ) { ?>
    <div class="ltn-frontend-ad-container">
      <a href="<?php echo esc_url( home_url( '?ltn_ad_click=' . $ad['key'] ) ); ?>"
         class="ltn-frontend-ad-link" target="_blank" rel="noopener">
        <img src="<?php echo esc_url( $ad['image'] ); ?>" class="ltn-frontend-ad-img" alt="Top Ad Banner" />
        <div class="ltn-frontend-ad-badge">AD</div>
      </a>
    </div>
    <?php }
}
?>