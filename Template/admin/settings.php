<div class="page-header">
    <h2>Matinee</h2>
</div>

<?php if ($key === null): ?>
    <p class="alert alert-error"><?= t('The Matinee key could not be loaded. Check MATINEE_KEY or the key file.') ?></p>
<?php else: ?>
    <p class="alert alert-info"><?= t('Encryption key: %s, key id %s', $key['source'] === 'env' ? t('the MATINEE_KEY environment variable') : t('the key file in the data directory'), $this->text->e($key['id'])) ?></p>
<?php endif ?>

<form method="post" action="<?= $this->url->href('MatineeAdminController', 'save', ['plugin' => 'Matinee']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Connecting servers') ?></legend>
        <?= $this->form->label(t('Who may connect a Plex server'), 'matinee_connect_policy') ?>
        <?= $this->form->select('matinee_connect_policy', $policies, $values) ?>

        <?= $this->form->label(t('Server connection attempts per user per 10 minutes'), 'matinee_attempt_limit') ?>
        <?= $this->form->number('matinee_attempt_limit', $values, [], ['min="1"', 'max="1000"', 'required']) ?>

        <?= $this->form->label(t('Servers per user'), 'matinee_max_servers') ?>
        <?= $this->form->number('matinee_max_servers', $values, [], ['min="1"', 'max="1000"', 'required']) ?>
    </fieldset>

    <fieldset>
        <legend><?= t('Streams') ?></legend>
        <?= $this->form->label(t('Long streams per server'), 'matinee_stream_cap_server') ?>
        <?= $this->form->number('matinee_stream_cap_server', $values, [], ['min="1"', 'max="1000"', 'required']) ?>

        <?= $this->form->label(t('Long streams per user'), 'matinee_stream_cap_user') ?>
        <?= $this->form->number('matinee_stream_cap_user', $values, [], ['min="1"', 'max="1000"', 'required']) ?>

        <?= $this->form->label(t('Long streams on this Kanboard'), 'matinee_stream_cap_global') ?>
        <?= $this->form->number('matinee_stream_cap_global', $values, [], ['min="1"', 'max="1000"', 'required']) ?>
        <p class="form-help"><?= t('Video sessions and transcoded music count. Keep the last limit under a quarter of PHP-FPM\'s pm.max_children.') ?></p>
    </fieldset>

    <fieldset>
        <legend><?= t('Network') ?></legend>
        <?= $this->form->label(t('Private targets'), 'matinee_private_targets') ?>
        <?= $this->form->textarea('matinee_private_targets', $values, [], ['rows="4"']) ?>
        <p class="form-help"><?= t('One per line: a host, IP address or CIDR range, with an optional :port. A private address is reachable only when it matches an entry. Loopback needs an IP or CIDR entry.') ?></p>

        <?= $this->form->label(t('Allowed hosts'), 'matinee_allowed_hosts') ?>
        <?= $this->form->textarea('matinee_allowed_hosts', $values, [], ['rows="4"']) ?>
        <p class="form-help"><?= t('One per line: a host name, IP address or *.domain. Leave empty to allow any public host.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>

<?= $this->render('Matinee:admin/servers', ['servers' => $servers]) ?>
