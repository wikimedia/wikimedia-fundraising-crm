<div class="tivy-grid-container">
  <div class="tivy-grid-menu">
    <?php print render($page['left']); ?>
  </div>
  <div class="tivy-grid-main">
    <?php print $breadcrumb; ?>
    <h1 class="title" id="page-title"><?php print $title; ?></h1>
    <?php if ($action_links): ?><ul class="action-links"><?php print render($action_links); ?></ul><?php endif; ?>
    <?php if ($messages): ?><?php print $messages; ?><?php endif; ?>
    <?php print render($page['content']); ?>
  </div>
</div>
