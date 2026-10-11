<?php
/** @var array<string, mixed> $entry @var string $jsonLd */
$sourceNames = [
    'wiktionary' => 'Wiktionary via Kaikki.org (CC BY-SA 4.0)',
    'wordnet' => 'Open English WordNet (CC BY 4.0)',
    'moby' => 'Moby Thesaurus (public domain)',
    'cmudict' => 'CMU Pronouncing Dictionary (BSD-style)',
    'frequency' => 'FrequencyWords, OpenSubtitles 2018 (CC BY-SA 4.0)',
];
?>
<article class="entry">
  <header class="entry-head">
    <h1><?= h((string) $entry['headword']) ?></h1>
    <?php if (!empty($entry['ipa'])): ?>
      <p class="ipa">
        <span><?= h((string) $entry['ipa']) ?></span>
        <?php if (!empty($entry['audio'])): ?>
          <audio controls preload="none" src="<?= h((string) $entry['audio']) ?>">Audio pronunciation</audio>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ((int) $entry['band'] > 0): ?>
      <p class="freq-row"><?= frequency_marks((int) $entry['band']) ?> <span><?= h(frequency_label((int) $entry['band'])) ?></span></p>
    <?php endif; ?>
  </header>
  <?php foreach ($entry['entries'] as $block): ?>
    <section class="pos-block">
      <h2><?= h((string) $block['pos']) ?></h2>
      <ol class="senses">
        <?php foreach ($block['senses'] as $sense): ?>
          <li>
            <p><?= h((string) $sense['gloss']) ?></p>
            <?php if (!empty($sense['example'])): ?>
              <blockquote>
                <p><?= h((string) $sense['example']) ?></p>
                <?= example_credit($sense['example_source']) ?>
              </blockquote>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php
        $groups = [
            'synonym' => 'Synonyms',
            'antonym' => 'Antonyms',
            'hypernym' => 'Hypernyms',
            'related' => 'Related words',
        ];
      ?>
      <?php foreach ($groups as $key => $label): ?>
        <?php if (!empty($block['relations'][$key])): ?>
          <p class="rels"><span><?= h($label) ?></span>
            <?php foreach ($block['relations'][$key] as $i => $item): ?>
              <?php if ($i > 0): ?><span class="dot" aria-hidden="true"> · </span><?php endif; ?>
              <?= word_link($item) ?>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!empty($block['etymology'])): ?>
        <p class="etym"><span>Etymology</span> <?= h((string) $block['etymology']) ?></p>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  <p class="credit-line">This entry draws on
    <?php
      $bits = [];
      foreach ($entry['sources'] as $source) {
          if (isset($sourceNames[$source])) {
              $bits[] = $sourceNames[$source];
          }
      }
      echo h($bits === [] ? 'open lexical data' : implode('; ', $bits));
    ?>.
  </p>
  <nav class="nearby" aria-label="Nearby words">
    <p>Nearby words</p>
    <div>
      <?php foreach (array_merge($entry['nearby_before'], [['slug' => $entry['slug'], 'headword' => $entry['headword'], 'here' => true]], $entry['nearby_after']) as $near): ?>
        <?php if (!empty($near['here'])): ?>
          <strong><?= h((string) $near['headword']) ?></strong>
        <?php else: ?>
          <a href="/dictionary/english/<?= h(rawurlencode((string) $near['slug'])) ?>"><?= h((string) $near['headword']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </nav>
</article>
<script type="application/ld+json" nonce="<?= h($nonce) ?>"><?= $jsonLd ?></script>
