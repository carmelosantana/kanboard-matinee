<div class="page-header">
    <h2><?= t('Plex servers') ?></h2>
    <?php if ($can_connect): ?>
    <ul>
        <li><?= $this->url->icon('plus', t('Add a server'), 'MatineeServerController', 'create', ['plugin' => 'Matinee']) ?></li>
    </ul>
    <?php endif ?>
</div>

<?php if (empty($servers)): ?>
    <p class="alert"><?= t('You have not connected a Plex server.') ?></p>
<?php else: ?>
    <table class="table-striped">
        <tr>
            <th><?= t('Name') ?></th>
            <th><?= t('Host') ?></th>
            <th><?= t('Status') ?></th>
        </tr>
        <?php foreach ($servers as $server): ?>
        <tr>
            <td><?= $this->url->link($this->text->e($server['name']), 'MatineeServerController', 'show', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?></td>
            <td><?= $this->text->e($server['host']) ?></td>
            <td><?= $this->text->e($server['status']) ?></td>
        </tr>
        <?php endforeach ?>
    </table>
<?php endif ?>
