<h3><?= t('Plex servers') ?></h3>
<?php if (empty($servers)): ?>
    <p class="alert"><?= t('No Plex server is connected.') ?></p>
<?php else: ?>
    <table class="table-striped">
        <tr>
            <th><?= t('Name') ?></th>
            <th><?= t('Owner') ?></th>
            <th><?= t('Host') ?></th>
            <th><?= t('Status') ?></th>
            <th><?= t('Shared') ?></th>
            <th></th>
        </tr>
        <?php foreach ($servers as $server): ?>
        <tr>
            <td><?= $this->url->link($this->text->e($server['name']), 'MatineeServerController', 'show', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?></td>
            <td><?= $this->text->e($server['owner']) ?></td>
            <td><?= $this->text->e($server['host']) ?></td>
            <td><?= $this->text->e($server['status']) ?></td>
            <td><?= $server['is_shared'] ? t('Yes') : t('No') ?></td>
            <td>
                <form method="post" action="<?= $this->url->href('MatineeAdminController', 'toggle', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?>" class="form-inline">
                    <?= $this->form->csrf() ?>
                    <input type="hidden" name="enabled" value="<?= $server['is_enabled'] ? '0' : '1' ?>">
                    <button type="submit" class="btn"><?= $server['is_enabled'] ? t('Disable') : t('Enable') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach ?>
    </table>
<?php endif ?>
