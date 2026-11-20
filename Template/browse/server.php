<div class="page-header">
    <h2 class="matinee-crumbs">
        <?= $this->url->link(t('Media'), 'MatineeBrowseController', 'index', ['plugin' => 'Matinee']) ?>
        <span class="matinee-crumb-sep">›</span>
        <?= $this->text->e($server['name']) ?>
    </h2>
</div>

<?php if (empty($sections)): ?>
    <p class="alert"><?= t('This server has no libraries to browse.') ?></p>
<?php else: ?>
    <ul class="matinee-list">
        <?php foreach ($sections as $section): ?>
        <li><a href="<?= $this->url->href('MatineeBrowseController', 'listing', ['plugin' => 'Matinee', 'ref' => $section['ref']]) ?>"><?= $this->text->e($section['title']) ?></a></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
