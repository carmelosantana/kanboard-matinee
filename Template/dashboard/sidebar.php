<li <?= $this->app->checkMenuSelection('MatineeBrowseController', '', 'Matinee') ?>>
    <?= $this->url->link(t('Media'), 'MatineeBrowseController', 'index', ['plugin' => 'Matinee']) ?>
</li>
