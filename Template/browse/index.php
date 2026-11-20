<div class="page-header">
    <h2><?= t('Media') ?></h2>
</div>

<?php if (empty($servers)): ?>
    <p class="alert"><?= t('No Plex server is shared with you yet.') ?></p>
<?php else: ?>
    <ul class="matinee-list">
        <?php foreach ($servers as $server): ?>
        <li><?= $this->url->link($this->text->e($server['name']), 'MatineeBrowseController', 'server', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
