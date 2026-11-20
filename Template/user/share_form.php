<div class="page-header">
    <h2><?= t('Share %s', $server['name']) ?></h2>
</div>

<?php if ($sections === null): ?>
    <p class="alert alert-error"><?= $this->text->e($section_error) ?></p>
<?php endif ?>

<?php if (empty($projects) && ! $can_share_everyone): ?>
    <p class="alert"><?= t('You manage no projects to share with.') ?></p>
<?php else: ?>
<form method="post" action="<?= $this->url->href('MatineeServerController', 'shareSave', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('What to share') ?></legend>
        <label><input type="checkbox" name="whole_server" value="1"> <?= t('Whole server (the libraries on it now and later)') ?></label>
        <?php foreach ($sections ?? [] as $section): ?>
            <label><input type="checkbox" name="sections[]" value="<?= $this->text->e($section['key']) ?>"> <?= $this->text->e($section['title']) ?></label>
        <?php endforeach ?>
    </fieldset>

    <fieldset>
        <legend><?= t('Who sees it') ?></legend>
        <label for="form-audience"><?= t('Audience') ?></label>
        <select name="audience" id="form-audience" required>
            <?php foreach ($projects as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $this->text->e($name) ?></option>
            <?php endforeach ?>
            <?php if ($can_share_everyone): ?>
            <option value="everyone"><?= t('Everyone') ?></option>
            <?php endif ?>
        </select>
        <p class="form-help"><?= t('A project share reaches everyone in the project, including members added later. You can unlink it at any time.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        <?= t('or') ?>
        <?= $this->url->link(t('cancel'), 'MatineeServerController', 'show', ['plugin' => 'Matinee', 'server_id' => $server['id']]) ?>
    </div>
</form>
<?php endif ?>
