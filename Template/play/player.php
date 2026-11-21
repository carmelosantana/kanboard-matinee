<div class="page-header">
    <h2 class="matinee-crumbs">
        <?= $this->url->link(t('Media'), 'MatineeBrowseController', 'index', ['plugin' => 'Matinee']) ?>
        <span class="matinee-crumb-sep">›</span>
        <?= $this->url->link($this->text->e($page['server']['name']), 'MatineeBrowseController', 'server', ['plugin' => 'Matinee', 'server_id' => $page['server']['id']]) ?>
    </h2>
</div>

<div class="matinee-player-page">
    <h2 class="matinee-player-title"><?= $this->text->e($page['title']) ?></h2>
    <?php if ($page['subtitle'] !== ''): ?>
    <p class="matinee-subtitle"><?= $this->text->e($page['subtitle']) ?></p>
    <?php endif ?>

    <?php if ($page['kind'] === 'audio'): ?>
    <audio controls preload="metadata" class="matinee-player" data-matinee-kind="audio"
           src="<?= $this->url->href('MatineePlayController', 'media', ['plugin' => 'Matinee', 'ref' => $page['mediaRef']]) ?>"
           data-matinee-keepalive="<?= $this->url->href('MatineePlayController', 'keepalive', ['plugin' => 'Matinee']) ?>"
           data-matinee-csrf="<?= $this->text->e($csrf) ?>"
           data-matinee-error="<?= $this->text->e(t('This item could not be played.')) ?>"></audio>
    <?php else: ?>
    <video controls playsinline class="matinee-player" data-matinee-kind="hls"
           data-matinee-src="<?= $this->url->href('MatineePlayController', 'hlsStart', ['plugin' => 'Matinee', 'ref' => $page['hlsRef'], 'session' => $page['session']]) ?>"
           data-matinee-stop="<?= $this->url->href('MatineePlayController', 'hlsStop', ['plugin' => 'Matinee']) ?>"
           data-matinee-ping="<?= $this->url->href('MatineePlayController', 'hlsPing', ['plugin' => 'Matinee']) ?>"
           data-matinee-keepalive="<?= $this->url->href('MatineePlayController', 'keepalive', ['plugin' => 'Matinee']) ?>"
           data-matinee-session="<?= $this->text->e($page['session']) ?>"
           data-matinee-csrf="<?= $this->text->e($csrf) ?>"
           data-matinee-error="<?= $this->text->e(t('This item could not be played.')) ?>"
           data-matinee-unsupported="<?= $this->text->e(t('Your browser cannot play this video.')) ?>"></video>
    <?php endif ?>
    <p class="matinee-player-message" hidden></p>
</div>
