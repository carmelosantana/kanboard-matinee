<?php if ($this->user->isAdmin()): ?>
<p class="alert alert-error"><?= $this->text->e(t('Matinee is disabled: PHP is missing the sodium or curl extension. Install both and reload.')) ?></p>
<?php endif ?>
