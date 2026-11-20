<?php if ($this->user->isCurrentUser($user['id'])): ?>
<li <?= $this->app->checkMenuSelection('MatineeServerController', '', 'Matinee') ?>>
    <?= $this->url->link(t('Plex servers'), 'MatineeServerController', 'index', ['plugin' => 'Matinee']) ?>
</li>
<?php endif ?>
