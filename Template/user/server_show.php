<div class="page-header">
    <h2><?= $this->text->e($server['name']) ?></h2>
    <?php if ($is_owner): ?>
    <ul>
        <li><?= $this->url->icon('edit', t('Edit'), 'MatineeServerController', 'edit', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?></li>
        <?php if ($server['is_enabled']): ?>
        <li><?= $this->url->icon('share-alt', t('Share'), 'MatineeServerController', 'shareCreate', ['plugin' => 'Matinee', 'server_id' => $server['id'], 'csrf_token' => $this->app->getToken()->getReusableCSRFToken()]) ?></li>
        <?php endif ?>
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
        <button type="submit" class="btn btn-red"><?= t('Remove server and its shares') ?></button>
    </form>
</div>

<h3><?= t('Shares') ?></h3>
<?php if (empty($shares)): ?>
    <p class="alert"><?= t('This server is not shared.') ?></p>
<?php else: ?>
    <table class="table-striped">
        <tr>
            <th><?= t('Audience') ?></th>
            <th><?= t('Libraries') ?></th>
            <th><?= t('Status') ?></th>
            <th></th>
        </tr>
        <?php foreach ($shares as $share): ?>
        <tr>
            <td><?= $this->text->e($share['audience_label']) ?></td>
            <td><?= $share['whole_server'] ? t('Whole server') : t('Sections %s', implode(', ', $share['sections'])) ?></td>
            <td><?= $share['is_enabled'] ? t('Active') : t('Disabled by an admin') ?></td>
            <td>
                <form method="post" action="<?= $this->url->href('MatineeServerController', 'shareUnlink', ['plugin' => 'Matinee', 'share_id' => $share['id']]) ?>" class="form-inline">
                    <?= $this->form->csrf() ?>
                    <button type="submit" class="btn btn-red"><?= t('Unlink') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach ?>
    </table>
<?php endif ?>
<p class="form-help"><?= t('A project share reaches everyone in the project, including members added later. You can unlink it at any time.') ?></p>
