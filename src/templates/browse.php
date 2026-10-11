<?php
/** @var string|null $letter @var list<array{slug:string,headword:string}> $rows @var string|null $next */
?>
<section class="browse">
  <h1><?= $letter === null ? 'A to Z' : h($letter === '0' ? '0–9' : strtoupper($letter)) ?></h1>
  <nav class="az" aria-label="Letters">
    <a href="/browse/0"<?= $letter === '0' ? ' aria-current="page"' : '' ?>>0–9</a>
    <?php foreach (range('a', 'z') as $item): ?>
      <a href="/browse/<?= h($item) ?>"<?= $letter === $item ? ' aria-current="page"' : '' ?>><?= h(strtoupper($item)) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($letter !== null): ?>
    <?php if ($rows === []): ?>
      <p class="empty">No headwords in this section yet.</p>
    <?php else: ?>
      <ul class="word-list">
        <?php foreach ($rows as $row): ?>
          <li><a href="/dictionary/english/<?= h(rawurlencode($row['slug'])) ?>"><?= h($row['headword']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($next !== null): ?>
        <p class="more"><a href="/browse/<?= h((string) $letter) ?>?after=<?= h(rawurlencode($next)) ?>">More</a></p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>
