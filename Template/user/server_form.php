<div class="page-header">
    <h2><?= $server === null ? t('Add a Plex server') : t('Edit %s', $server['name']) ?></h2>
</div>

<form method="post" action="<?= $this->url->href('MatineeServerController', 'save', ['plugin' => 'Matinee']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?php if ($server !== null): ?>
        <?= $this->form->hidden('server_id', ['server_id' => $server['id']]) ?>
    <?php endif ?>

    <?= $this->form->label(t('Name'), 'name') ?>
    <?= $this->form->text('name', $values, [], ['required', 'maxlength="100"']) ?>

    <?= $this->form->label(t('Server address'), 'url') ?>
    <?= $this->form->text('url', $values, [], ['required', 'autocomplete="off"', 'placeholder="https://plex.example.com:32400"']) ?>

    <?= $this->form->label(t('Plex token'), 'token') ?>
    <?php /* Write-only: the stored token is never sent back, not even masked. */ ?>
    <?php if ($server === null): ?>
        <input type="password" name="token" id="form-token" value="" autocomplete="new-password" required>
    <?php else: ?>
        <input type="password" name="token" id="form-token" value="" autocomplete="new-password" placeholder="<?= t('stored — leave blank to keep') ?>">
        <p class="form-help"><?= t('Changing the address needs the token again.') ?></p>
    <?php endif ?>
    <p class="form-help"><?= t('The token is stored encrypted and is never shown again.') ?></p>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        <?= t('or') ?>
        <?= $server === null
            ? $this->url->link(t('cancel'), 'MatineeServerController', 'index', ['plugin' => 'Matinee'])
            : $this->url->link(t('cancel'), 'MatineeServerController', 'show', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?>
    </div>
</form>
