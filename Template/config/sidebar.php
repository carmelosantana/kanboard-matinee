<?php if ($this->user->isAdmin()): ?>
<li <?= $this->app->checkMenuSelection('MatineeAdminController', '', 'Matinee') ?>>
    <?= $this->url->link('Matinee', 'MatineeAdminController', 'show', ['plugin' => 'Matinee']) ?>
</li>
<?php endif ?>
