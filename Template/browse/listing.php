<div class="page-header">
    <h2 class="matinee-crumbs">
        <?= $this->url->link(t('Media'), 'MatineeBrowseController', 'index', ['plugin' => 'Matinee']) ?>
        <span class="matinee-crumb-sep">›</span>
        <?= $this->url->link($this->text->e($page['server']['name']), 'MatineeBrowseController', 'server', ['plugin' => 'Matinee', 'server_id' => $page['server']['id']]) ?>
        <span class="matinee-crumb-sep">›</span>
        <?= $page['sectionTitle'] === '' ? t('Library') : $this->text->e($page['sectionTitle']) ?>
    </h2>
</div>

<?php if ($pastEnd || empty($page['items'])): ?>
    <p class="alert"><?= t('Nothing to show here.') ?></p>
<?php else: ?>
    <ul class="matinee-grid">
        <?php foreach ($page['items'] as $item): ?>
        <li class="matinee-card">
            <?php if ($item['browseRef'] !== null): ?>
            <a href="<?= $this->url->href('MatineeBrowseController', 'listing', ['plugin' => 'Matinee', 'ref' => $item['browseRef']]) ?>" class="matinee-card-link">
            <?php endif ?>
                <?php if ($item['artRef'] !== null): ?>
                <img loading="lazy" alt="" class="matinee-poster" width="144" height="144" src="<?= $this->url->href('MatineeBrowseController', 'art', ['plugin' => 'Matinee', 'ref' => $item['artRef'], 'size' => 144]) ?>">
                <?php else: ?>
                <span class="matinee-poster matinee-poster-none"></span>
                <?php endif ?>
                <span class="matinee-title"><?= $this->text->e($item['title']) ?></span>
            <?php if ($item['browseRef'] !== null): ?>
            </a>
            <?php endif ?>
            <?php if ($item['subtitle'] !== ''): ?>
            <span class="matinee-subtitle"><?= $this->text->e($item['subtitle']) ?></span>
            <?php endif ?>
            <?php if ($item['year'] !== null): ?>
            <span class="matinee-year"><?= $this->text->e((string) $item['year']) ?></span>
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<div class="matinee-paging">
    <?php if ($prev !== null): ?>
    <a href="<?= $this->url->href('MatineeBrowseController', 'listing', ['plugin' => 'Matinee', 'ref' => $ref, 'start' => $prev]) ?>" class="matinee-page-prev"><?= t('Previous') ?></a>
    <?php endif ?>
    <?php if ($pastEnd): ?>
    <a href="<?= $this->url->href('MatineeBrowseController', 'listing', ['plugin' => 'Matinee', 'ref' => $ref, 'start' => 0]) ?>" class="matinee-page-first"><?= t('Back to the first page') ?></a>
    <?php elseif ($page['total'] > 0): ?>
    <span class="matinee-page-count"><?= t('%d–%d of %d', $page['start'] + 1, min($page['start'] + $page['pageSize'], $page['total']), $page['total']) ?></span>
    <?php endif ?>
    <?php if ($next !== null): ?>
    <a href="<?= $this->url->href('MatineeBrowseController', 'listing', ['plugin' => 'Matinee', 'ref' => $ref, 'start' => $next]) ?>" class="matinee-page-next"><?= t('Next') ?></a>
    <?php endif ?>
</div>
