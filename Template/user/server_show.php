<div class="page-header">
    <h2><?= $this->text->e($server['name']) ?></h2>
    <?php if ($is_owner): ?>
    <ul>
        <li><?= $this->url->icon('edit', t('Edit'), 'MatineeServerController', 'edit', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?></li>
    </ul>
    <?php endif ?>
</div>

<ul class="panel">
    <li><strong><?= t('Host') ?>:</strong> <?= $this->text->e($server['host']) ?></li>
    <li><strong><?= t('Status') ?>:</strong> <?= $this->text->e($server['status']) ?></li>
    <?php if ($server['last_checked_at'] > 0): ?>
    <li><strong><?= t('Last checked') ?>:</strong> <?= $this->dt->datetime($server['last_checked_at']) ?></li>
    <?php endif ?>
</ul>

<div class="form-actions">
    <form method="post" action="<?= $this->url->href('MatineeServerController', 'verify', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?>" class="form-inline">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn"><?= t('Verify now') ?></button>
    </form>
    <form method="post" action="<?= $this->url->href('MatineeServerController', 'remove', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?>" class="form-inline">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-red"><?= t('Remove server') ?></button>
    </form>
</div>

